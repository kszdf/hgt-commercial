@section('title', '租户管理 · 追梦')
<x-app-layout>
<div class="min-h-screen bg-slate-50">
    <div class="mx-auto max-w-6xl p-6">
        <header class="mb-5 flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-slate-800">租户（试用账号）管理</h2>
                <p class="mt-0.5 text-sm text-slate-400">超级管理员专用 · 可创建任意数量试用账号并单独配置其权限</p>
            </div>
            <a href="/admin/monitor" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-500 hover:border-brand-300 hover:text-brand-600 transition">返回监控大盘</a>
        </header>

        @if (session('success'))
            <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-2.5 text-sm text-green-700">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-700">{{ session('error') }}</div>
        @endif

        <!-- 新建试用账号 -->
        <section class="luxury-glass mb-5 p-5">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">新建试用账号</h3>
            <form method="POST" action="{{ route('admin.tenants.store') }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @csrf
                <div>
                    <label class="mb-1 block text-xs text-slate-500">企业 / 团队名称 *</label>
                    <input name="tenant_name" required value="{{ old('tenant_name') }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">管理员姓名 *</label>
                    <input name="name" required value="{{ old('name') }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">登录邮箱（选填）</label>
                    <input name="email" type="email" value="{{ old('email') }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                    <p class="mt-1 text-xs text-slate-400">选填：用于客户「忘记密码」邮箱找回；不填则只能由你重置密码</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">手机号 *</label>
                    <input name="phone" required value="{{ old('phone') }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">登录密码 *（8-16位，含大写/小写/数字/特殊字符中至少两种）</label>
                    <input name="password" type="text" required class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">试用天数 *</label>
                    <input name="trial_days" type="number" min="1" max="30" required value="{{ old('trial_days', $defaults['days']) }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                    <p class="mt-1 text-xs text-slate-400">有效期最长 30 天，过期无效（系统硬上限）。</p>
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">累计生成条数上限 *（0=不限）</label>
                    <input name="trial_max_jobs" type="number" min="0" required value="{{ old('trial_max_jobs', $defaults['max_jobs']) }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-slate-500">累计生成时长上限（分钟）*（0=不限）</label>
                    <input name="trial_max_minutes" type="number" min="0" required value="{{ old('trial_max_minutes', $defaults['max_minutes']) }}" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-brand-400 focus:outline-none">
                </div>
                <div class="flex items-end">
                    <label class="flex cursor-pointer items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="allow_batch" value="1" {{ old('allow_batch', $defaults['allow_batch']) ? 'checked' : '' }} class="h-4 w-4 rounded border-slate-300 text-brand-600">
                        开放批量外发权限
                    </label>
                </div>
                <div class="flex items-end">
                    <button class="rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">创建试用账号</button>
                </div>
            </form>
        </section>

        <!-- 租户列表 -->
        <section class="luxury-glass p-5">
            <h3 class="mb-3 text-sm font-semibold text-slate-700">全部租户（{{ count($rows) }}）</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-slate-400">
                        <tr>
                            <th class="py-2 pr-3 font-medium">租户</th>
                            <th class="py-2 pr-3 font-medium">套餐</th>
                            <th class="py-2 pr-3 font-medium">试用剩余</th>
                            <th class="py-2 pr-3 font-medium">月度用量</th>
                            <th class="py-2 pr-3 font-medium">累计条数</th>
                            <th class="py-2 pr-3 font-medium">累计时长(分)</th>
                            <th class="py-2 pr-3 font-medium">批量外发</th>
                            <th class="py-2 pr-3 font-medium">权限编辑</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($rows as $r)
                            <tr class="hover:bg-slate-50/50 align-top">
                                <td class="py-2.5 pr-3">
                                    <div class="font-medium text-slate-700">{{ $r['name'] }}</div>
                                    <div class="text-xs text-slate-400">{{ $r['admin_email'] ?: '—' }}</div>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <span class="rounded-full {{ $r['plan']==='free' ? 'bg-amber-50 text-amber-600' : 'bg-emerald-50 text-emerald-600' }} px-2 py-0.5 text-xs font-medium">{{ $r['plan_label'] }}</span>
                                    @if($r['status']!=='active')<span class="ml-1 rounded-full bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">{{ $r['status'] }}</span>@endif
                                </td>
                                <td class="py-2.5 pr-3 text-slate-600">
                                    @if($r['plan']==='free' && $r['trial_days_left']!==null)
                                        <span class="{{ $r['trial_days_left']<=3 ? 'text-red-600 font-medium' : 'text-slate-600' }}">{{ $r['trial_days_left'] }} 天</span>
                                        <div class="text-xs text-slate-400">{{ $r['trial_ends_at'] }}</div>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-3 text-slate-600">{{ $r['usage_month'] }} / {{ $r['quota_monthly'] ?: '∞' }}</td>
                                <td class="py-2.5 pr-3 text-slate-600">
                                    @if($r['trial_max_jobs']>0)
                                        {{ $r['trial_jobs_used'] }} / {{ $r['trial_max_jobs'] }}
                                    @else <span class="text-slate-400">不限</span> @endif
                                </td>
                                <td class="py-2.5 pr-3 text-slate-600">
                                    @if($r['trial_max_minutes']>0)
                                        {{ $r['trial_minutes_used'] }} / {{ $r['trial_max_minutes'] }}
                                    @else <span class="text-slate-400">不限</span> @endif
                                </td>
                                <td class="py-2.5 pr-3">
                                    <span class="rounded-full {{ $r['allow_batch'] ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400' }} px-2 py-0.5 text-xs font-medium">{{ $r['allow_batch'] ? '已开放' : '关闭' }}</span>
                                </td>
                                <td class="py-2.5 pr-3">
                                    <form method="POST" action="{{ route('admin.tenants.update-trial', $r['id']) }}" class="flex flex-wrap items-center gap-1.5">
                                        @csrf
                                        <input name="trial_days" type="number" min="1" max="30" title="试用天数(最长30)" value="{{ $r['trial_days_left'] ?? 7 }}" class="w-16 rounded border border-slate-200 px-2 py-1 text-xs">
                                        <input name="trial_max_jobs" type="number" min="0" title="累计条数(0=不限)" value="{{ $r['trial_max_jobs'] }}" class="w-16 rounded border border-slate-200 px-2 py-1 text-xs">
                                        <input name="trial_max_minutes" type="number" min="0" title="累计时长分钟(0=不限)" value="{{ $r['trial_max_minutes'] }}" class="w-20 rounded border border-slate-200 px-2 py-1 text-xs">
                                        <label class="flex items-center gap-1 text-xs text-slate-500" title="批量外发">
                                            <input type="checkbox" name="allow_batch" value="1" {{ $r['allow_batch'] ? 'checked' : '' }} class="h-3.5 w-3.5 rounded border-slate-300">批量
                                        </label>
                                        <button class="rounded bg-brand-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-brand-700 transition">保存</button>
                                    </form>
                                    {{-- 重置密码兜底：客户未填邮箱无法自助找回时由超管重置 --}}
                                    <button type="button" onclick="hgtResetPwd('{{ $r['id'] }}', '{{ addslashes($r['name']) }}')"
                                        class="mt-1.5 rounded border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-100">重置密码</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-4 text-center text-slate-400">暂无租户</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-slate-400">说明：「累计条数 / 累计时长」为整个试用期内可生成的总上限（仅对 free 套餐生效）；月度用量为每月重置的额度。编辑后即时生效，下次生成即按新限额校验。</p>
        </section>
    </div>
</div>

{{-- 重置密码弹窗 --}}
<div id="resetPwdModal" class="fixed inset-0 z-50 hidden" aria-modal="true">
    <div class="absolute inset-0 bg-black/40"></div>
    <div class="absolute left-1/2 top-1/2 w-full max-w-sm -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
        <h3 class="text-base font-semibold text-slate-800">重置密码</h3>
        <p class="mt-1 text-sm text-slate-500">为租户 <span id="rpTenantName" class="font-medium text-slate-700"></span> 的管理员设置新密码。重置后请将新密码告知客户。</p>
        <form id="rpForm" method="POST" action="" class="mt-4 space-y-3">
            @csrf
            <div>
                <label class="mb-1 block text-sm text-slate-600">新密码</label>
                <input type="password" name="password" id="rpPassword" required minlength="8" maxlength="16"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
                <p class="mt-1 text-xs text-slate-400">8-16 位，且由大写、小写、数字、特殊字符中至少两种组合。</p>
            </div>
            <div>
                <label class="mb-1 block text-sm text-slate-600">确认新密码</label>
                <input type="password" name="password_confirmation" required minlength="8" maxlength="16"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200">
            </div>
            <div class="flex justify-end gap-2 pt-1">
                <button type="button" onclick="document.getElementById('resetPwdModal').classList.add('hidden')"
                    class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">取消</button>
                <button type="submit" class="rounded-lg bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600">确认重置</button>
            </div>
        </form>
    </div>
</div>

<script>
function hgtResetPwd(tenantId, tenantName) {
    document.getElementById('rpTenantName').textContent = tenantName;
    document.getElementById('rpForm').action = '/admin/tenants/' + tenantId + '/reset-password';
    document.getElementById('rpPassword').value = '';
    document.getElementById('rpForm').querySelector('input[name="password_confirmation"]').value = '';
    document.getElementById('resetPwdModal').classList.remove('hidden');
}
</script>
</x-app-layout>
