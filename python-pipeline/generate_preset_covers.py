#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
生成平台预设封面库：8 个通用行业营销分类，每类 10 张
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
        "slug": "food", "label": "美食餐饮",
        "palette": ["#3a0a2a", "#a01f6b", "#e0598f"],
        "items": [
            ("在家也能复刻", "餐厅级好味道"),
            ("藏在巷子里", "本地人才知道"),
            ("3分钟快手菜", "下班就能做"),
            ("甜品治愈时刻", "一口好心情"),
            ("食材挑选诀窍", "新鲜不踩雷"),
            ("减脂也能吃饱", "吃对不长肉"),
            ("探店真实测评", "好不好吃说了算"),
            ("节气养生食补", "顺应时节吃"),
            ("厨房小妙招", "省力又省心"),
            ("关注学做饭", "每天一道菜"),
        ],
    },
    {
        "slug": "edu", "label": "教育培训",
        "palette": ["#241344", "#5b2a86", "#b06ab3"],
        "items": [
            ("0基础也能学", "这门课适合你"),
            ("孩子成绩提升法", "家长都在问"),
            ("职场技能加点", "下班后学什么"),
            ("考证通关攻略", "一次过不是梦"),
            ("名师直播课", "今晚8点开讲"),
            ("免费试听课", "先体验再报名"),
            ("学员真实案例", "他做到了"),
            ("限时优惠报名", "早鸟价最后一天"),
            ("课程亮点拆解", "为什么值得学"),
            ("关注领资料", "干货每日更新"),
        ],
    },
    {
        "slug": "ecommerce", "label": "电商带货",
        "palette": ["#3a0d12", "#8a1f2b", "#d64550"],
        "items": [
            ("今日好物推荐", "闭眼入不踩雷"),
            ("工厂直供价", "没有中间商"),
            ("直播间专属价", "仅此一小时"),
            ("用户真实测评", "好不好用说了算"),
            ("新品首发", "抢先体验"),
            ("爆款返场", "错过的补货了"),
            ("买一送一", "手慢无"),
            ("老粉专属福利", "感谢一路支持"),
            ("源头好货", "品质看得见"),
            ("限时秒杀", "点开就省钱"),
        ],
    },
    {
        "slug": "bizservice", "label": "企业服务",
        "palette": ["#10242e", "#2c5364", "#4ca1af"],
        "items": [
            ("企业合规第一步", "从读懂报表开始"),
            ("代账还是自雇", "算笔账再决定"),
            ("SaaS 提效实测", "省下一个人力"),
            ("法律顾问随时问", "风险早规避"),
            ("资质办理指南", "少跑冤枉路"),
            ("用工成本优化", "合规又省钱"),
            ("品牌升级方案", "一眼被记住"),
            ("免费诊断名额", "限量领取"),
            ("客户案例复盘", "真实见效"),
            ("预约一对一", "专家帮你规划"),
        ],
    },
    {
        "slug": "local", "label": "本地生活",
        "palette": ["#3a1c0a", "#c05621", "#f5933b"],
        "items": [
            ("附近就好这一口", "老顾客都知道"),
            ("新店开业福利", "进店有惊喜"),
            ("周末去哪玩", "本地宝藏地"),
            ("招牌菜揭秘", "回头客的秘密"),
            ("门店实拍", "环境先看看"),
            ("老客带新客", "双方都有礼"),
            ("限时特惠套餐", "错过等一年"),
            ("主理人亲自上", "用心做服务"),
            ("本地便民信息", "生活更省心"),
            ("关注不迷路", "更新早知道"),
        ],
    },
    {
        "slug": "knowledge", "label": "知识科普",
        "palette": ["#2b1d0e", "#8a6d1f", "#d4af37"],
        "items": [
            ("一个冷知识", "90%人不知道"),
            ("每天懂一点", "知识不嫌多"),
            ("深度拆解", "真相在这里"),
            ("观点交锋", "你怎么看"),
            ("避坑指南", "少走弯路"),
            ("数据会说话", "看图更明白"),
            ("科普小课堂", "三分钟讲清"),
            ("行业洞察", "趋势早把握"),
            ("案例复盘", "经验值+1"),
            ("关注学知识", "持续输出"),
        ],
    },
    {
        "slug": "health", "label": "健康美业",
        "palette": ["#0f3d2e", "#1f7a5a", "#4cbf8f"],
        "items": [
            ("轻养生日常", "每天一小步"),
            ("皮肤管理清单", "素颜也自信"),
            ("健身打卡第N天", "变化看得见"),
            ("营养师建议", "吃对不挨饿"),
            ("调理身体信号", "别忽视预警"),
            ("医美避坑", "安全第一位"),
            ("睡眠改善法", "睡好精神好"),
            ("会员专属方案", "私人定制"),
            ("真实蜕变案例", "她做到了"),
            ("限时体验价", "先试再决定"),
        ],
    },
    {
        "slug": "general", "label": "通用模板",
        "palette": ["#1e293b", "#334155", "#94a3b8"],
        "items": [
            ("专业·实战·落地", "只讲能用的"),
            ("干货每天见", "关注不迷路"),
            ("案例拆解系列", "真实复盘"),
            ("避坑指南", "少走弯路"),
            ("听得懂的讲解", "不说官话"),
            ("知识卡片", "一图看懂"),
            ("关注领福利", "每周更新"),
            ("专业人设", "靠谱第一"),
            ("价值持续输出", "长期主义"),
            ("扫码了解更多", "一对一沟通"),
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
                 f'fill="#ffffff" opacity="0.6">短视频 · 封面模板</text>')

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
        d.text((50, 1230), "短视频 · 封面模板", font=get_font(28),
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
