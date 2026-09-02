<x-app-layout>
<x-workspace-layout title="发布渠道">
<div class="mx-auto max-w-5xl p-6">

    @include('components.flash')

    {{-- 说明 --}}
    <div class="mb-5 rounded-xl border border-slate-200 bg-white px-4 py-3">
        <div class="text-sm font-semibold text-slate-700">发布渠道</div>
        <p class="mt-0.5 text-sm text-slate-500">登记各平台发布账号，统一管理名称 / 标签 / 每日上限。抖音、小红书 OAuth 授权后自动发布；视频号人工发布。</p>
    </div>

    {{-- 新增渠道按钮 --}}
    <div class="luxury-glass mb-5 p-5">
        <div class="flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-slate-700">添加渠道</h3>
                <p class="text-xs text-slate-500">记录一个你常用的发布平台账号。</p>
            </div>
            <button type="button" onclick="openAccountModal('add')"
                class="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">添加渠道</button>
        </div>
    </div>

    {{-- 渠道列表 --}}
    <div class="luxury-glass overflow-hidden">
        <div class="px-5 py-4 text-sm font-semibold text-slate-700">我的渠道（{{ $accounts->count() }}）</div>
        @if($accounts->isEmpty())
            <div class="px-5 pb-6 text-center text-sm text-slate-400">还没有渠道备忘，点击上方「添加渠道」创建一个。</div>
        @else
            <div class="divide-y divide-slate-100">
                @foreach($accounts as $a)
                    <div class="flex flex-wrap items-center gap-3 px-5 py-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-slate-800">{{ $a->account_name ?: $a->platformLabel() }}</span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-500">{{ $a->platformLabel() }}</span>
                                @if($a->isManualPlatform())
                                    <span class="rounded bg-blue-50 px-2 py-0.5 text-xs text-blue-600">人工发布</span>
                                @else
                                    <span class="rounded px-2 py-0.5 text-xs {{ $a->isAuthorized() ? 'bg-emerald-50 text-emerald-600' : 'bg-amber-50 text-amber-600' }}">{{ $a->isAuthorized() ? '已授权' : '未授权' }}</span>
                                @endif
                                <span class="text-xs text-slate-400">建议 ≤ {{ $a->daily_limit }} 条/天</span>
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
                            @if(in_array($a->platform, ['douyin', 'xiaohongshu'], true) && ! $a->isAuthorized())
                                <button type="button" onclick="startOauth({{ $a->id }})"
                                    class="rounded-md border border-brand-200 bg-brand-50 px-2.5 py-1 text-[11px] text-brand-700 hover:bg-brand-100">去授权</button>
                            @endif
                            @php
                                $editData = [
                                    'id' => $a->id,
                                    'platform' => $a->platform,
                                    'account_name' => $a->account_name,
                                    'remark' => $a->remark,
                                    'content_tags' => $a->content_tags ?? [],
                                    'daily_limit' => $a->daily_limit,
                                ];
                            @endphp
                            <button type="button"
                                onclick='openAccountModal("edit", @json($editData))'
                                class="rounded-md border border-slate-200 bg-white px-2.5 py-1 text-[11px] text-slate-600 hover:bg-slate-50">编辑</button>
                            <form method="POST" action="{{ route('studio.accounts.destroy', $a) }}" onsubmit="return confirm('确认删除该渠道备忘？删除后不可恢复。')">
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

{{-- 渠道信息弹窗（新增 / 编辑共用） --}}
<div id="accountModal" class="fixed inset-0 z-50 hidden" aria-modal="true">
    <div class="absolute inset-0 bg-black/40" onclick="closeAccountModal()"></div>
    <div class="absolute left-1/2 top-1/2 w-full max-w-lg -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div class="mb-4 flex items-center justify-between">
            <h3 id="modalTitle" class="text-base font-semibold text-slate-800">添加渠道</h3>
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
                    <input type="text" name="account_name" id="accountName" required maxlength="60" placeholder="例如：昆山老张讲财税主号"
                        class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                </div>
            </div>

            <div>
                <label class="mb-1 block text-slate-600">每日建议上限（条/天）</label>
                <input type="number" name="daily_limit" id="dailyLimit" min="1" max="20" value="3"
                    class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-slate-700">
                <p class="mt-1 text-xs text-slate-400">仅作备忘提醒，防止同一内容过多账号同质化发布被平台风控。</p>
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
    let currentTags = [];
    let currentMode = 'add';

    function openAccountModal(mode, data) {
        currentMode = mode;
        currentTags = [];
        document.getElementById('modalTitle').textContent = mode === 'edit' ? '编辑渠道' : '添加渠道';

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
        } else {
            form.action = '{{ route('studio.accounts') }}';
            methodInput.value = '';
            idInput.value = '';
            document.getElementById('platformSelect').value = '{{ $platformKeys[0] ?? 'douyin' }}';
            document.getElementById('platformSelect').disabled = false;
            document.getElementById('accountName').value = '';
            document.getElementById('dailyLimit').value = 3;
            document.getElementById('remark').value = '';
        }

        // 凭证字段：编辑时留空（已保存凭证不明文回显），新增时清空
        document.getElementById('cred1').value = '';
        document.getElementById('cred2').value = '';
        if (mode === 'edit') {
            document.getElementById('cred1').placeholder = '已保存凭证，留空则不修改';
            document.getElementById('cred2').placeholder = '已保存凭证，留空则不修改';
        } else {
            document.getElementById('cred1').placeholder = '开放平台应用凭证';
            document.getElementById('cred2').placeholder = '开放平台应用密钥';
        }
        onPlatformChange(document.getElementById('platformSelect').value);

        renderTags();
        document.getElementById('accountModal').classList.remove('hidden');
    }

    function closeAccountModal() {
        document.getElementById('accountModal').classList.add('hidden');
    }

    const CRED_LABELS = {
        // 抖音/小红书走 OAuth 授权、无需手填凭证；视频号无公开 API 人工发布。
        // 公众号（wechat）渠道已于 2026-09-01 移除（公众号为图文平台，与短视频方向不符）。
    };

    function onPlatformChange(platform) {
        const area = document.getElementById('credentialArea');
        const labels = CRED_LABELS[platform];
        if (labels) {
            area.classList.remove('hidden');
            document.getElementById('credLabel1').textContent = labels[0];
            document.getElementById('credLabel2').textContent = labels[1];
            document.getElementById('credHint').textContent = labels[2];
        } else {
            area.classList.add('hidden');
        }
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
            const method = document.createElement('input');
            method.type = 'hidden';
            method.name = '_method';
            method.value = 'PUT';
            this.appendChild(method);
        }
    });

    // ---- OAuth 授权（抖音/小红书）----
    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    async function startOauth(accountId) {
        try {
            const r = await fetch(`/studio/accounts/${accountId}/oauth`, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            const data = await r.json();
            if (! r.ok || ! data.authorize_url) {
                alert(data.error || '获取授权地址失败，请重试');
                return;
            }
            window.open(data.authorize_url, 'oauth_authorize', 'width=640,height=760');
        } catch (e) {
            alert('网络错误，无法发起授权');
        }
    }

    window.addEventListener('message', async function (e) {
        if (! e.data || e.data.type !== 'oauth_authorized') return;
        const accountId = e.data.account_id;
        if (! accountId) { window.location.reload(); return; }
        try {
            const r = await fetch(`/studio/accounts/${accountId}/oauth-confirm`, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            });
            const data = await r.json();
            if (r.ok && data.authorized) {
                window.location.reload();
            } else {
                alert(data.error || '授权确认失败，请重试');
                window.location.reload();
            }
        } catch (e) {
            window.location.reload();
        }
    });
</script>
@endpush
</x-workspace-layout>
</x-app-layout>
