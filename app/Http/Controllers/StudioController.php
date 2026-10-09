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

    /** 对话出稿工作台·一期：选题/改写/成稿 用对话引导（代理 8500 /chat 编排层）。 */
    public function chat()
    {
        $tenant = $this->studioTenant(request());
        return view('studio.chat', [
            'tenantName' => $tenant->name,
            'tenantSlug' => $tenant->slug,
            'industryHint' => $tenant->settings['industry'] ?? '',
        ]);
    }

    /** 对话成稿导出：前端把成稿(标题+多篇正文)POST 过来，生成 docx/pdf/xlsx/md/txt 下载。 */
    public function chatExport(Request $request)
    {
        $data = $request->validate([
            'format'  => ['required', 'string', 'in:docx,pdf,xlsx,md,txt'],
            'title'   => ['nullable', 'string', 'max:200'],
            'pieces'  => ['nullable', 'array'],
            'pieces.*.title'  => ['nullable', 'string', 'max:300'],
            'pieces.*.script' => ['nullable', 'string'],
            'text'    => ['nullable', 'string'],
        ]);

        $format = $data['format'];
        $title  = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = '对话成稿';
        }

        // 归一化为 pieces：支持前端两种传法（pieces 数组 或 单段 text）
        $pieces = [];
        foreach (($data['pieces'] ?? []) as $p) {
            if (! is_array($p)) {
                continue;
            }
            $pieces[] = [
                'title'  => (string) ($p['title'] ?? ''),
                'script' => (string) ($p['script'] ?? ''),
            ];
        }
        $plain = trim((string) ($data['text'] ?? ''));
        if (count($pieces) === 0 && $plain !== '') {
            $pieces[] = ['title' => $title, 'script' => $plain];
        }

        try {
            $file = app(\App\Services\ChatExportService::class)->export($pieces, $format, $title);
            if ($format === 'md' || $format === 'txt') {
                // 文本格式直接返回内容 + 提示前端存 blob
                return response()->json([
                    'ok'   => true,
                    'format' => $format,
                    'content' => $file,
                    'filename' => ($title !== '对话成稿' ? $title : '口播稿') . '.' . $format,
                ]);
            }
            return $file;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('chatExport failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['error' => '导出失败：' . $e->getMessage()], 500);
        }
    }

    /** 对话出稿工作台·一期：一次对话回合（session_id + message → 8500 /chat）。 */
    public function chatSend(Request $request)
    {
        $tenant = $this->studioTenant(request());
        $data = $request->validate([
            'session_id' => ['nullable', 'string', 'max:64'],
            'message'    => ['nullable', 'string', 'max:600'],
            'action'     => ['sometimes', 'array'],
            'attachments' => ['nullable', 'array'],
            'attachments.*.name' => ['nullable', 'string', 'max:255'],
            'attachments.*.kind' => ['nullable', 'string', 'max:32'],
            'attachments.*.text' => ['nullable', 'string'],
        ]);
        if (empty($data['message']) && empty($data['action']) && empty($data['attachments'])) {
            return response()->json(['error' => 'message, action or attachments required'], 422);
        }
        $data['tenant'] = $tenant->slug;   // 租户隔离：会话归属到本租户

        // 附件作为「上下文素材」拼进 message：8500 无需改动即可理解图意 / 文档内容
        if (! empty($data['attachments']) && is_array($data['attachments'])) {
            $blocks = [];
            foreach ($data['attachments'] as $a) {
                $name = $a['name'] ?? '文件';
                $kind = $a['kind'] ?? '素材';
                $txt  = $a['text'] ?? '';
                $blocks[] = "[用户附件 · {$name}（{$kind}）]\n{$txt}";
            }
            $sep = "\n\n——以上为附件内容，请结合附件回答用户问题——\n\n";
            $data['message'] = implode("\n\n", $blocks) . $sep . ($data['message'] ?? '');
        }
        unset($data['attachments']);   // 8500 /chat 不消费此字段，转交前摘掉
        // 长任务出稿（"全写"5 篇）实测 200~280s，超过同步超时会让前端误判 502。
        // 这里给到 300s 兜底；真正根治是异步 job + 进度轮询（能力调度已在演进）。
        $timeout = ($request->input('message') !== null
                    && mb_strlen(trim((string) $request->input('message'))) <= 8)
            ? 320 : 150;
        try {
            $resp = app(PipelineClient::class)->post('/chat', $data, $timeout);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '对话服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '对话服务暂不可用，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    /** 语音输入整理：口述文本 → 8500 /polish 轻度润色为清晰指令。 */
    public function chatPolish(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
        ]);
        try {
            $resp = app(PipelineClient::class)->post('/polish', $data, 40);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'polished' => $data['text'],
                                    'error' => '对话服务暂时不可用，已保留原话可手动修改'], 200);
        }
        if (! $resp->successful()) {
            return response()->json(['ok' => false, 'polished' => $data['text'],
                                    'error' => '整理服务暂不可用，已保留原话'], 200);
        }
        return response()->json($resp->json());
    }

    /** 对话出稿·二期：会话/主题空间列表（左侧栏）。 */
    public function chatSessions()
    {
        $tenant = $this->studioTenant(request());
        try {
            $resp = app(PipelineClient::class)->get('/chat/sessions?tenant=' . urlencode($tenant->slug), 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'sessions' => []]);
        }
        if (! $resp->successful()) {
            return response()->json(['ok' => false, 'sessions' => []]);
        }
        return response()->json($resp->json());
    }

    /** 对话出稿·二期：加载某个会话/空间的完整历史消息（切换回来继续）。 */
    public function chatMessages(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:64'],
        ]);
        try {
            $resp = app(PipelineClient::class)->get(
                '/chat/session/messages?session_id=' . urlencode($data['session_id']), 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'messages' => []]);
        }
        if (! $resp->successful()) {
            return response()->json(['ok' => false, 'messages' => []]);
        }
        return response()->json($resp->json());
    }

    /** 对话出稿·异步长任务进度（B 版）：GET /studio/chat/status/{job_id} → 8500 /chat/status。 */
    public function chatStatus(string $jobId)
    {
        try {
            $resp = app(PipelineClient::class)->get(
                '/chat/status/' . urlencode($jobId), 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['stage' => 'error', 'error' => '对话服务暂时不可用'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['stage' => 'error', 'error' => '对话服务暂不可用'], 502);
        }
        return response()->json($resp->json());
    }

    /**
     * 通用长任务异步进度：GET /studio/cap/status/{job_id} → 8500 /async/status/{job_id}。
     *
     * 配合 dispatchPipelineAsync()：能力执行返回 {async:true, job_id} 后，前端轮询这里取最终结果。
     * 目的是绕开 Cloudflare 免费版源站响应 125 秒硬限制（同步必 524）。
     * 8500 侧「取走即清」，所以前端拿到 done 后必须停止轮询。
     */
    public function capStatus(string $jobId)
    {
        try {
            $resp = app(PipelineClient::class)->statusAsync($jobId, 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'status' => 'error',
                'error' => '后台服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['ok' => false, 'status' => 'error',
                'error' => '后台服务返回异常（HTTP ' . $resp->status() . '）'], 502);
        }
        return response()->json($resp->json());
    }

    /** 对话出稿·二期：新建会话（带 title 即为主题空间）。 */
    public function chatSessionCreate(Request $request)
    {
        $tenant = $this->studioTenant(request());
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:60'],
        ]);
        try {
            $resp = app(PipelineClient::class)->post('/chat/session/create', [
                'tenant' => $tenant->slug,
                'title'  => $data['title'] ?? '',
            ], 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => '对话服务暂时不可用'], 503);
        }
        return response()->json($resp->json());
    }

    /**
     * 对话驱动一切 · 能力调度：对话里点「开始执行」后，由这里真正去跑平台功能。
     *
     * 设计原则：
     *  - 出片/发布包/成片质检一律**复用现有 Controller**（内部 Request 注入用户），
     *    保留配额校验、并发闸、幂等去重、租户隔离，绝不绕开重写。
     *  - 其余能力直接代理到 8500 对应端点。
     *  - 权威能力表在 python-pipeline/capabilities.py，这里只做执行映射。
     */
    public function chatAction(Request $request)
    {
        $data = $request->validate([
            'cap'   => ['required', 'string', 'max:40'],
            'vals'  => ['sometimes', 'array'],
        ]);
        $cap  = $data['cap'];
        $vals = $data['vals'] ?? [];

        // 本期隐藏的能力：对话入口已摘除，这里再拦一道，
        // 防止绕过前端直接 POST /studio/chat/action 触发
        if (in_array($cap, self::HIDDEN_CAPS, true)) {
            return response()->json(['ok' => false, 'error' => '该能力暂未开放'], 422);
        }

        // select 选项形如 "scroll:单字幕滚动"，只取冒号前的值
        foreach (['mode', 'voice_form'] as $k) {
            if (! empty($vals[$k]) && str_contains((string) $vals[$k], ':')) {
                $vals[$k] = explode(':', (string) $vals[$k], 2)[0];
            }
        }

        $map = $this->capabilityMap();
        if (! isset($map[$cap])) {
            return response()->json(['ok' => false, 'error' => '暂不支持这个能力：' . $cap], 422);
        }
        $spec = $map[$cap];

        // 纯导航能力：直接返回跳转地址，由前端跳转到对应页面（如人工审核 review）
        if (($spec['type'] ?? '') === 'link') {
            return response()->json([
                'ok'   => true,
                'cap'  => $cap,
                'data' => ['link' => $spec['url'] ?? ''],
            ]);
        }

        try {
            if ($spec['type'] === 'internal') {
                $payload = $this->dispatchInternal($spec, $vals, $request);
            } elseif (! empty($spec['async'])) {
                // 长任务走异步（解 Cloudflare 125s 524）：只等 job_id，结果由前端轮询
                $payload = $this->dispatchPipelineAsync($spec, $vals);
            } else {
                $payload = $this->dispatchPipeline($spec, $vals, $cap);
            }
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'ok' => false, 'cap' => $cap,
                'error' => '执行出错了：' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'   => true,
            'cap'  => $cap,
            'data' => $payload,
        ]);
    }

    /**
     * 菜单壳数据源：优先代理 8500 /capabilities，返回可见能力清单（已含落地页 page 字段）。
     * 8500 未重启 / 不可达时，退回仓库内快照 capabilities.json，保证菜单壳始终可用。
     * 前端据此按 cat 自动生成分区菜单；缓存 60s 减轻 8500 压力。
     */
    public function capabilities()
    {
        try {
            $data = \Cache::remember('studio_capabilities', 60, function () {
                // 1) 优先 8500 实时端点（改 server.py 后需重启 8500 才生效）
                try {
                    $resp = app(PipelineClient::class)->get('/capabilities', 10);
                    if ($resp->successful()) {
                        $json = $resp->json();
                        if (! empty($json)) {
                            return $json;
                        }
                    }
                } catch (\Throwable $e) {
                    // 8500 未重启 / 不可达：忽略，走兜底快照
                }
                // 2) 兜底：仓库内快照（由 capabilities.py 生成，随代码提交）
                $snapshot = base_path('python-pipeline/capabilities.json');
                if (is_file($snapshot)) {
                    $dec = json_decode((string) file_get_contents($snapshot), true);
                    if (is_array($dec) && ! empty($dec)) {
                        return $dec;
                    }
                }
                throw new \RuntimeException('能力清单不可用且本地快照缺失');
            });
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => '能力清单获取失败：' . $e->getMessage(),
            ], 502);
        }
        return response()->json(['ok' => true, 'caps' => $data]);
    }

    /**
     * 商用端菜单壳：按插件自动生成的「智能创作工厂」首页（菜单来自 /studio/capabilities）。
     */
    public function factory()
    {
        return view('studio.factory');
    }

    /** 能力 → 执行方式映射表（权威定义在 python-pipeline/capabilities.py）。 */
    /**
     * 本期隐藏的能力（需与 python-pipeline/capabilities.py 的 HIDDEN_CAPS 一致）。
     * 只拦截执行，不删 capabilityMap 映射——二期把 id 从这里去掉即可完整恢复。
     */
    private const HIDDEN_CAPS = [
        'crm_record', 'consult_1v1', 'auto_reception',
        'matrix_publish', 'data_dashboard', 'advisor_chat',
    ];

    private function capabilityMap(): array
    {
        return [
            // —— 纯 8500 端点 ——
            // async=true：实测/预期耗时 >100s，走「提交→job_id→轮询」绕开 Cloudflare 125s 524。
            //   未标 async 的是快端点（<30s），保持同步直返，免得白白多一次轮询往返。
            'topic'        => ['type' => 'pipeline', 'path' => '/topic',          'timeout' => 150, 'async' => true],
            'hotspot'      => ['type' => 'pipeline', 'path' => '/hotspot',        'timeout' => 150, 'async' => true],
            'rewrite'      => ['type' => 'pipeline', 'path' => '/rewrite',        'timeout' => 180, 'async' => true],
            'qc'           => ['type' => 'pipeline', 'path' => '/qc',             'timeout' => 120, 'async' => true],
            'dissect'      => ['type' => 'pipeline', 'path' => '/dissect',        'timeout' => 180, 'async' => true],
            'xhs'          => ['type' => 'pipeline', 'path' => '/xhs_build_note', 'timeout' => 180],
            'footage_edit' => ['type' => 'pipeline', 'path' => '/footage-edit',   'timeout' => 120],
            'clone_voice'  => ['type' => 'pipeline', 'path' => '/clone_voice',    'timeout' => 120],
            // 2026-10-03：article（公众号文章）已下线，能力不再暴露
            'strategist'   => ['type' => 'pipeline', 'path' => '/strategist',     'timeout' => 120, 'async' => true],
            // —— Laravel 内部 Controller（含配额/并发/幂等/落库）——
            'video_render' => ['type' => 'internal', 'class' => \App\Http\Controllers\VideoController::class,      'method' => 'generate'],
            'publish_pack' => ['type' => 'internal', 'class' => \App\Http\Controllers\PublishPackController::class, 'method' => 'generate'],
            'qc_video'     => ['type' => 'internal', 'class' => self::class, 'method' => 'qcVideo', 'arg' => 'job_id'],
            // —— v2.0 P2 留资 / P3 增值（capabilities.py 注册的 6 个新能力执行层）——
            'data_dashboard' => ['type' => 'pipeline', 'path' => '/stats',            'timeout' => 30],
            'advisor_chat'   => ['type' => 'pipeline', 'path' => '/advisor',          'timeout' => 150, 'async' => true],
            'crm_record'     => ['type' => 'pipeline', 'path' => '/crm',              'timeout' => 30],
            'consult_1v1'    => ['type' => 'pipeline', 'path' => '/booking',          'timeout' => 30],
            'auto_reception' => ['type' => 'pipeline', 'path' => '/reception-config', 'timeout' => 30],
            'matrix_publish' => ['type' => 'pipeline', 'path' => '/matrix-config',    'timeout' => 30],
            // 纯导航能力：点开即跳转，不触发任何流水线（capabilities.py 里 review 带 link 字段）
            'review'        => ['type' => 'link', 'url' => '/studio/review'],
        ];
    }

    /** 代理到 8500。 */
    private function dispatchPipeline(array $spec, array $vals, string $cap): array
    {
        $vals = array_filter($vals, fn ($v) => $v !== null && $v !== '');
        try {
            $resp = app(PipelineClient::class)->post($spec['path'], $vals, $spec['timeout'] ?? 120);
        } catch (PipelineUnavailableException $e) {
            throw new \RuntimeException('后台服务暂时不可用，请稍后重试');
        }
        if (! $resp->successful()) {
            throw new \RuntimeException('后台服务返回异常（HTTP ' . $resp->status() . '）');
        }
        return $resp->json() ?: [];
    }

    /**
     * 异步提交到 8500：只拿 job_id 就返回，真正计算在 8500 后台线程跑。
     *
     * 解决 Cloudflare 免费版「源站响应超时 125 秒 → 524」：同步等一个 200~280 秒的
     * AI 端点必然被 CF 掐断，改成 job 化后入口请求 < 5 秒返回，前端轮询取结果。
     * 返回 ['async' => true, 'job_id' => '...']，由前端交给 pollCapJob() 轮询。
     */
    private function dispatchPipelineAsync(array $spec, array $vals): array
    {
        $vals = array_filter($vals, fn ($v) => $v !== null && $v !== '');
        try {
            $resp = app(PipelineClient::class)->submitAsync($spec['path'], $vals, 20);
        } catch (PipelineUnavailableException $e) {
            throw new \RuntimeException('后台服务暂时不可用，请稍后重试');
        }
        if (! $resp->successful()) {
            throw new \RuntimeException('后台服务返回异常（HTTP ' . $resp->status() . '）');
        }
        $json = $resp->json() ?: [];
        if (empty($json['job_id'])) {
            throw new \RuntimeException('后台服务未返回任务号，无法轮询');
        }
        return ['async' => true, 'job_id' => (string) $json['job_id']];
    }

    /**
     * 内部复用现有 Controller：保证配额校验 / 并发闸 / 幂等去重 / 租户隔离全部生效。
     * 做法：构造一个子 Request，注入当前用户与参数，临时替换容器中的 request 实例
     *（现有 Controller 内部有 request() 调用），执行完立即还原。
     */
    private function dispatchInternal(array $spec, array $vals, Request $request): array
    {
        $user = $request->user();
        $params = array_filter($vals, fn ($v) => $v !== null && $v !== '');

        // 出片默认走单字幕滚动；标题留空由后端兜底
        if (($spec['method'] ?? '') === 'generate' && empty($params['mode'])) {
            $params['mode'] = 'scroll';
        }

        $sub = \Illuminate\Http\Request::create('/studio/chat/action/internal', 'POST', $params);
        $sub->setUserResolver(fn () => $user);
        if ($request->hasSession()) {
            $sub->setLaravelSession($request->session());
        }

        $origin = app('request');
        app()->instance('request', $sub);
        try {
            $controller = app($spec['class']);
            $method     = $spec['method'];
            if (! empty($spec['arg'])) {
                $resp = $controller->{$method}($sub, $params[$spec['arg']] ?? '');
            } else {
                $resp = $controller->{$method}($sub);
            }
        } finally {
            app()->instance('request', $origin);
        }

        $payload = $resp instanceof \Illuminate\Http\JsonResponse
            ? ($resp->getData(true) ?: [])
            : (array) $resp;

        // Controller 返回的是业务错误（如并发超限/时长超限）时，向上抛成可读错误
        if (($resp instanceof \Illuminate\Http\JsonResponse) && $resp->getStatusCode() >= 400) {
            throw new \RuntimeException($payload['error'] ?? ('执行失败（HTTP ' . $resp->getStatusCode() . '）'));
        }
        return $payload;
    }

    /** 对话出稿·二期：重命名 / 存为空间 / 取消空间 / 置顶。 */
    public function chatSessionUpdate(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:64'],
            'title'      => ['nullable', 'string', 'max:60'],
            'kind'       => ['nullable', 'string', 'in:temp,space'],
            'pinned'     => ['nullable', 'boolean'],
        ]);
        try {
            $resp = app(PipelineClient::class)->post('/chat/session/update', $data, 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => '对话服务暂时不可用'], 503);
        }
        return response()->json($resp->json());
    }

    /** 对话出稿·二期：删除会话/空间（连同磁盘文件）。 */
    public function chatSessionDelete(Request $request)
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:64'],
        ]);
        try {
            $resp = app(PipelineClient::class)->post('/chat/session/delete', $data, 15);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => '对话服务暂时不可用'], 503);
        }
        return response()->json($resp->json());
    }

    public function qc()
    {
        $tenant = $this->studioTenant(request());
        // 列出本租户已完成渲染、待质检/已质检的出片，供前端逐条跑技术质检
        $jobs = VideoJob::where('tenant_id', $tenant->id)
            ->where('status', 'done')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get(['id', 'job_id', 'mode', 'title', 'qc_status', 'text_qc_status', 'updated_at']);
        // 预计算发布门禁结论，供「关联成片」下拉展示
        $jobs = $jobs->map(function ($j) {
            $v = $j->qcVerdict();
            return [
                'job_id'          => $j->job_id,
                'title'           => $j->title,
                'mode'            => $j->mode,
                'qc_status'       => $j->qc_status,
                'text_qc_status'  => $j->text_qc_status,
                'verdict'         => $v['verdict'],
                'checks'          => $v['checks'],
            ];
        });
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

        // 长任务异步化（解 Cloudflare 125s 524）：提交拿 job_id，结果由前端轮询
        try {
            $resp = app(PipelineClient::class)->submitAsync('/topic', $sendData, 20);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '选题服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '选题服务暂不可用，请确认微服务已启动'], 502);
        }
        $json = $resp->json() ?: [];
        if (empty($json['job_id'])) {
            return response()->json(['error' => '选题服务未返回任务号，无法轮询'], 502);
        }
        return response()->json([
            'ok'   => true,
            'cap'  => 'topic',
            'data' => ['async' => true, 'job_id' => (string) $json['job_id']],
        ]);
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

        // 热点检索要跑 tavily 多查询 + deepseek 过滤，实测常 >100s → 走异步，绕开 CF 524
        try {
            $resp = app(PipelineClient::class)->submitAsync('/hotspot', $payload, 20);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '热点服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '热点服务暂不可用，请确认微服务已启动'], 502);
        }
        $json = $resp->json() ?: [];
        if (empty($json['job_id'])) {
            return response()->json(['error' => '热点服务未返回任务号，无法轮询'], 502);
        }
        return response()->json([
            'ok'   => true,
            'cap'  => 'hotspot',
            'data' => ['async' => true, 'job_id' => (string) $json['job_id']],
        ]);
    }

    /**
     * 爆款选题雷达：代理到宿主 8500 微服务的 /newsfeed 端点。
     * 聚合微博/百度/头条热榜 + 国家税务总局/税屋最新政策（财税过滤 + 跨平台热度分），
     * 供聊天页顶部 ticker 展示，每条可「用作选题」进入对话拆角度流程。
     */
    public function newsfeed(Request $request)
    {
        $days = (int) ($request->query('days', 15));
        $days = max(1, min(30, $days));
        $force = $request->query('force') === '1' ? 'force=1&' : '';
        $qs = $force . 'days=' . $days;

        try {
            $resp = app(PipelineClient::class)->get('/newsfeed?' . $qs, 30);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '选题雷达服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '选题雷达服务暂不可用，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    /**
     * 对话框「上传本地文件」：收浏览器 multipart → base64 → 8500 /file_extract 解析
     * （docx/txt/md 提取文本；png/jpg/webp/bmp 走 qwen-vl OCR 提取图中文字）。
     */
    public function chatUpload(Request $request)
    {
        $file = $request->file('file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['error' => '未收到有效文件，请重选一次'], 400);
        }
        if ($file->getSize() > 10 * 1024 * 1024) {
            return response()->json(['error' => '文件超过 10MB 上限，请压缩后再传'], 400);
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: '');
        $allowed = ['txt', 'md', 'docx', 'png', 'jpg', 'jpeg', 'webp', 'bmp'];
        if (! in_array($ext, $allowed)) {
            return response()->json(['error' => '暂不支持 .'.$ext.' 类型（支持 txt/md/docx 与图片）'], 400);
        }
        $payload = [
            'name' => $file->getClientOriginalName(),
            'data_b64' => base64_encode(file_get_contents($file->getRealPath())),
        ];
        try {
            $resp = app(PipelineClient::class)->post('/file_extract', $payload, 150);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '文件解析服务暂不可用，请稍后重试'], 503);
        } catch (\Throwable $e) {
            return response()->json(['error' => '文件解析超时或失败，请换个文件或稍后再试'], 502);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '文件解析失败（'.$resp->status().'），请稍后再试'], 502);
        }
        return response()->json($resp->json());
    }

    /**
     * 每日热点·双题材：触发 8500 /hot-daily 后台抓取 微博/百度/头条热榜 → 财税/大事双题材 + 爆款方案。
     */
    public function hotDaily()
    {
        try {
            $resp = app(PipelineClient::class)->post('/hot-daily', [], 5);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '每日热点服务暂不可用，请稍后重试'], 503);
        }
        return response()->json($resp->json());
    }

    /**
     * 每日热点结果：读 8500 /hot-daily-result（daily_hot.json）。
     */
    public function hotDailyResult()
    {
        try {
            $resp = app(PipelineClient::class)->get('/hot-daily-result', 10);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '每日热点服务暂不可用，请稍后重试'], 503);
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
            'mode'              => ['sometimes', 'in:single,dual,script,content'],
            'focus'             => ['nullable', 'string', 'max:100'],
            'target_duration'   => ['nullable', 'integer', 'min:10', 'max:600'],
            'preserve'          => ['nullable', 'string', 'max:500'],
            'role_mode'         => ['sometimes', 'nullable', 'string', 'max:40'],
            'role_note'         => ['nullable', 'string', 'max:500'],
            'keep_manual_roles' => ['sometimes', 'nullable', 'boolean'],
            'industry'          => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        // 空值过滤，避免把无效字段透传到 8500；布尔真值保留
        $data = array_filter($data, fn ($v) => $v !== null && $v !== '');

        // 长任务异步化（解 Cloudflare 125s 524）
        try {
            $resp = app(PipelineClient::class)->submitAsync('/rewrite', $data, 20);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '二创服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '二创服务暂不可用，请确认微服务已启动'], 502);
        }
        $json = $resp->json() ?: [];
        if (empty($json['job_id'])) {
            return response()->json(['error' => '二创服务未返回任务号，无法轮询'], 502);
        }
        return response()->json([
            'ok'   => true,
            'cap'  => 'rewrite',
            'data' => ['async' => true, 'job_id' => (string) $json['job_id']],
        ]);
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
            'job_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        try {
            $resp = app(PipelineClient::class)->post('/qc', $data, 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '质检服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '质检服务暂不可用，请确认微服务已启动'], 502);
        }
        $result = $resp->json();

        // 关联成片时，把文本合规预检结论落库，作为发布门禁的「合规」一关
        if (! empty($data['job_id'])) {
            $tenant = $this->studioTenant($request);
            $job = VideoJob::where('job_id', $data['job_id'])
                ->where('tenant_id', $tenant->id)
                ->first();
            if ($job) {
                $risk = $result['risk_level'] ?? 'low';
                $status = match ($risk) {
                    'high'   => 'blocked',
                    'medium' => 'warned',
                    default  => 'passed',
                };
                $hits = $result['hits'] ?? [];
                $job->update([
                    'text_qc_status'   => $status,
                    'text_qc_summary'  => [
                        'risk_level' => $risk,
                        'hits'       => count($hits),
                        'chars'      => $result['chars'] ?? 0,
                        'key_hits'   => collect($hits)->take(5)->pluck('word')->all(),
                    ],
                ]);
                $result['job_text_qc_status'] = $status;
            }
        }

        return response()->json($result);
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
            'deep'       => ['sometimes', 'nullable', 'boolean'],
            'language'   => ['sometimes', 'nullable', 'string', 'max:10'],
            'platform'   => ['sometimes', 'nullable', 'string', 'max:20'],
            'industry'   => ['sometimes', 'nullable', 'string', 'max:40'],
            'title'      => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        // 拆解是「转写 → 结构拆解 → 潜力评估」三段串行，实测合计可达 330s，
        // 在 Cloudflare Tunnel 下同步返回必 524（源站响应 125s 上限）。
        // 整条链作为一个异步作业提交给 8500 /dissect（其内部完成转写+拆解+评估），
        // 入口 <5s 返回 job_id，前端轮询 /studio/cap/status/{job_id} 取最终结果。
        //
        // 链接模式例外：抖音/视频号反爬要立刻给明确提示，不值得异步绕，故同步短试一次。
        if (($data['input_mode'] ?? '') === 'link' && empty($data['text'])) {
            try {
                $tr = app(PipelineClient::class)->post('/transcribe', [
                    'video_url' => $data['video_url'] ?? '',
                    'language'  => $data['language'] ?? 'zh',
                ], 120);
            } catch (PipelineUnavailableException $e) {
                return response()->json(['error' => '拆解服务暂时不可用，请稍后重试'], 503);
            }
            if (! $tr->successful() || empty($tr->json()['ok'])) {
                return response()->json(['error' => '链接解析失败（抖音/视频号反爬，二期支持）'], 422);
            }
            $data['text'] = (string) ($tr->json()['text'] ?? '');
            if ($data['text'] === '') {
                return response()->json(['error' => '未获取到可拆解文案'], 422);
            }
        }

        // 视频「深度拆解」（可选）：技术规格 + 画面九宫格 + 分幕时间轴 + 逐字稿 + 结构拆解。
        // 走 8500 /video-dissect（抽帧 + whisper 转写，耗时更长）。
        // 若 8500 尚未加载该端点（未重启），自动降级为下面的普通拆解，体验不退化。
        if (! empty($data['deep']) && ! empty($data['video_b64'])) {
            try {
                $deepJob = $this->submitAsyncJob('/video-dissect', array_filter([
                    'video_b64' => $data['video_b64'],
                    'language'  => $data['language'] ?? null,
                    'title'     => $data['title'] ?? null,
                    'platform'  => $data['platform'] ?? null,
                    'industry'  => $data['industry'] ?? null,
                ]));
                return response()->json([
                    'ok'   => true,
                    'cap'  => 'dissect',
                    'data' => ['async' => true, 'job_id' => $deepJob],
                ]);
            } catch (\RuntimeException $e) {
                \Illuminate\Support\Facades\Log::info('video-dissect 暂不可用，降级普通拆解：' . $e->getMessage());
                // 落到下方普通 /dissect 路径
            }
        }

        try {
            $jobId = $this->submitAsyncJob('/dissect', array_filter([
                'text'       => $data['text'] ?? null,
                'video_b64'  => $data['video_b64'] ?? null,
                'title'      => $data['title'] ?? null,
                'language'   => $data['language'] ?? null,
                'platform'   => $data['platform'] ?? null,
                'industry'   => $data['industry'] ?? null,
            ]));
        } catch (\RuntimeException $e) {
            return response()->json(['error' => '拆解服务暂时不可用，请稍后重试'], 503);
        }

        return response()->json([
            'ok'   => true,
            'cap'  => 'dissect',
            'data' => ['async' => true, 'job_id' => $jobId],
        ]);
    }

    /**
     * 提交一个 8500 异步作业，返回 job_id。
     *
     * @throws \RuntimeException 8500 不可达 / 返回异常 / 未给 job_id
     */
    private function submitAsyncJob(string $path, array $payload): string
    {
        try {
            $resp = app(PipelineClient::class)->submitAsync($path, $payload, 20);
        } catch (PipelineUnavailableException $e) {
            throw new \RuntimeException('后台服务暂时不可用：' . $e->getMessage(), 0, $e);
        }
        if (! $resp->successful()) {
            throw new \RuntimeException('后台服务返回异常（HTTP ' . $resp->status() . '）');
        }
        $json = $resp->json() ?: [];
        if (empty($json['job_id'])) {
            throw new \RuntimeException('后台服务未返回任务号，无法轮询');
        }
        return (string) $json['job_id'];
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
