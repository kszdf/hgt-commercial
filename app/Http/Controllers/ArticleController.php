<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\Article;
use App\Models\PlatformAccount;
use App\Models\PublishRecord;
use App\Services\PipelineClient;
use App\Services\PublishRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 公众号文章控制器：AI 出稿 → SEO 优化 → 送公众号草稿箱 → 群发。
 *
 * 文本生成 / SEO 校验 / 微信接口调用全部下沉到 8500 微服务（Laravel 不碰 LLM key 与公众号密钥）：
 *   POST /article/write      {topic, kw_main, kw_long, region, audience, word_count, tenant, brand}
 *   POST /article/seo-check  {title, content, kw_main, kw_long, region}
 *   POST /article/push-draft {article_id, title, digest, content_html, author, cover_path, account_key, extra}
 *   POST /article/publish    {article_id, media_id, title, account_key, extra}
 * 8500 统一返回 {ok, ...}；缺凭证时返回 simulated=true（dry 降级），Laravel 侧只做状态落库与提示。
 *
 * 合规（微信公众平台运营规范 3.27）：
 *   - 禁止非真人自动化创作 / 脚本托管批量连续发布 → 群发必须 human-in-the-loop：
 *     只有 reviewed（人工审核通过，留 reviewed_by/reviewed_at）状态可群发；
 *   - 订阅号单号每日限群发 1 篇 → DAILY_MASS_SEND_LIMIT。
 */
class ArticleController extends Controller
{
    /** 订阅号单号每日群发上限（微信 3.27 合规要求）。 */
    private const DAILY_MASS_SEND_LIMIT = 1;

    /** 合法状态枚举（列表筛选防注入）。 */
    private const STATUSES = ['draft', 'reviewed', 'published', 'failed'];

    /**
     * 文章列表：状态筛选 + 关键词搜索，租户隔离（超管走 studioTenant 回退租户）。
     */
    public function index(Request $request)
    {
        try {
            $tenant = $this->studioTenant($request);
            $status  = (string) $request->input('status', '');
            $keyword = trim((string) $request->input('q', ''));

            $query = Article::forTenant($tenant->id)->latest();
            if (in_array($status, self::STATUSES, true)) {
                $query->ofStatus($status);
            }
            if ($keyword !== '') {
                $query->search($keyword);
            }

            $articles = $query->paginate(20)->withQueryString();
            $accounts = PlatformAccount::where('tenant_id', $tenant->id)
                ->where('platform', 'wechat')
                ->orderByDesc('id')
                ->get();

            return view('studio.articles.index', [
                'articles'         => $articles,
                'accounts'         => $accounts,
                'status'           => $status,
                'keyword'          => $keyword,
                'statusLabels'     => Article::STATUS_LABELS,
                'wechatConfigured' => $this->hasWechatCredentials($accounts),
                'tenant'           => $tenant,
            ]);
        } catch (\Throwable $e) {
            Log::error('article index failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['ok' => false, 'error' => '文章列表加载失败：' . $e->getMessage()], 500);
        }
    }

    /** 文章详情（JSON，供列表「查看」弹窗使用）。 */
    public function show(Request $request, Article $article)
    {
        try {
            $this->assertTenantOwner($request, (int) $article->tenant_id);
            return response()->json([
                'ok'      => true,
                'article' => $this->articlePayload($article),
            ]);
        } catch (\Throwable $e) {
            Log::error('article show failed: ' . $e->getMessage(), ['id' => $article->id ?? null]);
            return response()->json(['ok' => false, 'error' => '读取文章失败'], 500);
        }
    }

    /**
     * AI 出稿：调 8500 /article/write，落库为 draft（待人工审核）。
     */
    public function write(Request $request)
    {
        $data = $request->validate([
            'topic'      => ['required', 'string', 'max:200'],
            'kw_main'    => ['nullable', 'string', 'max:100'],
            'kw_long'    => ['nullable', 'string', 'max:2000'],
            'region'     => ['nullable', 'string', 'max:50'],
            'audience'   => ['nullable', 'string', 'max:300'],
            'word_count' => ['nullable', 'integer', 'min:300', 'max:6000'],
            'session_id' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $tenant = $this->studioTenant($request);
            // 与出片/图文同一配额池，防止免费用户绕过套餐无限出稿
            $block = $tenant->generationBlockReason();
            if ($block) {
                return response()->json(['ok' => false, 'error' => $block['message']], 403);
            }

            $resp = app(PipelineClient::class)->postJson('/article/write', [
                'topic'      => $data['topic'],
                'kw_main'    => $data['kw_main'] ?? '',
                'kw_long'    => $data['kw_long'] ?? '',
                'region'     => $data['region'] ?? '',
                'audience'   => $data['audience'] ?? '',
                'word_count' => (int) ($data['word_count'] ?? 1500),
                'tenant'     => (string) $tenant->slug,
                'brand'      => $this->defaultBrand($tenant),
            ], 180);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => '出稿服务暂时不可用，请稍后重试'], 503);
        } catch (\Throwable $e) {
            Log::error('article write failed: ' . $e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 600)]);
            return response()->json(['ok' => false, 'error' => '出稿失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => '出稿失败（HTTP ' . $resp->status() . '）：' . mb_substr((string) $resp->body(), 0, 300),
            ], 502);
        }

        $j = $resp->json() ?: [];
        $content  = (string) ($j['content'] ?? '');
        $html     = (string) ($j['content_html'] ?? '');
        $title    = (string) ($j['title'] ?? $data['topic']);

        try {
            $article = Article::create([
                'tenant_id'    => $tenant->id,
                'user_id'      => Auth::id(),
                'session_id'   => $data['session_id'] ?? null,
                'title'        => mb_substr($title, 0, 300),
                'digest'       => $j['digest'] ?? null,
                'content'      => $content,
                'content_html' => $html !== '' ? $html : null,
                'author'       => $j['author'] ?? null,
                'tags'         => $j['tags'] ?? null,
                'kw_main'      => $data['kw_main'] ?? null,
                'kw_long'      => $data['kw_long'] ?? null,
                'region'       => $data['region'] ?? null,
                'cover_path'   => $j['cover_path'] ?? null,
                'status'       => 'draft',
                // 兼容 8500 两种返回风格：{seo_score,...} 或 seo_check.check() 的 {score, items, stats}
                'seo_score'    => (int) ($j['seo_score'] ?? $j['score'] ?? 0),
                'seo_report'   => $j['seo_report'] ?? $j['items'] ?? null,
                'word_count'   => (int) ($j['word_count'] ?? mb_strlen($content)),
                'hit_count'    => (int) ($j['hit_count'] ?? ($j['stats']['banned_hits'] ?? 0)),
                // 字数压缩信息：出稿时若超目标 50% 会自动压缩一次，落库供前端给用户提示
                'meta'         => [
                    'topic'             => $data['topic'],
                    'audience'          => $data['audience'] ?? '',
                    'word_note'         => $j['word_note'] ?? '',
                    'compressed'        => (bool) ($j['compressed'] ?? false),
                    'word_count_before' => (int) ($j['word_count_before'] ?? 0),
                ],
            ]);

            return response()->json(['ok' => true, 'article' => $this->articlePayload($article)]);
        } catch (\Throwable $e) {
            Log::error('article write persist failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => '出稿成功但落库失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * SEO 校验：调 8500 /article/seo-check，直接回传校验明细（不落库，供前端即时提示）。
     */
    public function seoCheck(Request $request)
    {
        $data = $request->validate([
            'title'   => ['nullable', 'string', 'max:300'],
            'content' => ['required', 'string'],
            'kw_main' => ['nullable', 'string', 'max:100'],
            'kw_long' => ['nullable', 'string', 'max:2000'],
            'region'  => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $resp = app(PipelineClient::class)->postJson('/article/seo-check', [
                'title'   => $data['title'] ?? '',
                'content' => $data['content'],
                'kw_main' => $data['kw_main'] ?? '',
                'kw_long' => $data['kw_long'] ?? '',
                'region'  => $data['region'] ?? '',
            ], 60);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => 'SEO 校验服务暂时不可用，请稍后重试'], 503);
        } catch (\Throwable $e) {
            Log::error('article seoCheck failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => 'SEO 校验失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => 'SEO 校验失败（HTTP ' . $resp->status() . '）：' . mb_substr((string) $resp->body(), 0, 300),
            ], 502);
        }

        // 原样透传 8500 明细（score/level/items/stats/hard_fail/summary），
        // 并补两个前端统一字段名，兼容 seo_check.check() 的返回风格
        $j = $resp->json() ?: [];

        return response()->json(array_merge(['ok' => true], $j, [
            'seo_score' => (int) ($j['seo_score'] ?? $j['score'] ?? 0),
            'hit_count' => (int) ($j['hit_count'] ?? ($j['stats']['banned_hits'] ?? 0)),
        ]));
    }

    /**
     * 送公众号草稿箱：调 8500 /article/push-draft，成功回写 wechat_media_id。
     * 未配置公众号凭据时 8500 走 dry 模拟，透传 simulated 标志由前端黄色提示。
     */
    public function pushDraft(Request $request, Article $article)
    {
        try {
            $this->assertTenantOwner($request, (int) $article->tenant_id);
            $tenant  = $this->studioTenant($request);
            $account = $this->resolveAccount($request, $tenant->id, $article);

            $payload = array_merge([
                'article_id'   => $article->id,
                'title'        => $article->title,
                'digest'       => (string) $article->digest,
                'content_html' => (string) ($article->content_html ?: $article->content),
                'author'       => (string) $article->author,
                'cover_path'   => (string) $article->cover_path,
                'account_key'  => $account ? ('wechat:' . $account->id) : '',
            ], $account ? app(PublishRunner::class)->credentialsExtra($account) : []);

            $resp = app(PipelineClient::class)->postJson('/article/push-draft', $payload, 120);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => '草稿箱服务暂时不可用，请稍后重试'], 503);
        } catch (\Throwable $e) {
            Log::error('article pushDraft failed: ' . $e->getMessage(), ['id' => $article->id]);
            return response()->json(['ok' => false, 'error' => '送草稿箱失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => '送草稿箱失败（HTTP ' . $resp->status() . '）：' . mb_substr((string) $resp->body(), 0, 300),
            ], 502);
        }

        $j = $resp->json() ?: [];
        if (($j['ok'] ?? true) === false) {
            return response()->json(['ok' => false, 'error' => (string) ($j['error'] ?? '送草稿箱失败')], 502);
        }
        // 双保险判模拟：显式 simulated / status=simulated，或「成功但无 media_id」
        $simulated = ! empty($j['simulated'])
            || ($j['status'] ?? '') === 'simulated'
            || (empty($j['media_id']) && empty($j['draft_media_id']));

        if ($simulated) {
            // 模拟发送不写 media_id，避免污染真实草稿状态
            return response()->json([
                'ok'        => true,
                'simulated' => true,
                'warn'      => '未配置公众号凭据，本次为模拟发送',
                'article'   => $this->articlePayload($article->fresh()),
            ]);
        }

        try {
            $article->update([
                'wechat_media_id'    => (string) ($j['media_id'] ?: ($j['draft_media_id'] ?? '')),
                'wechat_account_id'  => $account ? $account->id : $article->wechat_account_id,
                'status'             => $article->status === 'failed' ? 'draft' : $article->status,
            ]);
        } catch (\Throwable $e) {
            Log::error('article pushDraft persist failed: ' . $e->getMessage(), ['id' => $article->id]);
        }

        return response()->json([
            'ok'        => true,
            'simulated' => false,
            'article'   => $this->articlePayload($article->fresh()),
        ]);
    }

    /**
     * 审核通过（human-in-the-loop）：draft → reviewed，记录审核人与时间。
     */
    public function approve(Request $request, Article $article)
    {
        try {
            $this->assertTenantOwner($request, (int) $article->tenant_id);

            if ($article->status === 'published') {
                return response()->json(['ok' => false, 'error' => '该文章已发表，无需重复审核'], 422);
            }

            $article->update([
                'status'      => 'reviewed',
                'reviewed_by' => Auth::id(),
                'reviewed_at' => now(),
            ]);

            return response()->json(['ok' => true, 'article' => $this->articlePayload($article->fresh())]);
        } catch (\Throwable $e) {
            Log::error('article approve failed: ' . $e->getMessage(), ['id' => $article->id]);
            return response()->json(['ok' => false, 'error' => '审核失败：' . $e->getMessage()], 500);
        }
    }

    /**
     * 群发（订阅号每日限 1 篇），四道校验按顺序：
     *   1) 必须是 reviewed（人工审核通过）
     *   2) 该公众号今日已群发数 ≥1 → 拒绝
     *   3) 调 8500 /article/publish
     *   4) 成功后落库 + 返回 AI 声明提醒
     */
    public function publish(Request $request, Article $article)
    {
        try {
            $this->assertTenantOwner($request, (int) $article->tenant_id);
            $tenant  = $this->studioTenant($request);
            $account = $this->resolveAccount($request, $tenant->id, $article);

            // —— 校验 1：必须先人工审核通过（微信 3.27：不得脚本自动连续发布）——
            if (! $article->canPublish()) {
                return response()->json(['ok' => false, 'error' => '请先审核通过'], 422);
            }

            // —— 校验 2：订阅号单号每日限群发 1 篇 ——
            if ($this->todayMassSendCount($tenant->id, $account) >= self::DAILY_MASS_SEND_LIMIT) {
                return response()->json([
                    'ok'    => false,
                    'error' => '订阅号每日限群发 1 篇（微信 3.27 合规要求）',
                ], 422);
            }

            if (empty($article->wechat_media_id)) {
                return response()->json(['ok' => false, 'error' => '请先送入公众号草稿箱再群发'], 422);
            }

            // —— 校验 3：调 8500 群发 ——
            $payload = array_merge([
                'article_id'  => $article->id,
                'media_id'    => (string) $article->wechat_media_id,
                'title'       => $article->title,
                'account_key' => $account ? ('wechat:' . $account->id) : '',
            ], $account ? app(PublishRunner::class)->credentialsExtra($account) : []);

            $resp = app(PipelineClient::class)->postJson('/article/publish', $payload, 180);
        } catch (PipelineUnavailableException $e) {
            return response()->json(['ok' => false, 'error' => '群发服务暂时不可用，请稍后重试'], 503);
        } catch (\Throwable $e) {
            Log::error('article publish failed: ' . $e->getMessage(), ['id' => $article->id]);
            return response()->json(['ok' => false, 'error' => '群发失败：' . $e->getMessage()], 500);
        }

        if (! $resp->successful()) {
            return response()->json([
                'ok'    => false,
                'error' => '群发失败（HTTP ' . $resp->status() . '）：' . mb_substr((string) $resp->body(), 0, 300),
            ], 502);
        }

        // —— 校验 4：成功落库 ——
        $j = $resp->json() ?: [];
        if (($j['ok'] ?? true) === false) {
            return response()->json(['ok' => false, 'error' => (string) ($j['error'] ?? '群发失败')], 502);
        }
        // 兼容 publishers 的 PublishResult 风格：status=simulated/published/failed
        $simulated = ! empty($j['simulated']) || ($j['status'] ?? '') === 'simulated';
        $url       = (string) ($j['article_url'] ?? $j['url'] ?? $j['platform_url'] ?? '');
        $errMsg    = (string) ($j['error'] ?? $j['error_message'] ?? '') ?: null;

        try {
            if (! $simulated) {
                $article->update([
                    'status'             => 'published',
                    'wechat_article_url' => $url !== '' ? $url : null,
                    'wechat_account_id'  => $account ? $account->id : $article->wechat_account_id,
                    'published_at'       => now(),
                ]);
                if ($account) {
                    $account->markPublished(); // 仅真实成功计入每日上限
                }
            }

            $this->recordPublish($tenant->id, $article, $account, $simulated, $url, $errMsg);
        } catch (\Throwable $e) {
            Log::error('article publish persist failed: ' . $e->getMessage(), ['id' => $article->id]);
        }

        return response()->json([
            'ok'                     => true,
            'simulated'              => $simulated,
            'warn'                   => $simulated ? '未配置公众号凭据，本次为模拟发送' : null,
            'article'                => $this->articlePayload($article->fresh()),
            // 微信要求 AI 生成内容显式声明，需人工在后台勾选
            'ai_declaration_reminder' => '请到公众号后台勾选『AI 生成合成内容』声明',
        ]);
    }

    /** 删除（软删，可在回收站语义下恢复）。 */
    public function destroy(Request $request, Article $article)
    {
        try {
            $this->assertTenantOwner($request, (int) $article->tenant_id);
            $article->delete();
            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('article destroy failed: ' . $e->getMessage(), ['id' => $article->id]);
            return response()->json(['ok' => false, 'error' => '删除失败：' . $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------------- 内部方法

    /** 出稿品牌：优先租户 settings.brand，回退租户名（不硬编码单一品牌）。 */
    private function defaultBrand($tenant): string
    {
        $settings = is_array($tenant->settings ?? null) ? $tenant->settings : [];
        $brand = trim((string) ($settings['brand'] ?? ''));
        return $brand !== '' ? $brand : ((string) ($tenant->name ?: '追梦'));
    }

    /**
     * 目标公众号：优先请求里的 account_id，其次文章已绑定账号，最后租户首个公众号账号。
     */
    private function resolveAccount(Request $request, $tenantId, ?Article $article = null): ?PlatformAccount
    {
        $id = (int) ($request->input('account_id') ?: ($article->wechat_account_id ?? 0));
        if ($id > 0) {
            $acc = PlatformAccount::where('platform', 'wechat')->where('id', $id)->first();
            if ($acc) {
                return $acc;
            }
        }
        return PlatformAccount::where('tenant_id', $tenantId)->where('platform', 'wechat')->first();
    }

    /** 公众号凭据是否齐备（账号级 appid/secret；缺失时 8500 会走模拟）。 */
    private function hasWechatCredentials($accounts): bool
    {
        foreach ($accounts as $acc) {
            try {
                if (! empty(app(PublishRunner::class)->credentialsExtra($acc))) {
                    return true;
                }
            } catch (\Throwable $e) {
                // 单账号凭据解密失败（如历史脏数据）不影响整页
                Log::warning('wechat credentials decode failed: ' . $e->getMessage(), ['account' => $acc->id]);
            }
        }
        return false;
    }

    /** 今日已群发数（按公众号账号统计；无账号时按租户统计）。 */
    private function todayMassSendCount($tenantId, ?PlatformAccount $account): int
    {
        $q = PublishRecord::where('platform', 'wechat')
            ->where('status', 'success')
            ->where('published_at', '>=', now()->startOfDay());

        return $account
            ? $q->where('platform_account_id', $account->id)->count()
            : $q->where('tenant_id', $tenantId)->count();
    }

    /** 落一条发布记录（publish_records 复用视频发布表，video_job_id 由迁移放宽为空）。 */
    private function recordPublish(
        $tenantId,
        Article $article,
        ?PlatformAccount $account,
        bool $simulated,
        string $url,
        ?string $error = null
    ): void {
        try {
            PublishRecord::create([
                'tenant_id'           => $tenantId,
                'video_job_id'        => null, // 文章无对应出片任务
                'platform'            => 'wechat',
                'platform_account_id' => $account ? $account->id : null,
                'status'              => $simulated ? 'manual' : 'success',
                'external_id'         => $url !== '' ? $url : ((string) $article->wechat_media_id ?: null),
                'error'               => $simulated ? '模拟发送（未真正发出）' : $error,
                'published_at'        => now(),
            ]);
        } catch (\Throwable $e) {
            // 记录失败不阻断群发主流程
            Log::warning('article publish record failed: ' . $e->getMessage(), ['id' => $article->id]);
        }
    }

    /** 统一文章输出结构（前端列表/弹窗共用）。 */
    private function articlePayload(Article $a): array
    {
        return [
            'id'                => $a->id,
            'title'             => $a->title,
            'digest'            => (string) $a->digest,
            'excerpt'           => $a->excerpt(80),
            'content'           => (string) $a->content,
            'content_html'      => (string) $a->content_html,
            'author'            => (string) $a->author,
            'tags'              => $a->tags ?: [],
            'kw_main'           => (string) $a->kw_main,
            'region'            => (string) $a->region,
            'status'            => $a->status,
            'status_label'      => $a->statusLabel(),
            'seo_score'         => (int) $a->seo_score,
            'seo_level'         => $a->seoLevel(),
            'seo_report'        => $a->seo_report,
            'word_count'        => (int) $a->word_count,
            'hit_count'         => (int) $a->hit_count,
            'word_note'         => (string) ($a->meta['word_note'] ?? ''),
            'compressed'        => (bool) ($a->meta['compressed'] ?? false),
            'wechat_media_id'   => (string) $a->wechat_media_id,
            'wechat_article_url' => (string) $a->wechat_article_url,
            'reviewed_at'       => $a->reviewed_at ? $a->reviewed_at->format('Y-m-d H:i') : null,
            'published_at'      => $a->published_at ? $a->published_at->format('Y-m-d H:i') : null,
            'created_at'        => $a->created_at ? $a->created_at->format('Y-m-d H:i') : null,
            'can_publish'       => $a->canPublish(),
        ];
    }
}
