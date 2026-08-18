<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\VideoJob;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 批量外发模块：审核通过(publish_status=approved)的视频，一键真实分发到多平台。
 *
 * 真实链路：表单提交 → 本控制器对每个视频调 8500 出片微服务 /publish 端点
 * （{job_id(8500 UUID), platforms}）→ 8500 逐平台调官方开放平台 API 上传发布
 * → 返回 results[{platform,status,url,error}] → 本控制器落 PublishRecord 真实成败。
 * 前端平台 key(wechat) 会自动映射为 8500 key(shipinhao)。未授权平台前置拦截，
 * 避免对开放平台白跑请求。
 */
class PublishController extends Controller
{
    /** 发布工作台：待发视频 + 平台账号 + 发布历史。 */
    public function index()
    {
        $tenant = $this->studioTenant(request());

        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('publish_status', 'approved')
            ->orderByDesc('updated_at')
            ->get();

        $accounts = PlatformAccount::where('tenant_id', $tenant->id)->get();

        // 平台清单（真实模式：未授权平台会前置拦截，提示先授权）
        // 视频号无开放发布 API，仅作手动发布渠道，不进入自动分发列表。
        $platforms = [
            'douyin'       => '抖音',
            'xiaohongshu'  => '小红书',
        ];
        // 手动发布渠道（无开放接口，需在对应 App 手动发布，平台仅做状态跟踪）
        $manualPlatforms = [
            'wechat' => '视频号',
        ];

        $records = PublishRecord::where('tenant_id', $tenant->id)
            ->with('videoJob')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // 真实授权态：问 8500 出片微服务的 /oauth/status（OAuth token 缓存 / client_credential env）。
        // 8500 不可达时降级为「未授权」，不阻断页面渲染。wechat 由 8500 归一为 shipinhao。
        $authStatus = [];
        $base = env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500');
        foreach (array_keys($platforms) as $key) {
            try {
                $resp = app(PipelineClient::class)->get("/oauth/status/{$key}", 3);
                $authStatus[$key] = $resp->successful() && ($resp->json('authorized') === true);
            } catch (PipelineUnavailableException $e) {
                $authStatus[$key] = false;
            }
        }

        // 浏览器侧访问 8500 的公网/本地地址（弹窗授权用；与 OAUTH_REDIRECT_BASE 保持一致）
        $publicBase = env('PYTHON_PIPELINE_PUBLIC_URL', 'http://127.0.0.1:8500');

        $isTrial = ! $tenant->allow_batch;

        return view('studio.publish', compact('videos', 'accounts', 'platforms', 'manualPlatforms', 'records', 'authStatus', 'publicBase', 'isTrial'));
    }

    /** 批量发布（真实链路：调 8500 出片微服务 /publish）。 */
    public function publish(Request $request)
    {
        $tenant = $this->studioTenant(request());

        // —— 未授权批量外发（allow_batch=false，免费套餐默认即如此；超管可单独开启）——
        if (! $tenant->allow_batch) {
            return redirect()->route('studio.publish')
                ->with('error', '当前账号暂未开放批量外发权限，请联系管理员开通或升级套餐。');
        }

        $data = $request->validate([
            'video_ids'   => ['required', 'array', 'min:1'],
            'video_ids.*' => ['integer'],
            'platforms'   => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', 'in:douyin,xiaohongshu'],
        ]);

        // 仅操作本租户、已审核通过、且渲染完成的视频（越权/非 approved/done 的自动忽略）
        $jobs = VideoJob::where('tenant_id', $tenant->id)
            ->whereIn('id', $data['video_ids'])
            ->where('publish_status', 'approved')
            ->where('status', 'done')
            ->get();

        if ($jobs->isEmpty()) {
            return redirect()->route('studio.publish')->with('error', '没有可发布的视频（需先通过人工审核且渲染完成）。');
        }

        $base = env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500');

        // 真实授权态：问 8500，未授权平台前置拦截（不白跑真实 API）
        $authStatus = [];
        foreach (['douyin', 'xiaohongshu'] as $k) {
            try {
                $r = app(PipelineClient::class)->get("/oauth/status/{$k}", 3);
                $authStatus[$k] = $r->successful() && $r->json('authorized') === true;
            } catch (PipelineUnavailableException $e) {
                $authStatus[$k] = false;
            }
        }

        // 前端平台 key → 8500 平台 key
        $map = ['douyin' => 'douyin', 'xiaohongshu' => 'xiaohongshu'];

        $okCount = 0;
        $failCount = 0;

        foreach ($jobs as $job) {
            if (empty($job->job_id)) {
                continue; // 缺少 8500 job_id，无法发布
            }

            $mapped = [];
            foreach ($data['platforms'] as $p) {
                if (empty($authStatus[$p])) {
                    PublishRecord::create([
                        'tenant_id'    => $tenant->id,
                        'video_job_id' => $job->id,
                        'platform'     => $p,
                        'status'       => 'failed',
                        'error'        => '该平台尚未授权，请先点击「授权」完成平台授权后再发布',
                        'published_at' => now(),
                    ]);
                    $failCount++;
                    continue;
                }
                $mapped[] = $map[$p];
            }
            if (empty($mapped)) {
                continue;
            }

            // 调 8500 /publish 真实分发（同步逐平台上传发布，超时放宽到 180s）
            // 注意：8500 严格要求 JSON body，必须用 withBody(json_encode) 显式发送，
            // 否则 Laravel 默认的 Http::post(array) 会发 form 表单导致 8500 返回 400 bad json。
            try {
                $resp = app(PipelineClient::class)->postJson('/publish', [
                    'job_id'    => $job->job_id,
                    'platforms' => $mapped,
                    'title'     => $job->title ?: '短视频',
                ], 180);

                if ($resp->failed()) {
                    // 8500 返回非 2xx（如 400 bad json / 500）→ 视为本次分发失败，记录真实错误
                    foreach ($mapped as $mk) {
                        $plat = array_search($mk, $map, true) ?: $mk;
                        PublishRecord::create([
                            'tenant_id'    => $tenant->id,
                            'video_job_id' => $job->id,
                            'platform'     => $plat,
                            'status'       => 'failed',
                            'error'        => '出片服务返回错误：' . $resp->body(),
                            'published_at' => now(),
                        ]);
                        $failCount++;
                    }
                    continue;
                }
                $results = $resp->json('results') ?: [];
            } catch (\Throwable $e) {
                foreach ($mapped as $mk) {
                    $plat = array_search($mk, $map, true) ?: $mk;
                    PublishRecord::create([
                        'tenant_id'    => $tenant->id,
                        'video_job_id' => $job->id,
                        'platform'     => $plat,
                        'status'       => 'failed',
                        'error'        => '出片服务不可达：' . $e->getMessage(),
                        'published_at' => now(),
                    ]);
                    $failCount++;
                }
                continue;
            }

            foreach ($results as $res) {
                $plat = array_search($res['platform'] ?? '', $map, true) ?: ($res['platform'] ?? '');
                $isOk = ($res['status'] ?? 'failed') === 'published';
                PublishRecord::create([
                    'tenant_id'    => $tenant->id,
                    'video_job_id' => $job->id,
                    'platform'     => $plat,
                    'status'       => $isOk ? 'success' : 'failed',
                    'external_id'  => ($res['url'] ?? '') ?: ($res['post_id'] ?? ''),
                    'error'        => $res['error'] ?? null,
                    'published_at' => now(),
                ]);
                if ($isOk) {
                    $okCount++;
                } else {
                    $failCount++;
                }
            }

            // 只要有一个平台成功即标记视频已外发
            $jobDone = collect($results)->contains(fn($r) => ($r['status'] ?? '') === 'published');
            if ($jobDone) {
                $job->update(['publish_status' => 'published']);
            }
        }

        $msg = "已提交真实发布：成功 {$okCount} 条";
        if ($failCount > 0) {
            $msg .= "，失败 {$failCount} 条（未授权或平台返回错误，详情见下方发布历史）";
        }

        return redirect()->route('studio.publish')->with('success', $msg);
    }
}
