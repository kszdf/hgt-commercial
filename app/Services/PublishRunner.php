<?php

namespace App\Services;

use App\Exceptions\PipelineUnavailableException;
use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\VideoJob;
use App\Models\Tenant;

/**
 * 发布执行器（功能包二抽取）：单条「视频 × 账号」发布的核心逻辑。
 *
 * 被两处复用：
 *  1) PublishController::publish —— 手动矩阵发布；
 *  2) schedules:dispatch —— 排期到点自动发布。
 *
 * 契约（与 8500 /publish 一致）：
 *   POST /publish {job_id, platforms:[platform], account_key:"<platform>:<accountId>", title}
 *   → results[{platform, status, post_id, url, error, simulated}]
 * 双保险判模拟：显式 simulated 标记，或「status=published 但无 post_id/url」。
 */
class PublishRunner
{
    /**
     * 发布单条视频到单个账号，落 PublishRecord。
     *
     * @return array{ok: bool, simulated: bool, record: PublishRecord, reason: ?string}
     */
    public function run(VideoJob $job, PlatformAccount $account, Tenant $tenant, ?string $title = null): array
    {
        // —— 前置：缺 job_id / 未授权 / 每日上限 ——
        if (empty($job->job_id)) {
            return $this->fail($job, $account, $tenant, '缺少 8500 job_id，无法发布');
        }
        if (! $account->isAuthorized()) {
            return $this->fail($job, $account, $tenant, '该账号尚未授权，请先在「平台账号」中授权');
        }
        if (! $account->canPublishToday()) {
            return $this->fail($job, $account, $tenant, '该账号今日发布已达上限（' . (int) $account->daily_limit . ' 条），请明日再发或调整每日上限');
        }

        // —— 调 8500 账号级发布 ——
        try {
            $resp = app(PipelineClient::class)->postJson('/publish', [
                'job_id' => $job->job_id,
                'platforms' => [$account->platform],
                'account_key' => $account->platform . ':' . $account->id,
                'title' => $title ?: ($job->title ?: '短视频'),
            ], 180);
        } catch (PipelineUnavailableException $e) {
            return $this->fail($job, $account, $tenant, '出片服务不可达：' . $e->getMessage());
        }

        if ($resp->failed()) {
            return $this->fail($job, $account, $tenant, '出片服务返回错误：' . substr((string) $resp->body(), 0, 300));
        }

        $results = $resp->json('results') ?: [];
        $result = collect($results)->firstWhere('platform', $account->platform) ?: ($results[0] ?? []);
        $result = $result ?: [];

        $platStatus = $result['status'] ?? 'failed';
        // 双保险判模拟
        $simulated = ! empty($result['simulated'])
            || ($platStatus === 'published' && empty($result['post_id']) && empty($result['url']));
        $ok = $platStatus === 'published';

        $record = PublishRecord::create([
            'tenant_id' => $tenant->id,
            'video_job_id' => $job->id,
            'platform' => $account->platform,
            'platform_account_id' => $account->id,
            'account_name_snapshot' => $account->account_name,
            'status' => $ok ? 'success' : 'failed',
            'simulated' => $simulated,
            'platform_status' => $platStatus,
            'external_id' => ($result['post_id'] ?? '') ?: ($result['url'] ?? ''),
            'post_url' => $result['url'] ?? null,
            'error' => $result['error'] ?? null,
            'published_at' => now(),
        ]);

        if ($ok) {
            $account->markPublished(); // 仅真实成功计数（模拟不计）
            $job->update(['publish_status' => 'published']);
        }

        return [
            'ok' => $ok,
            'simulated' => $simulated,
            'record' => $record,
            'reason' => $ok ? null : ($result['error'] ?? '平台返回失败'),
        ];
    }

    private function fail(VideoJob $job, PlatformAccount $account, Tenant $tenant, string $reason): array
    {
        $record = PublishRecord::create([
            'tenant_id' => $tenant->id,
            'video_job_id' => $job->id,
            'platform' => $account->platform,
            'platform_account_id' => $account->id,
            'account_name_snapshot' => $account->account_name,
            'status' => 'failed',
            'error' => $reason,
            'published_at' => now(),
        ]);

        return ['ok' => false, 'simulated' => false, 'record' => $record, 'reason' => $reason];
    }
}
