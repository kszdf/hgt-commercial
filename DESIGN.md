# DESIGN.md — 追梦 · 商用短视频智能生产平台

> AI 可读设计系统规范 · 供 Cursor / Claude Code / 飞书 Stitch 等编程代理直接消费
> 设计 DNA：**Stripe**（克制专业金融感） + **Linear**（indigo 主色 / 紧凑高效排版） + 腾讯云数字人站（白底 + 多彩功能色分区）

---

## 1. Visual Theme & Atmosphere

- **设计哲学**：B 端短视频生产工具需要「可信赖 + 高效」。以纯白为底、indigo 为主色锚定专业感，用一套独立的功能色为每个业务模块着色（出片/配音/剪辑/字幕各一色），做到「该彩的地方彩、该静的地方静」，避免廉价花哨。
- **视觉基调**：清爽、专业、克制、略带科技感；明亮优先，暗色为可选模式。
- **核心视觉特征关键词**：`white-base`（纯白底） · `indigo-anchor`（靛蓝锚点） · `functional-chroma`（功能分区多彩） · `soft-elevation`（柔阴影分层） · `compact-density`（紧凑信息密度）
- **光影与质感倾向**：纯扁平 + 微阴影（无重投影、无毛玻璃泛滥）；卡片用 1px 细边框 + 双层柔阴影；Hero 卡用饱和渐变 + 内嵌光斑模拟体积感。

---

## 2. Color Palette & Roles

### Primary Colors
```css
--color-brand-500: #6366f1;  /* 主交互色：按钮/链接/激活态 */
--color-brand-600: #4f46e5;  /* 主色按压/深色变体 */
--color-brand-700: #4338ca;  /* 主色文字（浅底上）/活跃导航 */
--color-brand-50:  #eef2ff;  /* 主色极浅背景：激活导航底/选中块 */
```

### Brand & Dark
```css
--color-bg:            #ffffff;  /* 页面底色（浅色模式） */
--color-bg-dark:       #0b0d1a;  /* 页面底色（暗色模式 .dark body） */
--color-brand-950:     #1e1b4b;  /* 最深靛蓝：暗色模式侧栏底 */
```

### Accent / Interactive (功能分区多彩)
```css
--color-fresh-500: #10b981;  --color-fresh-600: #059669;  /* 出片 / 播报（翠绿） */
--color-violet-500: #8b5cf6;  --color-violet-600: #7c3aed; /* 配音 / 二创（紫罗兰） */
--color-sky-500:    #3b82f6;  --color-sky-600:    #2563eb; /* 工具 / 直播（天蓝） */
--color-rose-500:   #f43f5e;  --color-rose-600:   #e11d48; /* 剪辑 / 危险（玫红） */
--color-amber-500:  #f59e0b;  --color-amber-600:  #d97706; /* 直播 / 擦除（琥珀） */
--color-teal-500:   #14b8a6;  --color-teal-600:   #0d9488; /* 抹除（青绿） */
--color-indigo-500: #6366f1;  --color-indigo-600: #4f46e5; /* 字幕 / 识别（靛蓝=主色复用） */
```

### Neutral / Gray Scale (Tailwind slate)
```css
--color-slate-50:  #f8fafc;  /* 悬停浅底 / 输入框底 */
--color-slate-100: #f1f5f9;  /* 工具图标浅容器 / 禁用底 */
--color-slate-200: #e2e8f0;  /* 分隔线 / 禁用边框 */
--color-slate-300: #cbd5e1;  /* 输入框边框（默认） */
--color-slate-400: #94a3b8;  /* 占位符文字 */
--color-slate-500: #64748b;  /* 次要文字 / 导航默认 */
--color-slate-600: #475569;  /* 工具图标文字 / 中文字 */
--color-slate-700: #334155;  /* 正文 / 导航悬停 */
--color-slate-900: #0f172a;  /* 标题 / 强文字 */
```

### Surface & Borders
```css
--color-surface:      #ffffff;  /* 卡片表面 */
--color-surface-dark: rgba(15,17,33,0.55); /* 暗色卡片（毛玻璃感） */
--color-border:       #e8ecf1;  /* 全局 1px 细边框 */
--color-border-dark:  rgba(255,255,255,0.08); /* 暗色边框 */
```

### Semantic Colors
```css
--color-success: #10b981;  /* 成功 / 审核通过 / 出片完成 */
--color-warning: #f59e0b;  /* 警告 / 配额临界 / 质检提示 */
--color-danger:  #f43f5e;  /* 错误 / 删除 / 审核驳回 */
--color-info:    #3b82f6;  /* 信息 / 处理中 / 平台标识 */
```

### Shadow Colors
```css
--shadow-color-soft: rgba(0, 0, 0, 0.04);
--shadow-color-faint: rgba(0, 0, 0, 0.02);
--shadow-color-hover: rgba(0, 0, 0, 0.08);
--shadow-color-hero:   rgba(0, 0, 0, 0.15);
```

**使用场景说明**：品牌主色仅用于「锚点」（主按钮、激活导航、关键链接），功能色严格按模块语义使用（出片=翠绿、配音=紫、剪辑=玫红、字幕=靛蓝），不可跨模块混用以免用户建立错误心智。中性 slate 承担 90% 的文字与边框，保证界面安静。

---

## 3. Typography Rules

### Font Family
```css
--font-sans: 'Inter', ui-sans-serif, system-ui, 'PingFang SC', 'Microsoft YaHei', sans-serif;
```
> 中文回退至 PingFang SC / 微软雅黑；代码/数字用 Inter 的 tabular-nums 特性。

### Type Scale
| Token | 用途 | Size | Weight | Line-Height | Letter-Spacing | OpenType |
|---|---|---|---|---|---|---|
| `display-hero` | 首页 Hero 主标题 | 36px / 2.25rem | 700 | 1.15 | -0.02em | `cv05, ss01` |
| `h1` | 页面一级标题 | 30px / 1.875rem | 700 | 1.25 | -0.01em | — |
| `h2` | 区块标题 | 24px / 1.5rem | 600 | 1.3 | -0.01em | — |
| `h3` | 卡片标题 | 18px / 1.125rem | 700 | 1.4 | -0.01em | — |
| `h4` | 子标题 | 16px / 1rem | 600 | 1.45 | 0 | — |
| `body` | 正文 / 表单标签 | 14px / 0.875rem | 400 | 1.6 | 0 | — |
| `body-strong` | 强调正文 | 14px | 600 | 1.6 | 0 | — |
| `small` | 辅助说明 / 按钮小字 | 13px / 0.8125rem | 500 | 1.5 | 0 | — |
| `caption` | 元数据 / 时间戳 | 12px / 0.75rem | 500 | 1.4 | 0.01em | — |
| `nano` | 步骤编号 / 标签徽章 | 11px / 0.6875rem | 700 | 1.2 | 0.02em | — |

### 设计哲学
字重克制：仅 Hero/卡片标题用 700，正文一律 400，避免「全屏加粗」的廉价感（呼应 Stripe）。行高宽松（正文 1.6）保证长文可读。字距在 display 级别收紧 -0.02em 营造现代紧凑感，小字 nano 反而 +0.02em 提升辨识度（呼应 Linear）。数字/金额统一 `font-variant-numeric: tabular-nums`。

---

## 4. Component Stylings

### Buttons
```css
/* Primary — 主操作（出片/提交/克隆） */
.btn-primary {
  background: var(--color-brand-600); color: #fff;
  border: 1px solid transparent; border-radius: 10px;
  padding: 9px 18px; font-size: 14px; font-weight: 600;
  transition: background .15s ease, transform .1s ease;
}
.btn-primary:hover { background: var(--color-brand-700); }
.btn-primary:active { transform: translateY(1px); }

/* Secondary — 次操作（取消/返回） */
.btn-secondary {
  background: #fff; color: var(--color-slate-700);
  border: 1px solid var(--color-slate-300); border-radius: 10px;
  padding: 9px 18px; font-weight: 600;
}
.btn-secondary:hover { background: var(--color-slate-50); }

/* Ghost — 弱操作（查看更多） */
.btn-ghost { background: transparent; color: var(--color-brand-600); padding: 8px 12px; font-weight: 600; }
.btn-ghost:hover { background: var(--color-brand-50); }

/* Danger — 删除/驳回 */
.btn-danger { background: var(--color-rose-600); color: #fff; border-radius: 10px; padding: 9px 18px; font-weight: 600; }
.btn-danger:hover { background: #be123c; }
```

### Cards
```css
.luxury-glass {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.02);
  padding: 20px 24px;
  transition: box-shadow .2s ease;
}
.luxury-glass:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.dark .luxury-glass { background: var(--color-surface-dark); border-color: var(--color-border-dark); box-shadow: none; }
```

### Inputs
```css
.input {
  width: 100%; background: #fff;
  border: 1px solid var(--color-slate-300); border-radius: 10px;
  padding: 9px 12px; font-size: 14px; color: var(--color-slate-900);
  transition: border-color .15s ease, box-shadow .15s ease;
}
.input::placeholder { color: var(--color-slate-400); }
.input:focus {
  outline: none; border-color: var(--color-brand-500);
  box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.dark .input { background: rgba(15,17,33,0.6); border-color: var(--color-border-dark); color: #e2e8f0; }
```

### Navigation (侧边栏)
```css
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px; border-radius: 10px;
  color: var(--color-slate-500); font-weight: 500; font-size: 14px;
  transition: all .15s ease;
}
.nav-item:hover { background: var(--color-slate-50); color: var(--color-slate-700); }
.nav-item.active { background: var(--color-brand-50); color: var(--color-brand-700); }
```

### Badges / Tags
```css
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  padding: 2px 8px; border-radius: 6px;
  font-size: 11px; font-weight: 700; letter-spacing: 0.02em;
}
.badge-success { background: var(--color-fresh-50); color: var(--color-fresh-600); }
.badge-warning { background: #fffbeb; color: var(--color-amber-600); }
.badge-danger  { background: #fff1f2; color: var(--color-rose-600); }
.badge-info    { background: var(--color-sky-50); color: var(--color-sky-600); }
```

### Modals / Dialogs
```css
.modal-overlay {
  position: fixed; inset: 0; background: rgba(15,23,42,0.45);
  backdrop-filter: blur(2px); display: flex; align-items: center; justify-content: center;
  z-index: 50; animation: overlay-in .15s ease;
}
.modal-panel {
  background: #fff; border-radius: 16px; padding: 24px;
  box-shadow: 0 20px 48px rgba(0,0,0,0.18);
  max-width: 480px; width: calc(100% - 32px);
  animation: panel-in .2s cubic-bezier(0.16,1,0.3,1);
}
@keyframes overlay-in { from { opacity: 0 } to { opacity: 1 } }
@keyframes panel-in { from { opacity: 0; transform: translateY(8px) scale(.98) } to { opacity: 1; transform: none } }
```

---

## 5. Layout Principles

### Spacing System
- 基数 **4px**，Token 序列：`1=4px 2=8px 3=12px 4=16px 6=24px 8=32px 10=40px 12=48px`
- 组件内边距统一 `20px 24px`（卡片）；表单控件间距 `12px`；按钮组间距 `8px`

### Grid System
- 默认 12 列；内容栅格间隙 `24px`
- 工作台三栏：左导航 `240px` 固定 / 中工作区 `1fr` / 右预览 `360px`（桌面）；折叠策略见 §8

### Container
```css
.container { max-width: 1280px; margin: 0 auto; padding: 0 24px; }
```

### Section Spacing
- 区块间垂直间距 `32px`（桌面）/ `24px`（移动）
- 区块内标题与内容间距 `16px`

### 留白哲学
B 端工具信息密度高，但「功能色块之间必须留白」——每个多彩模块用 12-16px 间距隔开，避免视觉打架。页面整体保持 24px 安全边距，不贴边。

---

## 6. Depth & Elevation

### Shadow System
```css
--shadow-xs:  0 1px 2px rgba(0,0,0,0.02);
--shadow-sm:  0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.02);
--shadow-md:  0 4px 12px rgba(0,0,0,0.08);
--shadow-lg:  0 12px 32px rgba(0,0,0,0.15);
--shadow-xl:  0 20px 48px rgba(0,0,0,0.18);
--shadow-2xl: 0 32px 64px rgba(0,0,0,0.22);
```
> 应用映射：卡片静息=`--shadow-sm`；卡片悬停=`--shadow-md`；Hero 卡悬停=`--shadow-lg`；下拉/弹层=`--shadow-xl`；模态框=`--shadow-xl/~2xl`。

### Surface Layers
```
L0 background  #ffffff (浅) / #0b0d1a (暗)
L1 surface     #ffffff 卡片 / 毛玻璃暗色
L2 elevated    浮起卡片 / 下拉菜单
L3 overlay     模态遮罩 + 面板
```

### Z-index Scale
```
nav/header: 40   dropdown: 45   modal-overlay: 50   toast: 60   tooltip: 70
```

### Backdrop Effects
- 模态遮罩：`backdrop-filter: blur(2px)` + `rgba(15,23,42,0.45)`
- Hero 卡光斑：`backdrop-filter: blur(4px)` 半透明白按钮
- 暗色卡片：`rgba(15,17,33,0.55)` 模拟毛玻璃（非真 blur，性能优先）

---

## 7. Do's and Don'ts

**Do's**
1. 主色 indigo 只用于锚点（主按钮/激活态/关键链接），其余交互动效用功能色按模块语义着色。
2. 所有输入框必须有 placeholder 说明文字（本平台铁律，降低用户认知负担）。
3. 长文/金额用 `tabular-nums`，保证数字对齐专业感。
4. 卡片统一 `.luxury-glass` 圆角 12px + 1px 细边框 + 柔阴影，全站一致。
5. 危险操作（删除/驳回）用玫红并加二次确认模态。
6. 暗色模式仅切换底色与边框变量，组件结构不变。
7. 功能模块用专属功能色图标容器（`.tool-*`），强化「一色一功能」心智。

**Don'ts**
1. ❌ 不要用重投影 / 大模糊毛玻璃堆砌（本平台质感=扁平+柔阴影，不是玻璃拟态风）。
2. ❌ 不要把主色 indigo 用在所有按钮上——次/弱操作必须次级样式。
3. ❌ 不要功能色跨模块混用（如剪辑用翠绿、出片用玫红，会破坏用户心智）。
4. ❌ 不要在浅色模式用深色文字、暗色模式用浅色边框（必须跟随变量切换）。
5. ❌ 不要全屏加粗——正文一律 400 字重。
6. ❌ 不要卡片贴边（必须 24px 安全边距 + 12px 模块间距）。
7. ❌ 不要用 emoji 作为功能图标（用 SVG 线性图标，已成规范）。

---

## 8. Responsive Behavior

### Breakpoints
```css
--bp-sm: 640px;   /* 手机横屏 */
--bp-md: 768px;   /* 平板 */
--bp-lg: 1024px;  /* 桌面 */
--bp-xl: 1280px;  /* 宽屏 */
```

### Touch Targets
- 所有可点击元素最小 **44×44px**（移动端按钮 padding 至少 `12px 16px`）。

### 折叠策略
- `< 1024px`：右预览栏收起为抽屉（图标触发）；左导航变底部 Tab 或汉堡。
- `< 768px`：工作台三栏 → 单栏纵向堆叠；Hero 三卡 → 1 列；表单控件全宽。
- `< 640px`：网格 12 列 → 1 列；表格横向滚动。

### Font Scaling
- 移动端 `display-hero` 降至 28px，其余层级不变；`body` 保持 14px 不缩。
- 触控设备增大按钮 padding，不增大字号（避免错位）。

---

## 9. Agent Prompt Guide

### Quick Reference
- 技术栈：Laravel 11 + Livewire Flux v2 + Tailwind v4 + Inter。主色 `--color-brand-600 (#4f46e5)`。
- 设计语言：白底 + indigo 锚点 + 功能分区多彩（出片翠绿/配音紫/剪辑玫红/字幕靛蓝）。
- 所有新组件必须用 `luxury-glass` 卡片 + 1px 边框 + 柔阴影；输入框必须有 placeholder。
- 暗色模式仅切换 `--color-bg/border/surface` 变量，结构不变。

### Component Prompts（可直接复制使用）
1. `基于 DESIGN.md 生成一个「出片任务卡片」组件：luxury-glass 卡片，左侧翠绿 tool-icon，标题 body-strong，状态 badge-success，右下 btn-primary「查看」`
2. `基于 DESIGN.md 实现「选题维度筛选」表单：4 个 select + 行业 input，所有控件用 .input 样式且带 placeholder，提交按钮 btn-primary`
3. `基于 DESIGN.md 做「平台预设封面库」网格：分类 Tab + 8 列网格，封面卡片 hover 用 --shadow-md，收藏按钮 btn-ghost`
4. `基于 DESIGN.md 生成「人工审核模态框」：modal-overlay+panel，左视频预览右元数据，底部 btn-secondary 驳回 + btn-primary 通过`
5. `基于 DESIGN.md 实现「发布状态看板」：6 平台横向卡片，每卡含平台 icon(功能色)+状态 badge+重试 btn-ghost`
6. `基于 DESIGN.md 做空态组件：icon 用 slate-300，标题 h3，说明 small，主操作 btn-primary`

### Iteration Guide（AI 生成 UI 时的迭代建议）
1. 先用 `--color-brand-600` 锚定主按钮，再决定其余控件是否需次级样式。
2. 任何新增颜色必须先定义 CSS 变量，禁止散落 HEX。
3. 间距一律走 4px 基数 Token，不要写 `padding: 13px` 这类非标值。
4. 暗色适配：只改变量引用，不要把 `bg-white` 硬改成 `dark:bg-[#0b0d1a]` 之外的任意值。
5. 图标统一线性 SVG（stroke 1.5），不用 emoji、不用填充卡通图标。
6. 阴影只从 §6 Shadow System 取，禁止临时 `box-shadow: 0 10px 20px black`。
7. 表单必须有 placeholder，且文案口语化（如「输入视频号标题，建议 15 字内」）。
8. 状态反馈：加载用骨架屏或 spinner，错误用 rose 文案 + 内联提示，不要用 alert()。
9. 移动端必须验证 44px 触控目标与三栏折叠，不达标不准合入。
10. 每次改动后跑 `npm run build`（Vite）确认无 Tailwind 编译错误。
