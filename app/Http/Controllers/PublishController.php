<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\VideoJob;
use App\Services\PublishRunner;
use Illuminate\Http\Request;

/**
 * 发布助手：一键发布 + 导出素材。
 *
 * 一键发布按平台特点分级（诚实透明）：
 *  - 自动：抖音（OAuth）、小红书（OAuth 笔记）—— 直接分发；
 *  - 人工：视频号 —— 无稳定公开 API，存入「待人工发布」清单，
 *          成片仍可下载，到各平台 App 手动发表。
 */
class PublishController extends Controller
{
    /** 发布助手：已完成成片 + 可发布账号 + 各视频发布记录。 */
    public function index()
    {
        $tenant = $this->studioTenant(request());

        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->get();

        // 可发布账号：自动平台需已授权，人工平台恒可
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->orderBy('platform')
            ->get()
            ->filter(fn ($a) => $a->isPublishable());

        // 账号统计（页面状态提示用）：总账号数 / 已授权数
        $accountCount = PlatformAccount::where('tenant_id', $tenant->id)->count();
        $authorizedCount = PlatformAccount::where('tenant_id', $tenant->id)
            ->where('status', 'authorized')->count();

        // 各视频的发布记录（按 video_job_id 分组，供前端展示已发/待人工）
        $publishRecords = PublishRecord::where('tenant_id', $tenant->id)
            ->whereIn('video_job_id', $videos->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('video_job_id');

        return view('studio.publish', compact('videos', 'accounts', 'publishRecords', 'accountCount', 'authorizedCount'));
    }

    /** 一键发布：视频 × 账号 → PublishRunner → 自动/待人工/失败。 */
    public function publish(Request $request, VideoJob $videoJob)
    {
        $tenant = $this->studioTenant($request);
        $this->assertTenantOwner($request, $videoJob->tenant_id);

        // 审核门槛：只有「已通过审核」的成片可外发
        if (! $videoJob->canPublish()) {
            return redirect()->route('studio.publish')
                ->with('error', '该视频尚未审核通过，请先到「人工审核」通过后再发布。');
        }

        $account = PlatformAccount::where('tenant_id', $tenant->id)
            ->where('id', $request->input('platform_account_id'))
            ->first();

        if (! $account) {
            return redirect()->route('studio.publish')->with('error', '请选择要发布的账号。');
        }
        if (! $account->isPublishable()) {
            return redirect()->route('studio.publish')
                ->with('error', '该账号尚未授权，请先在「发布渠道」完成授权。');
        }

        $r = app(PublishRunner::class)->run($videoJob, $account, $tenant);

        if (! empty($r['ok'])) {
            $extra = ! empty($r['simulated']) ? '（模拟发布，未真正发出）' : '';
            return redirect()->route('studio.publish')
                ->with('success', "已发布到 {$account->platformLabel()} · {$account->account_name}{$extra}");
        }
        if (! empty($r['manual'])) {
            return redirect()->route('studio.publish')
                ->with('info', "{$account->platformLabel()} 无自动发布接口，已存入「待人工发布」清单，请下载成片后到 App 手动发表。");
        }
        return redirect()->route('studio.publish')
            ->with('error', '发布失败：' . ($r['reason'] ?? '未知错误'));
    }
}
