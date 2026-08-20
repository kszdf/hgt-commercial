<x-app-layout>
<x-workspace-layout title="平台账号">
<div class="mx-auto max-w-5xl p-6">

    @include('components.flash')

    {{-- 说明 --}}
    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm font-semibold text-slate-700">多账号矩阵发布</div>
        <ul class="mt-1 space-y-1 text-sm text-slate-500">
            <li>· 抖音、小红书等平台可以添加 <strong>多个账号</strong>：既可以一条视频同时发到多个号（打矩阵），也可以不同视频指定不同账号发布。</li>
            <li>· 每个账号可设置「内容定位标签」和「每日发布上限」，防止同一内容过多账号同质化发布被平台风控。</li>
            <li>· 授权：填写平台账号信息并添加后，点击「去授权」完成平台授权；完成后点击「标记为已授权」即可用于发布。</li>
        </ul>
    </div>

    {{-- 新增账号按钮 --}}
    <div class="luxury-glass mb-5 p-5">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-slate-700">添加新账号</h3>
                <p class="text-xs text-slate-500">按平台要求填写账号信息，系统将加密保存。</p>
            </div>
            <button type="button" onclick="openAccountModal('add')"
                class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">添加账号</button>
        </div>
    </div>

    {{-- 账号列表 --}}
    <div class="luxury-glass overflow-hidden">
        <div class="px-5 py-4 text-sm font-semibold text-slate-700">我的账号（{{ $accounts->count() }}）</div>
        @if($accounts->isEmpty())
            <div class="px-5 pb-6 text-center text-sm text-slate-400">还没有账号，点击上方「添加账号」创建一个。</div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($accounts as $a)
                    <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-800">{{ $a->account_name ?: $a->platformLabel() }}</span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $a->platformLabel() }}</span>
                                @if($a->isAuthorized())
                                    <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">已授权</span>
                                @else
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-400">未授权</span>
                                @endif
                                <span class="text-xs text-slate-400">今日余量 {{ $a->remainingToday() }}/{{ $a->daily_limit }} 条</span>
                            </div>
                            @if($a->remark)
                                <p class="mt-0.5 truncate text-xs text-slate-400">{{ $a->remark }}</p>
                            @endif
                            @if(!empty($a->content_tags))
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach($a->content_tags as $t)
                                        <span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] text-brand-600">#{{ $t }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @php $publicBase = env('PYTHON_PIPELINE_PUBLIC_URL', 'http://127.0.0.1:8500'); @endphp
                            <button type="button" class="oauth-btn rounded-md bg-brand-600 px-2.5 py-1 text-[11px] font-medium text-white hover:bg-brand-700"
                                    data-auth-url="{{ $publicBase }}/oauth/authorize/{{ $a->platform }}?account_id={{ $a->id }}"
                                    data-account-id="{{ $a->id }}">去授权</button>
                            @if(!$a->isAuthorized())
                                <form method="POST" action="{{ route('studio.accounts.authorized', $a) }}">
                                    @csrf
                                    <button class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50">标记为已授权</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('studio.accounts.unauthorized', $a) }}">
                                    @csrf
                                    <button class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-400 hover:bg-slate-50">标记未授权</button>
                                </form>
                            @endif
                            <button type="button"
                                onclick='openAccountModal("edit", @json([
                                    "id" => $a->id,
                                    "platform" => $a->platform,
                                    "account_name" => $a->account_name,
                                    "remark" => $a->remark,
                                    "content_tags" => $a->content_tags ?? [],
                                    "daily_limit" => $a->daily_limit,
                                    "account_info" => $a->account_info ?? [],
                                ]))'
                                class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50">编辑</button>
                            <form method="POST" action="{{ route('studio.accounts.destroy', $a) }}" onsubmit="return confirm('确认删除该账号？删除后不可恢复。')">
                                @csrf
                                @method('DELETE')
                                <button class="rounded-md border border-red-100 bg-red-50 px-2.5 py-1 text-[11px] text-red-600 hover:bg-red-100">删除</button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

{{-- 账号信息弹窗（新增 / 编辑共用） --}}
<div id="accountModal" class="fixed inset-0 z-50 hidden" aria-modal="true">
    <div class="absolute inset-0 bg-black/40" onclick="closeAccountModal()"></div>
    <div class="absolute left-1/2 top-1/2 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
            <h3 id="modalTitle" class="text-base font-semibold text-slate-800">添加账号</h3>
            <button type="button" onclick="closeAccountModal()" class="text-slate-400 hover:text-slate-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form id="accountForm" method="POST" action="{{ route('studio.accounts') }}" class="space-y-4 text-sm">
            @csrf
            <input type="hidden" id="modalMethod" value="">
            <input type="hidden" id="accountId" value="">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-slate-600">平台 <span class="text-red-500">*</span></label>
                    <select id="platformSelect" name="platform" required class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"
                        onchange="onPlatformChange(this.value)">
                        @foreach($platformKeys as $k)
                            <option value="{{ $k }}">{{ \App\Models\PlatformAccount::PLATFORM_LABELS[$k] ?? $k }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-slate-600">账号名称 <span class="text-red-500">*</span></label>
                    <input type="text" name="account_name" id="accountName" required maxlength="60" placeholder="例如：慧根堂主号"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-slate-600">每日发布上限（条/天）</label>
                <input type="number" name="daily_limit" id="dailyLimit" min="1" max="20" value="3"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>

            {{-- 内容定位标签：自定义输入 --}}
            <div>
                <label class="mb-1 block text-slate-600">内容定位标签 <span class="text-xs text-slate-400">（最多 20 个，回车添加）</span></label>
                <div class="rounded-lg border border-slate-200 bg-white px-3 py-2">
                    <div id="tagContainer" class="mb-2 flex flex-wrap gap-2"></div>
                    <input type="text" id="tagInput" maxlength="20" placeholder="输入标签后按回车"
                        class="w-full outline-none text-slate-700">
                </div>
                <div id="tagInputs" class="hidden"></div>
                <p id="tagHint" class="mt-1 text-xs text-slate-400">已添加 0/20 个标签</p>
            </div>

            {{-- 平台信息字段（动态生成） --}}
            <div id="platformFields" class="space-y-3 rounded-lg border border-slate-100 bg-slate-50 p-3">
                {{-- JS 动态填充 --}}
            </div>

            <div>
                <label class="mb-1 block text-slate-600">备注（选填）</label>
                <input type="text" name="remark" id="remark" maxlength="120" placeholder="例如：主推留资转化、粉丝 1.2w"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
            </div>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeAccountModal()" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">取消</button>
                <button type="submit" class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">保存</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    const platformFields = @json($platformFields);
    let currentTags = [];
    let currentMode = 'add';

    function openAccountModal(mode, data) {
        currentMode = mode;
        currentTags = [];
        document.getElementById('modalTitle').textContent = mode === 'edit' ? '编辑账号' : '添加账号';

        const form = document.getElementById('accountForm');
        const methodInput = document.getElementById('modalMethod');
        const idInput = document.getElementById('accountId');

        if (mode === 'edit' && data) {
            form.action = '/studio/accounts/' + data.id;
            methodInput.value = 'PUT';
            idInput.value = data.id;
            document.getElementById('platformSelect').value = data.platform;
            document.getElementById('platformSelect').disabled = true;
            document.getElementById('accountName').value = data.account_name || '';
            document.getElementById('dailyLimit').value = data.daily_limit || 3;
            document.getElementById('remark').value = data.remark || '';
            currentTags = Array.isArray(data.content_tags) ? data.content_tags : [];
            renderPlatformFields(data.platform, data.account_info || {});
        } else {
            form.action = '{{ route('studio.accounts') }}';
            methodInput.value = '';
            idInput.value = '';
            document.getElementById('platformSelect').value = '{{ $platformKeys[0] ?? 'douyin' }}';
            document.getElementById('platformSelect').disabled = false;
            document.getElementById('accountName').value = '';
            document.getElementById('dailyLimit').value = 3;
            document.getElementById('remark').value = '';
            renderPlatformFields(document.getElementById('platformSelect').value, {});
        }

        renderTags();
        document.getElementById('accountModal').classList.remove('hidden');
    }

    function closeAccountModal() {
        document.getElementById('accountModal').classList.add('hidden');
    }

    function onPlatformChange(platform) {
        renderPlatformFields(platform, {});
    }

    function renderPlatformFields(platform, values) {
        const container = document.getElementById('platformFields');
        const fields = platformFields[platform] || [];

        if (fields.length === 0) {
            container.innerHTML = '<p class="text-xs text-slate-400">该平台无需额外账号信息。</p>';
            return;
        }

        container.innerHTML = '<div class="mb-1 text-xs font-medium text-slate-500">平台账号信息</div>';
        fields.forEach(field => {
            const wrapper = document.createElement('div');
            const requiredMark = field.required ? ' <span class="text-red-500">*</span>' : '';
            const value = values[field.name] || '';
            wrapper.innerHTML = `
                <label class="mb-1 block text-slate-600">${field.label}${requiredMark}</label>
                <input type="${field.type}" name="account_info[${field.name}]" value="${escapeHtml(value)}"
                    placeholder="${escapeHtml(field.hint)}"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700"
                    ${field.required ? 'required' : ''}>
                <p class="mt-0.5 text-[10px] text-slate-400">${escapeHtml(field.hint)}</p>
            `;
            container.appendChild(wrapper);
        });
    }

    function renderTags() {
        const container = document.getElementById('tagContainer');
        const inputs = document.getElementById('tagInputs');
        container.innerHTML = '';
        inputs.innerHTML = '';

        currentTags.forEach((tag, index) => {
            const span = document.createElement('span');
            span.className = 'inline-flex items-center gap-1 rounded-full bg-brand-50 px-2 py-1 text-xs text-brand-600';
            span.innerHTML = `${escapeHtml(tag)}<button type="button" onclick="removeTag(${index})" class="text-brand-400 hover:text-brand-700">×</button>`;
            container.appendChild(span);

            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'content_tags[]';
            hidden.value = tag;
            inputs.appendChild(hidden);
        });

        document.getElementById('tagHint').textContent = `已添加 ${currentTags.length}/20 个标签`;
        document.getElementById('tagHint').className = currentTags.length >= 20
            ? 'mt-1 text-xs text-amber-600'
            : 'mt-1 text-xs text-slate-400';
    }

    function addTag(value) {
        const raw = value.trim();
        if (!raw) return;
        if (currentTags.length >= 20) return;
        if (currentTags.includes(raw)) return;
        if (raw.length > 20) {
            alert('单个标签最多 20 个字符');
            return;
        }
        currentTags.push(raw);
        renderTags();
    }

    function removeTag(index) {
        currentTags.splice(index, 1);
        renderTags();
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    document.getElementById('tagInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addTag(this.value);
            this.value = '';
        }
    });

    document.getElementById('accountForm').addEventListener('submit', function (e) {
        if (currentMode === 'edit') {
            // Laravel 不支持 PUT 表单，追加 _method
            const method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'PUT';
            this.appendChild(method);
        }
    });

    // 平台授权弹窗 + 回调（8500 授权成功后 postMessage 回传 account_id → 自动标记已授权）
    document.querySelectorAll('.oauth-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var w = 640, h = 720;
            var left = (window.screen.width - w) / 2, top = (window.screen.height - h) / 2;
            window.open(btn.getAttribute('data-auth-url'), 'oauth_popup',
                'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top);
        });
    });
    window.addEventListener('message', function (e) {
        var d = e.data || {};
        if (d.type === 'oauth_authorized') {
            var aid = d.account_id;
            if (!aid) { location.reload(); return; }
            var token = document.querySelector('meta[name="csrf-token"]')?.content || '';
            fetch('/studio/accounts/' + aid + '/authorized', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: new URLSearchParams({})
            }).then(function () { location.reload(); });
        }
    });
</script>
@endpush
</x-workspace-layout>
</x-app-layout>
