<?php

namespace App\Http\Controllers;

use App\Models\VideoJob;
use Illuminate\Http\Request;

/**
 * 发布助手（导出 / 手动发布中心）：
 * 本系统负责产出视频与小红书图文素材，发布由各平台 App 手动完成。
 * 本页汇总已渲染完成的成片，提供下载入口与各平台手动发布指引。
 */
class PublishController extends Controller
{
    /** 发布助手：已完成的成片 + 各平台手动发布指引。 */
    public function index()
    {
        $tenant = $this->studioTenant(request());

        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->get();

        return view('studio.publish', compact('videos'));
    }
}
