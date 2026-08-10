# CSS 构建校验检查清单（追梦 / HGTCommercial）

## 事故回顾（2026-08-05）
dashboard 接入 `x-workspace-layout` 组件后，**构建产物停留在 8/1 13:25**，组件引用的
Tailwind 工具类（`w-56`/`shrink-0`/`flex`/`min-h-screen` 等）未进入产物，导致侧栏无宽度、
flex 布局失效、Hero 卡片装饰圆失控。根因：**新增组件后从未运行 `npm run build`**，
且没有任何自动化在构建后校验样式是否生效。

## 根因结论
- **不是** Tailwind 内容扫描路径遗漏：项目用 Tailwind v4（`@tailwindcss/vite`），内容扫描
  自动覆盖 `resources/views/**`；`npm run build` 一次就把 CSS 从 238KB 增到 256KB，证明路径正确。
- **不是** 缓存未清理（仅清缓存不能让新类进产物，必须重新 build）。
- **是** 构建步骤未执行 + 缺乏自动化校验。已落地三层防护（见下）。

## 已落地的防护
1. **显式 `@source`**（`resources/css/app.css` 顶部）：声明扫描 `resources/views`、`resources/js`，
   不依赖 v4 自动探测，未来新增非常规位置模板也能覆盖。
2. **构建后校验脚本** `scripts/verify-css-build.ps1`：核对 13 个关键类是否进入最新 `public/build/assets/app-*.css`，
   缺失即 exit 1。
3. **构建+校验封装** `scripts/build-and-verify.ps1`：替代裸 `npm run build`，构建完自动校验。
4. **git pre-commit 钩子** `.git/hooks/pre-commit`：提交涉及前端资源时强制 build+verify，
   校验失败直接拦截提交，并把刷新后的 `public/build` 重新暂存。

## 每次改动前端后的标准动作（人工核验清单）
- [ ] 改了任何 `.blade.php` / `*.css` / `*.js` / 新增组件 / 改 `vite.config.js` / `package.json`？
- [ ] 运行 `powershell -ExecutionPolicy Bypass -File scripts/build-and-verify.ps1`
- [ ] 确认输出 `SUCCESS: build + verify passed.` 且 13 个关键类全部 `OK`
- [ ] 若 CI/服务器部署：发布流程必须包含 `npm ci && npm run build`（切勿直接拷贝旧 `public/build`）
- [ ] 浏览器**硬刷新**（Ctrl+Shift+R）确认布局比例正常，再宣称"界面检查通过"

## 关键类（校验脚本监控，缺一则构建过期）
min-h-screen · w-56 · shrink-0 · flex · flex-col · flex-1 · overflow-y-auto ·
backdrop-blur-sm · grid-cols-3 · border-r · gap-4 · luxury-glass · hero-card

> 注：组件内联 `<style>` 里的 `.ws-*` 类随 HTML 原样输出，不走 Vite 编译，不在校验范围。

## 故障自查
- 页面布局错乱 / 侧栏塌陷 → 先查 `public/build/assets/app-*.css` 的修改时间是否早于最近一次模板改动。
- 跑 `scripts/verify-css-build.ps1`，看哪个类 `MISSING` → 跑 `scripts/build-and-verify.ps1` 重建。
- 仍缺失 → 检查 `app.css` 顶部 `@source` 是否覆盖该模板所在目录。
