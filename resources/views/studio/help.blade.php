<x-app-layout>
<x-workspace-layout title="帮助中心">
<div class="mx-auto max-w-3xl p-6">

    <div class="luxury-glass p-5">
        <h3 class="text-sm font-semibold text-slate-700">帮助中心 · 常见问题</h3>
        <p class="mt-1 text-xs text-slate-400">「追梦」是短视频创作平台；「昆山老张讲财税」是你的内容 IP，封面与发布落款使用 IP 名。</p>
    </div>

    <div class="mt-4 space-y-3">
        @php
            $faqs = [
                ['q' => '一条视频怎么从零做出来？', 'a' => '三步：①智能选题（或直接粘贴你的文案）→ ②智能二创润色 → ③视频出片选形式（幕后音·动态画面 / 数字人出镜）生成。生成后在视频库可质检、审核、发布。'],
                ['q' => '幕后音·动态画面 和 数字人出镜 有什么区别？', 'a' => '幕后音·动态画面：不露脸，配音 + 风景底图 + 中部滚动字幕，出片快（1-3 分钟）；数字人出镜：用你自己的出镜形象驱动数字人说话，更有真人感，出片较慢（5-10 分钟）。'],
                ['q' => '我有拍好的真人视频，怎么自动精剪？', 'a' => '左侧菜单「真人素材精剪」→ 上传原片（≤500MB，竖屏最佳）→ 系统自动去气口/停顿/重复句、烧字幕、出封面。'],
                ['q' => '视频做好了怎么发布？', 'a' => '视频库 → 智能质检 → 人工审核通过后 → 发布助手选账号发布。抖音/小红书需先完成 OAuth 授权；视频号无开放接口，会自动存进"待人工发布"清单，下载成片到 App 手动发。'],
                ['q' => '发布渠道为什么提示"模拟发布"？', 'a' => '平台账号尚未配置对应平台的授权凭证（抖音/小红书的 Client Key 等）。配置后即为真实发布；未配置时只做模拟记录，不会真正发出。'],
                ['q' => '密码忘了怎么办？', 'a' => '登录页点「忘记密码，用邮箱重置」→ 输入注册时填写的邮箱 → 收 6 位验证码 → 重置。验证码 5 分钟有效。'],
                ['q' => '每月能生成多少条视频？', 'a' => '看套餐：免费试用 10 条/月，专业版 200 条/月，企业版不限量。用量与剩余额度在出片页顶部实时显示。'],
                ['q' => '试用期有什么限制？', 'a' => '试用期内单条视频 ≤10 分钟；累计生成条数/时长按租户配置执行（默认 20 条 / 30 天），到期或超限会提示升级。'],
                ['q' => '出片失败了怎么办？', 'a' => '视频库中失败的任务会显示失败原因，可直接点「↻ 重新出片」用原稿重试；若持续失败，通常是配音或渲染服务异常，稍后重试或联系管理员。'],
            ];
        @endphp
        @foreach($faqs as $i => $faq)
            <details class="rounded-xl border border-slate-200 bg-white shadow-sm" {{ $i === 0 ? 'open' : '' }}>
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-slate-700 select-none">{{ $faq['q'] }}</summary>
                <p class="px-4 pb-4 text-sm leading-relaxed text-slate-500">{{ $faq['a'] }}</p>
            </details>
        @endforeach
    </div>

    <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
        仍需要帮助？请联系管理员（邮箱：<span class="text-slate-700">support@zmgen.cn</span>，或平台账号页查看联系方式）。
    </div>
</div>
</x-workspace-layout>
</x-app-layout>
