<?php

namespace App\Http\Controllers;

use App\Exceptions\PipelineUnavailableException;
use App\Models\PlatformAccount;
use App\Services\PipelineClient;
use Illuminate\Http\Request;

/**
 * 平台账号管理（多账号矩阵发布）。
 *
 *  - 账号属性：平台 / 名称 / 备注 / 内容定位标签 / 每日发布上限；
 *  - 应用凭证（AppID/AppSecret 等）经 account_info 加密存储，不落明文；
 *  - 抖音/小红书走 OAuth 授权码模式（本控制器代理 8500 授权路由）；
 *  - 公众号（wechat）走 client_credential 模式，填写 AppID/AppSecret 即视为已授权。
 */
class AccountController extends Controller
{
    /** 可登记的平台（wechat=公众号，shipinhao=视频号）。 */
    private const PLATFORM_KEYS = [
        'douyin', 'shipinhao', 'xiaohongshu', 'kuaishou', 'bilibili', 'youtube', 'wechat',
    ];

    /** 账号管理页：本租户全部渠道备忘 + 今日余量。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->orderBy('platform')->orderByDesc('created_at')
            ->get();

        return view('studio.accounts', [
            'accounts' => $accounts,
            'platformKeys' => self::PLATFORM_KEYS,
        ]);
    }

    /** 发布页矩阵选择器数据源（保留接口兼容，返回渠道备忘列表）。 */
    public function json(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->orderBy('platform')->orderByDesc('created_at')
            ->get(['id', 'platform', 'account_name', 'avatar_url', 'remark', 'content_tags', 'daily_limit', 'today_count', 'last_published_at']);

        $grouped = $accounts->groupBy('platform')->map(fn ($items) => $items->map(fn ($a) => [
            'id' => $a->id,
            'platform' => $a->platform,
            'platform_label' => $a->platformLabel(),
            'account_name' => $a->account_name ?: $a->platformLabel(),
            'remark' => $a->remark,
            'tags' => $a->content_tags ?? [],
            'daily_limit' => (int) $a->daily_limit,
        ])->values());

        return response()->json(['ok' => true, 'platforms' => $grouped]);
    }

    /** 新增渠道（含应用凭证，经 account_info 加密存储）。 */
    public function store(Request $request)
    {
        $tenant = $this->studioTenant($request);

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:' . implode(',', self::PLATFORM_KEYS)],
            'account_name' => ['required', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:120'],
            'content_tags' => ['nullable', 'array', 'max:20'],
            'content_tags.*' => ['string', 'max:20'],
            'daily_limit' => ['nullable', 'integer', 'between:1,20'],
            'account_info' => ['nullable', 'array'],
        ]);

        $info = $this->sanitizeAccountInfo($data['account_info'] ?? []);

        PlatformAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => $data['platform'],
            'account_name' => $data['account_name'],
            'remark' => $data['remark'] ?? null,
            'content_tags' => $this->normalizeTags($data['content_tags'] ?? []),
            'daily_limit' => (int) ($data['daily_limit'] ?? 3),
            'status' => $this->statusFor($data['platform'], $info),
            'account_info' => $info,
        ]);

        return redirect()->route('studio.accounts')->with('success', '渠道已添加（凭证已加密保存）。');
    }

    /** 编辑渠道（备注 / 标签 / 每日上限 / 名称 / 凭证）。 */
    public function update(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);

        $data = $request->validate([
            'account_name' => ['nullable', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:120'],
            'content_tags' => ['nullable', 'array', 'max:20'],
            'content_tags.*' => ['string', 'max:20'],
            'daily_limit' => ['nullable', 'integer', 'between:1,20'],
            'account_info' => ['nullable', 'array'],
        ]);

        $update = [
            'account_name' => $data['account_name'] ?? $account->account_name,
            'remark' => $data['remark'] ?? $account->remark,
            'content_tags' => $this->normalizeTags($data['content_tags'] ?? ($account->content_tags ?? [])),
            'daily_limit' => isset($data['daily_limit']) ? (int) $data['daily_limit'] : $account->daily_limit,
        ];
        // 凭证：只在提交了 account_info 时才更新（避免空提交覆盖已有凭证）
        if (array_key_exists('account_info', $data) && $data['account_info'] !== null) {
            $info = $this->sanitizeAccountInfo($data['account_info']);
            $update['account_info'] = $info;
            // 公众号：填写/清空凭证直接决定授权态（client_credential 模式）
            if ($account->platform === 'wechat') {
                $update['status'] = $info ? 'authorized' : 'active';
            }
        }
        $account->update($update);

        return redirect()->route('studio.accounts')->with('success', '渠道已更新。');
    }

    public function destroy(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);
        $account->delete();
        return redirect()->route('studio.accounts')->with('success', '渠道备忘已删除。');
    }

    /** 规范化标签：去重、去空、去前后空白、限制数量。 */
    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(fn ($t) => trim((string) $t))
            ->filter(fn ($t) => $t !== '')
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    /** 应用凭证白名单（各平台键名），只存这些，过滤无关字段。 */
    private const CREDENTIAL_KEYS = [
        'client_key', 'client_secret',     // 抖音
        'app_id', 'app_secret',            // 小红书 / 公众号
        'appid', 'secret',                 // 视频号 / 公众号(微信命名)
    ];

    /** 只保留白名单凭证键，去空值；无有效凭证返回 null。 */
    private function sanitizeAccountInfo(array $info): ?array
    {
        $clean = array_intersect_key($info, array_flip(self::CREDENTIAL_KEYS));
        $clean = array_filter($clean, fn ($v) => is_string($v) && trim($v) !== '');
        return $clean ?: null;
    }

    /** 新增账号的初始授权态：公众号有凭证即授权，其余待 OAuth/手动。 */
    private function statusFor(string $platform, ?array $info): string
    {
        return ($platform === 'wechat' && ! empty($info)) ? 'authorized' : 'active';
    }

    /** OAuth 授权入口：代理 8500 /oauth/authorize/{platform}?account_id={id}，返回 authorize_url。 */
    public function oauthAuthorize(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);
        if (! in_array($account->platform, ['douyin', 'xiaohongshu'], true)) {
            return response()->json(['error' => '该平台不支持 OAuth 授权（仅抖音/小红书）'], 422);
        }
        try {
            $resp = app(PipelineClient::class)->get(
                '/oauth/authorize/' . $account->platform . '?account_id=' . $account->id, 30
            );
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '授权服务暂时不可用，请稍后重试'], 503);
        }
        if (! $resp->successful()) {
            return response()->json(['error' => '授权服务返回错误，请确认微服务已启动'], 502);
        }
        return response()->json($resp->json());
    }

    /** OAuth 授权确认：校验 8500 账号级 token 已落地后，把账号标记为已授权。 */
    public function oauthConfirm(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);
        if (! in_array($account->platform, ['douyin', 'xiaohongshu'], true)) {
            return response()->json(['error' => '该平台不支持 OAuth 授权'], 422);
        }
        $accountKey = $account->platform . ':' . $account->id;
        try {
            $resp = app(PipelineClient::class)->get(
                '/oauth/status/' . $account->platform . '?account_key=' . $accountKey, 30
            );
        } catch (PipelineUnavailableException $e) {
            return response()->json(['error' => '授权校验服务暂时不可用'], 503);
        }
        $authorized = $resp->successful() && ($resp->json('authorized') ?? false) === true;
        if (! $authorized) {
            return response()->json(['error' => '尚未检测到授权结果，请先在弹窗中完成授权'], 422);
        }
        $account->update(['status' => 'authorized']);
        return response()->json(['ok' => true, 'authorized' => true]);
    }
}
