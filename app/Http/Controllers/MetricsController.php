<?php

namespace App\Http\Controllers;

use App\Models\MetricDaily;
use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Services\PipelineClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 数据效果页（数据回流 · 半自动）。
 *
 * 原则：数据可信度优先——「未同步」显式标注，绝不回填假数据。
 *  - 手动速填：发布建档 → 用户填 播放/点赞/评论/转发/收藏/留资 6 个数（data_source=manual）；
 *  - 自动同步：抖音经 8500 /metrics/fetch（data.external.item），未授权返回显式错误（data_source=auto 仅真数据）。
 */
class MetricsController extends Controller
{
    /** 效果看板 + 速填 + 待回填清单。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $tid = $tenant->id;

        // —— 汇总 ——
        $totals = DB::table('metrics_daily')
            ->where('tenant_id', $tid)
            ->selectRaw('COALESCE(SUM(views),0) views, COALESCE(SUM(shares+comments+likes+favorites),0) interactions, COALESCE(SUM(leads),0) leads, COUNT(DISTINCT metric_date) days')
            ->first();
        $totals->videos = DB::table('metrics_daily')->where('tenant_id', $tid)->distinct('video_job_id')->count('video_job_id');

        // 平台分布（按播放）
        $byPlatform = DB::table('metrics_daily')
            ->where('tenant_id', $tid)
            ->selectRaw('platform, SUM(views) views')
            ->groupBy('platform')->orderByDesc('views')->get();

        // 近 30 天播放趋势
        $trend = DB::table('metrics_daily')
            ->where('tenant_id', $tid)
            ->where('metric_date', '>=', now()->subDays(30)->toDateString())
            ->selectRaw('metric_date, SUM(views) views')
            ->groupBy('metric_date')->orderBy('metric_date')->get();

        // Top 出片（按播放，带互动）
        $topVideos = DB::table('metrics_daily as m')
            ->where('m.tenant_id', $tid)
            ->selectRaw('m.video_job_id, SUM(m.views) views, SUM(m.shares+m.comments+m.likes+m.favorites) interactions')
            ->groupBy('m.video_job_id')->orderByDesc('views')->limit(10)->get()
            ->map(fn ($r) => (object) [
                'video_job_id' => $r->video_job_id,
                'views' => (int) $r->views,
                'interactions' => (int) $r->interactions,
                'videoJob' => $r->video_job_id ? \App\Models\VideoJob::find($r->video_job_id) : null,
            ]);

        // 待回填：已发布（success）但近 30 天没有任何指标记录的视频×账号
        $unSynced = PublishRecord::where('tenant_id', $tid)
            ->where('status', 'success')
            ->where('created_at', '>=', now()->subDays(30))
            ->has('videoJob')
            ->with('videoJob', 'account')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->filter(fn ($rec) => ! MetricDaily::where('tenant_id', $tid)
                ->where('video_job_id', $rec->video_job_id)
                ->where('platform', $rec->platform)
                ->where('platform_account_id', $rec->platform_account_id)
                ->exists());

        // 速填下拉数据
        $videos = \App\Models\VideoJob::where('tenant_id', $tid)
            ->whereIn('id', PublishRecord::where('tenant_id', $tid)->where('status', 'success')->pluck('video_job_id'))
            ->orderByDesc('created_at')->get(['id', 'title', 'job_id']);
        $accounts = PlatformAccount::where('tenant_id', $tid)->orderBy('platform')->get();

        // 最近明细
        $recentMetrics = MetricDaily::where('tenant_id', $tid)
            ->with('videoJob', 'account')->orderByDesc('metric_date')->orderByDesc('id')->limit(30)->get();

        return view('studio.metrics', compact(
            'totals', 'byPlatform', 'trend', 'topVideos', 'unSynced', 'videos', 'accounts', 'recentMetrics'
        ));
    }

    /** 手动速填（半自动录入，upsert）。 */
    public function record(Request $request)
    {
        $tenant = $this->studioTenant($request);

        $data = $request->validate([
            'video_job_id' => ['required', 'integer', 'exists:video_jobs,id'],
            'platform_account_id' => ['nullable', 'integer', 'exists:platform_accounts,id'],
            'platform' => ['nullable', 'string', 'max:20'],
            'metric_date' => ['required', 'date'],
            'views' => ['nullable', 'integer', 'min:0'],
            'likes' => ['nullable', 'integer', 'min:0'],
            'comments' => ['nullable', 'integer', 'min:0'],
            'shares' => ['nullable', 'integer', 'min:0'],
            'favorites' => ['nullable', 'integer', 'min:0'],
            'leads' => ['nullable', 'integer', 'min:0'],
        ]);

        // 平台取账号维度；未选账号时取显式 platform 或回退 manual
        $account = $data['platform_account_id'] ?? null;
        $platform = $account
            ? (PlatformAccount::find($account)?->platform ?? ($data['platform'] ?? 'manual'))
            : ($data['platform'] ?? 'manual');

        MetricDaily::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'video_job_id' => $data['video_job_id'],
                'platform' => $platform,
                'platform_account_id' => $account,
                'metric_date' => $data['metric_date'],
            ],
            [
                'views' => (int) ($data['views'] ?? 0),
                'likes' => (int) ($data['likes'] ?? 0),
                'comments' => (int) ($data['comments'] ?? 0),
                'shares' => (int) ($data['shares'] ?? 0),
                'favorites' => (int) ($data['favorites'] ?? 0),
                'leads' => (int) ($data['leads'] ?? 0),
                'data_source' => 'manual',
                'synced_at' => now(),
            ]
        );

        return redirect()->route('studio.metrics')->with('success', '数据已保存。');
    }

    /**
     * 手动触发同步（当前支持抖音）。
     * 调 8500 /metrics/fetch：未授权 / 无数据时返回显式错误，绝不写假数据。
     */
    public function sync(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $tid = $tenant->id;

        $accounts = PlatformAccount::where('tenant_id', $tid)
            ->where('platform', 'douyin')->where('status', 'authorized')->get();
        if ($accounts->isEmpty()) {
            return redirect()->route('studio.metrics')
                ->with('error', '暂无可同步的已授权抖音账号，请先在「平台账号」中添加并授权。');
        }

        $records = PublishRecord::where('tenant_id', $tid)
            ->where('platform', 'douyin')
            ->where('status', 'success')
            ->whereNotNull('external_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->get();

        if ($records->isEmpty()) {
            return redirect()->route('studio.metrics')
                ->with('info', '没有近 30 天已发布的抖音视频可供同步。');
        }

        $items = $records->map(fn ($r) => [
            'account_key' => 'douyin:' . ($r->platform_account_id ?? ''),
            'external_id' => $r->external_id,
            'video_job_id' => $r->video_job_id,
        ])->values()->all();

        try {
            $resp = app(PipelineClient::class)->postJson('/metrics/fetch', [
                'tenant_id' => (string) $tid,
                'items' => $items,
            ], 120);
        } catch (\App\Exceptions\PipelineUnavailableException $e) {
            return redirect()->route('studio.metrics')->with('error', '同步服务暂不可用（8500 未启动或未升级到功能包一）。');
        }

        if (! $resp->successful()) {
            return redirect()->route('studio.metrics')->with('error', '同步失败：' . substr((string) $resp->body(), 0, 200));
        }
        $r = $resp->json();

        if (($r['ok'] ?? false) === false) {
            return redirect()->route('studio.metrics')->with('error', $r['error'] ?? '同步失败（平台未授权或接口报错）');
        }

        $count = 0;
        foreach (($r['results'] ?? []) as $row) {
            if (empty($row['external_id']) || empty($row['video_job_id'])) {
                continue;
            }
            MetricDaily::updateOrCreate(
                [
                    'tenant_id' => $tid,
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
            $count++;
        }

        return redirect()->route('studio.metrics')
            ->with('success', "同步完成：已写入 {$count} 条播放互动数据（自动来源）。");
    }
}
