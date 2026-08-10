<x-app-layout>
<x-workspace-layout title="账号安全" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '账号安全']]">
<div class="mx-auto max-w-2xl space-y-6 p-6">

    @include('components.flash')

    @if (isset($errors) && $errors->any())
        <div class="mb-4 space-y-1 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
            @foreach ($errors->all() as $err)
                <div>{{ $err }}</div>
            @endforeach
        </div>
    @endif

    <section class="studio-card">
        <h3 class="studio-section-title mb-4">修改登录密码</h3>
        <p class="mb-5 text-sm text-slate-400">为安全起见，修改密码需先验证当前密码。新密码至少 6 位，且需含大小写字母，或数字与特殊字符组合。</p>

        <form class="space-y-4" method="POST" action="/settings/password">
            @csrf
            <flux:input label="当前密码" name="current_password" type="password" placeholder="••••••••" required />
            <flux:input label="新密码" name="password" type="password" placeholder="••••••••" required />
            <flux:input label="确认新密码" name="password_confirmation" type="password" placeholder="••••••••" required />

            <div class="pt-2">
                <flux:button variant="primary" type="submit" class="!bg-brand-500 hover:!bg-brand-600 !shadow-sm">保存新密码</flux:button>
            </div>
        </form>
    </section>

    <section class="studio-card">
        <h3 class="studio-section-title mb-3">找回密码方式</h3>
        <p class="text-sm leading-relaxed text-slate-500">
            若忘记密码，可在登录页点击「忘记密码」，通过
            <span class="font-medium text-slate-700">手机号短信</span>
            或
            <span class="font-medium text-slate-700">邮箱验证码</span>
            两种方式重置。两种方式均已在注册时采集，无需额外设置。
        </p>
    </section>

</div>
</x-workspace-layout>
</x-app-layout>
