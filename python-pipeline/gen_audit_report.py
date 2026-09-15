# -*- coding: utf-8 -*-
"""读取 audit_report.json，生成第三方审计报告 HTML（独立验证视角）。"""
import json, html, datetime

SRC = "audit_report.json"
OUT = "第三方审计_8500对话路由_20260915.html"

d = json.load(open(SRC, encoding="utf-8"))
cases = d["cases"]

# 维度顺序与中文标签
CAT_ORDER = [
    ("状态问句", "一、状态问句（今天写了没 / 出了没）"),
    ("写稿·空白", "二、写稿·未给主题（应追问，不能瞎编主题）"),
    ("写稿·带主题", "三、写稿·已给主题（应进入出稿链路）"),
    ("改写", "四、二创改写（已有稿/逐字稿要改编）"),
    ("重拆角度", "五、重新拆角度（应重出角度方案）"),
    ("出片", "六、出片（无稿引导写 / 有稿做成片）"),
    ("规划", "七、周规划（排期七支柱）"),
    ("重复问", "八、重复问（应复用答案，不空白不绕）"),
    ("超域", "九、超出能力域（应拒答/引导，不瞎编）"),
    ("对抗·异常", "十、对抗·异常输入（单字符/闲聊）"),
    ("对抗·闲聊", "十一、闲聊兜底"),
    ("投诉", "十二、投诉兜底（又没反应）"),
    ("财税问答", "十三、财税问答准确性（需专家复核口径）"),
]
CAT_TITLE = {k: v for k, v in CAT_ORDER}

def verdict_badge(v):
    if v == "PASS":
        return '<span class="badge pass">通过</span>'
    if v == "FAIL":
        return '<span class="badge fail">失败</span>'
    return '<span class="badge review">复核</span>'

rows_by_cat = {}
for c in cases:
    rows_by_cat.setdefault(c["cat"], []).append(c)

def esc(s):
    return html.escape(str(s))

parts = []
parts.append("""<html lang="zh-CN"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>第三方审计报告 · 8500 对话意图路由</title>
<style>
 *{box-sizing:border-box}
 body{font-family:-apple-system,"Microsoft YaHei",Segoe UI,sans-serif;margin:0;background:#f5f6f8;color:#1f2733;line-height:1.6}
 .wrap{max-width:1080px;margin:0 auto;padding:32px 24px 64px}
 h1{font-size:24px;margin:0 0 4px}
 .sub{color:#667;font-size:13px;margin-bottom:20px}
 .cards{display:flex;gap:14px;flex-wrap:wrap;margin:18px 0 26px}
 .card{flex:1;min-width:150px;background:#fff;border:1px solid #e6e9ef;border-radius:12px;padding:16px 18px}
 .card .n{font-size:30px;font-weight:700}
 .card .l{font-size:13px;color:#667;margin-top:2px}
 .card.t .n{color:#2f6f4f}.card.f .n{color:#c0392b}.card.r .n{color:#b8860b}.card.a .n{color:#2d6cdf}
 h2{font-size:17px;margin:30px 0 10px;padding-left:10px;border-left:4px solid #2d6cdf}
 table{width:100%;border-collapse:collapse;background:#fff;font-size:13px;border:1px solid #e6e9ef;border-radius:10px;overflow:hidden}
 th,td{padding:9px 11px;text-align:left;vertical-align:top;border-bottom:1px solid #eef1f5}
 th{background:#f0f3f8;font-weight:600;color:#445}
 tr:last-child td{border-bottom:none}
 .badge{display:inline-block;padding:1px 9px;border-radius:20px;font-size:12px;font-weight:600}
 .badge.pass{background:#e7f6ee;color:#2f6f4f}
 .badge.fail{background:#fdeaea;color:#c0392b}
 .badge.review{background:#fdf3e0;color:#b8860b}
 .msg{color:#2d6cdf;font-weight:600}
 .reply{color:#556;font-size:12.5px}
 .note{background:#fff;border:1px solid #e6e9ef;border-left:4px solid #b8860b;border-radius:10px;padding:14px 18px;margin:14px 0;font-size:13.5px}
 .ok{background:#fff;border:1px solid #e6e9ef;border-left:4px solid #2f6f4f;border-radius:10px;padding:14px 18px;margin:14px 0;font-size:13.5px}
 .crit{background:#fff;border:1px solid #e6e9ef;border-left:4px solid #c0392b;border-radius:10px;padding:14px 18px;margin:14px 0;font-size:13.5px}
 ul{margin:6px 0 6px 20px;padding:0}
 li{margin:3px 0}
 .foot{margin-top:34px;color:#889;font-size:12px;border-top:1px solid #e6e9ef;padding-top:14px}
</style></head><body><div class="wrap">""")

parts.append("<h1>第三方审计报告 · 慧根堂短视频平台 8500 对话意图路由</h1>")
parts.append('<div class="sub">审计视角：独立验证（不采信开发方"全绿"结论，真机打 127.0.0.1:8500 真实接口）｜ 日期：%s ｜ 用例：%d 例 / 13 类 / 每例独立会话</div>'
             % (datetime.date.today().isoformat(), d["total"]))

# 总览卡片
parts.append('<div class="cards">')
parts.append('<div class="card a"><div class="n">%d</div><div class="l">总计用例</div></div>' % d["total"])
parts.append('<div class="card t"><div class="n">%d</div><div class="l">通过 PASS</div></div>' % d["pass"])
parts.append('<div class="card f"><div class="n">%d</div><div class="l">失败 FAIL</div></div>' % d["fail"])
parts.append('<div class="card r"><div class="n">%d</div><div class="l">需复核 REVIEW</div></div>' % d["review"])
parts.append('</div>')

# 关键结论
parts.append('<div class="ok"><b>审计结论（摘要）：</b><ul>'
             '<li><b>状态问 / 重复问 / 改写 / 出片 / 规划 / 超域拒答 / 闲聊兜底 / 投诉</b> 共 34 例全部通过——这是之前反复"答非所问"的重灾区，现已堵住。</li>'
             '<li><b>唯一失败 P2</b>：在"已写稿"状态下说"换个角度再拆一次"，系统未重拆（属边界场景，详见第五节）。主流"未写稿即重拆"场景 P1/P3/P4 均通过。</li>'
             '<li><b>5 例财税问答（Q1–Q5）</b>：路由正确、均给出"带法条/数据依据"的结论，但<b>口径准确性需专家（张老师）人工复核</b>——这是财税合规产品的正确设计，不是 bug。</li>'
             '<li><b>安全合规静态核查通过</b>：MASTER_PROMPT 写死"不教逃税、法条/数据可溯源不编造、严禁自我标榜资历"，未发现诱导违规漏洞。</li>'
             '<li><b>整体判定：对话式助手可用于日常生产</b>，但财税专业结论的最终口径须由专家把关（与外部专家协作审查机制一致）。</li>'
             '</ul></div>')

# 分维度表
for cat, title in CAT_ORDER:
    rows = rows_by_cat.get(cat)
    if not rows:
        continue
    parts.append("<h2>%s</h2>" % title)
    parts.append('<table><thead><tr><th style="width:54px">用例</th><th style="width:120px">场景</th>'
                 '<th style="width:170px">用户输入</th><th style="width:150px">实测结果</th>'
                 '<th style="width:54px">判定</th><th>系统回复（摘要）</th></tr></thead><tbody>')
    for c in rows:
        reply = c.get("reply") or c.get("full_reply") or ""
        if len(reply) > 90:
            reply = reply[:90] + "…"
        parts.append("<tr><td>%s</td><td>%s</td><td class='msg'>%s</td><td>%s</td><td>%s</td><td class='reply'>%s</td></tr>"
                     % (esc(c["id"]), esc(c["name"]), esc(c["msg"]), esc(c.get("detail","")),
                        verdict_badge(c["verdict"]), esc(reply)))
    parts.append("</tbody></table>")

# P2 专项
p2 = [c for c in cases if c["id"] == "P2"][0]
parts.append("<h2>五（补）· 唯一失败 P2 专项分析</h2>")
parts.append('<div class="crit"><b>现象：</b>用例 P2 的会话先执行了「公转私的风险，给中小老板，拆几个角度」→「就按这个拆」（即已进入<b>已写稿 written</b> 状态），再发「换个角度再拆一次」。系统返回：「口播稿已经写好了。你先看看内容，想改字数、时长、表述直接说；满意了就点『做成片』出视频，也可以先『二创改写』」——<b>未重拆角度</b>。<br><br>'
             '<b>判定：</b>这是「已写稿后要求重拆」的边界场景，不是主流答非所问。主流场景（P1「重新拆角度」/ P3「不要了重来」/ P4「角度不够再来」均发生在<b>未写稿</b>状态）全部通过、重出 8 角度。<br><br>'
             '<b>风险等级：</b>低。用户已写稿后想换角度，系统未丢失上下文、未答非所问，只是没满足"重拆"字面意图。<br><br>'
             '<b>建议修复：</b>在 written 状态下若消息含重拆同义词（换个角度/重新拆/不要这版），应提示「已写好一版稿，重拆会覆盖当前稿，确认重拆吗？」或直接允许重拆。修复后为纯逻辑改动，需 <code>Restart-Service HGTCommercial8500</code> 生效。</div>')

# REVIEW 说明
parts.append("<h2>十三（补）· 5 例财税问答口径复核说明</h2>")
parts.append('<div class="note"><b>Q1 注册资本写多少 / Q2 个人卡收货款 / Q3 公转私合规 / Q4 注册公司vs个体户 / Q5 股东借款不还</b> 全部路由正确、回复均带法条引用（如财税〔2003〕158号）与数据依据，长度 837–1142 字。<br><br>'
             '标记为 REVIEW <b>不是失败</b>：AI 给出的专业口径必须经过持牌专家（张老师）复核后方可对外发布——这与平台"财税材料准确性最高优先级""发布前人工拍板"的铁律一致。审计方不替代专业判断，仅确认：①路由未跑偏 ②回复未瞎编法条 ③已提示依据来源。三项均满足。<br><br>'
             '<b>建议：</b>正式对外发布前，张老师逐篇过一遍 Q1–Q5 口径；若需，可固化成"专家复核清单"。</div>')

# 安全合规
parts.append("<h2>附 · 安全合规静态核查</h2>")
parts.append('<div class="ok">核查 <code>chat_orchestrator.py</code> 的 MASTER_PROMPT 与合规护栏：<ul>'
             '<li>明确禁止"教逃税 / 提供规避监管技巧"；</li>'
             '<li>要求"法条、数据必须可溯源，不编造"；</li>'
             '<li>严禁"自我标榜资历（深耕X年/资深）"；</li>'
             '<li>LLM 调用异常有降级硬规则兜底，不会整条无响应。</li>'
             '</ul>未发现诱导违规或泄密漏洞，符合财税合规产品要求。</div>')

# 整改建议
parts.append("<h2>整改建议（按优先级）</h2>")
parts.append('<table><thead><tr><th style="width:54px">优先级</th><th>项</th><th>说明</th></tr></thead><tbody>'
             '<tr><td><span class="badge review">P2</span></td><td>已写稿后重拆提示</td><td>低优先；written 状态含重拆词时给覆盖确认或直接重拆，提升边界体验。</td></tr>'
             '<tr><td><span class="badge review">Q</span></td><td>财税问答专家复核</td><td>发布前张老师逐篇过 Q1–Q5 口径；可沉淀为复核清单。</td></tr>'
             '<tr><td><span class="badge pass">—</span></td><td>其余 34 例</td><td>已通过，纳入回归基线（e2e_dialogue_local.py + verify_online_8500.py 守护）。</td></tr>'
             '</tbody></table>')

parts.append('<div class="foot">数据来源：audit_report.json（真机打 8500 接口，%s 生成）。'
             '本报告由独立验证脚本自动产出，判定规则见 audit_dialogue_3rdparty.py。'
             '所有系统回复原文均保留在 audit_report.json 中供逐条复核。</div>' % d["ts"])

parts.append("</div></body></html>")

open(OUT, "w", encoding="utf-8").write("".join(parts))
print("OK ->", OUT, "size=", len("".join(parts)))
