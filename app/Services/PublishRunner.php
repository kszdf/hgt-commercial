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
        if (! $account->isPublishable()) {
            return $this->fail($job, $account, $tenant, '该账号尚未授权，请先在「平台账号」中授权');
        }
        if (! $account->canPublishToday()) {
            return $this->fail($job, $account, $tenant, '该账号今日发布已达上限（' . (int) $account->daily_limit . ' 条），请明日再发或调整每日上限');
        }

        // —— 调 8500 账号级发布 ——
        try {
            $payload = [
                'job_id' => $job->job_id,
                'platforms' => [$account->platform],
                'account_key' => $account->platform . ':' . $account->id,
                'title' => $title ?: ($job->title ?: '短视频'),
            ];
            // 2026-08-31 封面闭环：用户所选封面(cover_asset_id)随发布传给 8500，用于平台封面指定
            if ($job->cover_asset_id) {
                $cover = \App\Models\CoverAsset::find($job->cover_asset_id);
                if ($cover) {
                    $payload['cover_path'] = $cover->path();
                }
            }
            // 公众号（client_credential）：解密 account_info 经 extra 传给 8500，明文不出 Laravel 容器
            $payload = array_merge($payload, $this->credentialsExtra($account));
            $resp = app(PipelineClient::class)->postJson('/publish', $payload, 180);
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
        $manual = $platStatus === 'manual_required'; // 无 API 平台：待人工发布
        // 模拟发布（无凭证 dry 降级）视为成功态：UI 展示"模拟发布成功"而非"失败"，
        // 避免误导用户以为发布出错；真实发布时 status=published 且带 post_id/url。
        $ok = $platStatus === 'published' || $simulated;

        $record = PublishRecord::create([
            'tenant_id' => $tenant->id,
            'video_job_id' => $job->id,
            'platform' => $account->platform,
            'platform_account_id' => $account->id,
            'account_name_snapshot' => $account->account_name,
            'status' => $ok ? 'success' : ($manual ? 'manual' : 'failed'),
            'simulated' => $simulated,
            'platform_status' => $platStatus,
            'external_id' => ($result['post_id'] ?? '') ?: ($result['url'] ?? ''),
            'post_url' => $result['url'] ?? null,
            'error' => $manual ? ($result['error'] ?? '待人工发布') : ($result['error'] ?? null),
            'published_at' => now(),
        ]);

        if ($ok && ! $simulated) {
            $account->markPublished(); // 仅真实成功计数（模拟发布不计入每日上限）
            // 2026-08-31 修复矩阵发布：不再覆盖 publish_status（保持 approved），
            // "已发布"语义由 PublishRecord 承载；否则 canPublish() 只认 approved，
            // 同一成片发布到账号 A 后无法再发到账号 B（矩阵发布被锁死）。
            // 发布历史/时间可通过 PublishRecord 查询。
        }

        return [
            'ok' => $ok,
            'manual' => $manual,
            'simulated' => $simulated,
            'record' => $record,
            'reason' => $simulated ? ($result['error'] ?? '模拟发布（未真正发出）')
                : ($ok ? null : ($manual ? ($result['error'] ?? '待人工发布') : ($result['error'] ?? '平台返回失败'))),
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

        return ['ok' => false, 'manual' => false, 'simulated' => false, 'record' => $record, 'reason' => $reason];
    }

    /**
     * 公众号（wechat）账号级凭证：解密 account_info 转 8500 的 extra（appid/appsecret）。
     * 其余平台（OAuth/手动）不传，避免明文在内部链路无谓扩散。
     * public：供 PublishController 的公众号文章发布复用。
     */
    public function credentialsExtra(PlatformAccount $account): array
    {
        if ($account->platform !== 'wechat') {
            return [];
        }
        $info = $account->account_info ?: [];
        $appid = $info['appid'] ?? $info['app_id'] ?? '';
        $secret = $info['secret'] ?? $info['app_secret'] ?? '';
        if ($appid === '' || $secret === '') {
            return [];
        }
        return ['extra' => ['appid' => $appid, 'appsecret' => $secret]];
    }
}
