<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use App\Models\PublishSchedule;
use App\Models\VideoJob;
use App\Services\PublishRunner;
use Illuminate\Http\Request;

/**
 * 内容日历 / 发布排期（功能包二）。
 *
 * 一条排期 = 视频 × 账号 × 时间点：
 *   - auto_publish=true：到点由 schedules:dispatch 自动发布（走 PublishRunner）；
 *   - auto_publish=false：到点仅提醒（status=pending→due，日历页高亮今日待发）。
 */
class ScheduleController extends Controller
{
    /** 日历 + 排期列表 + 新建表单。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);

        $month = $request->input('month'); // YYYY-MM，缺省当月
        $ref = $month && preg_match('/^\d{4}-\d{2}$/', $month)
            ? \Carbon\Carbon::parse($month . '-01')
            : now()->startOfMonth();
        $start = $ref->copy()->startOfMonth();
        $end = $ref->copy()->endOfMonth();

        $schedules = PublishSchedule::where('tenant_id', $tenant->id)
            ->whereBetween('schedule_at', [$start, $end])
            ->with('videoJob', 'account')
            ->orderBy('schedule_at')
            ->get();

        // 日历网格：本月每天的排期计数
        $byDay = $schedules->groupBy(fn ($s) => $s->schedule_at->format('Y-m-d'))
            ->map(fn ($items) => $items->count());

        // 今日待发（pending/due 且在今天及以前）
        $todayDue = PublishSchedule::where('tenant_id', $tenant->id)
            ->whereIn('status', [PublishSchedule::STATUS_PENDING, PublishSchedule::STATUS_DUE])
            ->where('schedule_at', '<=', now()->endOfDay())
            ->with('videoJob', 'account')
            ->orderBy('schedule_at')
            ->limit(20)
            ->get();

        // 新建表单数据
        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('publish_status', 'approved')
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'job_id', 'mode']);
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->orderBy('platform')
            ->get();

        return view('studio.schedule', compact('ref', 'schedules', 'byDay', 'todayDue', 'videos', 'accounts'));
    }

    /** 新建排期。 */
    public function store(Request $request)
    {
        $tenant = $this->studioTenant($request);

        $data = $request->validate([
            'video_job_id' => ['required', 'integer', 'exists:video_jobs,id'],
            'platform_account_id' => ['nullable', 'integer', 'exists:platform_accounts,id'],
            'schedule_date' => ['required', 'date'],
            'schedule_time' => ['required', 'date_format:H:i'],
            'auto_publish' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        PublishSchedule::create([
            'tenant_id' => $tenant->id,
            'video_job_id' => $data['video_job_id'],
            'platform_account_id' => $data['platform_account_id'] ?? null,
            'schedule_at' => $data['schedule_date'] . ' ' . $data['schedule_time'] . ':00',
            'status' => PublishSchedule::STATUS_PENDING,
            'auto_publish' => (bool) ($data['auto_publish'] ?? false),
            'note' => $data['note'] ?? null,
        ]);

        return redirect()->route('studio.schedule')->with('success', '排期已创建。');
    }

    /** 切换是否自动发布。 */
    public function toggleAuto(Request $request, PublishSchedule $schedule)
    {
        $this->assertTenantOwner($request, $schedule->tenant_id);
        $schedule->update(['auto_publish' => false]);
        return redirect()->route('studio.schedule')->with('info', '自动发布已停用，排期仅作提醒，请手动发布。');
    }

    /** 立即执行某条排期（手动）。 */
    public function runNow(Request $request, PublishSchedule $schedule)
    {
        $tenant = $this->studioTenant($request);
        $this->assertTenantOwner($request, $schedule->tenant_id);

        if (! $schedule->isRunnable()) {
            return redirect()->route('studio.schedule')->with('error', '该排期已终态（' . $schedule->statusLabel() . '），不能重复执行。');
        }

        // 自动发布已停用（2026-08-20）：排期仅作发布提醒，执行请到「发布助手」下载成片后手动发布。
        return redirect()->route('studio.schedule')->with('info', '自动发布已停用，请到「发布助手」下载成片，在各平台 App 手动发布。');
    }

    public function destroy(Request $request, PublishSchedule $schedule)
    {
        $this->assertTenantOwner($request, $schedule->tenant_id);
        $schedule->delete();
        return redirect()->route('studio.schedule')->with('success', '排期已删除。');
    }
}
