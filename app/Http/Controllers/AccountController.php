<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use Illuminate\Http\Request;

/**
 * 平台账号管理（多账号矩阵发布）。
 *
 * 每个租户可维护同一平台的多个发布账号：
 *  - 账号属性：平台 / 名称 / 备注 / 内容定位标签 / 每日发布上限；
 *  - 授权态：账号级 status（unauthorized | authorized | expired）；
 *    OAuth 流程在 8500 侧按 (platform, account_id) 键控 token（见实施说明-功能包一），
 *    Laravel 侧提供「标记已授权」入口，授权弹窗 postMessage 回传 account_id 后自动调用。
 */
class AccountController extends Controller
{
    /** 可登记的平台（与 config/platforms.php 对齐；wechat 为手动发布渠道）。 */
    private const PLATFORM_KEYS = [
        'douyin', 'shipinhao', 'xiaohongshu', 'kuaishou', 'bilibili', 'youtube', 'wechat',
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
            'tags' => PlatformAccount::CONTENT_TAGS,
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

        $data = $request->validate([
            'platform' => ['required', 'string', 'in:' . implode(',', self::PLATFORM_KEYS)],
            'account_name' => ['required', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:120'],
            'content_tags' => ['nullable', 'array'],
            'content_tags.*' => ['string', 'max:20'],
            'daily_limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        PlatformAccount::create([
            'tenant_id' => $tenant->id,
            'platform' => $data['platform'],
            'account_name' => $data['account_name'],
            'remark' => $data['remark'] ?? null,
            'content_tags' => $data['content_tags'] ?? [],
            'daily_limit' => (int) ($data['daily_limit'] ?? 3),
            'status' => 'unauthorized',
        ]);

        return redirect()->route('studio.accounts')->with('success', '账号已添加，请完成平台授权后即可用于发布。');
    }

    /** 编辑账号属性（备注 / 标签 / 每日上限 / 名称）。 */
    public function update(Request $request, PlatformAccount $account)
    {
        $this->assertTenantOwner($request, $account->tenant_id);

        $data = $request->validate([
            'account_name' => ['nullable', 'string', 'max:60'],
            'remark' => ['nullable', 'string', 'max:120'],
            'content_tags' => ['nullable', 'array'],
            'content_tags.*' => ['string', 'max:20'],
            'daily_limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        $account->update([
            'account_name' => $data['account_name'] ?? $account->account_name,
            'remark' => $data['remark'] ?? $account->remark,
            'content_tags' => $data['content_tags'] ?? $account->content_tags ?? [],
            'daily_limit' => isset($data['daily_limit']) ? (int) $data['daily_limit'] : $account->daily_limit,
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
}
