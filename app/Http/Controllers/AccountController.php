<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use Illuminate\Http\Request;

/**
 * 平台账号管理（多账号矩阵发布）。
 *
 * 每个租户可维护同一平台的多个发布账号：
 *  - 账号属性：平台 / 名称 / 备注 / 内容定位标签 / 每日发布上限；
 *  - 平台信息：根据平台动态收集账号信息（手机号、密码等敏感字段加密存储）；
 *  - 授权态：账号级 status（unauthorized | authorized | expired）。
 */
class AccountController extends Controller
{
    /** 可登记的平台（与 config/platforms.php 对齐；wechat 为手动发布渠道）。 */
    private const PLATFORM_KEYS = [
        'douyin', 'shipinhao', 'xiaohongshu', 'kuaishou', 'bilibili', 'youtube', 'wechat',
    ];

    /**
     * 各平台需要收集的账号信息字段。
     *
     * 字段说明：
     *  - label   : 展示名称
     *  - type    : 输入类型（text / password / tel / email）
     *  - rules   : 后端验证规则
     *  - hint    : 输入提示
     */
    public const PLATFORM_FIELDS = [
        'douyin' => [
            ['name' => 'phone',    'label' => '注册手机号', 'type' => 'tel',      'required' => true,  'rules' => ['required', 'regex:/^1[3-9]\d{9}$/'], 'hint' => '抖音登录手机号'],
            ['name' => 'password', 'label' => '登录密码',   'type' => 'password', 'required' => false, 'rules' => ['nullable', 'string', 'min:6', 'max:64'], 'hint' => '如需自动发布请填写（加密存储）'],
        ],
        'xiaohongshu' => [
            ['name' => 'phone',    'label' => '注册手机号', 'type' => 'tel',      'required' => true,  'rules' => ['required', 'regex:/^1[3-9]\d{9}$/'], 'hint' => '小红书登录手机号'],
            ['name' => 'password', 'label' => '登录密码',   'type' => 'password', 'required' => false, 'rules' => ['nullable', 'string', 'min:6', 'max:64'], 'hint' => '如需自动发布请填写（加密存储）'],
        ],
        'kuaishou' => [
            ['name' => 'phone',    'label' => '注册手机号', 'type' => 'tel',      'required' => true,  'rules' => ['required', 'regex:/^1[3-9]\d{9}$/'], 'hint' => '快手登录手机号'],
            ['name' => 'password', 'label' => '登录密码',   'type' => 'password', 'required' => false, 'rules' => ['nullable', 'string', 'min:6', 'max:64'], 'hint' => '如需自动发布请填写（加密存储）'],
        ],
        'shipinhao' => [
            ['name' => 'wechat_id', 'label' => '微信号/手机号', 'type' => 'text',     'required' => true,  'rules' => ['required', 'string', 'min:2', 'max:40'], 'hint' => '视频号绑定的微信号或手机号'],
            ['name' => 'password',  'label' => '登录密码',      'type' => 'password', 'required' => false, 'rules' => ['nullable', 'string', 'min:6', 'max:64'], 'hint' => '如需自动发布请填写（加密存储）'],
        ],
        'bilibili' => [
            ['name' => 'username', 'label' => 'B站账号/手机号', 'type' => 'text',     'required' => true,  'rules' => ['required', 'string', 'min:2', 'max:40'], 'hint' => 'B站登录账号'],
            ['name' => 'password', 'label' => '登录密码',       'type' => 'password', 'required' => false, 'rules' => ['nullable', 'string', 'min:6', 'max:64'], 'hint' => '如需自动发布请填写（加密存储）'],
        ],
        'youtube' => [
            ['name' => 'channel_id', 'label' => 'YouTube 频道 ID', 'type' => 'text', 'required' => false, 'rules' => ['nullable', 'string', 'max:80'], 'hint' => 'OAuth 授权后自动回填，可手动补充'],
        ],
        'wechat' => [
            ['name' => 'note', 'label' => '备注说明', 'type' => 'text', 'required' => false, 'rules' => ['nullable', 'string', 'max:120'], 'hint' => '视频号为手动发布渠道，仅作记录'],
        ],
    ];

    /** 账号管理页：本租户全部账号 + 今日余量。 */
    public function index(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->orderBy('platform')->orderByDesc('created_at')
            ->get();

        return view('studio.accounts', [
            'accounts' => $accounts,
            'platformKeys' => self::PLATFORM_KEYS,
            'platformFields' => self::PLATFORM_FIELDS,
        ]);
    }

    /** 发布页矩阵选择器数据源：已授权账号（含今日剩余可发数），按平台分组。 */
    public function json(Request $request)
    {
        $tenant = $this->studioTenant($request);
        $accounts = PlatformAccount::where('tenant_id', $tenant->id)
            ->where('status', 'authorized')
            ->orderBy('platform')->orderByDesc('created_at')
            ->get(['id', 'platform', 'account_name', 'avatar_url', 'remark', 'content_tags', 'daily_limit', 'today_count', 'last_published_at']);

        $grouped = $accounts->groupBy('platform')->map(fn ($items) => $items->map(fn ($a) => [
            'id' => $a->id,
            'platform' => $a->platform,
            'platform_label' => $a->platformLabel(),
            'account_name' => $a->account_name ?: $a->platformLabel(),
            'remark' => $a->remark,
            'tags' => $a->content_tags ?? [],
            'remaining_today' => $a->remainingToday(),
            'daily_limit' => (int) $a->daily_limit,
        ])->values());

        return response()->json(['ok' => true, 'platforms' => $grouped]);
    }

    /** 新增账号（未授权占位，授权走 8500 OAuth 或手动标记）。 */
    public function store(Request $request)
    {
        $tenant = $this->studioTenant($request);

        $rules = [
            'platform' => ['required', 'string', 'in:' . implode(',', self::PLATFORM_KEYS)],
            'account_name' => ['required', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:120'],
            'content_tags' => ['nullable', 'array', 'max:20'],
            'content_tags.*' => ['string', 'max:20'],
            'daily_limit' => ['nullable', 'integer', 'between:1,20'],
        ];

        foreach (self::PLATFORM_FIELDS[$request->input('platform')] ?? [] as $field) {
            $rules["account_info.{$field['name']}"] = $field['rules'];
        }

        $data = $request->validate($rules);

        PlatformAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => $data['platform'],
            'account_name' => $data['account_name'],
            'remark' => $data['remark'] ?? null,
            'content_tags' => $this->normalizeTags($data['content_tags'] ?? []),
            'daily_limit' => (int) ($data['daily_limit'] ?? 3),
            'account_info' => $this->normalizeAccountInfo($data['platform'], $data['account_info'] ?? []),
            'status' => 'unauthorized',
        ]);

        return redirect()->route('studio.accounts')->with('success', '账号已添加，请完成平台授权后即可用于发布。');
    }

    /** 编辑账号属性（备注 / 标签 / 每日上限 / 名称 / 平台信息）。 */
    public function update(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);

        $rules = [
            'account_name' => ['nullable', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:120'],
            'content_tags' => ['nullable', 'array', 'max:20'],
            'content_tags.*' => ['string', 'max:20'],
            'daily_limit' => ['nullable', 'integer', 'between:1,20'],
        ];

        foreach (self::PLATFORM_FIELDS[$account->platform] ?? [] as $field) {
            $rules["account_info.{$field['name']}"] = $field['rules'];
        }

        $data = $request->validate($rules);

        $account->update([
            'account_name' => $data['account_name'] ?? $account->account_name,
            'remark' => $data['remark'] ?? $account->remark,
            'content_tags' => $this->normalizeTags($data['content_tags'] ?? ($account->content_tags ?? [])),
            'daily_limit' => isset($data['daily_limit']) ? (int) $data['daily_limit'] : $account->daily_limit,
            'account_info' => $this->normalizeAccountInfo($account->platform, $data['account_info'] ?? ($account->account_info ?? [])),
        ]);

        return redirect()->route('studio.accounts')->with('success', '账号已更新。');
    }

    /** 标记为已授权（OAuth 成功后由前端调用，或用户自查后手动标记）。 */
    public function markAuthorized(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);
        $account->update(['status' => 'authorized', 'expires_at' => now()->addDays(60)]);
        return response()->json(['ok' => true]);
    }

    /** 标记为未授权（凭证失效等）。 */
    public function markUnauthorized(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);
        $account->update(['status' => 'unauthorized']);
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);
        $account->delete();
        return redirect()->route('studio.accounts')->with('success', '账号已删除。');
    }

    /** 规范化标签：去重、去空、去前后空白、限制数量。 */
    private function normalizeTags(array $tags): array
    {
        $normalized = collect($tags)
            ->map(fn ($t) => trim((string) $t))
            ->filter(fn ($t) => $t !== '')
            ->unique()
            ->take(20)
            ->values()
            ->all();

        return $normalized;
    }

    /** 规范化平台信息：只保留该平台配置的字段，去除空值。 */
    private function normalizeAccountInfo(string $platform, array $info): array
    {
        $allowed = collect(self::PLATFORM_FIELDS[$platform] ?? [])->pluck('name')->all();
        return collect($info)
            ->only($allowed)
            ->map(fn ($v) => is_string($v) ? trim($v) : $v)
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->all();
    }
}
