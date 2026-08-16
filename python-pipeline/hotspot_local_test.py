#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
热点模块本地诊断脚本：绕过 8500 HTTP 服务，直接调用 ai_hotspot()。
用法（用 PY310，与 8500 服务同一解释器）：
    D:/heygem/py310/Scripts/python.exe hotspot_local_test.py
"""
import sys
import os

# 复用 8500 服务的依赖路径
sys.path.insert(0, r"D:/heygem_data/gpt_sovits")
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from server import ai_hotspot


def run(label, subs):
    print(f"\n===== {label} (subs={subs}) =====")
    try:
        res = ai_hotspot(7, subs)
        print(f"realtime={res.get('realtime')}")
        print(f"filtered={res.get('filtered')}")
        print(f"topics_count={len(res.get('topics', []))}")
        for idx, t in enumerate(res.get("topics", [])[:5], 1):
            print(f"  {idx}. {t.get('title')} | tags={t.get('tags')}")
    except Exception as e:
        print(f"ERROR: {e}")
        import traceback
        traceback.print_exc()


if __name__ == "__main__":
    run("个人所得税", ["个人所得税"])
    run("增值税", ["增值税"])
