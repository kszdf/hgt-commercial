<?php

namespace App\Console\Commands;

use App\Models\MetricDaily;
use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\Tenant;
use App\Services\PipelineClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 自动同步已发布视频的播放互动数据（当前仅抖音，经 8500 /metrics/fetch）。
 *
 * 契约：POST 8500 /metrics/fetch {tenant_id, items:[{account_key, external_id, video_job_id}]}
 *   → {ok:true, results:[{external_id, video_job_id, platform_account_id, metric_date,
 *       views, likes, comments, shares, favorites, leads}]}
 *   未授权/无凭证时返回 {ok:false, error:"..."}，绝不写假数据。
 *
 * 建议调度：每 6 小时一次（routes/console.php 已注册）。
 */
class SyncMetrics extends Command
{
    protected $signature = 'metrics:sync {--days=30 : 仅同步近 N 天发布的视频}';

    protected $description = '自动同步已发布视频的播放互动数据（抖音，经 8500 /metrics/fetch）';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $total = 0;
        $tenants = 0;

        foreach (Tenant::where('status', 'active')->get() as $tenant) {
            $accounts = PlatformAccount::where('tenant_id', $tenant->id)
                ->where('platform', 'douyin')
                ->where('status', 'authorized')
                ->get();
            if ($accounts->isEmpty()) {
                continue;
            }

            $records = PublishRecord::where('tenant_id', $tenant->id)
                ->where('platform', 'douyin')
                ->where('status', 'success')
                ->whereNotNull('external_id')
                ->where('created_at', '>=', now()->subDays($days))
                ->get();
            if ($records->isEmpty()) {
                continue;
            }

            $items = $records->map(fn ($r) => [
                'account_key' => 'douyin:' . ($r->platform_account_id ?? ''),
                'external_id' => $r->external_id,
                'video_job_id' => $r->video_job_id,
            ])->values()->all();

            try {
                $resp = app(PipelineClient::class)->postJson('/metrics/fetch', [
                    'tenant_id' => (string) $tenant->id,
                    'items' => $items,
                ], 120);
            } catch (\App\Exceptions\PipelineUnavailableException $e) {
                Log::warning('metrics:sync 8500 unavailable', ['tenant' => $tenant->id, 'msg' => $e->getMessage()]);
                continue;
            }

            if (! $resp->successful() || (($resp->json('ok') ?? false) === false)) {
                Log::warning('metrics:sync remote refused', [
                    'tenant' => $tenant->id,
                    'body' => substr((string) $resp->body(), 0, 300),
                ]);
                continue;
            }

            foreach (($resp->json('results') ?? []) as $row) {
                if (empty($row['external_id']) || empty($row['video_job_id'])) {
                    continue;
                }
                MetricDaily::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'video_job_id' => (int) $row['video_job_id'],
                        'platform' => 'douyin',
                        'platform_account_id' => isset($row['platform_account_id']) ? (int) $row['platform_account_id'] : null,
                        'metric_date' => $row['metric_date'] ?? now()->toDateString(),
                    ],
                    [
                        'views' => (int) ($row['views'] ?? 0),
                        'likes' => (int) ($row['likes'] ?? 0),
                        'comments' => (int) ($row['comments'] ?? 0),
                        'shares' => (int) ($row['shares'] ?? 0),
                        'favorites' => (int) ($row['favorites'] ?? 0),
                        'leads' => (int) ($row['leads'] ?? 0),
                        'data_source' => 'auto',
                        'synced_at' => now(),
                    ]
                );
                $total++;
            }
            $tenants++;
        }

        $this->info("metrics:sync done — tenants={$tenants}, rows={$total}");
        return self::SUCCESS;
    }
}
