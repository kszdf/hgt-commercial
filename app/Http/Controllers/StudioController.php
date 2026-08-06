<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\QcReport;
use App\Models\QcRule;
use App\Models\VideoJob;
use App\Services\PipelineClient;
use App\Services\PlatformRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * 智能选题 / 智能二创 / 智能质检（AI 文本 + 视频技术层能力）。
 * 代理到 Windows 宿主上的 Python 微服务 8500 的 /topic、/rewrite、/qc、/qc-video 端点
 * （服务端复用 gpt_sovits 的 DeepSeek 封装 + 违禁词库 + ffprobe，Laravel 不碰 key）。
 */
class StudioController extends Controller
{
    private function pipelineUrl(): string
    {
        return env('PYTHON_PIPELINE_URL', 'http://host.docker.internal:8500');
    }

    public function topic()
    {
        $tenant = request()->user()->tenant;
        return view('studio.topic', [
            'tenantName'   => $tenant->name,
            'tenantSlug'   => $tenant->slug,
            'industryHint' => $tenant->settings['industry'] ?? '',
            'topicPlatforms' => PlatformRegistry::topicList(),
        ]);
    }

    public function rewrite()
    {
        return view('studio.rewrite');
    }

    public function qc()
    {
        $tenant = request()->user()->tenant;
        // 列出本租户已完成渲染、待质检/已质检的出片，供前端逐条跑技术质检
        $jobs = VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'job_id', 'mode', 'title', 'qc_status', 'updated_at']);
        return view('studio.qc', compact('jobs'));
    }

    public function topicGenerate(Request $request)
    {
        $topicKeys = array_keys(PlatformRegistry::topicList());
        $topicLabels = array_values(PlatformRegistry::topicList());
        $validPlatforms = array_unique(array_merge($topicKeys, $topicLabels));

        $data = $request->validate([
            // 行业非必填：通用平台，行业由用户输入（不预置特定行业），不强填
            'industry' => ['sometimes', 'string', 'max:40'],
            'keywords' => ['nullable', 'string', 'max:120'],
            'count'    => ['sometimes', 'integer', 'between:3,12'],
            // 平台兼容 key（如 shipinhao）与中文 label（如 视频号），「不限」传空/null 视为未选
            'platform' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', $validPlatforms)],
            'hotness'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'hook'     => ['sometimes', 'nullable', 'string', 'max:20'],
            'form'     => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);

        // 统一把 platform 转成中文 label，供 8500 /topic 注入 AI 提示词的调性描述
        if (!empty($data['platform'])) {
            $label = PlatformRegistry::label($data['platform']);
            $data['platform'] = $label ?: $data['platform'];
        }

        try {
            $resp = app(PipelineClient::class)->post('/topic', $data, 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '选题服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '选题服务暂不可用，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    public function rewriteGenerate(Request $request)
    {
        $data = $request->validate([
            'text'  => ['required', 'string'],
            'mode'  => ['sometimes', 'in:single,dual,script'],
            'focus' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $resp = app(PipelineClient::class)->post('/rewrite', $data, 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '二创服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '二创服务暂不可用，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    public function qcGenerate(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string'],
            'platform' => ['sometimes', 'string', 'max:20'],
        ]);

        try {
            $resp = app(PipelineClient::class)->post('/qc', $data, 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '质检服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '质检服务暂不可用，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    /** 出片产物技术质检：调 8500 /qc-video，写 qc_reports，更新 video_job.qc_status。 */
    public function qcVideo(Request $request, string $jobId)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        try {
            $job = VideoJob::where('job_id', $jobId)
                ->where('tenant_id', $tenant->id)
                ->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => '出片任务不存在或无权访问'], 404);
        }

        try {
            $resp = app(PipelineClient::class)->post('/qc-video', [
                'job_id' => $jobId,
                'rules'  => $this->collectRuleParams(),
            ], 90);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '质检服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '质检服务暂不可用，请确认微服务已启动'], 502);
        }
        $r = $resp->json();

        $report = QcReport::create([
            'tenant_id'     => $tenant->id,
            'video_job_id' => $job->id,
            'target_type'   => 'video',
            'target_id'     => $job->id,
            'score'         => (int) ($r['score'] ?? 0),
            'level'         => $r['level'] ?? 'low',
            'status'        => $r['status'] ?? 'passed',
            'issues'        => $r['issues'] ?? [],
            'auto_fixed'    => $r['auto_fixed'] ?? [],
        ]);
        $job->update(['qc_status' => $r['status'] ?? 'passed']);

        return response()->json(['ok' => true, 'qc' => $r, 'report_id' => $report->id]);
    }

    /** 汇总启用规则的阈值参数，传给 8500 引擎。 */
    private function collectRuleParams(): array
    {
        $out = [];
        foreach (QcRule::where('status', 'active')->where('enabled', true)->get() as $rule) {
            if (! empty($rule->params) && is_array($rule->params)) {
                $out = array_merge($out, $rule->params);
            }
        }
        return $out;
    }
}
