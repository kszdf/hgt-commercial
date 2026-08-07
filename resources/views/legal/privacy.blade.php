<x-app-layout>
@section('title', '隐私政策 · 追梦')
<div class="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 px-6 py-12">
    <div class="mx-auto max-w-3xl rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-100 sm:p-10">
        <div class="mb-8 flex items-center gap-3 border-b border-slate-100 pb-6">
            <img src="/images/logo.jpg" alt="追梦" class="h-12 w-auto rounded-xl shadow-sm">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">隐私政策</h1>
                <p class="text-xs text-slate-400">最后更新：2026-08-07</p>
            </div>
        </div>

        <div class="space-y-6 text-sm leading-relaxed text-slate-600">
            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">一、我们收集的信息</h2>
                <p>为提供短视频智能生产服务，我们可能收集以下信息：</p>
                <ul class="ml-5 mt-1 list-disc space-y-1">
                    <li><strong class="text-slate-700">账号信息</strong>：注册时提供的手机号、邮箱、企业/团队名称及登录密码（经加盐哈希存储，平台无法还原明文）。</li>
                    <li><strong class="text-slate-700">内容素材</strong>：您上传的口播文案、音频、图片、视频及数字人模特等生产素材，仅用于为您生成作品。</li>
                    <li><strong class="text-slate-700">使用日志</strong>：服务访问与操作日志，用于安全审计与故障排查。</li>
                </ul>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">二、信息的使用</h2>
                <p>我们仅将信息用于：提供并改进出片、配音、字幕、分发等核心功能；保障账号与交易安全；履行法律法规要求的义务。我们<strong class="text-slate-700">不会</strong>将您的个人信息出售给第三方。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">三、信息的存储与安全</h2>
                <p>您的数据存储于受访问控制保护的服务器。传输层全程采用 TLS 加密（HTTPS）。我们采取加盐哈希、访问隔离、操作审计等措施保护数据安全。数字人音色克隆仅限您本人或已授权声音，严禁商用分发他人声纹。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">四、您的权利</h2>
                <p>您可随时登录后查看、更正账号信息，或联系平台管理员申请删除账号及关联数据。删除后，我们将在合理期限内清除您的个人数据与素材（法律法规要求留存的除外）。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">五、第三方服务</h2>
                <p>视频分发至抖音、小红书等平台时，相关操作受对应平台隐私政策约束；短信验证码由具备资质的云通信服务商代为发送，仅传输必要校验信息。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">六、联系我们</h2>
                <p>如对本政策有疑问，可通过平台内「联系管理员」或企业资质主体渠道与我们联系。</p>
            </section>

            <p class="rounded-lg bg-slate-50 p-4 text-xs text-slate-400">
                说明：本页为合规占位模板，正式上线前建议由法务根据实际情况修订完善。
            </p>
        </div>

        <div class="mt-8 border-t border-slate-100 pt-6 text-center">
            <a href="/" class="text-sm font-medium text-brand-500 hover:underline">返回首页</a>
        </div>
    </div>
</div>
</x-app-layout>
