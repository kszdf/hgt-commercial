<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\QcReport;
use App\Models\QcRule;
use App\Models\UserActivity;
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
        $tenant = $this->studioTenant(request());
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

    /** 原始稿二创：与选题上下文隔离的独立入口。 */
    public function rewriteOriginal()
    {
        return view('studio.rewrite-original');
    }

    public function qc()
    {
        $tenant = $this->studioTenant(request());
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
            // 所有选填字段均接受空字符串 / null / undefined，后端会自动跳过或回退为默认值
            'industry' => ['sometimes', 'nullable', 'string', 'max:40'],
            'keywords' => ['nullable', 'string', 'max:120'],
            'count'    => ['sometimes', 'integer', 'between:1,10'],
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

        // 空字符串 / null / undefined 一律视为未选，从下发参数中剔除，由 8500 使用默认值
        $sendData = array_filter($data, fn ($v) => $v !== null && $v !== '');
        // count 缺省回退为 5，保证 8500 始终拿到有效数量
        $sendData['count'] = (int) ($sendData['count'] ?? 5);

        try {
            $resp = app(PipelineClient::class)->post('/topic', $sendData, 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '选题服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '选题服务暂不可用，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    /**
     * 全网财税热点选题：代理到宿主 8500 微服务的 /hotspot 端点。
     * 8500 复用 gpt_sovits.model_providers 的 tavily_search（有 TAVILY_API_KEY 时真实时）
     * + deepseek_chat 生成创作角度建议；无 key 时降级为非实时（realtime=false）。
     */
    public function hotspotTopics(Request $request)
    {
        $data = $request->validate([
            'days'       => ['sometimes', 'integer', 'in:1,3,7,30'],
            'subfields'  => ['sometimes', 'nullable', 'array'],
            'subfields.*' => ['string', 'max:20'],
        ]);

        $payload = [];
        $payload['days'] = (int) ($data['days'] ?? 7);
        if (!empty($data['subfields']) && is_array($data['subfields'])) {
            $payload['subfields'] = array_values(array_filter(
                $data['subfields'],
                fn ($v) => is_string($v) && $v !== ''
            ));
        }

        try {
            $resp = app(PipelineClient::class)->post('/hotspot', $payload, 90);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '热点服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '热点服务暂不可用，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    /**
     * 8500 微服务心跳探测：GET /health，连接失败/超时/异常返回 {ok:false}。
     * 供前端全局轮询显示红字预警（出片/拆解/选题等功能依赖 8500）。
     */
    public function pipelineHealth()
    {
        $checkedAt = now()->toDateTimeString();
        $resp = null;
        try {
            $resp = app(PipelineClient::class)->get('/health', 3);
        } catch (PipelineUnavailableException $e) {
            return response()->json([
                'ok'         => false,
                'error'      => '出片微服务（8500）无响应，请重启 Windows 服务 HGTCommercial8500：' . $e->getMessage(),
                'checked_at' => $checkedAt,
            ]);
        }
        if ($resp === null || ! $resp->successful()) {
            return response()->json([
                'ok'         => false,
                'error'      => $resp ? ('出片微服务（8500）返回异常状态 ' . $resp->status()) : '出片微服务（8500）请求超时',
                'checked_at' => $checkedAt,
            ]);
        }
        return response()->json([
            'ok'         => true,
            'checked_at' => $checkedAt,
        ]);
    }

    public function rewriteGenerate(Request $request)
    {
        $data = $request->validate([
            'text'              => ['required', 'string'],
            'mode'              => ['sometimes', 'in:single,dual,script'],
            'focus'             => ['nullable', 'string', 'max:100'],
            'target_duration'   => ['nullable', 'integer', 'min:10', 'max:600'],
            'preserve'          => ['nullable', 'string', 'max:500'],
            'role_mode'         => ['sometimes', 'nullable', 'string', 'max:40'],
            'role_note'         => ['nullable', 'string', 'max:500'],
            'keep_manual_roles' => ['sometimes', 'nullable', 'boolean'],
        ]);

        // 空值过滤，避免把无效字段透传到 8500；布尔真值保留
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

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

    /** 对标爆款 → 财税仿写（极简创作台一键生成用）。代理 8500 /follow_hot。 */
    public function followHot(Request $request)
    {
        $data = $request->validate([
            'text'     => ['required', 'string'],
            'industry' => ['nullable', 'string', 'max:50'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        try {
            $resp = app(PipelineClient::class)->post('/follow_hot', $data, 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '对标仿写服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '对标仿写服务暂不可用，请确认微服务已启动'], 502);
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
        $tenant = $this->studioTenant(request());
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

    /** AI 智能生成标题/副标题：代理到 8500 的 /suggest-title。 */
    public function suggestTitle(Request $request)
    {
        $data = $request->validate([
            'dialogue' => ['required', 'string', 'max:4000'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:40'],
            'style' => ['sometimes', 'nullable', 'string', 'in:smart,full,suspense'],
        ]);
        $send = array_filter([
            'dialogue' => $data['dialogue'],
            'industry' => $data['industry'] ?? null,
            'style' => $data['style'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');
        try {
            $resp = app(PipelineClient::class)->post('/suggest-title', $send, 30);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '标题生成服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '标题生成服务暂不可用，请确认微服务已启动'], 502);
        }
        $r = $resp->json();
        // 8500 可能返回 {ok:false, error}（模型异常），原样透传错误信息
        if (! empty($r['ok']) && $r['ok'] === false) {
            return response()->json(['error' => $r['error'] ?? 'AI 标题生成失败'], 200);
        }
        return response()->json([
            'title'    => $r['title'] ?? '',
            'subtitle' => $r['subtitle'] ?? '',
        ]);
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

    /** 外观设置页：展示预设方案 + 当前租户已保存的 DIY 覆盖。 */
    public function appearance()
    {
        $tenant = $this->studioTenant(request());
        $preset = in_array($tenant->theme_preset, ['indigo', 'warm', 'teal'], true) ? $tenant->theme_preset : 'indigo';
        $ov = is_array($tenant->theme_overrides) ? $tenant->theme_overrides : (json_decode($tenant->theme_overrides ?? '{}', true) ?: []);
        $density = in_array($ov['density'] ?? null, ['comfortable', 'compact'], true) ? $ov['density'] : 'comfortable';

        $presets = [
            'indigo' => ['label' => '靛蓝商务', 'desc' => '经典科技靛蓝，专业稳重', 'accent' => '#4f46e5', 'page' => '#ffffff'],
            'warm'   => ['label' => '暖阳亲和', 'desc' => '暖橙底色，温暖亲切', 'accent' => '#d97706', 'page' => '#fdfaf5'],
            'teal'   => ['label' => '青翠清新', 'desc' => '清新青绿，轻快自然', 'accent' => '#0d9488', 'page' => '#f0fdfa'],
        ];

        return view('studio.settings-appearance', [
            'preset'   => $preset,
            'density'  => $density,
            'accent'   => $ov['accent'] ?? '',
            'pageTint' => $ov['page_tint'] ?? '',
            'presets'  => $presets,
        ]);
    }

    /** 保存外观设置：预设 + 可选 DIY 覆盖（强调色 / 页面底色 / 密度）。 */
    public function appearanceUpdate(Request $request)
    {
        $tenant = $this->studioTenant(request());

        $data = $request->validate([
            'theme_preset' => ['required', 'string', 'in:indigo,warm,teal'],
            'accent'       => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'page_tint'    => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'density'      => ['required', 'string', 'in:comfortable,compact'],
        ]);

        $overrides = ['density' => $data['density']];
        if (! empty($data['accent'])) {
            $overrides['accent'] = $data['accent'];
        }
        if (! empty($data['page_tint'])) {
            $overrides['page_tint'] = $data['page_tint'];
        }

        $tenant->update([
            'theme_preset'    => $data['theme_preset'],
            'theme_overrides' => $overrides,
        ]);

        return redirect()->route('studio.settings.appearance')
            ->with('success', '外观设置已保存');
    }

    /**
     * 活动心跳上报：前端每 20s 上报当前所处环节（topic/rewrite/video），
     * 覆盖式写入 user_activities（同用户仅一条），并刷新 users.last_seen_at。
     * 全局管理员不计入（其 tenant_id 为 null）。数据供超级管理员实时监控大盘使用。
     */
    public function activityPing(Request $request)
    {
        $user = $request->user();
        if ($user->isGlobalAdmin()) {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        $data = $request->validate([
            'action' => ['required', 'string', 'in:topic,rewrite,video,studio'],
            'detail' => ['sometimes', 'nullable', 'array'],
        ]);

        UserActivity::upsertFor($user, $data['action'], $data['detail'] ?? null);
        $user->touchSeen();

        return response()->json(['ok' => true]);
    }

    /**
     * 爆款拆解页：渲染输入/结果双栏页面。
     */
    public function dissect()
    {
        $tenant = $this->studioTenant(request());
        return view('studio.dissect', [
            'tenantName'     => $tenant->name,
            'industryHint'   => $tenant->settings['industry'] ?? '',
            'topicPlatforms' => PlatformRegistry::topicList(),
        ]);
    }

    /**
     * 爆款拆解主流程：串联 /transcribe → /dissect → /strategist（长任务）。
     * 输入：input_mode(paste/upload/link) + 文案/视频/链接；输出结构化拆解 + 潜力评分。
     */
    public function dissectAnalyze(Request $request)
    {
        $data = $request->validate([
            'input_mode' => ['required', 'in:paste,upload,link'],
            'text'       => ['nullable', 'string', 'max:20000'],
            'video_b64'  => ['nullable', 'string'],
            'video_url'  => ['nullable', 'string', 'url'],
            'language'   => ['sometimes', 'nullable', 'string', 'max:10'],
            'platform'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'industry'   => ['sometimes', 'nullable', 'string', 'max:40'],
            'title'      => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        try {
            $client = app(PipelineClient::class);

            // ① 视频输入先转文字（上传 / 链接）
            $text = trim((string) ($data['text'] ?? ''));
            if (($data['input_mode'] ?? '') === 'upload' && !empty($data['video_b64'])) {
                $tr = $client->post('/transcribe',
                    ['video_b64' => $data['video_b64'], 'language' => $data['language'] ?? 'zh'], 120);
                if (! $tr->successful() || empty($tr->json()['ok'])) {
                    return response()->json(['error' => '视频转写失败：' . ($tr->json()['error'] ?? '服务异常')], 502);
                }
                $text = $tr->json()['text'] ?? '';
            } elseif (($data['input_mode'] ?? '') === 'link' && !empty($data['video_url'])) {
                // 二期为主；MVP 先尝试直链下载，失败给友好提示
                $tr = $client->post('/transcribe', ['video_url' => $data['video_url'], 'language' => $data['language'] ?? 'zh'], 120);
                if (! $tr->successful() || empty($tr->json()['ok'])) {
                    return response()->json(['error' => '链接解析失败（抖音/视频号反爬，二期支持）'], 422);
                }
                $text = $tr->json()['text'] ?? '';
            }
            if (! $text) {
                return response()->json(['error' => '未获取到可拆解文案'], 422);
            }

            // ② 结构拆解
            $dissect = $client->post('/dissect',
                array_filter([
                    'text'     => $text,
                    'platform' => $data['platform'] ?? null,
                    'industry' => $data['industry'] ?? null,
                ]), 120);
            if (! $dissect->successful()) {
                return response()->json(['error' => '拆解服务暂不可用'], 502);
            }

            // ③ 潜力评估（唤醒沉睡的 /strategist）
            $strategist = $client->post('/strategist',
                array_filter([
                    'title'    => $data['title'] ?? '',
                    'script'   => $text,
                    'industry' => $data['industry'] ?? null,
                    'platform' => $data['platform'] ?? null,
                ]), 90);

            return response()->json([
                'ok'         => true,
                'text'       => $text,
                'dissect'    => $dissect->json(),
                'strategist' => $strategist->successful() ? $strategist->json() : null,
            ]);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '拆解服务暂时不可用，请稍后重试'], 503);
        }
    }

    /** 去AI痕迹（唤醒沉睡的 /deai 端点）。 */
    public function deai(Request $request)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:20000']]);
        try {
            $resp = app(PipelineClient::class)->post('/deai', ['text' => $data['text']], 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '去AI痕迹服务暂不可用'], 503);
        }
        return response()->json($resp->json());
    }

    /** 获客军师 / 潜力评估（唤醒沉睡的 /strategist 端点，供页面单独调用）。 */
    public function suggestStrategist(Request $request)
    {
        $data = $request->validate([
            'title'    => ['sometimes', 'nullable', 'string', 'max:60'],
            'script'   => ['required', 'string'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:40'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        try {
            $resp = app(PipelineClient::class)->post('/strategist', $data, 90);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '潜力评估服务暂不可用'], 503);
        }
        return response()->json($resp->json());
    }
}
