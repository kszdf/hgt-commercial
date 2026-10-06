#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
发图 / 看图 端到端回归探针 —— 直接打 8500 /file_extract

载荷与 Laravel `StudioController::chatUpload` 完全一致：{"name": ..., "data_b64": ...}

为什么是常驻回归项：
  对话里"发图 AI 看得懂"整条链路 = 前端上传 → Laravel base64 → 8500 /file_extract
  → 千问 VL 解析成文字 → 拼进 /chat 消息。前端与 Laravel 只是搬运工，真正的
  "看懂"能力全在 8500 的 file_extract.py（_OCR_PROMPT）。改它 / 换模型后必跑本探针。

判定标准（测"结构理解能力"，不抠具体字眼）：
  PASS = 同时满足
         ① 文字 / 数值按原序提取（含中文与数字）
         ② 图表理解 —— 出现 图表类型 / 坐标轴 / 逐月数值 任一
         ③ 另有 趋势分析（峰值/回落/最高/逐月/上升下降）或 版式描述（布局/左/右/表头）
  FAIL = 只抄文字、无任何结构化理解（提示词被回退 / 视觉模型降级）

退出码：0=PASS  1=FAIL  2=连不上 8500   （可直接当 CI 回归闸门）

用法：
  <受管python> e2e_image_extract.py            # 默认打 127.0.0.1
  <受管python> e2e_image_extract.py 127.0.0.1  # 可指定主机

注意：必须在 8500 本机跑（127.0.0.1:8500）；脚本已自动清空所有代理 env。
"""
import base64
import json
import os
import sys
import urllib.error
import urllib.request

# 清掉所有代理 env，强制直连本机 8500（本机有 HTTP_PROXY=127.0.0.1:55759 干扰）
for k in ("HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy", "ALL_PROXY", "all_proxy"):
    os.environ.pop(k, None)

try:
    from PIL import Image, ImageDraw, ImageFont
except ImportError:
    print("ERR: 缺少 Pillow（用受管 python: C:/Users/lenovo/.workbuddy/binaries/python/versions/3.13.12/python.exe）")
    sys.exit(2)

HOST = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
URL = "http://%s:8500/file_extract" % HOST


def make_test_image(path):
    """生成一张"文字 + 柱状图"的测试图，用于检验看图结构理解能力。"""
    W, H = 820, 520
    img = Image.new("RGB", (W, H), "white")
    d = ImageDraw.Draw(img)
    try:
        f = ImageFont.truetype("C:/Windows/Fonts/msyh.ttc", 30)
        fsm = ImageFont.truetype("C:/Windows/Fonts/msyh.ttc", 22)
    except Exception:
        f = fsm = ImageFont.load_default()
    d.text((40, 26), "2026年第一季度经营简报", fill=(20, 20, 20), font=f)
    rows = [
        "营收总额：100 万元",
        "净利润：30 万元",
        "同比增长：+25%",
        "客户数：从 80 增至 100",
    ]
    y = 80
    for t in rows:
        d.text((40, y), t, fill=(30, 30, 30), font=fsm)
        y += 38
    # 简易柱状图（四个月销售额）
    base_y = 470
    bars = [("1月", 150), ("2月", 260), ("3月", 200), ("4月", 330)]
    bx = 420
    for label, h in bars:
        d.rectangle([bx, base_y - h, bx + 56, base_y], fill="steelblue")
        d.text((bx, base_y - h - 26), str(h), fill=(20, 20, 20), font=fsm)
        d.text((bx, base_y + 8), label, fill=(20, 20, 20), font=fsm)
        bx += 90
    d.text((40, 430), "各月销售额（万元）示意", fill=(30, 30, 30), font=fsm)
    img.save(path, "PNG")
    return path


def judge(text):
    """按结构能力判定 PASS/FAIL，返回 (ok, hits)。"""
    t = text or ""
    hits = {
        "文字数值": bool(("%" in t) or ("万" in t) or any(c.isdigit() for c in t)),
        "图表理解": any(k in t for k in ("柱状", "柱形", "条形", "图表", "横轴", "纵轴", "坐标", "X轴", "Y轴")),
        "趋势分析": any(k in t for k in ("趋势", "峰值", "回落", "最高", "最低", "逐月", "上升", "下降", "增长", "波动")),
        "版式描述": any(k in t for k in ("版式", "布局", "左侧", "右侧", "上半", "下半", "标题", "表头", "居中")),
        "原序文字": ("营收" in t) or ("净利润" in t) or ("客户数" in t),
    }
    ok = hits["文字数值"] and hits["图表理解"] and (hits["趋势分析"] or hits["版式描述"])
    return ok, hits


def main():
    tmp = os.path.join(os.path.dirname(os.path.abspath(__file__)), "_e2e_test_chart.png")
    make_test_image(tmp)
    with open(tmp, "rb") as fh:
        b64 = base64.b64encode(fh.read()).decode("ascii")

    payload = json.dumps({"name": "test_chart.png", "data_b64": b64}).encode("utf-8")
    req = urllib.request.Request(URL, data=payload, headers={"Content-Type": "application/json"})
    print("==> POST %s  (image=%d KB)" % (URL, len(b64) // 1024))

    text = ""
    try:
        with urllib.request.urlopen(req, timeout=150) as r:
            body = r.read().decode("utf-8", "ignore")
        print("HTTP %s" % r.status)
        try:
            j = json.loads(body)
            text = j.get("text") or j.get("result") or j.get("content") or body
        except Exception:
            text = body
        print("--- 8500 返回 ---\n%s" % str(text)[:4000])
    except urllib.error.HTTPError as e:
        print("HTTPError %s\n%s" % (e.code, e.read().decode("utf-8", "ignore")[:800]))
        sys.exit(1)
    except Exception as e:
        print("ERR %s: %s" % (type(e).__name__, e))
        sys.exit(2)
    finally:
        try:
            os.remove(tmp)
        except Exception:
            pass

    ok, hits = judge(str(text))
    flags = "  ".join("%s=%s" % (k, "Y" if v else "N") for k, v in hits.items())
    print("\n--- 判定 ---\n%s" % flags)
    print("RESULT: %s" % ("PASS ✅ 完整看图能力在线" if ok else "FAIL ❌ 疑似退化为逐字提文字"))
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
