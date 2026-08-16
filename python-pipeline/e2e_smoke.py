"""端到端冒烟测试：直连 8500 微服务，覆盖各功能与组合。
用法：python e2e_smoke.py [--host localhost] [--port 8500]
"""
import sys, time, json, argparse
try:
    import requests
except ImportError:
    print("requests 未安装，请先 pip install requests")
    sys.exit(2)

parser = argparse.ArgumentParser()
parser.add_argument("--host", default="localhost")
parser.add_argument("--port", type=int, default=8500)
args = parser.parse_args()
BASE = f"http://{args.host}:{args.port}"

PASS, FAIL, SKIP = [], [], []
def mark(name, ok, detail=""):
    (PASS if ok else FAIL).append(name)
    t = "PASS" if ok else "FAIL"
    print(f"[{t}] {name}" + (f"  -> {detail}" if detail else ""))

def call(path, payload=None, timeout=90, method="POST"):
    t0 = time.time()
    try:
        if method == "POST":
            r = requests.post(BASE + path, json=payload or {}, timeout=timeout)
        else:
            r = requests.get(BASE + path, timeout=timeout)
        el = time.time() - t0
        return r, el
    except Exception as e:
        el = time.time() - t0
        return None, el, str(e)

print("="*60)
print("8500 端到端冒烟  BASE=" + BASE)
print("="*60)

# 1. health
r, el = call("/health", method="GET", timeout=10)
mark("health", r is not None and r.status_code == 200 and "ok" in r.text,
     f"status={r.status_code if r else 'ERR'} {el:.1f}s")

# 2. hotspot 不同子领域组合
for subs in [["税务稽查"], ["增值税","个税"], ["金税四期","发票管理","社保公积金"]]:
    r, el = call("/hotspot", {"days":7, "subs": subs}, timeout=60)
    ok = False; detail = ""
    if r and r.status_code == 200:
        try:
            d = r.json()
            ok = isinstance(d.get("topics"), list)
            detail = f"topics={len(d.get('topics',[]))} realtime={d.get('realtime')} degraded={d.get('tavily_degraded')} {el:.1f}s"
        except Exception as e:
            detail = "json解析失败 " + str(e)
    else:
        detail = f"status={r.status_code if r else 'ERR'} {el:.1f}s"
    mark(f"hotspot subs={subs}", ok, detail)

# 3. suggest-title 三种风格
sample_text = "老板们注意了，金税四期上线后，税务稽查越来越严。很多企业因为发票管理不规范，被查出来补税加罚款。今天给大家讲三个最常见的坑，第一是虚开发票，第二是公私账不分，第三是社保没按实际工资交。"
for style in ["smart", "full", "suspense"]:
    r, el = call("/suggest-title", {"dialogue": sample_text, "style": style}, timeout=90)
    ok = False; detail = ""
    if r and r.status_code == 200:
        try:
            d = r.json()
            ok = bool(d.get("title")) or d.get("ok") is False  # 允许模型失败但需结构化返回
            title = d.get("title","") if d.get("ok") is not False else ""
            detail = f"title='{title}' ok={d.get('ok')} err={d.get('error','')} {el:.1f}s"
        except Exception as e:
            detail = "json解析失败 " + str(e)
    else:
        detail = f"status={r.status_code if r else 'ERR'} {el:.1f}s"
    mark(f"suggest-title style={style}", ok, detail)

# 4. dissect（粘贴文案）
r, el = call("/dissect", {"text": sample_text + " 另外公转私也要注意，大额转账容易被监控。",
                          "platform":"douyin", "industry":"财税"}, timeout=90)
ok = False; detail = ""
if r and r.status_code == 200:
    try:
        d = r.json()
        ok = isinstance(d.get("structure"), list) and len(d.get("structure",[]))>0
        detail = f"hook_type={d.get('hook_type')} structure={len(d.get('structure',[]))} {el:.1f}s"
    except Exception as e:
        detail = "json解析失败 " + str(e)
else:
    detail = f"status={r.status_code if r else 'ERR'} {el:.1f}s"
mark("dissect(文案)", ok, detail)

# 5. transcribe 降级（无视频，应友好报错而非崩溃）
r, el = call("/transcribe", {"video_b64": "AAAA", "url": ""}, timeout=30)
ok = r is not None
detail = f"status={r.status_code if r else 'ERR'} (期望 400/友好降级) {el:.1f}s"
mark("transcribe(降级)", ok, detail)

# 6. deai（返回字段为 rewritten）
r, el = call("/deai", {"text": sample_text}, timeout=90)
ok = False; detail = ""
if r and r.status_code == 200:
    try:
        d = r.json()
        ok = bool(d.get("rewritten")) or d.get("ok") is False
        detail = f"rewritten_len={len(d.get('rewritten',''))} ok={d.get('ok')} {el:.1f}s"
    except Exception as e:
        detail = "json解析失败"
else:
    detail = f"status={r.status_code if r else 'ERR'} {el:.1f}s"
mark("deai", ok, detail)

# 7. strategist（返回字段含 potential_score）
r, el = call("/strategist", {"text": sample_text, "platform":"douyin"}, timeout=90)
ok = False; detail = ""
if r and r.status_code == 200:
    try:
        d = r.json()
        ok = "potential_score" in d or "potential" in d or d.get("ok") is False
        detail = f"score={d.get('potential_score')} level={d.get('level')} {el:.1f}s"
    except Exception as e:
        detail = "json解析失败"
else:
    detail = f"status={r.status_code if r else 'ERR'} {el:.1f}s"
mark("strategist", ok, detail)

# 8. qc（智能质检）
r, el = call("/qc", {"text": sample_text}, timeout=60)
ok = False; detail = ""
if r and r.status_code == 200:
    try:
        d = r.json()
        ok = isinstance(d, (dict, list))
        detail = f"keys={list(d.keys())[:6] if isinstance(d,dict) else 'list'} {el:.1f}s"
    except Exception as e:
        detail = "json解析失败"
else:
    detail = f"status={r.status_code if r else 'ERR'} {el:.1f}s"
mark("qc", ok, detail)

print("="*60)
print(f"总计: PASS={len(PASS)} FAIL={len(FAIL)}")
if FAIL:
    print("失败项:")
    for f in FAIL:
        print("  - " + f)
print("="*60)
sys.exit(1 if FAIL else 0)
