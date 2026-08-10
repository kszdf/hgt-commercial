<x-app-layout>
<x-workspace-layout title="外观设置" :breadcrumbs="[['label' => '工作台总览', 'url' => '/dashboard'], ['label' => '外观设置']]">
<div class="mx-auto max-w-5xl space-y-6 p-6">

    @include('components.flash')

    <p class="text-sm text-slate-400">选择一套整体风格，或自由微调强调色与页面底色、菜单密度。设置仅对当前租户生效，保存后立即应用。</p>

    <!-- 预设方案 -->
    <section class="studio-card">
        <h3 class="studio-section-title mb-4">风格预设</h3>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach($presets as $key => $p)
            <label class="cursor-pointer rounded-xl border p-4 transition
                {{ $preset === $key ? 'border-brand-500 ring-2 ring-brand-100' : 'border-[var(--surface-card-border)] hover:border-brand-300' }}">
                <input type="radio" name="preset" value="{{ $key }}" class="sr-only preset-radio" {{ $preset === $key ? 'checked' : '' }}>
                <div class="mb-3 flex items-center gap-2">
                    <span class="h-6 w-6 rounded-full" style="background:{{ $p['accent'] }}"></span>
                    <span class="h-6 w-6 rounded-md border" style="background:{{ $p['page'] }}"></span>
                    <span class="ml-auto text-sm font-semibold">{{ $p['label'] }}</span>
                </div>
                <p class="text-xs text-slate-500">{{ $p['desc'] }}</p>
            </label>
            @endforeach
        </div>
    </section>

    <!-- 自由微调 DIY -->
    <section class="studio-card">
        <h3 class="studio-section-title mb-4">自由微调（DIY）</h3>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div>
                <label class="studio-field-label">强调色（覆盖品牌主色）</label>
                <div class="flex items-center gap-3">
                    <input type="color" name="accent" id="accent" value="{{ $accent ?: '#4f46e5' }}"
                        class="h-10 w-16 cursor-pointer rounded-lg border border-[var(--surface-card-border)]">
                    <input type="text" id="accentHex" value="{{ $accent ?: '' }}" placeholder="留空则用预设色"
                        class="studio-input !w-40">
                    <button type="button" onclick="clearAccent()" class="studio-btn studio-btn-secondary !px-3 !py-2 text-xs">清除</button>
                </div>
                <p class="studio-field-hint">作用于按钮、导航高亮、链接等主色。</p>
            </div>
            <div>
                <label class="studio-field-label">页面底色（覆盖背景）</label>
                <div class="flex items-center gap-3">
                    <input type="color" name="page_tint" id="pageTint" value="{{ $pageTint ?: '#ffffff' }}"
                        class="h-10 w-16 cursor-pointer rounded-lg border border-[var(--surface-card-border)]">
                    <input type="text" id="pageTintHex" value="{{ $pageTint ?: '' }}" placeholder="留空则用预设色"
                        class="studio-input !w-40">
                    <button type="button" onclick="clearPageTint()" class="studio-btn studio-btn-secondary !px-3 !py-2 text-xs">清除</button>
                </div>
                <p class="studio-field-hint">作用于页面与卡片底色。</p>
            </div>
        </div>

        <!-- 菜单密度 -->
        <div class="mt-5">
            <label class="studio-field-label">菜单密度</label>
            <div class="flex gap-3">
                <label class="cursor-pointer rounded-lg border px-4 py-2 text-sm
                    {{ $density === 'comfortable' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-[var(--surface-card-border)] text-slate-600' }}">
                    <input type="radio" name="density" value="comfortable" class="sr-only" {{ $density === 'comfortable' ? 'checked' : '' }}> 舒适（默认）
                </label>
                <label class="cursor-pointer rounded-lg border px-4 py-2 text-sm
                    {{ $density === 'compact' ? 'border-brand-500 bg-brand-50 text-brand-700' : 'border-[var(--surface-card-border)] text-slate-600' }}">
                    <input type="radio" name="density" value="compact" class="sr-only" {{ $density === 'compact' ? 'checked' : '' }}> 紧凑
                </label>
            </div>
        </div>
    </section>

    <div class="flex items-center gap-3">
        <button type="button" onclick="submitForm()" class="studio-btn studio-btn-primary">保存外观设置</button>
        <a href="/dashboard" class="studio-btn studio-btn-secondary">返回</a>
    </div>

</div>

<script>
    // 实时预览：切换预设 / 密度时立即作用于本页，保存后才持久化
    function applyPreview() {
        var preset = document.querySelector('input[name="preset"]:checked').value;
        var density = document.querySelector('input[name="density"]:checked').value;
        var root = document.documentElement;
        root.setAttribute('data-theme', preset);
        root.setAttribute('data-density', density);

        var accent = (document.getElementById('accentHex').value || '').trim();
        var pageTint = (document.getElementById('pageTintHex').value || '').trim();
        var existing = document.getElementById('diyPreview');
        if (existing) existing.remove();
        if (/^#[0-9a-fA-F]{6}$/.test(accent) || /^#[0-9a-fA-F]{6}$/.test(pageTint)) {
            var s = document.createElement('style');
            s.id = 'diyPreview';
            var css = ':root{';
            if (/^#[0-9a-fA-F]{6}$/.test(accent)) css += '--color-brand-500:' + accent + ';--color-brand-600:' + accent + ';--color-brand-700:' + accent + ';--nav-active-fg:' + accent + ';';
            if (/^#[0-9a-fA-F]{6}$/.test(pageTint)) css += '--surface-page:' + pageTint + ';';
            css += '}';
            s.textContent = css;
            document.head.appendChild(s);
        }
    }

    document.querySelectorAll('input[name="preset"], input[name="density"]').forEach(function (el) {
        el.addEventListener('change', applyPreview);
    });
    ['accent', 'pageTint'].forEach(function (id) {
        var c = document.getElementById(id), h = document.getElementById(id + 'Hex');
        if (c) c.addEventListener('input', function () { h.value = c.value; applyPreview(); });
        if (h) h.addEventListener('input', applyPreview);
    });

    function clearAccent() { document.getElementById('accent').value = '#4f46e5'; document.getElementById('accentHex').value = ''; applyPreview(); }
    function clearPageTint() { document.getElementById('pageTint').value = '#ffffff'; document.getElementById('pageTintHex').value = ''; applyPreview(); }

    function submitForm() {
        var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var f = document.createElement('form');
        f.method = 'POST';
        f.action = '/studio/settings/appearance';
        function add(name, val) {
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = name; i.value = val;
            f.appendChild(i);
        }
        add('_token', token);
        add('theme_preset', document.querySelector('input[name="preset"]:checked').value);
        add('density', document.querySelector('input[name="density"]:checked').value);
        add('accent', (document.getElementById('accentHex').value || '').trim());
        add('page_tint', (document.getElementById('pageTintHex').value || '').trim());
        document.body.appendChild(f);
        f.submit();
    }
</script>
</x-workspace-layout>
</x-app-layout>
