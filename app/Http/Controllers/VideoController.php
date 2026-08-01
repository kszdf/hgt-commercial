<?php

namespace App\Http\Controllers;

use App\Models\VideoJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 视频出片：代理到 Windows 宿主上的 Python 微服务。
 * 服务地址经 host.docker.internal 访问（见 .env PYTHON_PIPELINE_URL）。
 * 支持两种模式：scroll（滚动字幕卡·不出镜） / avatar（本地数字人出镜）。
 */
class VideoController extends Controller
{
    private function pipelineUrl(): string
    {
        return env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500');
    }

    public function showScroll()
    {
        return view('studio.scroll');
    }

    public function generate(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;

        // —— 配额拦截（计费基础）——
        if ($tenant->isOverQuota()) {
            return response()->json([
                'error' => '本月生成额度已用完，请升级套餐或下月继续使用。',
                'code' => 'quota_exceeded',
                'usage' => $tenant->usageThisMonth(),
                'quota' => $tenant->quota_monthly,
            ], 402);
        }

        $data = $request->validate([
            'mode' => ['sometimes', 'in:scroll,avatar'],
            'dialogue' => ['required', 'string'],
            'title' => ['nullable', 'string', 'max:20'],
            'subtitle' => ['nullable', 'string', 'max:40'],
            // 分声线感情/快慢（可选；不传则用脚本默认值）
            'male_rate' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'female_rate' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'male_pitch' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'female_pitch' => ['sometimes', 'numeric', 'between:0.5,2.0'],
            'male_vol' => ['sometimes', 'integer', 'between:0,100'],
            'female_vol' => ['sometimes', 'integer', 'between:0,100'],
            'natural' => ['sometimes', 'boolean'],
            'model' => ['sometimes', 'string', 'max:120'],
            'cover_id' => ['sometimes', 'integer', 'exists:cover_assets,id'],
        ]);

        $mode = $data['mode'] ?? 'scroll';
        $title = $data['title'] ?? null;

        // 解析自传模特：前端传 "User:{asset_id}" → 查库取 HEYGEM 容器路径后透传
        $modelInput = $request->input('model');
        if ($mode === 'avatar' && $modelInput && str_starts_with($modelInput, 'User:')) {
            $assetId = substr($modelInput, 5);
            $asset = \App\Models\ModelAsset::where('id', $assetId)
                ->where('tenant_id', $tenant->id)
                ->where('status', 'ready')
                ->first();
            if ($asset) {
                $modelInput = $asset->containerPath();
                $asset->increment('use_count');
            }
        }

        // —— 记录用量（先落库，用于配额计量）——
        $job = VideoJob::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'job_id' => '',           // 待微服务返回后回填
            'mode' => $mode,
            'title' => $title,
            'status' => 'queued',
        ]);

        // 解析封面素材（仅限本租户），落库后回填
        if ($request->filled('cover_id')) {
            $cover = \App\Models\CoverAsset::where('id', $request->input('cover_id'))
                ->where('tenant_id', $tenant->id)
                ->first();
            if ($cover) {
                $job->update(['cover_asset_id' => $cover->id]);
                $cover->increment('use_count');
            }
        }

        // 真实 TTS（默认）；dry_tts 仅用于内部测试
        $payload = [
            'mode' => $mode,
            'dialogue' => $data['dialogue'],
            'title' => $title,
            'subtitle' => $data['subtitle'] ?? null,
            'dry_tts' => (bool) $request->input('dry_tts', false),
            'male_voice' => $tenant->default_male_voice,
            'female_voice' => $tenant->default_female_voice,
            'natural' => (bool) $request->input('natural', false),
            'model' => $request->input('model'),   // 仅数字人模式使用；字幕卡模式为 null，微服务自动跳过
        ];

        // 分声线感情/快慢：仅当页面传了才透传（不传则脚本用默认值）
        foreach (['male_rate', 'female_rate', 'male_pitch', 'female_pitch', 'male_vol', 'female_vol'] as $k) {
            if ($request->has($k)) {
                $payload[$k] = $request->input($k);
            }
        }

        $resp = Http::timeout(15)->post($this->pipelineUrl() . '/generate', $payload);

        if (! $resp->successful()) {
            $job->update(['status' => 'failed']);
            return response()->json(['error' => '出片服务暂不可用，请确认微服务已启动'], 502);
        }

        $pj = $resp->json('job_id');
        $job->update(['job_id' => $pj, 'status' => 'queued']);

        return response()->json([
            'job_id' => $pj,
            'mode' => $mode,
            'usage' => $tenant->usageThisMonth(),
            'quota' => $tenant->quota_monthly,
        ]);
    }

    public function status(string $jobId)
    {
        $resp = Http::timeout(15)->get($this->pipelineUrl() . '/status/' . $jobId);
        if (! $resp->successful()) {
            return response()->json(['error' => '状态查询失败'], 502);
        }
        $json = $resp->json();
        // 同步任务状态到本地用量表
        $job = VideoJob::where('job_id', $jobId)->first();
        if ($job && isset($json['status'])) {
            if (in_array($json['status'], ['done', 'failed'], true) && $job->status === 'queued') {
                $job->update(['status' => $json['status']]);
                // 渲染完成即进入「待人工审核」初始态（draft）
                if ($json['status'] === 'done' && is_null($job->publish_status)) {
                    $job->update(['publish_status' => 'draft']);
                }
            }
        }
        return response()->json($json);
    }

    public function download(string $jobId)
    {
        $resp = Http::timeout(180)->get($this->pipelineUrl() . '/download/' . $jobId);
        if (! $resp->successful()) {
            abort(404);
        }
        $job = VideoJob::where('job_id', $jobId)->first();
        if ($job && $job->status === 'queued') {
            $job->update(['status' => 'done']);
            // 渲染完成即进入「待人工审核」初始态（draft）
            if (is_null($job->publish_status)) {
                $job->update(['publish_status' => 'draft']);
            }
        }
        return response($resp->body(), 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => 'inline; filename="' . $jobId . '.mp4"',
        ]);
    }
}
