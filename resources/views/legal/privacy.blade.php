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

        <div class="space-y-6 text-sm leading-relaxed text-slate-600" style="text-wrap: pretty; word-break: keep-all;">
            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">一、我们收集的信息</h2>
                <p>为提供短视频智能生产服务，我们可能收集以下信息：</p>
                <ul class="ml-5 mt-1 list-disc space-y-1">
                    <li><strong class="text-slate-700">账号信息</strong>：注册时提供的手机号、邮箱、企业/团队名称及登录密码（经加盐哈希存储，平台无法还原明文）。</li>
                    <li><strong class="text-slate-700">内容素材</strong>：您上传的口播文案、音频、图片、视频及数字人模特等生产素材，仅用于为您生成作品。</li>
                    <li><strong class="text-slate-700">设备与网络信息</strong>：访问时自动记录的 IP 地址、设备型号、操作系统与浏览器类型，用于安全防护与体验优化。</li>
                    <li><strong class="text-slate-700">使用日志</strong>：服务访问与操作日志，用于安全审计与故障排查。</li>
                    <li><strong class="text-slate-700">Cookie 与本地存储</strong>：用于保持登录状态与记住偏好设置；您可在浏览器中关闭，但可能影响部分功能。</li>
                </ul>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">二、信息的使用</h2>
                <p>我们仅将信息用于：提供并改进出片、配音、字幕、分发等核心功能；协助维护账号与交易安全；履行法律法规要求的义务。您的素材将调用第三方 AI 模型（如文本大模型、图像/视频生成、语音合成服务）用于生成作品，生成结果以您实际收到的输出为准。我们<strong class="text-slate-700">不会</strong>将您的个人信息出售给第三方。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">三、信息的存储与安全</h2>
                <p>您的数据存储于受访问控制保护的服务器。我们尽力在传输过程中采用加密措施（如 HTTPS）。我们采取加盐哈希、访问隔离、操作审计等措施以降低数据安全风险。需要说明的是，任何安全措施均无法保证绝对安全，因不可抗力、第三方攻击或您自身原因导致的数据泄露、丢失或损毁，平台在已尽合理注意义务的前提下不承担相应责任。数字人音色克隆仅限您本人或已授权声音，严禁商用分发他人声纹。我们仅在实现本政策所述目的所必需的期限内保留您的数据，法律法规另有要求的除外。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">四、您的权利</h2>
                <p>您可登录后查看、更正账号信息，或联系平台管理员申请删除账号及关联数据。删除后，我们将在合理期限内尽力清除您的个人数据与素材（法律法规要求留存的除外）。您也可撤回对信息使用的同意，撤回不影响撤回前已进行的处理。因素材为 AI 处理所必需，删除或撤回可能导致已生成作品无法继续使用或恢复。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">五、素材的保存与备份</h2>
                <p>您上传的素材在 AI 处理过程中可能因技术原因发生丢失、损坏或处理失败，且素材及生成结果可能被平台按清理策略删除。建议您对重要素材自行备份，平台不承诺素材及生成结果的永久保存，亦不对因素材丢失、损坏或删除造成的损失承担责任。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">六、未成年人条款</h2>
                <p>本服务面向企业及成年人用户。如您为未成年人，请在监护人指导下使用，且不得向我们提供个人敏感信息。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">七、第三方服务</h2>
                <p>视频分发至抖音、小红书等平台时，相关操作受对应平台隐私政策约束，因第三方平台原因导致的账号或内容问题，平台不承担责任；短信验证码由具备资质的云通信服务商代为发送，仅传输必要校验信息；AI 模型服务由相应云服务商提供，素材传输受其隐私政策约束，其服务中断、限流或调整可能导致生成失败或延迟，平台对此不承担责任。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">八、免责声明</h2>
                <p>AI 生成内容可能存在不准确、不完整或过时之处，发布前请您自行核实与审核，平台不对 AI 生成内容的准确性、完整性、合法性及其引发的后果承担责任。平台展示的财税科普内容仅供参考，不构成税务、法律或投资建议。</p>
            </section>

            <section>
                <h2 class="mb-2 text-base font-semibold text-slate-800">九、联系我们</h2>
                <p>如对本政策有疑问，可通过平台内「联系管理员」或企业资质主体渠道与我们联系。</p>
            </section>
        </div>

        <div class="mt-8 border-t border-slate-100 pt-6 text-center">
            <a href="/" class="text-sm font-medium text-brand-500 hover:underline">返回首页</a>
        </div>
    </div>
</div>
</x-app-layout>
