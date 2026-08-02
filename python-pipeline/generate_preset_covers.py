#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成平台预设封面库：8 个财税行业分类，每类 10 张
  - 8 张矢量 SVG（渐变 + 几何 + 排版，竖版 1080x1920，浏览器原生渲染，体积小）
  - 2 张真实动画「海浪」GIF（PIL 绘制，竖版 720x1280，循环波浪 + 中文标题）

输出目录：<项目>/storage/app/covers/presets/{slug}/
并写入：<项目>/storage/app/covers/presets/manifest.json
供 artisan covers:seed-presets 读取建库。

依赖：Pillow（仅 GIF 需要；SVG 为纯字符串，无第三方依赖）。
运行：python generate_preset_covers.py
"""
import os
import json
import math
import colorsys

try:
    from PIL import Image, ImageDraw, ImageFont
    HAS_PIL = True
except Exception:
    HAS_PIL = False

BASE = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "storage", "app", "private", "covers", "presets"))
FONT_REG = "C:/Windows/Fonts/msyh.ttc"
FONT_BOLD = "C:/Windows/Fonts/msyhbd.ttc"

# 行业分类：slug / 展示名 / 配色[深,中,亮] / 10 组(主标题, 副标题)
CATEGORIES = [
    {
        "slug": "tax_risk", "label": "税务风险",
        "palette": ["#0f2c4c", "#1e5aa8", "#4f9cf0"],
        "items": [
            ("税务风险自查清单", "老板必看的5条红线"),
            ("虚开发票的三大坑", "一张发票的连锁反应"),
            ("金税四期下的预警", "你的账本正在被盯着"),
            ("公转私的合规边界", "老板从公司拿钱怎么安全"),
            ("暂估成本的风险", "年底暂估别乱用"),
            ("个人卡流水过大", "私户收款的隐形代价"),
            ("税务稽查重点", "这6类企业最易被查"),
            ("留抵退税的陷阱", "退了也可能被追回"),
            ("个税筹划的底线", "别碰这些违规操作"),
            ("发票合规自查", "进销项要匹配"),
        ],
    },
    {
        "slug": "construction", "label": "建筑财税",
        "palette": ["#10242e", "#2c5364", "#4ca1af"],
        "items": [
            ("建筑行业财税痛点", "挂靠·分包·材料票"),
            ("挂靠项目的税务风险", "资质背后谁在买单"),
            ("劳务分包怎么合规", "农民工工资专户必懂"),
            ("甲供材的税务处理", "选错方式多交税"),
            ("建筑发票新规", "跨区域预缴别漏了"),
            ("项目经理私户收款", "项目利润去哪了"),
            ("材料票缺失怎么办", "无票支出如何合规"),
            ("竣工结算税务点", "质保金涉税处理"),
            ("建筑企业社保筹划", "临时用工合规路径"),
            ("EPC项目财税差异", "总包分包税会不同"),
        ],
    },
    {
        "slug": "gongzhisi", "label": "公转私",
        "palette": ["#241344", "#5b2a86", "#b06ab3"],
        "items": [
            ("公转私的合规通道", "老板取钱不踩雷"),
            ("股东分红怎么交税", "20%个税能省吗"),
            ("备用金借支合规", "借款超期变收入"),
            ("老板车房归公司", "资产剥离的税筹"),
            ("工资与分红平衡", "到手更多怎么搭"),
            ("个人借款涉税", "年底必须还回去"),
            ("公司买楼写谁名", "持有结构税差大"),
            ("未分配利润怎么用", "不分红也有解法"),
            ("家族企业传承税", "提前布局少交税"),
            ("公转私红线清单", "这5种方式别碰"),
        ],
    },
    {
        "slug": "golden_tax", "label": "金税四期",
        "palette": ["#2b1d0e", "#8a6d1f", "#d4af37"],
        "items": [
            ("金税四期查什么", "企业画像全透明"),
            ("大数据比对预警", "进销项不匹配就亮灯"),
            ("银税互动的影响", "私户被打通了吗"),
            ("个税与社保联动", "工资表藏不住了"),
            ("发票全生命周期", "从领购到冲红全监控"),
            ("税收优惠实名制", "享受优惠要留痕"),
            ("非税收入也入网", "残保金等别漏报"),
            ("跨区域税源监控", "外地项目无所遁形"),
            ("税收信用修复", "失信后还能救吗"),
            ("金税四期应对清单", "企业合规三步走"),
        ],
    },
    {
        "slug": "cost_expense", "label": "成本费用",
        "palette": ["#0f3d2e", "#1f7a5a", "#4cbf8f"],
        "items": [
            ("成本费用税前扣除", "这些票才能抵"),
            ("业务招待费限额", "超标部分白花了"),
            ("差旅费怎么报销", "合规凭证四要素"),
            ("研发费用加计扣除", "别浪费政策红利"),
            ("折旧与摊销选择", "加速折旧省当期"),
            ("无票支出的解法", "真实业务也能合规"),
            ("工资薪金的边界", "发多少最划算"),
            ("咨询费涉税风险", "大额咨询易被查"),
            ("成本暂估的合规", "跨年取得发票"),
            ("费用归集的艺术", "利润平滑有方法"),
        ],
    },
    {
        "slug": "policy", "label": "政策解读",
        "palette": ["#3a0d12", "#8a1f2b", "#d64550"],
        "items": [
            ("最新减税政策解读", "小微企业再受益"),
            ("增值税留抵新规", "谁能退怎么退"),
            ("加计扣除扩围", "制造业利好速览"),
            ("个税专项扣除", "每月多留一点"),
            ("税收协定红利", "跨境业务别错过"),
            ("社保费率调整", "用工成本变化"),
            ("小微企业标准", "你还在范围内吗"),
            ("税务注销便利化", "退出也省心"),
            ("数电票全面推开", "开票方式大变"),
            ("政策红利落地", "申报时注意啥"),
        ],
    },
    {
        "slug": "lead_hook", "label": "留资钩子",
        "palette": ["#3a1c0a", "#c05621", "#f5933b"],
        "items": [
            ("免费财税体检", "留下联系方式领取"),
            ("企业税务健康吗", "3分钟自测报告"),
            ("老板财税答疑群", "每周直播干货"),
            ("领取节税方案", "一对一诊断"),
            ("扫码获取工具包", "合同模板免费送"),
            ("限时财税咨询", "前20名免单"),
            ("测测你的风险分", "高分企业注意了"),
            ("加我领资料", "财税干货每日更新"),
            ("预约一对一", "专家帮你算笔账"),
            ("关注领福利", "每周三场直播"),
        ],
    },
    {
        "slug": "general", "label": "通用模板",
        "palette": ["#1e293b", "#334155", "#94a3b8"],
        "items": [
            ("老张讲财税", "20年实战经验"),
            ("老板财税必修课", "从小白到懂行"),
            ("财税干货每天见", "关注不迷路"),
            ("专业·实战·落地", "只讲能用的"),
            ("企业合规第一步", "从读懂报表开始"),
            ("财税知识卡片", "一图看懂"),
            ("案例拆解系列", "真实企业复盘"),
            ("避坑指南", "少走弯路省钱"),
            ("老板听得懂的财税", "不说官话"),
            ("关注老张", "持续输出价值"),
        ],
    },
]

W, H = 1080, 1920  # SVG 竖版
FONT_STACK = "'Microsoft YaHei','PingFang SC','Noto Sans CJK SC','Source Han Sans SC',sans-serif"


def esc(s: str) -> str:
    return (s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
            .replace('"', "&quot;").replace("'", "&apos;"))


def wrap_cjk(text: str, per: int):
    """按字符数折行（中文无空格）。"""
    lines, cur = [], ""
    for ch in text:
        cur += ch
        if len(cur) >= per:
            lines.append(cur)
            cur = ""
    if cur:
        lines.append(cur)
    return lines


def hex2rgb(h: str):
    h = h.lstrip("#")
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def svg_cover(palette, title, subtitle, idx):
    dark, mid, accent = palette
    ang = idx * 22.5  # 渐变角度随序号变化
    blob_layouts = [
        ((820, 300, 520, 520), (180, 1500, 460, 460)),
        ((900, 120, 460, 460), (260, 1400, 520, 520)),
        ((760, 420, 560, 560), (120, 1380, 420, 420)),
        ((880, 260, 480, 480), (220, 1520, 500, 500)),
    ]
    b1, b2 = blob_layouts[idx % len(blob_layouts)]
    title_lines = wrap_cjk(title, 8)
    sub_lines = wrap_cjk(subtitle, 16)

    parts = []
    parts.append(
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" '
        f'viewBox="0 0 {W} {H}">'
    )
    parts.append('<defs>')
    parts.append(
        f'<linearGradient id="bg" x1="0" y1="0" x2="0" y2="1" '
        f'gradientTransform="rotate({ang} 0.5 0.5)">'
        f'<stop offset="0" stop-color="{dark}"/>'
        f'<stop offset="1" stop-color="{mid}"/></linearGradient>'
    )
    parts.append(
        f'<radialGradient id="glow" cx="0.8" cy="0.15" r="0.7">'
        f'<stop offset="0" stop-color="{accent}" stop-opacity="0.45"/>'
        f'<stop offset="1" stop-color="{accent}" stop-opacity="0"/></radialGradient>'
    )
    parts.append(
        '<filter id="soft" x="-50%" y="-50%" width="200%" height="200%">'
        '<feGaussianBlur stdDeviation="60"/></filter>'
    )
    parts.append('</defs>')

    # 背景 + 右上角光晕
    parts.append(f'<rect width="{W}" height="{H}" fill="url(#bg)"/>')
    parts.append(f'<rect width="{W}" height="{H}" fill="url(#glow)"/>')

    # 有机光斑（模糊椭圆，营造层次）
    parts.append(f'<g filter="url(#soft)" opacity="0.55">')
    parts.append(f'<ellipse cx="{b1[0]}" cy="{b1[1]}" rx="{b1[2]}" ry="{b1[3]}" '
                 f'fill="{accent}" opacity="0.5"/>')
    parts.append(f'<ellipse cx="{b2[0]}" cy="{b2[1]}" rx="{b2[2]}" ry="{b2[3]}" '
                 f'fill="#ffffff" opacity="0.10"/>')
    parts.append('</g>')

    # 角部同心弧（精致几何点缀）
    parts.append(f'<g fill="none" stroke="{accent}" stroke-opacity="0.25">')
    for r in (260, 340, 420):
        parts.append(f'<circle cx="1080" cy="0" r="{r}" stroke-width="2"/>')
    parts.append('</g>')

    # 分类标签胶囊
    parts.append(f'<g>'
                 f'<rect x="80" y="120" rx="40" ry="40" width="300" height="84" '
                 f'fill="#ffffff" fill-opacity="0.14" stroke="{accent}" stroke-opacity="0.6"/>'
                 f'<text x="230" y="172" font-family="{FONT_STACK}" font-size="42" '
                 f'fill="#ffffff" text-anchor="middle" opacity="0.92">平台模板</text>'
                 f'</g>')

    # 主标题（多行，白字带阴影）
    ty = 1180
    line_h = 128
    for i, ln in enumerate(title_lines[:3]):
        y = ty + i * line_h
        parts.append(f'<text x="80" y="{y}" font-family="{FONT_STACK}" font-weight="700" '
                     f'font-size="104" fill="#ffffff" '
                     f'style="paint-order:stroke;stroke:{dark};stroke-width:6px;stroke-opacity:0.35">'
                     f'{esc(ln)}</text>')
    # 副标题
    sy = ty + len(title_lines[:3]) * line_h + 30
    for ln in sub_lines[:2]:
        parts.append(f'<text x="80" y="{sy}" font-family="{FONT_STACK}" font-size="46" '
                     f'fill="{accent}" opacity="0.95">{esc(ln)}</text>')
        sy += 64

    # 底部分隔线 + 小字
    parts.append(f'<line x1="80" y1="1700" x2="1000" y2="1700" stroke="#ffffff" '
                 f'stroke-opacity="0.25" stroke-width="2"/>')
    parts.append(f'<text x="80" y="1770" font-family="{FONT_STACK}" font-size="34" '
                 f'fill="#ffffff" opacity="0.6">财税短视频 · 封面模板</text>')

    parts.append('</svg>')
    return "\n".join(parts)


# ---------------- 动画海浪 GIF ----------------
def get_font(size, bold=False):
    path = FONT_BOLD if bold else FONT_REG
    try:
        return ImageFont.truetype(path, size)
    except Exception:
        return ImageFont.load_default()


def make_gradient_bg(w, h, top, bottom):
    """竖向渐变背景 RGB。"""
    img = Image.new("RGB", (w, h))
    t = hex2rgb(top)
    b = hex2rgb(bottom)
    px = img.load()
    for y in range(h):
        r = int(t[0] + (b[0] - t[0]) * y / (h - 1))
        g = int(t[1] + (b[1] - t[1]) * y / (h - 1))
        bl = int(t[2] + (b[2] - t[2]) * y / (h - 1))
        for x in range(w):
            px[x, y] = (r, g, bl)
    return img


def gif_cover(palette, title, subtitle, idx, out_path):
    if not HAS_PIL:
        raise RuntimeError("Pillow 不可用，无法生成 GIF")
    w, h = 720, 1280
    dark, mid, accent = palette
    base = make_gradient_bg(w, h, dark, mid)
    # 右上角光晕
    glow = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    gd = ImageDraw.Draw(glow)
    ac = hex2rgb(accent)
    gd.ellipse([w * 0.35, -h * 0.35, w * 1.35, h * 0.4], fill=(ac[0], ac[1], ac[2], 110))
    base = Image.composite(glow, base.convert("RGBA"), glow).convert("RGB")

    frames = 48
    durations = [60] * frames
    out_frames = []
    accent_rgba = (ac[0], ac[1], ac[2], 70)
    white_rgba = (255, 255, 255, 38)
    layer_specs = [
        {"amp": 26, "wl": 360, "speed": 0.06, "base_y": 0.62, "color": accent_rgba},
        {"amp": 18, "wl": 260, "speed": -0.09, "base_y": 0.70, "color": white_rgba},
        {"amp": 34, "wl": 520, "speed": 0.04, "base_y": 0.78, "color": (ac[0], ac[1], ac[2], 45)},
    ]
    for f in range(frames):
        frame = base.copy()
        for spec in layer_specs:
            phase = f * spec["speed"] * math.pi * 2
            by = int(h * spec["base_y"])
            amp = spec["amp"]
            wl = spec["wl"]
            layer = Image.new("RGBA", (w, h), (0, 0, 0, 0))
            ld = ImageDraw.Draw(layer)
            pts = [(0, h)]
            steps = 60
            for i in range(steps + 1):
                x = int(i / steps * w)
                y = by + int(amp * math.sin(2 * math.pi * (x / wl) + phase))
                pts.append((x, y))
            pts.append((w, h))
            ld.polygon(pts, fill=spec["color"])
            frame = Image.composite(layer, frame.convert("RGBA"), layer).convert("RGB")
        out_frames.append(frame)

    # 文字层（静态，叠加在每帧之上）
    title_lines = wrap_cjk(title, 8)
    sub_lines = wrap_cjk(subtitle, 14)
    for fi, frame in enumerate(out_frames):
        d = ImageDraw.Draw(frame)
        # 标签胶囊
        d.rounded_rectangle([50, 70, 250, 130], radius=30,
                            fill=(255, 255, 255, 40), outline=ac)
        d.text((150, 86), "平台模板", font=get_font(26), fill=(255, 255, 255), anchor="mm")
        # 主标题
        ty = 760
        lh = 86
        for i, ln in enumerate(title_lines[:3]):
            d.text((50, ty + i * lh), ln, font=get_font(72, bold=True),
                   fill=(255, 255, 255), anchor="ls",
                   stroke_width=3, stroke_fill=dark)
        sy = ty + len(title_lines[:3]) * lh + 20
        for ln in sub_lines[:2]:
            d.text((50, sy), ln, font=get_font(34), fill=ac, anchor="ls")
            sy += 48
        d.line([50, 1180, 670, 1180], fill=(255, 255, 255), width=2)
        d.text((50, 1230), "财税短视频 · 封面模板", font=get_font(28),
               fill=(255, 255, 255), anchor="ls")

    out_frames[0].save(
        out_path, save_all=True, append_images=out_frames[1:],
        duration=durations, loop=0, optimize=True, disposal=2,
    )
    return w, h


def main():
    os.makedirs(BASE, exist_ok=True)
    manifest = {"categories": []}
    total = 0
    for cat in CATEGORIES:
        slug = cat["slug"]
        label = cat["label"]
        palette = cat["palette"]
        cdir = os.path.join(BASE, slug)
        os.makedirs(cdir, exist_ok=True)
        covers = []
        items = cat["items"]
        # 前 8 张 SVG
        for i in range(8):
            title, subtitle = items[i]
            svg = svg_cover(palette, title, subtitle, i)
            fname = f"cover_{i + 1}.svg"
            with open(os.path.join(cdir, fname), "w", encoding="utf-8") as fh:
                fh.write(svg)
            rel = f"covers/presets/{slug}/{fname}"
            covers.append({"file": rel, "name": title, "width": W, "height": H, "animated": False})
            total += 1
        # 后 2 张 动画海浪 GIF（取第 9、10 组文案）
        if not HAS_PIL:
            print("⚠️ 未安装 Pillow，跳过 GIF 动态封面（仅生成 SVG）")
        else:
            for j in range(2):
                idx = 8 + j
                title, subtitle = items[idx]
                fname = f"wave_{j + 1}.gif"
                gw, gh = gif_cover(palette, title, subtitle, idx, os.path.join(cdir, fname))
                rel = f"covers/presets/{slug}/{fname}"
                covers.append({"file": rel, "name": title + "（动态）",
                               "width": gw, "height": gh, "animated": True})
                total += 1
        manifest["categories"].append({"slug": slug, "label": label, "covers": covers})
        print(f"✓ {label}: {len(covers)} 张")

    with open(os.path.join(BASE, "manifest.json"), "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, ensure_ascii=False, indent=2)
    print(f"\n完成：共生成 {total} 张预设封面，清单写入 manifest.json")


if __name__ == "__main__":
    main()
