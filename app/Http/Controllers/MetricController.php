<?php

namespace App\Http\Controllers;

use App\Models\MetricDaily;
use App\Models\PlatformAccount;
use App\Models\VideoJob;
use App\Services\PlatformAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MetricController extends Controller
{
    /** 数据模块首页：指标列表 + 单条录入 + CSV导入 + 平台授权状态。 */
    public function index()
    {
        $tenant = Auth::user()->tenant;
        $this->ensurePlatformAccounts($tenant);

        $metrics = MetricDaily::with('videoJob')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('metric_date')
            ->paginate(20);

        $videos = VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'done')
            ->orderByDesc('id')
            ->get(['id', 'job_id', 'title']);

        $accounts = PlatformAccount::where('tenant_id', $tenant->id)->get();

        return view('studio.metrics', compact('metrics', 'videos', 'accounts'));
    }

    /** 单条手动录入（经 ManualAdapter upsert）。 */
    public function store(Request $request)
    {
        $data = $request->validate([
            'video_job_id' => 'required|exists:video_jobs,id',
            'platform'     => 'required|in:wechat,douyin,xiaohongshu,manual',
            'metric_date'  => 'required|date',
            'views'        => 'nullable|integer|min:0',
            'shares'       => 'nullable|integer|min:0',
            'comments'     => 'nullable|integer|min:0',
            'likes'        => 'nullable|integer|min:0',
        ]);

        $tenant = Auth::user()->tenant;
        $row = MetricDaily::firstOrNew([
            'video_job_id' => $data['video_job_id'],
            'platform'     => $data['platform'],
            'metric_date'  => $data['metric_date'],
        ]);
        $row->tenant_id = $tenant->id;
        $row->views    = $data['views'] ?? 0;
        $row->shares   = $data['shares'] ?? 0;
        $row->comments = $data['comments'] ?? 0;
        $row->likes    = $data['likes'] ?? 0;
        $row->save();

        return redirect()->route('studio.metrics')->with('success', '已保存 ' . $data['metric_date'] . ' 的数据');
    }

    /** CSV 批量导入（utf-8，表头：job_id,platform,metric_date,views,shares,comments,likes）。 */
    public function import(Request $request)
    {
        $request->validate([
            'csv' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $tenant = Auth::user()->tenant;
        $path = $request->file('csv')->getRealPath();
        $handle = fopen($path, 'r');
        if (! $handle) {
            return redirect()->route('studio.metrics')->with('error', '无法读取文件');
        }

        $header = fgetcsv($handle);
        if (! $header) {
            return redirect()->route('studio.metrics')->with('error', 'CSV 表头为空');
        }
        $header = array_map('trim', $header);

        $imported = 0;
        $skipped  = 0;
        while (($line = fgetcsv($handle)) !== false) {
            $r = array_combine($header, $line);
            if ($r === false) { $skipped++; continue; }

            $jobId = trim($r['job_id'] ?? '');
            $platform = trim($r['platform'] ?? '');
            $date = trim($r['metric_date'] ?? '');
            if (! $jobId || ! $platform || ! $date) { $skipped++; continue; }

            $job = VideoJob::where('tenant_id', $tenant->id)
                ->where(function ($q) use ($jobId) {
                    $q->where('id', $jobId)->orWhere('job_id', $jobId);
                })->first();
            if (! $job) { $skipped++; continue; }

            $row = MetricDaily::firstOrNew([
                'video_job_id' => $job->id,
                'platform'     => $platform,
                'metric_date'  => $date,
            ]);
            $row->tenant_id = $tenant->id;
            $row->views    = (int) ($r['views'] ?? 0);
            $row->shares   = (int) ($r['shares'] ?? 0);
            $row->comments = (int) ($r['comments'] ?? 0);
            $row->likes    = (int) ($r['likes'] ?? 0);
            $row->save();
            $imported++;
        }
        fclose($handle);

        return redirect()->route('studio.metrics')
            ->with('success', "导入完成：成功 {$imported} 条，跳过 {$skipped} 条");
    }

    /**
     * 平台 OAuth 授权入口（占位）。
     * 真实平台需在开放平台后台配置回调，这里仅标记账号状态为「待授权/已授权」演示。
     */
    public function connect(Request $request, string $platform)
    {
        $tenant = Auth::user()->tenant;
        $account = PlatformAccount::firstOrCreate(
            ['tenant_id' => $tenant->id, 'platform' => $platform],
            ['account_name' => $platform, 'status' => 'unauthorized']
        );

        $adapter = PlatformAdapter::make($platform);
        $url = $adapter->oauthUrl($account);
        if ($url) {
            return redirect($url);
        }

        // 占位：当前演示模式，标记需授权并提示
        return redirect()->route('studio.metrics')
            ->with('info', "「{$platform}」真实授权需在开放平台后台配置回调地址，当前为演示模式（数据手动录入）。");
    }

    /** 为租户补齐三平台账号记录（不覆盖已有）。 */
    private function ensurePlatformAccounts($tenant): void
    {
        foreach (['wechat', 'douyin', 'xiaohongshu'] as $p) {
            PlatformAccount::firstOrCreate(
                ['tenant_id' => $tenant->id, 'platform' => $p],
                ['account_name' => $p, 'status' => 'unauthorized']
            );
        }
    }
}
