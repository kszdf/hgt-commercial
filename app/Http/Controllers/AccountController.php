<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use Illuminate\Http\Request;

/**
 * 平台账号管理（发布渠道备忘）。
 *
 * 自动发布（OAuth 授权 / 一键群发）已停用，本模块仅作为「你在哪些平台发」的渠道备忘：
 *  - 账号属性：平台 / 名称 / 备注 / 内容定位标签 / 每日发布上限；
 *  - 不收集任何账号密码等敏感信息；正式发布在各平台 App 手动完成。
 */
class AccountController extends Controller
{
    /** 可登记的平台（与 config/platforms.php 对齐；wechat 为手动发布渠道）。 */
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

    /** 新增渠道备忘（不收集敏感信息）。 */
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
        ]);

        PlatformAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => $data['platform'],
            'account_name' => $data['account_name'],
            'remark' => $data['remark'] ?? null,
            'content_tags' => $this->normalizeTags($data['content_tags'] ?? []),
            'daily_limit' => (int) ($data['daily_limit'] ?? 3),
            'status' => 'active',
        ]);

        return redirect()->route('studio.accounts')->with('success', '渠道备忘已添加。');
    }

    /** 编辑渠道备忘属性（备注 / 标签 / 每日上限 / 名称）。 */
    public function update(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);

        $data = $request->validate([
            'account_name' => ['nullable', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:120'],
            'content_tags' => ['nullable', 'array', 'max:20'],
            'content_tags.*' => ['string', 'max:20'],
            'daily_limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        $account->update([
            'account_name' => $data['account_name'] ?? $account->account_name,
            'remark' => $data['remark'] ?? $account->remark,
            'content_tags' => $this->normalizeTags($data['content_tags'] ?? ($account->content_tags ?? [])),
            'daily_limit' => isset($data['daily_limit']) ? (int) $data['daily_limit'] : $account->daily_limit,
        ]);

        return redirect()->route('studio.accounts')->with('success', '渠道备忘已更新。');
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
}
