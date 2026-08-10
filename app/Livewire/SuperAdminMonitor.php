<?php

namespace App\Livewire;

use App\Models\Tenant;
use App\Models\UserActivity;
use App\Models\VideoJob;
use Illuminate\Support\Facades\Request;
use Livewire\Component;

/**
 * 超级管理员实时监控大盘。
 *
 * - 仅超级管理员（tenant_id 为 null）可访问，mount 内强制 abort(403)。
 * - 每 10s 自动拉取一次（wire:poll），按租户聚合在线用户 / 选题中 / 二创中 / 出片中。
 * - 数据来源：users.last_seen_at（在线态）、user_activities（当前环节）、video_jobs（出片进度）。
 */
class SuperAdminMonitor extends Component
{
    /** @var array<int, mixed> */
    public array $tenants = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    public string $updatedAt = '';

    /** 在线判定窗口（分钟）。 */
    public int $onlineWindow = 5;

    public function mount()
    {
        abort_unless(
            request()->user() && request()->user()->isGlobalAdmin(),
            403,
            '仅超级管理员可访问实时监控大盘'
        );
        $this->loadData();
    }

    /**
     * 聚合所有租户的即时运行状态。仅列出「有信号」的租户（在线 / 在选题 / 在二创 / 在出片），
     * 并按在线人数降序排列，满足「最近若干租户的即时运行状态」诉求。
     */
    public function loadData()
    {
        $since = now()->subMinutes($this->onlineWindow);

        $all = Tenant::orderBy('name')->get();
        $rows = [];
        $totOnline = 0;
        $totTopic = 0;
        $totRewrite = 0;
        $totVideo = 0;

        foreach ($all as $tenant) {
            $online = $tenant->users()
                ->where('last_seen_at', '>=', $since)
                ->orderByDesc('last_seen_at')
                ->get(['id', 'name', 'last_seen_at']);

            $topic = UserActivity::where('tenant_id', $tenant->id)
                ->where('action', 'topic')
                ->where('last_seen_at', '>=', $since)
                ->with('user:id,name')
                ->get();

            $rewrite = UserActivity::where('tenant_id', $tenant->id)
                ->where('action', 'rewrite')
                ->where('last_seen_at', '>=', $since)
                ->with('user:id,name')
                ->get();

            $videos = VideoJob::where('tenant_id', $tenant->id)
                ->where('status', 'queued')
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'user_id', 'mode', 'created_at']);

            $hasSignal = $online->isNotEmpty()
                || $topic->isNotEmpty()
                || $rewrite->isNotEmpty()
                || $videos->isNotEmpty();
            if (! $hasSignal) {
                continue;
            }

            $totOnline += $online->count();
            $totTopic += $topic->count();
            $totRewrite += $rewrite->count();
            $totVideo += $videos->count();

            $rows[] = [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'plan' => $tenant->planLabel(),
                'online' => $online->map(fn ($u) => [
                    'name' => $u->name,
                    'ago' => $u->last_seen_at ? $u->last_seen_at->diffForHumans() : '',
                ])->all(),
                'topic' => $topic->map(fn ($a) => $a->user?->name ?? '—')->all(),
                'rewrite' => $rewrite->map(fn ($a) => $a->user?->name ?? '—')->all(),
                'videos' => $videos->map(function ($v) {
                    return [
                        'title' => $v->title ?: '未命名视频',
                        'user' => $v->user?->name ?? '—',
                        'mode' => $v->mode === 'avatar' ? '数字人出镜' : '字幕卡',
                        'elapsed' => $v->created_at ? $v->created_at->diffForHumans() : '',
                    ];
                })->all(),
            ];
        }

        // 在线人数多的租户排前
        usort($rows, fn ($a, $b) => count($b['online']) <=> count($a['online']));

        // 全局出片状态汇总（最近 24h）
        $since24 = now()->subHours(24);
        $statusDist = VideoJob::where('created_at', '>=', $since24)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->all();

        $this->tenants = $rows;
        $this->summary = [
            'tenants' => count($rows),
            'online' => $totOnline,
            'topic' => $totTopic,
            'rewrite' => $totRewrite,
            'video' => $totVideo,
            'queued' => (int) ($statusDist['queued'] ?? 0),
            'done' => (int) ($statusDist['done'] ?? 0),
            'failed' => (int) ($statusDist['failed'] ?? 0),
        ];
        $this->updatedAt = now()->format('H:i:s');
    }

    public function render()
    {
        return view('livewire.super-admin-monitor');
    }
}
