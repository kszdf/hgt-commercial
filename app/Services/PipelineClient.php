<?php

namespace App\Services;

use App\Exceptions\PipelineUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * 统一封装 Laravel → 8500 出片微服务的 HTTP 调用。
 *
 * 解决稳定性两类坑：
 *  1) 连接抖动（8500 重启 / 瞬时不可达）自动指数退避重试，避免随机 502；
 *  2) 超时 / 连接失败统一抛 PipelineUnavailableException，控制器降级返回 503
 *     而非未捕获的 500（原代码仅拦截 ConnectionException，漏了 RequestException 超时）。
 */
class PipelineClient
{
    private string $base;
    private int $retries;
    private int $retryDelayMs;

    public function __construct()
    {
        $this->base = rtrim(env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500'), '/');
        $this->retries = (int) env('PIPELINE_RETRIES', 3);
        // 250ms 基延迟，retry 内部按尝试次数指数退避
        $this->retryDelayMs = (int) env('PIPELINE_RETRY_DELAY_MS', 250);
    }

    public function post(string $endpoint, array $payload = [], int $timeout = 15): Response
    {
        return $this->send('post', $endpoint, $payload, $timeout);
    }

    public function get(string $endpoint, int $timeout = 15): Response
    {
        return $this->send('get', $endpoint, [], $timeout);
    }

    /** 中止出片任务：POST 8500 /cancel，body {job_id}。 */
    public function cancel(string $jobId, int $timeout = 15): Response
    {
        return $this->post('/cancel', ['job_id' => $jobId], $timeout);
    }

    /**
     * 通用异步作业提交：POST 8500 /async/submit {path, payload} → 立即返回 job_id。
     *
     * 为什么需要它：走 Cloudflare Tunnel 后，CF 免费版「源站响应超时」硬限制 125 秒，
     * 超时返回 524。而 /rewrite /dissect /topic /article/write /hotspot 等 AI 端点
     * 实测 120~280 秒，同步返回必然被 CF 掐断。改成「提交→拿 job_id→轮询」即可绕开。
     *
     * @param  string  $path  8500 上的真实端点，如 '/rewrite'（必须在其异步白名单内）
     */
    public function submitAsync(string $path, array $payload = [], int $timeout = 20): Response
    {
        return $this->post('/async/submit', ['path' => $path, 'payload' => $payload], $timeout);
    }

    /** 通用异步作业轮询：GET 8500 /async/status/{job_id}。 */
    public function statusAsync(string $jobId, int $timeout = 15): Response
    {
        return $this->get('/async/status/' . urlencode($jobId), $timeout);
    }

    /** 显式发送 JSON raw body（8500 /publish 严格要求 JSON body）。 */
    public function postJson(string $endpoint, array $payload, int $timeout = 180): Response
    {
        $url = $this->base . $endpoint;
        $request = $this->baseRequest($timeout);
        try {
            $response = $request
                ->withBody(json_encode($payload, JSON_UNESCAPED_UNICODE), 'application/json')
                ->post($url);
        } catch (ConnectionException | RequestException $e) {
            return $this->handleTransportException($e);
        }
        return $response;
    }

    /**
     * @throws PipelineUnavailableException
     */
    private function send(string $method, string $endpoint, array $payload, int $timeout): Response
    {
        $url = $this->base . $endpoint;
        $request = $this->baseRequest($timeout);

        try {
            $response = $method === 'post'
                ? $request->post($url, $payload)
                : $request->get($url);
        } catch (ConnectionException | RequestException $e) {
            return $this->handleTransportException($e);
        }

        return $response;
    }

    /**
     * 传输层异常处理：
     *  - 连接失败 / 超时（无响应）→ 抛 PipelineUnavailableException（控制器降级 503）
     *  - 4xx/5xx（有响应体）→ 还原为 Response，交给控制器按 successful()/failed() 处理
     *    （例如 8500 返回 400 bad json / 422 校验错误，不应被误判为「服务不可用」）
     *
     * @throws PipelineUnavailableException
     */
    private function handleTransportException(\Throwable $e): Response
    {
        // 4xx/5xx：Laravel 把它们包成 RequestException 且必带 $response，
        // 还原为正常 Response 返回，交给控制器按 successful()/failed() 处理
        //（例如 8500 返回 400 bad json / 422 校验错误，不应被误判为「服务不可用」）。
        if ($e instanceof RequestException) {
            // 4xx/5xx 且有响应体：还原为 Response，交给控制器按 successful()/failed() 处理
            if ($e->response) {
                return $e->response;
            }
            // 超时 / 无响应体（如 Guzzle cURL 28）：视为服务不可用，避免返回 null 导致调用方 500
            throw new PipelineUnavailableException('出片微服务请求超时或失败：' . $e->getMessage(), 0, $e);
        }
        // ConnectionException（连接失败 / 超时，无响应）→ 降级 503
        throw new PipelineUnavailableException('出片微服务暂时不可达（连接失败/超时）：' . $e->getMessage(), 0, $e);
    }

    private function baseRequest(int $timeout)
    {
        return Http::timeout($timeout)
            ->withHeaders([
                'X-Pipeline-Client' => 'laravel',
                'X-Request-Id'      => (string) Str::uuid(),
            ])
            ->retry(
                $this->retries,
                $this->retryDelayMs,
                // 仅对连接失败（连接拒绝 / 重置 / 不可达）重试；超时与 4xx/5xx 不重试
                fn ($exception) => $exception instanceof ConnectionException
            );
    }
}
