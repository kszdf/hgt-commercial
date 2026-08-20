<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\VideoJob;
use App\Services\PublishRunner;
use Illuminate\Http\Request;

/**
 * 批量外发（多账号矩阵版）：审核通过(publish_status=approved)的视频，按「视频 × 账号」矩阵分发。
 * 单条发布的真实逻辑在 App\Services\PublishRunner（与发布排期自动发布共用）。
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

        $isSuperAdmin = $request->user()?->isGlobalAdmin() ?? false;
        $isTrial = ! $isSuperAdmin && ! $tenant->allow_batch;

        return view('studio.publish', compact(
            'videos', 'accounts', 'authorizedIds', 'manualPlatforms', 'records', 'isTrial'
        ));
    }

    /** 批量发布（视频 × 账号 矩阵分发，核心逻辑见 PublishRunner）。 */
    public function publish(Request $request)
    {
        $tenant = $this->studioTenant(request());
        $isSuperAdmin = $request->user()?->isGlobalAdmin() ?? false;

        // —— 未授权批量外发（免费套餐默认即如此）；超管绕过该限制 ——
        if (! $isSuperAdmin && ! $tenant->allow_batch) {
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

        $runner = app(PublishRunner::class);
        $real = 0;
        $simulated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($jobs as $job) {
            foreach ($accounts as $account) {
                $r = $runner->run($job, $account, $tenant);
                if ($r['ok']) {
                    $r['simulated'] ? $simulated++ : $real++;
                } elseif (str_contains((string) $r['reason'], '已达上限')) {
                    $skipped++;
                } else {
                    $failed++;
                }
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
