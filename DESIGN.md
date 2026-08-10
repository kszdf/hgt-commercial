# 追梦 · 短视频智能生产平台 — DESIGN.md

> 统一设计规范（AI 可读）。本文件为平台前端单一事实来源（Single Source of Truth）。
> 参考风格：**腾讯云数字人站「白底 + 多彩功能色」**；品牌主色靛蓝（indigo）取自现有 `app.css` `@theme`。
> 适用栈：Laravel 11 + Livewire/Flux v2 + Tailwind v4 + 现有 `app.css` 组件类。

---

## 1. Visual Theme & Atmosphere（视觉主题与氛围）

- **设计哲学**：专业、清爽、可信赖的 B 端智能生产工作台。以纯白为底，用克制的多彩功能色区分「生产管线」各模块，避免花哨，强调「信息密度高 + 操作反馈强」。
- **视觉基调**：科技感、极简留白、轻量活力。
- **核心视觉特征关键词**：`白底通透` · `靛蓝主品牌` · `模块多彩语义色` · `柔阴影卡片` · `强按钮反馈`
- **光影与质感倾向**：纯扁平 + 微阴影（非毛玻璃主用）。卡片用 1px 浅边框 + 双层柔阴影；渐变仅用于 Hero 大卡与品牌标题。

---

## 2. Color Palette & Roles（调色板与角色）

### Primary Colors（品牌主色 — 靛蓝）
| 角色 | HEX | CSS 变量 | 用途 |
|------|-----|----------|------|
| Brand 500 | `#6366f1` | `--color-brand-500` | 主按钮、链接、激活态主色 |
| Brand 600 | `#4f46e5` | `--color-brand-600` | 主按钮 hover、导航激活文字/底色 |
| Brand 700 | `#4338ca` | `--color-brand-700` | 主按钮 active、深色强调 |
| Brand 50 | `#eef2ff` | `--color-brand-50` | 激活态底、浅色高亮块 |

### Brand & Dark（深色变体）
| 角色 | HEX | CSS 变量 | 用途 |
|------|-----|----------|------|
| Brand 900 | `#312e81` | `--color-brand-900` | 暗色主题强调 |
| 暗底 | `#0b0d1a` | `.dark body` | 暗色模式页面底色 |

### Accent / Interactive（多彩功能色 — 按模块分工）
> 铁律：**每个功能只用自己的色，不在别处乱用**。这是本系统辨识度的核心。

| 模块 | 主色 HEX | 变量 | 用途 |
|------|----------|------|------|
| 出片 / 播报 | `#10b981` / `#059669` | `--color-fresh-500/600` | 视频出片、生成、播报类按钮与标签 |
| 配音 / 二创 | `#8b5cf6` / `#7c3aed` | `--color-violet-500/600` | 智能二创、声音克隆、配音 |
| 直播 / 工具 | `#3b82f6` / `#2563eb` | `--color-sky-500/600` | 工具类、直播入口 |
| 剪辑 | `#f43f5e` / `#e11d48` | `--color-rose-500/600` | 剪辑、危险/删除强调 |
| 擦除 / 直播 | `#f59e0b` / `#d97706` | `--color-amber-500/600` | 擦除、提醒、试用限制标识 |
| 抹除 | `#14b8a6` / `#0d9488` | `--color-teal-500/600` | 抹除类功能 |
| 字幕 / 识别 | `#6366f1` / `#4f46e5` | `--color-indigo-500/600` | 字幕、识别（同品牌靛蓝） |
| 点缀金 | `#fbbf24` / `#f59e0b` | `--color-gold-400/500` | 稀有/会员/特殊标识点缀 |

### Neutral / Gray Scale（中性灰阶 — slate）
| 角色 | HEX | 用途 |
|------|-----|------|
| slate-50 | `#f8fafc` | 页面浅底、禁用区 |
| slate-100 | `#f1f5f9` | 悬停底、次级分隔 |
| slate-200 | `#e2e8f0` | **旧灰卡边框（逐步废弃）** |
| slate-300 | `#cbd5e1` | 占位符、弱边框 |
| slate-400 | `#94a3b8` | 图标弱色、辅助文字 |
| slate-500 | `#64748b` | 次要文字 |
| slate-600 | `#475569` | 正文 |
| slate-700 | `#334155` | 标题正文 |
| slate-800 | `#1e293b` | 主标题 |
| slate-900 | `#0f172a` | 最高对比文字 |

### Surface & Borders（表面与边框）
| 角色 | HEX | CSS 变量/类 | 用途 |
|------|-----|-------------|------|
| 页面底 | `#ffffff` | `body` | 亮色模式主底 |
| 卡片面 | `#ffffff` | `.luxury-glass` | 白卡 |
| 卡片边框 | `#e8ecf1` | `.luxury-glass` border | 统一浅边框（**非 slate-200**） |
| 顶/侧栏底 | `#ffffff` | `.top-header`/`.sidebar-nav` | 导航容器 |

### Semantic Colors（语义色）
| 语义 | HEX | 变量/类 | 用途 |
|------|-----|---------|------|
| 成功 | `#059669` | `text-emerald-600` / `--color-fresh-600` | 成功 Toast、完成态 |
| 警告 | `#d97706` | `text-amber-600` | 试用限制、注意 |
| 错误 | `#e11d48` | `text-rose-600` | 失败、删除、错误 Toast |
| 信息 | `#4338ca` | `text-brand-600` / `--color-brand-700` | 提示、信息 Toast |

### Shadow Colors（阴影色）
| 角色 | rgba | 用途 |
|------|------|------|
| 卡片静息 | `rgba(0,0,0,0.04)` + `rgba(0,0,0,0.02)` | `.luxury-glass` 双层柔阴影 |
| 卡片悬停 | `rgba(0,0,0,0.08)` | `.luxury-glass:hover` |
| 按钮内陷（按下） | `rgba(15,23,42,0.22)` + `rgba(15,23,42,0.15)` | `:active` inset |

---

## 3. Typography Rules（排版规则）

### Font Family
```
--font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif;
```
中文回退依赖系统 `system-ui`（PingFang / 微软雅黑）。标题如需更紧，可用 `tracking-tight`。

### Type Scale
| 级别 | 字号 | 字重 | 行高 | 字距 | 用途 |
|------|------|------|------|------|------|
| Display Hero | 30px / 1.875rem | 700 | 1.2 | -0.02em | Hero 大卡标题 |
| H1 页面标题 | 24px / 1.5rem | 700 | 1.3 | -0.01em | 页面 `<h1>`（workspace 顶栏用 16px） |
| H2 区块标题 | 18px / 1.125rem | 600 | 1.4 | 0 | 卡片内区块标题 |
| H3 子标题 | 15px / 0.9375rem | 600 | 1.4 | 0 | 分组小标题 |
| Body 正文 | 14px / 0.875rem | 400 | 1.6 | 0 | 默认正文 |
| Small 辅助 | 13px / 0.8125rem | 500 | 1.5 | 0 | 标签、说明、面包屑 |
| Caption 微注 | 11px / 0.6875rem | 500 | 1.4 | 0.02em | 角标、统计副标 |

### 设计哲学
- **字重区分层级**：标题 600–700，正文 400，辅助 500；不靠颜色堆砌。
- **字距克制**：仅 Display/Hero 用 `-0.01~-0.02em` 收紧；正文 0。
- **行高宽松**：正文 1.6 提升长文可读性；标题 1.2–1.4 紧凑。

---

## 4. Component Stylings（组件样式）

### Buttons（基于全局 `:active` 反馈，勿另写按下态）
```css
/* 主按钮：品牌色 */
.btn-primary { background: var(--color-brand-600); color:#fff; border-radius:10px; padding:9px 18px; font-weight:600; }
.btn-primary:hover { background: var(--color-brand-700); }
/* 出片主操作（模块色）：绿 */
.btn-fresh { background: var(--color-fresh-600); color:#fff; border-radius:10px; padding:9px 18px; font-weight:600; }
.btn-fresh:hover { background:#047857; }
/* 次要：白底描边 */
.btn-secondary { background:#fff; border:1px solid #e8ecf1; color:var(--color-slate-600,#475569); border-radius:10px; padding:9px 16px; font-weight:500; }
.btn-secondary:hover { background:#f8fafc; }
/* 危险：玫红 */
.btn-danger { background: var(--color-rose-600); color:#fff; border-radius:10px; padding:9px 16px; font-weight:600; }
```
> **全局按下态（已在 app.css）**：`transform: scale(0.96)` + inset 阴影 + `brightness(0.95)`，覆盖所有 `button/.btn/[role=button]/a.rounded-*`，无需每处重写。

### Cards（统一用 `.luxury-glass`，禁止 `border-slate-200 bg-slate-50/80` 旧灰卡）
```css
.luxury-glass {
  background:#ffffff; border:1px solid #e8ecf1; border-radius:12px;
  box-shadow:0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.02);
}
.luxury-glass:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); }
```
> 白卡内文字**必须用深色**（slate-700/600/500），严禁 `text-white`（白压白不可见）。

### Inputs
```css
/* 输入框：聚焦品牌环 */
input,select,textarea { border:1px solid #e8ecf1; border-radius:10px; padding:9px 12px; color:#334155; background:#fff; }
input:focus,select:focus,textarea:focus { outline:none; border-color:var(--color-brand-400); box-shadow:0 0 0 3px var(--color-brand-100); }
::placeholder { color:#94a3b8; }
```

### Navigation（`.ws-nav-item` / `.ws-nav-active`，已在 workspace-layout）
```css
.ws-nav-item { color:#475569; border-radius:8px; padding:9px 14px; font-weight:500; }
.ws-nav-item:hover { background:#f1f5f9; color:#334155; }
.ws-nav-active { color:var(--color-brand-600); background:var(--color-brand-50); font-weight:600; box-shadow:0 1px 2px rgba(79,70,229,0.06); }
```

### Badges / Tags
```css
/* 模块色浅底标签：用对应 50 底 + 600 字 */
.badge-fresh { background:var(--color-fresh-50); color:var(--color-fresh-600); border-radius:9999px; padding:2px 10px; font-size:11px; font-weight:600; }
.badge-violet { background:var(--color-violet-50); color:var(--color-violet-600); } /* 配音/二创 */
.badge-sky { background:var(--color-sky-50); color:var(--color-sky-600); }
```

### Modals / Dialogs（`.luxury-glass` 内容 + 遮罩）
```css
/* 遮罩 */
.modal-mask { background:rgba(0,0,0,0.4); backdrop-filter:blur(4px); }
/* 内容：沿用 .luxury-glass，入场动画 */
@keyframes modalIn { from{opacity:0;transform:translateY(8px) scale(.98)} to{opacity:1;transform:none} }
.modal-card { animation:modalIn .18s ease; }
```

---

## 5. Layout Principles（布局原则）

### Spacing System
- 基数 **4px**（Tailwind 默认）。常用阶梯：`1=4px 2=8px 3=12px 4=16px 5=20px 6=24px 8=32px`。
- 卡片内 padding 统一 `p-5`(20px) 或 `p-4`(16px)；卡片间距 `gap-4`(16px) / `gap-6`(24px)。

### Grid System
- 内容栅格：KPI/卡片用 `grid-cols-2 lg:grid-cols-4`；双栏用 `lg:grid-cols-2`。
- 间距（gap）：`gap-4`(16) 标准，`gap-6`(24) 宽松。

### Container
- 主内容由 `<x-container>` 包裹（workspace 主区可滚动）；页内块最大宽随容器，不硬性锁 max-width（后台型）。

### Section Spacing
- 区块间 `mb-6`(24px)；页头与内容 `mb-6`。
- **留白哲学**：白底 + 大留白突出卡片；同屏信息分组用 `space-y-*` 与分隔线，不堆砌边框。

---

## 6. Depth & Elevation（深度与层级）

### Shadow System
| 层级 | box-shadow | 用途 |
|------|-----------|------|
| shadow-xs | `0 1px 2px rgba(0,0,0,0.03)` | 极浅分隔 |
| shadow-sm（卡片静息） | `0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.02)` | `.luxury-glass` |
| shadow-md（卡片悬停） | `0 4px 12px rgba(0,0,0,0.08)` | `.luxury-glass:hover` |
| shadow-lg（Hero） | `0 12px 32px rgba(0,0,0,0.15)` | `.hero-card:hover` |
| shadow-inset（按下） | `inset 0 2px 5px rgba(15,23,42,0.22), inset 0 1px 2px rgba(15,23,42,0.15)` | 按钮 `:active` |

### Surface Layers
`background(#fff)` → `surface(.luxury-glass)` → `elevated(hero-card/modal)` → `overlay(遮罩 z-50/60)`

### Z-index Scale
| 层级 | 值 | 用途 |
|------|----|------|
| 侧栏(移动) | `z-40` | 抽屉 |
| 遮罩 | `z-50` | modal mask |
| Toast | `z-60` | 顶部提示 |

### Backdrop Effects
- 顶栏：`backdrop-blur-sm` 半透明白。
- 弹窗遮罩：`backdrop-filter: blur(4px)`。
- 主用扁平，毛玻璃仅点缀。

---

## 7. Do's and Don'ts（设计规范与禁忌）

### Do's
1. 卡片一律用 `.luxury-glass`（白底 + `#e8ecf1` 边框），**不再新增** `border-slate-200 bg-slate-50/80` 灰卡。
2. 模块功能色严格按第 2 章语义表使用（绿=出片、紫=配音/二创…）。
3. 主按钮用 `btn-primary`(品牌) 或 `btn-fresh`(出片绿)；危险操作用 `btn-danger`。
4. 输入框聚焦用品牌环（`brand-400` 边 + `brand-100` 3px 光晕）。
5. 导航激活态统一 `.ws-nav-active`（brand-600 字 + brand-50 底）。
6. 白卡内文字用 slate-700/600/500 深字，保证对比度。
7. 间距走 4px 阶梯，区块 `mb-6`、卡片 `gap-4/6`。

### Don'ts
1. ❌ 在 `.luxury-glass` 白卡内用 `text-white` / `text-slate-300`（白压白不可见）。
2. ❌ 混用旧灰卡与白卡于同页造成割裂。
3. ❌ 跨模块乱用功能色（如出片按钮用紫色）。
4. ❌ 自定义 `:active` 按下态覆盖全局（app.css 已统一，重复写会冲突）。
5. ❌ 用 `slate-200` 作卡片边框（应统一 `#e8ecf1`，即 `.luxury-glass` 边框色）。
6. ❌ 大段纯文字不用卡片包裹，直接铺在白底（信息无边界）。
7. ❌ 暗色模式未适配组件（凡新增组件需补 `.dark` 回退）。

---

## 8. Responsive Behavior（响应式行为）

### Breakpoints
| 名称 | 范围 | 典型 |
|------|------|------|
| mobile | `<768px` | 侧栏抽屉化（`ws-sidebar` fixed + overlay） |
| tablet | `768–1024px` | 栅格降列 |
| desktop | `≥1024px` | 双栏/四列铺开 |
| wide | `≥1440px` | 最大内容宽自适应 |

### Touch Targets
- 最小可点尺寸 **44×44px**；导航项 `padding:9px 14px` 已满足。

### 折叠策略
- `<768px`：侧栏变抽屉（`toggleSidebar()`），遮罩 `z-20`；选中导航自动收起。
- 栅格：KPI `grid-cols-2` → `lg:grid-cols-4`；双栏 `lg:grid-cols-2`。

### Font Scaling
- 桌面/平板用规范字号；移动端 H1 可降至 20px，正文保持 14px，Caption 11px 不变。

---

## 9. Agent Prompt Guide（AI 代理提示指南）

### Quick Reference
- 主色 `brand-600 #4f46e5`；白卡 `.luxury-glass`；模块色 `fresh`(绿/出片) `violet`(紫/配音二创) `sky`(蓝/工具) `rose`(玫红/剪辑删除) `amber`(琥珀/限制) `teal`(青/抹除)。
- 字体 Inter；间距 4px 阶梯；圆角卡片 12px / 按钮 10px。
- 全局按钮按下态已统一，勿重写。
- 白卡内禁白字。

### Component Prompts（可直接复制）
1. `用追梦 DESIGN.md 规范生成一个「视频出片设置」卡片：.luxury-glass 白卡，标题用 slate-800，主按钮 btn-fresh（出片绿），配音选择区用 violet 浅底标签。`
2. `把这段 border-slate-200 bg-slate-50/80 的灰卡改成 .luxury-glass，文字从 slate-600 调到 slate-700，标题加 font-semibold text-slate-800。`
3. `生成一个表单输入框组件：聚焦时 border-brand-400 + 3px brand-100 光晕，placeholder slate-400。`
4. `生成一个 danger 删除按钮 btn-danger（rose-600），复用全局 :active 内陷反馈，不要另写按下态。`
5. `生成一个模态框：遮罩 rgba(0,0,0,0.4)+blur(4px)，内容 .luxury-glass + modalIn 入场动画。`
6. `给「智能二创」模块生成 section 标题与 violet 浅底徽标，严格只用 violet 功能色。`

### Iteration Guide（AI 生成 UI 迭代建议）
1. 改样式前先读 `app.css` 现有 token 与组件类，复用而非新建。
2. 任何新增组件必须补 `.dark` 回退，保持暗色可用。
3. 卡片统一 `.luxury-glass`；发现旧灰卡立即替换，不保留混搭。
4. 功能色只服务对应模块，跨模块使用前先核对第 2 章语义表。
5. 白卡内文字一律深色，提交前肉眼确认对比度（禁白字）。
6. 按钮按下态交给全局规则，新增 `:active` 会冲突，避免。
7. 改动后跑 `build-and-verify.ps1` + `view:cache` 验证，路由探 302。
8. 修改含 `<script>` 的 blade（如 scroll 出片逻辑）只改 class，绝不动 JS 变量与事件绑定。
9. 间距/圆角对齐 4px 阶梯与 12/10px 规范，不一致即修。
10. 每次统一一批页面后，用对照截图确认与外壳（workspace-layout）观感一致。

---

## Addendum — Theme Variants（多主题 + 租户 DIY）

第 2 章令牌已抽为语义变量层，支持租户级风格定制（2026-08-08 落地）：

- **预设（3 套浅色）**：`indigo` 靛蓝商务（默认）/ `warm` 暖阳亲和 / `teal` 青翠清新。通过 `<html data-theme="...">` 切换，覆盖 `--color-brand-*` 与 `--surface-*`/`--text-*`/`--nav-*` 变量。
- **语义变量**：`--surface-page`(页面底) / `--surface-card`(卡片底) / `--surface-card-border`(边框) / `--text-strong` / `--text-body` / `--text-muted` / `--nav-active-bg` / `--nav-active-fg` / `--nav-hover-bg` / `--sidebar-bg` / `--topbar-bg` / `--nav-py`(密度)。
- **租户 DIY**：`tenants.theme_preset`(string) + `tenants.theme_overrides`(json: accent/page_tint/density)。DIY 经 `workspace-layout` 注入 `:root` 内联变量，优先级高于预设。
- **密度**：`[data-density="compact"]` 收紧 `--nav-py`(0.5625rem→0.4rem)。
- **约定**：新增组件一律用语义变量 / `--color-brand-*` 而非硬编码色值，否则预设切换失效。暗色（`.dark`）为独立机制，暂未纳入预设体系。

