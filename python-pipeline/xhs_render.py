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

# AI 背景插画：检测到 ARK_API_KEY 自动启用（无需改开关）；未配 key 时降级为多套深色渐变
# 配色变体（仍可凭 seed 切换观感），保证「重新生成封面」功能始终可用、且不依赖外网 key。
def ai_bg_available():
    """有火山方舟 key 才启用 AI 插画背景。"""
    return bool(os.environ.get("ARK_API_KEY", "").strip())


# 无 AI key 时的多套封面深色配色变体（按 seed 轮换）。白字保证可读，仅底色/装饰色不同，
# 让「重新生成封面」在没有 AI key 时也能切换不同观感。
COVER_DARK_PALETTES = [
    ((14, 116, 144), (11, 85, 99)),     # 青（默认）
    ((30, 64, 124), (23, 48, 99)),      # 深蓝
    ((76, 29, 149), (55, 20, 110)),     # 深紫
    ((6, 95, 70), (5, 70, 52)),         # 深绿
    ((124, 45, 18), (90, 32, 12)),      # 深棕橙
    ((15, 23, 42), (30, 41, 59)),       # 深灰蓝
]
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


def _cover_prompt(cover: dict, topic: str = "", selling: str = "", audience: str = "") -> str:
    """根据封面内容构造 AI 插画 prompt（中文，火山方舟可理解）。"""
    title = (cover.get("title") or "").strip()
    sub = (cover.get("subtitle") or "").strip()
    parts = []
    if title:
        parts.append(f"主标题：{title}")
    if sub:
        parts.append(f"副标题：{sub}")
    if topic:
        parts.append(f"选题场景：{topic}")
    if selling:
        parts.append(f"核心卖点：{selling}")
    if audience:
        parts.append(f"目标受众：{audience}")
    base = ("小红书封面插画，财税知识科普风格，扁平化/商务简约插画风，画面干净留白充足，"
            "明亮积极、专业可信。")
    if parts:
        base += "内容相关元素：" + "；".join(parts) + "。"
    base += ("画面不得出现任何文字、字母、数字、标语或品牌名，纯插画背景即可；"
             "色调以蓝/青/暖金为主，适合在上方叠加白色中文大标题，整体有信任感与高级感。")
    return base


def _gradient_cover(bg_seed: int) -> Image.Image:
    """无 AI key 时的深色渐变封面底（按 seed 切换配色变体，白字保证可读）。"""
    top, bottom = COVER_DARK_PALETTES[bg_seed % len(COVER_DARK_PALETTES)]
    img = _vgradient(W, H, top, bottom)
    draw = ImageDraw.Draw(img)
    deco = COVER_DARK_PALETTES[(bg_seed + 1) % len(COVER_DARK_PALETTES)][1]
    draw.ellipse([-200, -200, 520, 520], fill=(255, 255, 255, 18))
    draw.ellipse([760, 980, 1400, 1620], fill=(255, 255, 255, 12))
    return img


def _apply_ai_bg(bg) -> Image.Image:
    """AI 插画上叠半透明深青遮罩，压暗 42%，保证白色标题清晰可读。"""
    img = bg.convert("RGB").resize((W, H))
    overlay = Image.new("RGB", (W, H), (8, 47, 73))
    return Image.blend(img, overlay, 0.42)


def _download_b64(url: str):
    try:
        import requests, base64
        r = requests.get(url, timeout=60)
        r.raise_for_status()
        return base64.b64encode(r.content).decode("ascii")
    except Exception as e:  # noqa: BLE001
        print("[xhs_render] download failed:", e, flush=True)
        return None


def _poll_ark_task(endpoint: str, api_key: str, task_id: str, max_wait: int = 110):
    import requests, time, base64
    url = endpoint.rstrip("/") + "/tasks/" + str(task_id)
    headers = {"Authorization": f"Bearer {api_key}"}
    deadline = time.time() + max_wait
    while time.time() < deadline:
        try:
            r = requests.get(url, headers=headers, timeout=30)
            j = r.json()
            status = j.get("status") or (j.get("data") or {}).get("status")
            if status in ("completed", "succeeded", "success"):
                out = j.get("output") or j.get("data") or {}
                if isinstance(out, dict) and out.get("image_urls"):
                    return _download_b64(out["image_urls"][0])
                if isinstance(j.get("data"), list) and j["data"]:
                    item = j["data"][0]
                    if item.get("b64_json"):
                        return item["b64_json"]
                    if item.get("url"):
                        return _download_b64(item["url"])
            if status in ("failed", "error"):
                return None
        except Exception as e:  # noqa: BLE001
            print("[xhs_render] poll task err:", e, flush=True)
        time.sleep(3)
    return None


def _gen_ai_bg(prompt: str, seed):
    """调火山方舟 Seedream 文生图，返回 PIL Image(1080x1440) 或 None（无 key/失败降级）。"""
    api_key = os.environ.get("ARK_API_KEY", "").strip()
    if not api_key:
        return None
    try:
        import requests, base64, io
        endpoint = os.environ.get("ARK_IMG_ENDPOINT",
                                  "https://ark.cn-beijing.volces.com/api/v1/images/generations")
        model = os.environ.get("ARK_IMG_MODEL", "doubao-seedream-5-0-260128")
        size = os.environ.get("ARK_IMG_SIZE", "1024x1536")
        payload = {"model": model, "prompt": prompt, "size": size, "n": 1,
                   "response_format": "b64_json"}
        if seed:
            try:
                payload["seed"] = int(seed)
            except Exception:  # noqa: BLE001
                pass
        headers = {"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"}
        r = requests.post(endpoint, headers=headers, json=payload, timeout=120)
        r.raise_for_status()
        j = r.json()
        b64 = None
        # 同步返回：data[].b64_json / data[].url
        if isinstance(j.get("data"), list) and j["data"]:
            item = j["data"][0]
            if item.get("b64_json"):
                b64 = item["b64_json"]
            elif item.get("url"):
                b64 = _download_b64(item["url"])
        # 异步返回：task_id / id
        elif j.get("task_id") or j.get("id"):
            b64 = _poll_ark_task(endpoint, api_key, j.get("task_id") or j.get("id"))
        if not b64:
            print("[xhs_render] ai bg: no image in response", flush=True)
            return None
        return Image.open(io.BytesIO(base64.b64decode(b64))).convert("RGB")
    except Exception as e:  # noqa: BLE001
        print("[xhs_render] ai bg failed:", repr(e), flush=True)
        return None


def _draw_cover(cover: dict, brand: str, bg_seed: int = 0,
                topic: str = "", selling: str = "", audience: str = "") -> Image.Image:
    """绘制封面：优先 AI 插画背景（带压暗遮罩），否则深色渐变配色变体。文字绘制逻辑不变。"""
    if ai_bg_available():
        bg = _gen_ai_bg(_cover_prompt(cover, topic, selling, audience), bg_seed)
        img = _apply_ai_bg(bg) if bg is not None else _gradient_cover(bg_seed)
    else:
        img = _gradient_cover(bg_seed)
    draw = ImageDraw.Draw(img)

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


def render_note(note: dict, outdir: str, brand: str = "慧根堂 · 老张讲财税",
                cover_seed: int = 0) -> List[str]:
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
    _draw_cover(note.get("cover", {}), brand, cover_seed).save(cover_path, "PNG")
    paths.append(cover_path)

    total = len(pages)
    for i, pg in enumerate(pages):
        p = os.path.join(outdir, f"page_{i + 1}.png")
        _draw_page(pg.get("heading", f"第{i + 1}页"), pg.get("points", []), i + 1, total, brand).save(p, "PNG")
        paths.append(p)

    return paths


def render_cover(note: dict, outpath: str, brand: str = "慧根堂 · 老张讲财税",
                 seed: int = 0, topic: str = "", selling: str = "",
                 audience: str = "") -> str:
    """只渲封面（用于「重新生成封面」），返回封面路径。文字（标题/副标题）保持不变，仅换背景。"""
    _draw_cover(note.get("cover", {}), brand, seed, topic, selling, audience).save(outpath, "PNG")
    return outpath
