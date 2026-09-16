# 慧根堂短视频平台 · 全格式出片总览（2026-09-13）

主题：**老板用个人卡收公司货款的税务风险**
输出目录：`D:/heygem_data/hgt-commercial/python-pipeline/produce_all_20260913_014848/`

## 一、6 种短视频形式（全部渲染完成，QC 通过）

| 形式 | 文件 | 大小 | QC | 时长 |
|------|------|------|----|------|
| 滚动字幕 scroll | videos/scroll.mp4 | 1.4 MB | — | — |
| 数字人 avatar | videos/avatar.mp4 | 5.4 MB | — | — |
| 幕后音 motion | videos/motion.mp4 | 10.2 MB | 100 分/低风险 | 36.6s |
| 漫剧 manga | videos/manga.mp4 | 11.9 MB | 100 分/低风险 | 32.6s |
| 白板 whiteboard | videos/whiteboard.mp4 | 0.6 MB | 100 分/低风险 | 20.5s |
| 图解版 card | videos/card.mp4 | 0.9 MB | 100 分/低风险 | 32.5s |

## 二、小红书（7 张图）

`xhs/` 目录：xhs_00.png ~ xhs_06.png，共 7 张（1080×1440 封顶）。

## 三、公众号文章

`article.md`（约 1639 字，SEO 评分 95 / good）
- 含 3 个备用标题、摘要、5 个话题标签
- 正文分 4 个板块：风险有哪些 → 为什么容易被查 → 账上怎么处理 → 还能补救吗
- 政策依据：税收征管法 §32/§63、财税〔2003〕158 号（均已标注"以主管税务机关口径为准"）

## 四、质检结论

- 4 个走 QC 流水线的视频（motion/manga/whiteboard/card）全部 **score=100 / 低风险 / passed**
- avatar 走 HEYGEM 数字人管线（独立质检），scroll 为滚动字幕，均无异常

## 五、配套说明

- v1 主控：`produce_all_formats.py`（公众号文章 + 小红书 + scroll + avatar）
- v2 补跑：`produce_remaining.py`（motion/manga/whiteboard/card，串行避 429）
- 明细清单：`manifest.json`（v1）+ `videos_extra_manifest.json`（v2）
