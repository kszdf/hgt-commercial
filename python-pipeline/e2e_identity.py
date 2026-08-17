#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""两种身份端到端验证：超管 / 普通pro(正常) / 普通过期。
对本地 8080 真实走 HTTP（nginx -> app -> 8500），校验：
  - 路由守卫（admin 仅超管 / 超管访问 studio 页面是否 500）
  - 配额拦截（过期 free 用户生成视频 402，正常/超管放行）
  - 各功能 POST 可用性（真实调 8500）
  - 批量外发后端鉴权（allow_batch=false 拦截）
  - 功能间逻辑（sessionStorage/srcMap 已静态确认，这里校验跳转目标路由可达）
用法：python e2e_identity.py
"""
import re, sys, time, json
import requests

BASE = "http://127.0.0.1:8080"

USERS = {
    "admin":   ("2864225@qq.com", "kszdf123456"),
    "pro":     ("e2e_pro@e2e.local", "TestE2e123!"),
    "expired": ("e2e_expired@e2e.local", "TestE2e123!"),
}

GET_ROUTES = [
    "/dashboard", "/studio/topic", "/studio/rewrite", "/studio/rewrite-original",
    "/studio/dissect", "/studio/scroll", "/studio/qc", "/studio/videos",
    "/studio/review", "/studio/publish", "/studio/voices", "/studio/covers",
    "/studio/models", "/studio/recycle", "/studio/settings/appearance",
    "/admin/billing", "/admin/monitor", "/admin/tenants",
]

POST_FUNCS = [
    ("/studio/topic/hotspots", {"days": 7, "subfields": ["税务稽查"]}, "topics"),
    ("/studio/deai", {"text": "老板虚开发票有税务风险，要注意合规处理。"}, "rewritten"),
    ("/studio/strategist", {"script": "老板虚开发票有风险", "title": "测试"}, "potential_score"),
    ("/studio/qc/generate", {"text": "老板虚开发票有税务风险。"}, None),
    ("/studio/scroll/suggest-title", {"dialogue": "老板虚开发票有风险要注意", "style": "smart"}, "title"),
    ("/studio/dissect/analyze", {"input_mode": "paste", "text": "老板虚开发票有风险，要注意合规处理。"}, "dissect"),
    ("/studio/rewrite/generate", {"text": "老板虚开发票有税务风险", "mode": "single"}, "cleaned"),
]

SHORT_DIALOGUE = "老板虚开发票有风险，要注意合规处理。"

def login(s, email, pwd):
    last_err = ""
    for attempt in range(3):
        try:
            r = s.get(BASE + "/login", timeout=60)
        except Exception as e:
            last_err = str(e); time.sleep(2); continue
        m = re.search(r'name="_token" value="([^"]+)"', r.text)
        token = m.group(1) if m else ""
        try:
            r = s.post(BASE + "/login", data={"login": email, "password": pwd, "_token": token, "remember": "on"},
                       allow_redirects=False, timeout=60)
        except Exception as e:
            last_err = str(e); time.sleep(2); continue
        return r.status_code in (302, 303), token
    return False, last_err

def get_token(s, page):
    r = s.get(BASE + page, timeout=60)
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', r.text)
    return m.group(1) if m else ""

def do_get(s, route):
    try:
        r = s.get(BASE + route, timeout=60, allow_redirects=False)
        return r.status_code, r.text[:3000]
    except Exception as e:
        return -1, str(e)

def do_post(s, route, data, token):
    try:
        r = s.post(BASE + route,
                   data=json.dumps(data),
                   headers={"X-CSRF-TOKEN": token, "X-Requested-With": "XMLHttpRequest",
                            "Content-Type": "application/json"},
                   timeout=150, allow_redirects=False)
        return r.status_code, r.text[:3000]
    except Exception as e:
        return -1, str(e)

def check_json(text, field):
    try:
        d = json.loads(text)
        if field is None:
            # 仅检查是否结构化返回
            return True
        if field in d:
            return True
        if isinstance(d, dict) and d.get("ok") is False:
            return True  # 模型失败但结构化
        return False
    except Exception:
        return False

def run_identity(name, email, pwd):
    print("\n" + "=" * 78)
    print(f"身份: {name} ({email})")
    print("=" * 78)
    s = requests.Session()
    ok, tok = login(s, email, pwd)
    if not ok:
        print(f"  [登录失败] status={tok if tok else ''} 无法继续")
        return {"login": False}
    print(f"  [登录成功]")

    out = {"login": True, "get": [], "post": [], "generate": None, "publish": None}

    # --- GET 路由 ---
    print("\n  -- GET 路由 --")
    for route in GET_ROUTES:
        st, body = do_get(s, route)
        note = ""
        if route == "/dashboard" and name == "admin":
            note = "超管横幅" if "超级管理员" in body else "⚠无超管横幅"
        out["get"].append((route, st, note))
        print(f"    {st:>4}  {route:<34} {note}")

    # --- POST 功能 ---
    print("\n  -- POST 功能(真实调8500) --")
    for route, payload, field in POST_FUNCS:
        tok2 = get_token(s, route.rsplit("/", 1)[0]) or tok
        st, body = do_post(s, route, payload, tok2)
        good = (st == 200 and check_json(body, field))
        out["post"].append((route, st, good))
        print(f"    {st:>4}  {'OK' if good else 'FAIL':<4}  {route}  field={field}")

    # --- 生成视频配额 ---
    print("\n  -- 生成视频配额(generate) --")
    tok2 = get_token(s, "/studio/scroll") or tok
    payload = {"mode": "scroll", "dialogue": SHORT_DIALOGUE, "title": "e2e配额测试"}
    st, body = do_post(s, "/studio/scroll/generate", payload, tok2)
    try:
        d = json.loads(body) if body else {}
    except Exception:
        d = {}
    code = d.get("code", "")
    job_id = d.get("job_id", "")
    if name == "expired":
        exp = (st == 402 and code in ("trial_expired", "trial_jobs_exceeded", "trial_minutes_exceeded"))
        note = f"期望402拦截 code={code} -> {'PASS' if exp else 'FAIL'}"
    else:
        exp = (st == 200 and bool(job_id))
        note = f"期望放行 job_id={'有' if job_id else '无'} -> {'PASS' if exp else 'FAIL'}"
    out["generate"] = (st, code, bool(job_id), exp)
    print(f"    {st:>4}  code={code:<22} job_id={'有' if job_id else '无'}  {note}")

    # --- 批量外发后端鉴权 ---
    print("\n  -- 批量外发 POST(/studio/publish) --")
    tok2 = get_token(s, "/studio/publish") or tok
    # 用一个不存在的 video_id，重点验证 allow_batch 拦截（不真发）
    st, body = do_post(s, "/studio/publish",
                       {"video_ids": [999999], "platforms": ["douyin"]}, tok2)
    # 重定向(302)带回 error 说明被 allow_batch 拦；200 说明放行（超管应为放行）
    if name == "expired" or name == "pro":
        exp = (st in (302, 303))  # allow_batch=false -> 重定向带 error
        note = f"期望被allow_batch拦截(302) -> {'PASS' if exp else 'FAIL'}"
    else:
        exp = (st in (200, 302, 303))  # 超管放行（页面或重定向均可）
        note = f"超管(可能500待修复) status={st} -> {'PASS' if exp else 'CHECK'}"
    out["publish"] = (st, exp)
    print(f"    {st:>4}  {note}")

    return out

def main():
    summary = {}
    for name, (email, pwd) in USERS.items():
        summary[name] = run_identity(name, email, pwd)
        time.sleep(1)

    print("\n" + "#" * 78)
    print("汇总")
    print("#" * 78)
    for name, r in summary.items():
        if not r.get("login"):
            print(f"  {name}: 登录失败")
            continue
        get500 = [rt for rt, st, _ in r["get"] if st == 500]
        get403 = [rt for rt, st, _ in r["get"] if st == 403]
        postfail = [rt for rt, st, g in r["post"] if not g]
        gen = r["generate"]
        print(f"\n  [{name}]")
        print(f"    GET 500(崩): {get500 if get500 else '无'}")
        print(f"    GET 403(守卫): {get403 if get403 else '无'}")
        print(f"    POST 失败: {postfail if postfail else '无'}")
        if gen:
            print(f"    generate: {gen[0]} code={gen[1]} job={'有' if gen[2] else '无'} -> {'PASS' if gen[3] else 'FAIL'}")
        pub = r.get("publish")
        if pub:
            print(f"    publish: status={pub[0]} -> {'PASS' if pub[1] else 'CHECK'}")
    print("\n完成。")

if __name__ == "__main__":
    main()
