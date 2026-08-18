<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\VideoJob;
use App\Services\PipelineClient;
use Illuminate\Http\Request;

/**
 * 批量外发（多账号矩阵版）：审核通过(publish_status=approved)的视频，按「视频 × 账号」矩阵分发。
 *
 * 与 8500 /publish 的契约（功能包一）：
 *   POST /publish {job_id, platforms:[platform], account_key:"<platform>:<accountId>", title}
 *   → {job_id, results:[{platform, status, post_id, url, error, simulated}]}
 *   - simulated=true 表示 dry 模拟（该账号未配置平台凭证），非真实发出；
 *   - 本控制器双保险判模拟：显式 simulated 标记，或「status=published 但无 post_id/url」。
 *
 * 每日限额：每账号 daily_limit 条/天（跨天自动归零，见 PlatformAccount::usedToday）。
 */
class PublishController extends Controller
{
    /** 发布工作台：待发视频 + 已授权账号（矩阵勾选）+ 发布历史。 */
    public function index()
    {
        $tenant = $this->studioTenant(request());

        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('publish_status', 'approved')
            ->orderByDesc('updated_at')
            ->get();

        // 本租户全部账号（含未授权，供「去授权」）
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->orderBy('platform')->orderByDesc('created_at')
            ->get();

        // 已授权账号（矩阵勾选默认选中）
        $authorizedIds = $accounts->where('status', 'authorized')->pluck('id');

        // 手动发布渠道（无开放接口，需在对应 App 手动发布）
        $manualPlatforms = [
            'wechat' => '视频号',
        ];

        $records = PublishRecord::where('tenant_id', $tenant->id)
            ->with('videoJob', 'account')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $isTrial = ! $tenant->allow_batch;

        return view('studio.publish', compact(
            'videos', 'accounts', 'authorizedIds', 'manualPlatforms', 'records', 'isTrial'
        ));
    }

    /** 批量发布（视频 × 账号 矩阵分发）。 */
    public function publish(Request $request)
    {
        $tenant = $this->studioTenant(request());

        // —— 未授权批量外发（免费套餐默认即如此）——
        if (! $tenant->allow_batch) {
            return redirect()->route('studio.publish')
                ->with('error', '当前账号暂未开放批量外发权限，请联系管理员开通或升级套餐。');
        }

        $data = $request->validate([
            'video_ids' => ['required', 'array', 'min:1'],
            'video_ids.*' => ['integer'],
            'accounts' => ['required', 'array', 'min:1'],
            'accounts.*' => ['integer'],
        ]);

        $jobs = VideoJob::where('tenant_id', $tenant->id)
            ->whereIn('id', $data['video_ids'])
            ->where('publish_status', 'approved')
            ->where('status', 'done')
            ->get();
        if ($jobs->isEmpty()) {
            return redirect()->route('studio.publish')->with('error', '没有可发布的视频（需先通过人工审核且渲染完成）。');
        }

        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->whereIn('id', $data['accounts'])
            ->where('status', 'authorized')
            ->get();
        if ($accounts->isEmpty()) {
            return redirect()->route('studio.publish')->with('error', '所选账号均未授权或不存在，请先在「平台账号」中授权。');
        }

        $real = 0;   // 真实发布成功
        $simulated = 0; // 演示（模拟）发布
        $failed = 0;  // 失败（含限额/未授权/平台错误）
        $skipped = 0; // 跳过（账号今日已达上限）

        foreach ($jobs as $job) {
            if (empty($job->job_id)) {
                continue; // 缺少 8500 job_id，无法发布
            }

            foreach ($accounts as $account) {
                // —— 每日限额 ——
                if (! $account->canPublishToday()) {
                    PublishRecord::create([
                        'tenant_id' => $tenant->id,
                        'video_job_id' => $job->id,
                        'platform' => $account->platform,
                        'platform_account_id' => $account->id,
                        'account_name_snapshot' => $account->account_name,
                        'status' => 'failed',
                        'error' => '该账号今日发布已达上限（' . (int) $account->daily_limit . ' 条），请明日再发或调整每日上限。',
                        'published_at' => now(),
                    ]);
                    $skipped++;
                    continue;
                }

                // —— 调 8500 真实分发（账号级凭证，account_key 键控）——
                try {
                    $resp = app(PipelineClient::class)->postJson('/publish', [
                        'job_id' => $job->job_id,
                        'platforms' => [$account->platform],
                        'account_key' => $account->platform . ':' . $account->id,
                        'title' => $job->title ?: '短视频',
                    ], 180);
                } catch (PipelineUnavailableException $e) {
                    PublishRecord::create([
                        'tenant_id' => $tenant->id,
                        'video_job_id' => $job->id,
                        'platform' => $account->platform,
                        'platform_account_id' => $account->id,
                        'account_name_snapshot' => $account->account_name,
                        'status' => 'failed',
                        'error' => '出片服务不可达：' . $e->getMessage(),
                        'published_at' => now(),
                    ]);
                    $failed++;
                    continue;
                }

                if ($resp->failed()) {
                    PublishRecord::create([
                        'tenant_id' => $tenant->id,
                        'video_job_id' => $job->id,
                        'platform' => $account->platform,
                        'platform_account_id' => $account->id,
                        'account_name_snapshot' => $account->account_name,
                        'status' => 'failed',
                        'error' => '出片服务返回错误：' . substr((string) $resp->body(), 0, 300),
                        'published_at' => now(),
                    ]);
                    $failed++;
                    continue;
                }

                // 解析本账号对应的平台结果
                $results = $resp->json('results') ?: [];
                $result = collect($results)->firstWhere('platform', $account->platform) ?: ($results[0] ?? null);
                $result = $result ?: [];

                $platStatus = $result['status'] ?? 'failed';
                // 双保险判模拟：显式 simulated 标记，或「称已发布但无外链」
                $isSim = ! empty($result['simulated'])
                    || ($platStatus === 'published' && empty($result['post_id']) && empty($result['url']));
                $ok = $platStatus === 'published';

                PublishRecord::create([
                    'tenant_id' => $tenant->id,
                    'video_job_id' => $job->id,
                    'platform' => $account->platform,
                    'platform_account_id' => $account->id,
                    'account_name_snapshot' => $account->account_name,
                    'status' => $ok ? 'success' : 'failed',
                    'simulated' => $isSim,
                    'platform_status' => $platStatus,
                    'external_id' => ($result['post_id'] ?? '') ?: ($result['url'] ?? ''),
                    'post_url' => $result['url'] ?? null,
                    'error' => $result['error'] ?? null,
                    'published_at' => now(),
                ]);

                if ($ok) {
                    $account->markPublished(); // 仅真实成功计数
                    if ($isSim) {
                        $simulated++;
                    } else {
                        $real++;
                    }
                } else {
                    $failed++;
                }
            }

            // 只要有任一账号真实/模拟成功即标记视频已外发
            $jobDone = PublishRecord::where('video_job_id', $job->id)
                ->whereIn('status', ['success'])
                ->exists();
            if ($jobDone) {
                $job->update(['publish_status' => 'published']);
            }
        }

        $msg = "发布完成：真实成功 {$real} 条";
        if ($simulated > 0) {
            $msg .= "，演示(模拟) {$simulated} 条（未配置平台凭证，未实际发出，请到「平台账号」完成授权后重发）";
        }
        if ($failed > 0) {
            $msg .= "，失败 {$failed} 条";
        }
        if ($skipped > 0) {
            $msg .= "，{$skipped} 条因账号每日上限跳过";
        }

        return redirect()->route('studio.publish')->with('success', $msg);
    }
}
