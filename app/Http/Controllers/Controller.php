<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     * 操作上下文租户：超级管理员(tenant_id=null)回退到 pro/enterprise 租户作为演示/操作上下文，
     * 避免超管访问 studio 创作页因 $user->tenant 为 null 而 500。
     * 普通用户返回本租户。
     */
    protected function studioTenant(Request $request): Tenant
    {
        $user = $request->user();
        if ($user && $user->isGlobalAdmin()) {
            return Tenant::whereIn('plan', ['pro', 'enterprise'])->first()
                ?? Tenant::first()
                ?? new Tenant();
        }
        return $user->tenant;
    }

    /**
     * 跨租户资源鉴权：超级管理员放行，普通用户仅限本租户。
     * 管理视角下超管可操作任意租户资源（视频/声音/封面/模特等）。
     */
    protected function assertTenantOwner(Request $request, int $ownerTenantId): void
    {
        $user = $request->user();
        if ($user->isGlobalAdmin()) {
            return;
        }
        if ($ownerTenantId !== $user->tenant_id) {
            abort(403);
        }
    }
}
