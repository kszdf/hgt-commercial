<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 智库（AI 财税顾问）控制器。
 *
 * 原本 advisor_chat 只是对话工作台里的一个能力，现在升级为独立页面 /studio/zhiku，
 * 让财税同行可以直接提问拿到 AI 初答，不必再走对话路由解析。
 *
 * 与 ArticleController 同构：
 *   - 租户上下文一律走基类 studioTenant()（超管回退租户，避免 null → 500）；
 *   - 调 8500 一律走 PipelineClient::postJson()（统一超时/重试/降级，不自己写 curl）；
 *   - 失败统一返回 {ok:false, error}，由页面 fetch 捕获后展示。
 *
 * 8500 契约：POST /advisor {topic, industry, urgency}
 *   → {ok:true, topic, industry, urgency, answer, disclaimer} / {ok:false, error}
 * 注意：/advisor 无论业务成功失败都回 HTTP 200，必须看 body 的 ok 字段。
 */
class ZhikuController extends Controller
{
    /** LLM 初答较慢（8500 内部 deepseek timeout=120），与对话侧 advisor_chat 的 150s 对齐。 */
    private const ADVISOR_TIMEOUT = 150;

    /** 提问正文长度上限（与出稿 topic 一致，防超长 prompt 打爆 LLM）。 */
    private const TOPIC_MAX = 200;

    /** 页面渲染：只带租户/品牌上下文，不查库（问答记录不落库，符合"不建表"约束）。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);

        return view('studio.zhiku.index', [
            'tenant' => $tenant,
            'brand'  => $this->brand($tenant),
        ]);
    }

    /**
     * 提问：转发到 8500 /advisor，原样透传 topic/industry/urgency 与 answer/disclaimer。
     * disclaimer 必须回传并在页面上显式渲染（合规：AI 初答不构成正式税务意见）。
     */
    public function ask(Request $request)
    {
        $data = $request->validate([
            'topic'    => ['required', 'string', 'max:' . self::TOPIC_MAX],
            'industry' => ['nullable', 'string', 'max:100'],
            'urgency'  => ['nullable', 'string', 'max:20'],
        ]);

        try {
            // 租户上下文：与出片/出稿同一隔离口径，超管也能拿到可操作租户
            $tenant = $this->studioTenant($request);

            $resp = app(PipelineClient::class)->postJson('/advisor', [
                'topic'    => trim((string) $data['topic']),
                'industry' => (string) ($data['industry'] ?? ''),
                'urgency'  => (string) ($data['urgency'] ?? ''),
            ], self::ADVISOR_TIMEOUT);
        } catch (PipelineUnavailableException $e) {
            // 连接失败 / 超时：8500 大概率没起来，提示用户确认服务
            Log::warning('zhiku ask unavailable: ' . $e->getMessage(), ['tenant' => $tenant->id ?? null]);
            return response()->json([
                'ok'    => false,
                'error' => 'AI 顾问服务暂时不可用：' . $e->getMessage() . ' 若长时间无响应，请确认 8500 服务已运行。',
            ], 503);
        } catch (\Throwable $e) {
            Log::error('zhiku ask failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['ok' => false, 'error' => '提问失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => 'AI 顾问返回异常（HTTP ' . $resp->status() . '）：' . mb_substr((string) $resp->body(), 0, 300)
                    . ' 若长时间无响应，请确认 8500 服务已运行。',
            ], 502);
        }

        $j = $resp->json() ?: [];
        // /advisor 业务失败也是 HTTP 200（如未配置 LLM key），必须按 ok 字段判定
        if (($j['ok'] ?? false) !== true) {
            return response()->json([
                'ok'    => false,
                'error' => (string) ($j['error'] ?? 'AI 顾问未返回结果')
                    . ' 若长时间无响应，请确认 8500 服务已运行。',
            ], 502);
        }

        return response()->json([
            'ok'         => true,
            'topic'      => (string) ($j['topic'] ?? $data['topic']),
            'industry'   => (string) ($j['industry'] ?? ''),
            'urgency'    => (string) ($j['urgency'] ?? ''),
            'answer'     => (string) ($j['answer'] ?? ''),
            // 8500 未带 disclaimer 时兜底，保证页面上永远有免责文案（合规不允许缺省）
            'disclaimer' => (string) ($j['disclaimer'] ?? 'AI 初答仅供参考，涉税决策请以专业意见为准'),
        ]);
    }

    /**
     * 品牌文案：优先租户 settings.brand，回退全局 config('hgt.brand_fallback')。
     * 不在代码里写死任何具体人设/品牌字样。
     */
    private function brand($tenant): string
    {
        $settings = is_array($tenant->settings ?? null) ? $tenant->settings : [];
        $brand = trim((string) ($settings['brand'] ?? ''));
        return $brand !== '' ? $brand : (string) config('hgt.brand_fallback', '');
    }
}
