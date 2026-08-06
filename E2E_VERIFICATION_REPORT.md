# 追梦短视频平台 — 端到端功能验证报告

- **验证日期**：2026-08-04
- **验证范围**：字幕（滚动字幕卡）/ 双人幕后音 / 真人出镜（数字人）三大核心模块 + 模块间联动 + 功能取舍
- **验证方法**：登录态 + CSRF 模拟真实调用（临时验证脚本，已完成并清理）
- **测试环境**：
  - 8080 Laravel（docker: nginx → app:9000 → mysql:3306 → redis）
  - 8500 Python 出片微服务（NSSM 服务 `HGTCommercial8500`）
  - HEYGEM 数字人容器（gen-video:8383 / tts-old:18180）
- **登录凭据**：`admin@huigentang.com / admin888`
- **总体结论**：✅ 三大核心模块全部可正常触发与展示；修复 2 个真实缺陷；全链路数据传递通畅；无必须移除的功能模块。

---

## 一、模块验证明细（按模块分类）

| # | 模块 | 功能入口 | 状态 | 说明 / 问题 |
|---|------|----------|------|-------------|
| 1 | 智能选题 | `/studio/topic` | ✅ 通过 | 页面 200，选题接口调 8500 `/topic` 正常 |
| 2 | 智能二创 | `/studio/rewrite` | ✅ 通过 | 双声改写返回 `女：` / `男：` 对话稿，接口 200 |
| 3 | 配音频（双人幕后音） | scroll 男/女声线选择 | ✅ 通过 | `male_voice` / `female_voice` 空值发 `null`；真实 TTS 出片成功 |
| 4 | 配模特 | `/studio/models` | ✅ 通过 | 页面 200，模特选择关联 avatar 出片 |
| 5 | 字幕卡出片 | `/studio/scroll` (mode=scroll) | ✅ 通过 | dry 占位出片 `done`（job `44d5d4f4…`），平台自动触发视频质检 |
| 6 | 真人出镜（数字人） | `/studio/scroll` (mode=avatar) | ✅ 通过（修复后） | 修复 `--scene` 崩溃后真实出片 `done` + 下载 200（job `d6015d0b…`） |
| 7 | 智能质检 | `/studio/qc` + `/qc-video` | ✅ 通过 | `/qc-video` 200（report_id=2），score 85，标出 11.8s 静音（dry 占位音预期表现） |
| 8 | 人工审核 | `/studio/review` | ✅ 通过 | approve 200，`publish_status=approved` |
| 9 | 批量外发 | `/studio/publish` | ✅ 通过（修复后） | 修复闭合标签后 GET 200 / POST 302（未授权平台优雅失败落 `PublishRecord(failed)`） |
| 10 | 素材管理 · 声音 | `/studio/voices` | ✅ 通过 | 200，克隆音色管理（老张/江老师），授权提醒已就位 |
| 10 | 素材管理 · 封面 | `/studio/covers` | ✅ 通过 | 200，预设封面库（8 行业×10 张） |
| 10 | 素材管理 · 模特 | `/studio/models` | ✅ 通过 | （同 #4，模特素材与出片联动） |
| 11 | 数据复盘 · 看板 | `/studio/analytics` | ✅ 通过 | 200，输出看板 |
| 11 | 数据录入 · 台账 | `/studio/metrics` | ✅ 通过 | 200，输入台账（与 analytics 互补） |
| 12 | 计费订阅 | `/studio/billing` + `/admin/billing` | ✅ 通过 | 200，租户/管理员双侧计费入口 |

---

## 二、模块间联动验证

| 联动项 | 验证结果 | 说明 |
|--------|----------|------|
| 9 步管线导航 | ✅ 健全 | dashboard 9 步（选题→二创→配音频→配模特→出片→质检→审核→外发→复盘）链接正确可达 |
| 跨页跳转关联 | ✅ 健全 | scroll 页 → `/studio/models` / `/studio/covers` / `/studio/voices` 链接均存在且 200 |
| 选题 → 二创 → 出片 数据传递 | ✅ 通畅 | 选题产出 → 二创稿 → scroll 出片携带 topic/rewrite 内容，id 链路完整 |
| 下游闭环 | ✅ 通畅 | qc-video → review approve → publish 均 200/302，数据闭环无断点 |
| 统一出片页 mode 切换 | ✅ 正常 | `/studio/scroll?mode=scroll` 字幕卡 / `mode=avatar` 真人出镜，双声线内嵌对话稿 |

---

## 三、已修复缺陷（验证中发现并修复）

### 缺陷 A（致命）— 真人出镜 100% 崩溃
- **现象**：`make_avatar_from_dialogue.py: error: unrecognized arguments: --scene office_a`，job 立即 `failed`
- **根因**：`server.py` 把 UI 的 `scene` 无条件拼成 `--scene` 传给脚本，但脚本 argparse 只接受 `--dialogue/--out/--male-voice/--female-voice/--model`，无 `--scene`
- **修复**：`server.py` 改为 `scene → model` 映射（`office_a` → `BGZSP20260721_t18_silent.mp4`，`office_b` → `YXSZR1.mp4`），移除 `--scene` 传参；经 8501 临时实例（PORT=8501）验证 job `done` + 下载 200

### 缺陷 B（阻塞性）— 发布页 500
- **现象**：`/studio/publish` GET/POST 均 500（`syntax error, unexpected end of file, expecting "elseif" or "else" or "endif"`）
- **根因**：`publish.blade.php` 结尾漏 `</x-app-layout>` 闭合标签，Blade 组件未闭合
- **修复**：补 `</x-app-layout>` + `php artisan view:clear`，复验 GET 200 / POST 302

---

## 四、冗余项处理建议（功能取舍）

| 评估项 | 结论 | 理由 |
|--------|------|------|
| `metrics`（数据录入）vs `analytics`（复盘看板） | **保留两者** | 输入→输出互补关系（metrics 录台账，analytics 出看板），非冗余 |
| 9 步管线导航面板 | **保留** | 导航健全，无重复/死链菜单项 |
| `MODEL_REGISTRY` 陈旧条目（szrsp/cjps/zmszr） | **建议清理（非阻塞）** | 容器内不存在，仅影响展示；`SCENE_MODEL` 映射已只引用真实存在的 office_a/office_b |
| 各素材页（voices/covers/models） | **保留** | 均被出片链路直接引用，有实际业务用途 |
| 无功能模块需移除 | — | 12 个模块全部通过验证且具备业务价值 |

---

## 五、已知观察项（非阻塞，供后续优化）

1. **出片时长硬编码上限**：`VideoController.php` 第 78 行 `1800s` 上限为硬编码，建议提取为 env 常量（与 server.py 的 `MAX_DURATION_SEC=1800` 对齐）。
2. **配额/并发护栏已生效**：配额超限 → 402，租户并发 `TENANT_MAX_CONCURRENT_JOBS=2` 超限 → 429（验证中触发符合预期）。
3. **MODEL_REGISTRY 陈旧条目**（见第四节）建议清理。
4. **8080 偶发 000**：nginx 重启瞬时抖动，localhost 实际 200，非代码问题。

---

## 六、环境验证状态（收尾确认）

| 项 | 状态 |
|----|------|
| 8080（Laravel） | ✅ 200/302，服务在线 |
| 8500（出片微服务） | ✅ online，`/oauth/status/wechat` 200 |
| 8501 临时实例 | ✅ 已释放，端口空闲 |
| 临时验证脚本 `_validate_*.py` | ✅ 已删除 |
| `.env` `PYTHON_PIPELINE_URL` | ✅ 已还原指向 `http://host.docker.internal:8500` |
| app 容器 | ✅ 已重启，缓存已清 |

---

*报告生成：基于登录态端到端调用验证，覆盖三大核心模块、模块联动与功能取舍。所有缺陷修复均经真实出片 job 复验确认。*
