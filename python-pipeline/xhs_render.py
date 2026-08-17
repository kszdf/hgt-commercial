"""
小红书图文渲染引擎（模板精排 + 中文字体精准叠字）。

设计原则：
  - 财税内容「零乱码」优先：不依赖文生图模型吐中文（豆包/即梦常出错字），
    而是「AI 出结构化文案 + 模板精排 + 系统字体叠字」，保证每页版式统一、文字 100% 正确。
  - 画布 1080×1440（小红书竖屏 3:4 黄金比例）。一篇 = 一个系列图文笔记，
    封面 + 内文分页，总计封顶 9 张（小红书单篇笔记最多 9 图）。
  - 配色走专业可信路线（青蓝主色 + 琥珀强调 + 浅底），贴合「慧根堂·老张讲财税」品牌。
  - AI 背景插画接口预留（AI_BG_AVAILABLE）：接入火山方舟/即梦后，可让封面/内页背景换成
    生成的插画，文字仍由本模块精准叠，避免乱码。当前未配 key，默认走渐变模板。

对外函数：
  render_note(note: dict, outdir: str, brand: str = "慧根堂 · 老张讲财税") -> list[str]
    返回图片绝对路径列表，顺序：封面在前，其后为内文分页（总张数 ≤ 9）。
  note 结构见 server.py /xhs_generate 的 DeepSeek 输出约定：
    {
      "cover":  {"title": str, "subtitle": str, "tag": str},
      "pages":  [{"heading": str, "points": [str, ...]}, ...],   # 1~8 个
      "body":   str,        # 小红书正文（含话题标签）
      "titles": [str, ...]  # 候选标题 3~5 个
    }
"""
from __future__ import annotations

import os
from typing import List, Optional

from PIL import Image, ImageDraw, ImageFont

# ---------- 常量 ----------
W, H = 1080, 1440
FONT_BOLD = "C:/Windows/Fonts/msyhbd.ttc"   # 微软雅黑 Bold（标题）
FONT_REG = "C:/Windows/Fonts/simhei.ttf"    # 黑体（正文）

# 品牌色板
TEAL = (14, 116, 144)        # #0E7490 主色
TEAL_DARK = (11, 85, 99)     # #0B5563 深
AMBER = (245, 158, 11)       # #F59E0B 强调
INK = (15, 23, 42)           # #0F172A 正文深
MUTED = (100, 116, 139)      # #64748B 次要
WHITE = (255, 255, 255)
CARD = (255, 255, 255)
CARD_BORDER = (226, 232, 240)
BG_TOP = (255, 255, 255)
BG_BOTTOM = (241, 245, 250)  # #F1F5FA

# AI 背景插画开关（接火山方舟/即梦后改 True 并实现 _gen_ai_bg）
AI_BG_AVAILABLE = False
MAX_IMAGES = 9  # 小红书单篇笔记最多 9 图


def _font(path: str, size: int) -> ImageFont.FreeTypeFont:
    try:
        return ImageFont.truetype(path, size)
    except Exception:
        return ImageFont.load_default()


def _vgradient(w: int, h: int, top, bottom) -> Image.Image:
    img = Image.new("RGB", (w, h), top)
    draw = ImageDraw.Draw(img)
    tr, tg, tb = top
    br, bg, bb = bottom
    for y in range(h):
        t = y / max(1, h - 1)
        draw.line([(0, y), (w, y)], fill=(
            int(tr + (br - tr) * t),
            int(tg + (bg - tg) * t),
            int(tb + (bb - tb) * t),
        ))
    return img


def _wrap(text: str, font: ImageFont.FreeTypeFont, max_w: int, max_lines: int = 99) -> List[str]:
    """CJK 友好的按字符换行（中文无空格，逐字测量）。"""
    lines, cur = [], ""
    for ch in text:
        if ch == "\n":
            lines.append(cur)
            cur = ""
            continue
        test = cur + ch
        if font.getlength(test) <= max_w:
            cur = test
        else:
            lines.append(cur)
            cur = ch
    if cur:
        lines.append(cur)
    if len(lines) > max_lines:
        lines = lines[:max_lines]
        lines[-1] = lines[-1][:-1] + "…"
    return lines


def _text_w(draw, text, font):
    return draw.textlength(text, font=font)


def _draw_cover(cover: dict, brand: str) -> Image.Image:
    img = _vgradient(W, H, TEAL, TEAL_DARK)
    draw = ImageDraw.Draw(img)

    # 顶部柔光圆（装饰）
    draw.ellipse([-200, -200, 520, 520], fill=(255, 255, 255, 18))
    draw.ellipse([760, 980, 1400, 1620], fill=(255, 255, 255, 12))

    pad = 90
    # 标签 pill
    tag = (cover.get("tag") or "").strip()
    if tag:
        f_tag = _font(FONT_BOLD, 38)
        tw = _text_w(draw, tag, f_tag) + 56
        draw.rounded_rectangle([pad, 150, pad + tw, 150 + 72], radius=36, fill=AMBER)
        draw.text((pad + 28, 150 + 14), tag, font=f_tag, fill=INK)

    # 主标题（白色粗体，最多 3 行）
    title = (cover.get("title") or "").strip()
    f_title = _font(FONT_BOLD, 96)
    title_lines = _wrap(title, f_title, W - pad * 2, max_lines=3)
    y = 430
    for ln in title_lines:
        draw.text((pad, y), ln, font=f_title, fill=WHITE)
        y += 116
    # 标题下琥珀分隔线
    draw.rectangle([pad, y + 6, pad + 150, y + 18], fill=AMBER)

    # 副标题（浅色，最多 3 行）
    sub = (cover.get("subtitle") or "").strip()
    if sub:
        f_sub = _font(FONT_REG, 44)
        sub_lines = _wrap(sub, f_sub, W - pad * 2, max_lines=3)
        y2 = y + 50
        for ln in sub_lines:
            draw.text((pad, y2), ln, font=f_sub, fill=(226, 240, 245))
            y2 += 64

    # 底部品牌条
    draw.line([(pad, H - 150), (W - pad, H - 150)], fill=(255, 255, 255, 90), width=2)
    f_brand = _font(FONT_BOLD, 40)
    draw.text((pad, H - 120), brand, font=f_brand, fill=WHITE)
    f_hint = _font(FONT_REG, 32)
    draw.text((pad, H - 70), "小红书财税干货 · 关注不踩坑", font=f_hint, fill=(214, 234, 240))
    return img


def _draw_page(heading: str, points: List[str], page_no: int, total: int, brand: str) -> Image.Image:
    img = _vgradient(W, H, BG_TOP, BG_BOTTOM)
    draw = ImageDraw.Draw(img)

    # 顶部主色条
    draw.rectangle([0, 0, W, 20], fill=TEAL)

    pad = 90
    # 标题
    f_h = _font(FONT_BOLD, 66)
    h_lines = _wrap(heading, f_h, W - pad * 2, max_lines=2)
    y = 90
    for ln in h_lines:
        draw.text((pad, y), ln, font=f_h, fill=INK)
        y += 84
    draw.rectangle([pad, y + 6, pad + 120, y + 14], fill=TEAL)

    # 内容卡片
    card_top = y + 50
    card_bottom = H - 170
    draw.rounded_rectangle([pad - 20, card_top, W - pad + 20, card_bottom], radius=28,
                           fill=CARD, outline=CARD_BORDER, width=3)

    # 要点列表（带青色圆点 + 编号）
    n = max(1, len(points))
    inner_top = card_top + 50
    inner_bottom = card_bottom - 40
    area_h = inner_bottom - inner_top
    line_h = min(150, area_h // max(1, n))
    f_p = _font(FONT_REG, 46)
    f_num = _font(FONT_BOLD, 40)

    cy = inner_top
    for i, raw in enumerate(points):
        # 圆点编号
        draw.ellipse([pad + 6, cy + 6, pad + 58, cy + 58], fill=TEAL)
        draw.text((pad + 20, cy + 10), str(i + 1), font=f_num, fill=WHITE)
        # 文本（最多 3 行）
        lines = _wrap(raw, f_p, W - pad * 2 - 110, max_lines=3)
        ty = cy + 4
        for ln in lines:
            draw.text((pad + 100, ty), ln, font=f_p, fill=INK)
            ty += 58
        cy += max(line_h, ty - cy + 30)

    # 底部品牌 + 页码
    f_brand = _font(FONT_BOLD, 34)
    draw.text((pad, H - 110), brand, font=f_brand, fill=TEAL)
    f_pg = _font(FONT_REG, 34)
    pg = f"{page_no:02d} / {total:02d}"
    pw = _text_w(draw, pg, f_pg)
    draw.text((W - pad - pw, H - 110), pg, font=f_pg, fill=MUTED)
    return img


def render_note(note: dict, outdir: str, brand: str = "慧根堂 · 老张讲财税") -> List[str]:
    """把结构化笔记渲染成一组 PNG，返回绝对路径列表（封面在前）。"""
    os.makedirs(outdir, exist_ok=True)
    paths: List[str] = []

    pages = note.get("pages") or []
    # 总张数封顶 9（封面 1 + 内文 ≤8）
    max_inner = MAX_IMAGES - 1
    if len(pages) > max_inner:
        pages = pages[:max_inner]

    # 封面
    cover_path = os.path.join(outdir, "cover.png")
    _draw_cover(note.get("cover", {}), brand).save(cover_path, "PNG")
    paths.append(cover_path)

    total = len(pages)
    for i, pg in enumerate(pages):
        p = os.path.join(outdir, f"page_{i + 1}.png")
        _draw_page(pg.get("heading", f"第{i + 1}页"), pg.get("points", []), i + 1, total, brand).save(p, "PNG")
        paths.append(p)

    return paths
