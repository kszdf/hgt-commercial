<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Models\VideoJob;
use Illuminate\Http\Request;

/**
 * 批量外发模块：审核通过(publish_status=approved)的视频，一键分发到多平台。
 *
 * 当前为「演示模式」——真实多平台分发需要各平台 OAuth 授权凭据
 * （platform_accounts），由后台任务对接官方开放平台 API 完成实际上传。
 * 演示模式下直接落 success 记录，便于打通「审核→外发→数据复盘」整条链路，
 * 页面顶部明确标注「需配置 OAuth 授权」。
 */
class PublishController extends Controller
{
    /** 发布工作台：待发视频 + 平台账号 + 发布历史。 */
    public function index()
    {
        $tenant = request()->user()->tenant;

        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('publish_status', 'approved')
            ->orderByDesc('updated_at')
            ->get();

        $accounts = PlatformAccount::where('tenant_id', $tenant->id)->get();

        // 平台清单（演示模式下即使未授权也可发布，仅作提示）
        $platforms = [
            'wechat'       => '视频号',
            'douyin'       => '抖音',
            'xiaohongshu'  => '小红书',
        ];

        $records = PublishRecord::where('tenant_id', $tenant->id)
            ->with('videoJob')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('studio.publish', compact('videos', 'accounts', 'platforms', 'records'));
    }

    /** 批量发布（演示桩）。 */
    public function publish(Request $request)
    {
        $tenant = request()->user()->tenant;

        $data = $request->validate([
            'video_ids'   => ['required', 'array', 'min:1'],
            'video_ids.*' => ['integer'],
            'platforms'   => ['required', 'array', 'min:1'],
            'platforms.*' => ['string', 'in:wechat,douyin,xiaohongshu'],
        ]);

        // 仅操作本租户、且已审核通过的视频（越权/非 approved 的自动忽略）
        $jobs = VideoJob::where('tenant_id', $tenant->id)
            ->whereIn('id', $data['video_ids'])
            ->where('publish_status', 'approved')
            ->get();

        if ($jobs->isEmpty()) {
            return redirect()->route('studio.publish')->with('error', '没有可发布的视频（需先通过人工审核）。');
        }

        $created = 0;
        foreach ($jobs as $job) {
            foreach ($data['platforms'] as $platform) {
                // 优先关联已授权账号（演示模式下可能没有）
                $account = PlatformAccount::where('tenant_id', $tenant->id)
                    ->where('platform', $platform)
                    ->where('status', 'authorized')
                    ->first();

                PublishRecord::create([
                    'tenant_id'           => $tenant->id,
                    'video_job_id'        => $job->id,
                    'platform'            => $platform,
                    'platform_account_id' => $account?->id,
                    'status'              => 'success', // 演示桩：直接成功
                    'published_at'        => now(),
                ]);
                $created++;
            }
            // 标记视频已外发
            $job->update(['publish_status' => 'published']);
        }

        return redirect()->route('studio.publish')
            ->with('success', "演示发布完成：已生成 {$created} 条分发记录（演示模式，真实平台需配置 OAuth 授权后自动上传）。");
    }
}
