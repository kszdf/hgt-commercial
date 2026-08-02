<x-app-layout>
    <x-container>
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold">数据模块</h1>
                <p class="text-zinc-500 dark:text-zinc-400 text-sm mt-1">录入 / 批量导入各平台播放互动数据，供复盘看板分析</p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-sm text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200">← 返回工作台</a>
        </div>

        @if (session('success'))
            <div class="mb-4 rounded-lg bg-emerald-500/10 border border-emerald-500/30 text-emerald-600 dark:text-emerald-300 px-4 py-2 text-sm">{{ session('success') }}</div>
        @endif
        @if (session('info'))
            <div class="mb-4 rounded-lg bg-sky-500/10 border border-sky-500/30 text-sky-600 dark:text-sky-300 px-4 py-2 text-sm">{{ session('info') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-4 rounded-lg bg-rose-500/10 border border-rose-500/30 text-rose-600 dark:text-rose-300 px-4 py-2 text-sm">{{ session('error') }}</div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            {{-- 单条录入 --}}
            <div class="lg:col-span-2 space-y-6">
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <h2 class="font-semibold mb-4">单条数据录入</h2>
                    <form method="POST" action="{{ route('studio.metrics.store') }}" class="grid grid-cols-2 gap-3 text-sm">
                        @csrf
                        <div>
                            <label class="block text-zinc-500 mb-1">出片任务</label>
                            <select name="video_job_id" required class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-transparent px-3 py-2">
                                @foreach ($videos as $v)
                                    <option value="{{ $v->id }}">{{ $v->title ?: $v->job_id }} ({{ $v->job_id }})</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-zinc-500 mb-1">平台</label>
                            <select name="platform" required class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-transparent px-3 py-2">
                                <option value="wechat">视频号</option>
                                <option value="douyin">抖音</option>
                                <option value="xiaohongshu">小红书</option>
                                <option value="manual">手动</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-zinc-500 mb-1">日期</label>
                            <input type="date" name="metric_date" required class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-transparent px-3 py-2">
                        </div>
                        <div class="col-span-2 grid grid-cols-4 gap-3">
                            <div><label class="block text-zinc-500 mb-1">播放</label><input type="number" name="views" min="0" class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-transparent px-3 py-2"></div>
                            <div><label class="block text-zinc-500 mb-1">转发</label><input type="number" name="shares" min="0" class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-transparent px-3 py-2"></div>
                            <div><label class="block text-zinc-500 mb-1">评论</label><input type="number" name="comments" min="0" class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-transparent px-3 py-2"></div>
                            <div><label class="block text-zinc-500 mb-1">点赞</label><input type="number" name="likes" min="0" class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-transparent px-3 py-2"></div>
                        </div>
                        <div class="col-span-2">
                            <button class="rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 text-sm font-medium">保存</button>
                        </div>
                    </form>
                </div>

                {{-- 指标列表 --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <h2 class="font-semibold mb-4">数据明细</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-zinc-500 border-b border-zinc-200 dark:border-zinc-700">
                                <tr>
                                    <th class="text-left py-2 pr-3">日期</th>
                                    <th class="text-left py-2 pr-3">出片</th>
                                    <th class="text-left py-2 pr-3">平台</th>
                                    <th class="text-right py-2 pr-3">播放</th>
                                    <th class="text-right py-2 pr-3">转发</th>
                                    <th class="text-right py-2 pr-3">评论</th>
                                    <th class="text-right py-2">点赞</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($metrics as $m)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="py-2 pr-3">{{ $m->metric_date->format('Y-m-d') }}</td>
                                        <td class="py-2 pr-3">{{ $m->videoJob?->title ?: ($m->videoJob?->job_id ?? '—') }}</td>
                                        <td class="py-2 pr-3">{{ $m->platform }}</td>
                                        <td class="py-2 pr-3 text-right">{{ number_format($m->views) }}</td>
                                        <td class="py-2 pr-3 text-right">{{ number_format($m->shares) }}</td>
                                        <td class="py-2 pr-3 text-right">{{ number_format($m->comments) }}</td>
                                        <td class="py-2 text-right">{{ number_format($m->likes) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="py-6 text-center text-zinc-400">暂无数据，先录入或导入</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="mt-4">{{ $metrics->links() }}</div>
                </div>
            </div>

            {{-- 右栏：CSV导入 + 平台授权 --}}
            <div class="space-y-6">
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <h2 class="font-semibold mb-4">CSV 批量导入</h2>
                    <form method="POST" action="{{ route('studio.metrics.import') }}" enctype="multipart/form-data" class="space-y-3 text-sm">
                        @csrf
                        <input type="file" name="csv" accept=".csv,.txt" required class="w-full text-sm">
                        <p class="text-xs text-zinc-400 leading-relaxed">表头：job_id,platform,metric_date,views,shares,comments,likes<br>job_id 支持任务编号或出片任务号；重复按「出片×平台×日期」覆盖。</p>
                        <button class="w-full rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 text-sm font-medium">导入</button>
                    </form>
                </div>

                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <h2 class="font-semibold mb-4">平台授权</h2>
                    <ul class="space-y-2 text-sm">
                        @foreach ($accounts as $a)
                            <li class="flex items-center justify-between">
                                <span>{{ $a->platform }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="text-xs px-2 py-0.5 rounded {{ $a->isAuthorized() ? 'bg-emerald-500/15 text-emerald-600' : 'bg-zinc-500/15 text-zinc-500' }}">
                                        {{ $a->isAuthorized() ? '已授权' : '未授权' }}
                                    </span>
                                    <a href="{{ route('studio.metrics.connect', $a->platform) }}" class="text-indigo-600 hover:underline text-xs">授权</a>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </x-container>
</x-app-layout>
