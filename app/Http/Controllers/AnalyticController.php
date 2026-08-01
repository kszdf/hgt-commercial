<?php

namespace App\Http\Controllers;

use App\Models\MetricDaily;
use App\Models\VideoJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AnalyticController extends Controller
{
    /** 数据复盘看板：聚合 metrics_daily，呈现 KPI / 平台分布 / Top出片 / 趋势。 */
    public function index()
    {
        $tenant = Auth::user()->tenant;
        $tid = $tenant->id;

        // KPI
        $totals = MetricDaily::where('tenant_id', $tid)
            ->selectRaw('COALESCE(SUM(views),0) as views, COALESCE(SUM(shares),0) as shares, COALESCE(SUM(comments),0) as comments, COALESCE(SUM(likes),0) as likes, COUNT(DISTINCT video_job_id) as videos, COUNT(DISTINCT metric_date) as days')
            ->first();

        $interactions = $totals->shares + $totals->comments + $totals->likes;

        // 平台分布（按播放）
        $byPlatform = MetricDaily::where('tenant_id', $tid)
            ->selectRaw('platform, COALESCE(SUM(views),0) as views')
            ->groupBy('platform')
            ->orderByDesc('views')
            ->get();
        $maxPlatformViews = $byPlatform->max('views') ?: 1;

        // Top 出片（按播放）
        $topVideos = MetricDaily::with('videoJob')
            ->where('tenant_id', $tid)
            ->selectRaw('video_job_id, COALESCE(SUM(views),0) as views, COALESCE(SUM(shares+comments+likes),0) as interactions')
            ->groupBy('video_job_id')
            ->orderByDesc('views')
            ->limit(8)
            ->get();

        // 趋势（按日期聚合播放）
        $trend = MetricDaily::where('tenant_id', $tid)
            ->selectRaw('metric_date, COALESCE(SUM(views),0) as views')
            ->groupBy('metric_date')
            ->orderBy('metric_date')
            ->get();

        return view('studio.analytics', compact(
            'totals', 'interactions', 'byPlatform', 'maxPlatformViews',
            'topVideos', 'trend'
        ));
    }
}
