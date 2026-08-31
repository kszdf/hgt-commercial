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
            'video_job_id' => ['required', 'integer'],
            'platform_account_id' => ['nullable', 'integer'],
            'schedule_date' => ['required', 'date'],
            'schedule_time' => ['required', 'date_format:H:i'],
            'auto_publish' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        // 安全门禁：视频必须属于当前租户且已审核通过（approved）才可排期外发；
        // 账号必须属于当前租户（防跨租户 IDOR 越权发布）。
        $video = VideoJob::where('id', $data['video_job_id'])
            ->where('tenant_id', $tenant->id)
            ->first();
        if (! $video) {
            return back()->withErrors(['video_job_id' => '视频不存在或不属于当前租户。'])->withInput();
        }
        if ($video->publish_status !== 'approved') {
            return back()->withErrors(['video_job_id' => '视频尚未通过人工审核，不能排期发布。请先在「审核」中通过后再排期。'])->withInput();
        }
        if (! empty($data['platform_account_id'])) {
            $account = PlatformAccount::where('id', $data['platform_account_id'])
                ->where('tenant_id', $tenant->id)
                ->first();
            if (! $account) {
                return back()->withErrors(['platform_account_id' => '发布账号不存在或不属于当前租户。'])->withInput();
            }
        }

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
        $next = ! $schedule->auto_publish;
        $schedule->update(['auto_publish' => $next]);
        return redirect()->route('studio.schedule')
            ->with('success', $next ? '已开启自动发布，到点自动分发到账号。' : '已取消自动发布，到点仅提醒。');
    }

    /** 立即执行某条排期（手动）。 */
    public function runNow(Request $request, PublishSchedule $schedule)
    {
        $tenant = $this->studioTenant($request);
        $this->assertTenantOwner($request, $schedule->tenant_id);

        if (! $schedule->isRunnable()) {
            return redirect()->route('studio.schedule')->with('error', '该排期已终态（' . $schedule->statusLabel() . '），不能重复执行。');
        }

        $job = $schedule->videoJob;
        $account = $schedule->account;

        if (! $job || $job->status !== 'done') {
            $schedule->update(['status' => PublishSchedule::STATUS_FAILED, 'error' => '视频未完成渲染或已删除']);
            return redirect()->route('studio.schedule')->with('error', '视频未完成渲染或已删除，无法发布。');
        }
        // 安全门禁：视频须已审核通过（approved），且视频/账号均须属于当前租户（防越权）
        if ($job->publish_status !== 'approved') {
            $schedule->update(['status' => PublishSchedule::STATUS_FAILED, 'error' => '视频未通过人工审核']);
            return redirect()->route('studio.schedule')->with('error', '视频尚未通过人工审核，不能发布。请先在「审核」中通过后再执行。');
        }
        if ($job->tenant_id != $tenant->id) {
            $schedule->update(['status' => PublishSchedule::STATUS_FAILED, 'error' => '视频不属于当前租户']);
            return redirect()->route('studio.schedule')->with('error', '视频不属于当前租户，无法发布。');
        }
        if (! $account) {
            $schedule->update(['status' => PublishSchedule::STATUS_FAILED, 'error' => '未指定发布账号']);
            return redirect()->route('studio.schedule')->with('error', '该排期未指定发布账号，请编辑或重新创建。');
        }
        if ($account->tenant_id != $tenant->id) {
            $schedule->update(['status' => PublishSchedule::STATUS_FAILED, 'error' => '账号不属于当前租户']);
            return redirect()->route('studio.schedule')->with('error', '发布账号不属于当前租户，无法发布。');
        }

        $schedule->update(['status' => PublishSchedule::STATUS_PUBLISHING]);
        $r = app(PublishRunner::class)->run($job, $account, $schedule->tenant);

        $schedule->update([
            'status' => $r['ok'] ? PublishSchedule::STATUS_PUBLISHED : PublishSchedule::STATUS_FAILED,
            'published_at' => $r['ok'] ? now() : null,
            'error' => $r['ok'] ? null : ($r['reason'] ?? '发布失败'),
        ]);

        if (! empty($r['ok'])) {
            return redirect()->route('studio.schedule')
                ->with('success', '发布成功' . (! empty($r['simulated']) ? '（模拟发布，未真正发出）' : '') . '。');
        }
        if (! empty($r['manual'])) {
            return redirect()->route('studio.schedule')
                ->with('info', '已存入「待人工发布」清单，请下载成片后到各平台 App 手动发表。');
        }
        return redirect()->route('studio.schedule')->with('error', '发布失败：' . ($r['reason'] ?? '未知错误'));
    }

    public function destroy(Request $request, PublishSchedule $schedule)
    {
        $this->assertTenantOwner($request, $schedule->tenant_id);
        $schedule->delete();
        return redirect()->route('studio.schedule')->with('success', '排期已删除。');
    }
}
