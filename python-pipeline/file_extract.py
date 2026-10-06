# -*- coding: utf-8 -*-
"""file_extract.py —— 对话框「上传本地文件」的解析层。

被 server.py 的 POST /file_extract 懒加载调用（Laravel 收浏览器 multipart 后转 base64 JSON 转发）。

支持：
  - 文档：.docx（zipfile 读 word/document.xml 剥标签）、.txt / .md（utf-8/gbk 兜底解码）
  - 图片：.png / .jpg / .jpeg / .webp / .bmp → 阿里 dashscope qwen-vl-max OCR 提取图中文字
          （复用 model_keys.env 的 DASHSCOPE_API_KEY，与配音同一把 key）

统一返回：{ok, name, kind, chars, text, error?}
"""
import base64
import io
import json
import os
import re
import time
import urllib.request
import zipfile
import xml.sax.saxutils as _sx

MAX_BYTES = 10 * 1024 * 1024          # 与 Laravel 侧上限一致
MAX_TEXT_CHARS = 20000                # 塞进对话输入框的安全上限

_IMG_EXTS = {"png", "jpg", "jpeg", "webp", "bmp"}
_IMG_MIME = {"png": "image/png", "jpg": "image/jpeg", "jpeg": "image/jpeg",
             "webp": "image/webp", "bmp": "image/bmp"}

_VL_MODEL = "qwen-vl-max"
_VL_URL = "https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions"
_OCR_PROMPT = (
    "请完整理解这张图片承载的信息，输出一段结构化文字，供后续文字大模型据此回答用户问题。"
    "请包含：\n"
    "1）图中全部文字，按原阅读顺序、保留分段与关键数字；\n"
    "2）若有表格，用「行/列」方式还原表头与每个单元格内容；\n"
    "3）若是图表/统计图，说明图表类型、坐标轴含义、各系列趋势与关键数值；\n"
    "4）若是照片/截图，描述画面主体、布局与可见的关键信息；\n"
    "5）整体版式结构（如分栏、标题层级等）。\n"
    "只客观还原图中信息，不做主观评论，不编造图中没有的内容。"
)


def _ext(name):
    m = re.search(r"\.([A-Za-z0-9]+)$", name or "")
    return (m.group(1) if m else "").lower()


def _docx_text(raw):
    """docx → 纯文本：zipfile 取 word/document.xml，段末补换行，剥所有标签。"""
    with zipfile.ZipFile(io.BytesIO(raw)) as zf:
        xml = zf.read("word/document.xml").decode("utf-8", "ignore")
    xml = re.sub(r"<w:p[ >]", "\n<w:p ", xml)          # 段落边界 → 换行
    xml = re.sub(r"<[^>]+>", "", xml)                   # 剥所有标签
    text = _sx.unescape(xml)
    return re.sub(r"\n{3,}", "\n\n", text).strip()


def _plain_text(raw):
    for enc in ("utf-8", "gbk"):
        try:
            return raw.decode(enc)
        except UnicodeDecodeError:
            continue
    return raw.decode("utf-8", "ignore")


def _ocr_image(raw, ext):
    """dashscope qwen-vl-max OCR。key 复用 model_keys.env（经 ensure_env 灌入进程 env）。"""
    try:
        import model_providers
        model_providers.ensure_env()
    except Exception:  # noqa: BLE001 —— ensure_env 失败不阻断，key 直接从 env 找
        pass
    key = os.environ.get("DASHSCOPE_API_KEY") or os.environ.get("DASHSCOPE_API_KEY_TTS")
    if not key:
        return None, "未配置 DASHSCOPE_API_KEY（图片文字提取需要）"
    mime = _IMG_MIME.get(ext, "image/png")
    data_url = "data:%s;base64,%s" % (mime, base64.b64encode(raw).decode("ascii"))
    payload = {
        "model": _VL_MODEL,
        "messages": [{
            "role": "user",
            "content": [
                {"type": "image_url", "image_url": {"url": data_url}},
                {"type": "text", "text": _OCR_PROMPT},
            ],
        }],
        "temperature": 0.1,
    }
    req = urllib.request.Request(
        _VL_URL,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json", "Authorization": "Bearer " + key},
        method="POST")
    last_err = ""
    for _ in range(2):  # 最多 2 次
        try:
            with urllib.request.urlopen(req, timeout=90) as resp:
                d = json.loads(resp.read().decode("utf-8"))
            text = ((d.get("choices") or [{}])[0].get("message") or {}).get("content") or ""
            text = text.strip()
            if text:
                return text, ""
            last_err = "模型返回空内容"
        except Exception as e:  # noqa: BLE001
            last_err = str(e)[:160]
        time.sleep(1)
    return None, last_err


def extract(name, data_b64):
    name = (name or "未命名").strip()
    try:
        raw = base64.b64decode(data_b64 or "")
    except Exception:  # noqa: BLE001
        return {"ok": False, "name": name, "error": "文件数据解码失败"}
    if not raw:
        return {"ok": False, "name": name, "error": "文件内容为空"}
    if len(raw) > MAX_BYTES:
        return {"ok": False, "name": name, "error": "文件超过 10MB 上限"}

    ext = _ext(name)
    if ext in _IMG_MIME:
        text, err = _ocr_image(raw, ext)
        if text is None:
            return {"ok": False, "name": name, "kind": "image", "error": err or "图片文字提取失败"}
        kind = "image"
    elif ext == "docx":
        try:
            text = _docx_text(raw)
        except Exception as e:  # noqa: BLE001
            return {"ok": False, "name": name, "kind": "docx",
                    "error": "docx 解析失败（若是老 .doc 格式请先另存为 .docx）：" + str(e)[:100]}
        kind = "docx"
    elif ext in ("txt", "md", "markdown", "log", "csv"):
        text = _plain_text(raw)
        kind = "text"
    else:
        return {"ok": False, "name": name,
                "error": "暂不支持 ." + (ext or "?") + " 类型（支持 txt/md/docx 与图片 png/jpg/webp/bmp）"}

    text = (text or "").strip()
    truncated = False
    if len(text) > MAX_TEXT_CHARS:
        text = text[:MAX_TEXT_CHARS]
        truncated = True
    if not text:
        return {"ok": False, "name": name, "kind": kind, "error": "未从文件中提取到文字"}
    return {"ok": True, "name": name, "kind": kind, "chars": len(text),
            "truncated": truncated, "text": text}
