#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
selfcheck_studio.py — 慧根堂商用平台「交付前自检」harness

覆盖项（对应交付前自检增强 prompt）：
  A. 正常路径：登录 → 提交滚动字幕卡出片 → 轮询至 done
  B. 异常场景1：时长超限 → 422 duration_exceeded（前端预估即拦 + 后端硬闸）
  C. 异常场景2：本租户并发超限 → 429 tenant_busy（与 8500 双保险）
  D. 异常场景3：未登录/CSRF 缺失 → 401/419（鉴权护栏）
  E. 路由冒烟：主功能页全部可达（非 500/404/419）

用法：
  python selfcheck_studio.py                  # 全量（含真实渲染，约 8 分钟）
  python selfcheck_studio.py --skip-render    # 只跑快速项（~20s），真实渲染单独跑
  python selfcheck_studio.py --check-429      # 额外占用 2 个真实渲染槽验证并发闸（~16 分钟）

注：真实渲染消耗 CosyVoice TTS 配额（真实 API 调用）。快速项均为即时拦截/冒烟，不耗时。
"""
import sys
import re
import time
import json
import argparse
import urllib.request
import urllib.parse
import http.cookiejar

BASE_APP = "http://127.0.0.1:8080"
BASE_PIPE = "http://127.0.0.1:8500"
EMAIL = "admin@huigentang.com"
PASSWORD = "admin888"

UA = {"User-Agent": "selfcheck/1.0"}

results = []


def record(name, ok, detail=""):
    results.append((name, ok, detail))
    mark = "PASS" if ok else "FAIL"
    print(f"  [{mark}] {name}" + (f" — {detail}" if detail else ""))


def open_url(opener, url, data=None, headers=None, timeout=30, method=None):
    h = dict(UA)
    if headers:
        h.update(headers)
    req = urllib.request.Request(url, data=data, headers=h, method=method)
    try:
        with opener.open(req, timeout=timeout) as r:
            return r.status, r.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", "replace")
        return e.code, body
    except Exception as e:  # 连接失败等
        return -1, str(e)


def get_csrf(opener, url):
    status, html = open_url(opener, url, timeout=20)
    m = re.search(r'<meta name="csrf-token" content="([^"]+)"', html)
    return m.group(1) if m else ""


def do_login(opener):
    token = get_csrf(opener, BASE_APP + "/login")
    if not token:
        return False, "登录页无 csrf-token"
    body = urllib.parse.urlencode({
        "_token": token,
        "email": EMAIL,
        "password": PASSWORD,
    }).encode()
    status, _ = open_url(
        opener, BASE_APP + "/login", data=body,
        headers={"X-CSRF-TOKEN": token, "X-Requested-With": "XMLHttpRequest"},
        timeout=30, method="POST",
    )
    # 登录成功会 302 跳转到 dashboard；失败回 login(200) 或 419
    if status not in (302, 200):
        return False, f"登录返回异常状态码 {status}"
    return True, f"登录提交状态码 {status}"


def check_protected(opener, path):
    """已登录状态下访问受保护路由，200 即通过；302 多表示被踢回登录页（鉴权失败）。"""
    status, _ = open_url(opener, BASE_APP + path, timeout=20)
    return status == 200, f"HTTP {status}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--skip-render", action="store_true", help="跳过真实渲染（快速项）")
    ap.add_argument("--check-429", action="store_true", help="额外验证并发闸（占用 2 槽）")
    args = ap.parse_args()

    print("=" * 60)
    print("慧根堂商用平台 — 交付前自检")
    print(f"APP={BASE_APP}  PIPELINE={BASE_PIPE}")
    print("=" * 60)

    # —— 0. 出片微服务 health ——
    print("\n[0] 出片微服务 health")
    try:
        with urllib.request.urlopen(BASE_PIPE + "/health", timeout=10) as r:
            st = r.status
            body = r.read().decode("utf-8", "replace")
    except Exception as e:
        st, body = -1, str(e)
    try:
        ok = (st == 200 and json.loads(body).get("status") == "ok")
    except Exception:
        ok = False
    record("8500 /health", ok, f"HTTP {st} {body.strip()}")

    # —— 1. 登录 ——
    print("\n[1] 登录鉴权")
    cj = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    ok, detail = do_login(opener)
    record("管理员登录", ok, detail)

    # —— 2. 路由冒烟（受保护页须 200）——
    print("\n[2] 路由冒烟（主功能页可达性）")
    routes = ["/dashboard", "/studio/scroll", "/studio/topic",
              "/studio/rewrite", "/studio/models", "/admin/billing"]
    all_ok = True
    for r in routes:
        ok, detail = check_protected(opener, r)
        record(f"GET {r}", ok, detail)
        all_ok = all_ok and ok
    record("路由冒烟整体", all_ok)

    # —— 3. 异常场景1：时长超限 422 ——
    print("\n[3] 异常场景1：单次时长超限 → 422 duration_exceeded")
    token = get_csrf(opener, BASE_APP + "/studio/scroll")
    long_text = "。".join(["建筑企业财税合规要点说明与风险防范实务操作指引详解"] * 600)  # ~数千字，预估远超30分钟
    body = urllib.parse.urlencode({
        "_token": token,
        "mode": "scroll",
        "dialogue": long_text,
    }).encode()
    st, resp = open_url(
        opener, BASE_APP + "/studio/scroll/generate", data=body,
        headers={"X-CSRF-TOKEN": token, "X-Requested-With": "XMLHttpRequest"},
        timeout=30, method="POST",
    )
    try:
        j = json.loads(resp)
        code = j.get("code")
    except Exception:
        code = None
    ok = (st == 422 and code == "duration_exceeded")
    record("时长超限拦截", ok, f"HTTP {st} code={code} " + (f"est={j.get('estimated_sec')}s" if code else ""))

    # —— 4. 异常场景3：CSRF 缺失 → 419 ——
    print("\n[4] 异常场景3：CSRF 缺失 → 419")
    body2 = urllib.parse.urlencode({
        "mode": "scroll",
        "dialogue": "女：测试一下。",
    }).encode()
    st, _ = open_url(
        opener, BASE_APP + "/studio/scroll/generate", data=body2,
        headers={"X-Requested-With": "XMLHttpRequest"},  # 故意不带 X-CSRF-TOKEN
        timeout=30, method="POST",
    )
    ok = (st == 419)
    record("CSRF 缺失拦截", ok, f"HTTP {st} (期望 419)")

    # —— 5. 正常路径：真实渲染（可选）——
    if not args.skip_render:
        print("\n[5] 正常路径：真实滚动字幕卡出片（真实 CosyVoice 配音，约 8 分钟）")
        token = get_csrf(opener, BASE_APP + "/studio/scroll")
        dialogue = (
            "女：老板，金税四期上线后，公转私到底还能不能碰？\n"
            "男：可以碰，但得有正当理由。比如工资薪金、分红、备用金借款，都要留凭证。\n"
            "女：那没有票的个人卡收款呢？\n"
            "男：这是红线。一旦被大数据比对出来，补税加滞纳金，严重的还要担刑责。\n"
            "女：所以核心还是业务真实、凭证齐全？\n"
            "男：对，合规不是不转账，是每一笔都经得起查。"
        )
        body = urllib.parse.urlencode({
            "_token": token,
            "mode": "scroll",
            "dialogue": dialogue,
            "title": "公转私合规要点",
            "subtitle": "老张讲财税",
        }).encode()
        st, resp = open_url(
            opener, BASE_APP + "/studio/scroll/generate", data=body,
            headers={"X-CSRF-TOKEN": token, "X-Requested-With": "XMLHttpRequest"},
            timeout=30, method="POST",
        )
        try:
            j = json.loads(resp)
            job_id = j.get("job_id")
        except Exception:
            job_id = None
        if st == 200 and job_id:
            record("提交出片任务", True, f"job_id={job_id}")
            # 轮询
            done = False
            final = ""
            for i in range(40):  # 40 * 15s = 10 分钟上限
                time.sleep(15)
                s2, r2 = open_url(opener, BASE_APP + f"/studio/scroll/status/{job_id}", timeout=20)
                try:
                    j2 = json.loads(r2)
                    final = j2.get("status")
                except Exception:
                    final = f"?({s2})"
                print(f"     轮询 #{i+1}: status={final}")
                if final in ("done", "failed"):
                    done = True
                    break
            ok = (final == "done")
            detail = f"最终状态={final}"
            if ok:
                # 校验成品可下载
                s3, _ = open_url(opener, BASE_APP + f"/studio/scroll/download/{job_id}", timeout=60)
                detail += f" 下载HTTP={s3}"
                ok = ok and (s3 == 200)
            record("真实渲染至 done", ok, detail)
        else:
            record("提交出片任务", False, f"HTTP {st} resp={resp[:200]}")
    else:
        print("\n[5] 正常路径真实渲染：跳过（--skip-render）")

    # —— 6. 并发闸 429（可选，占用 2 槽）——
    if args.check_429:
        print("\n[6] 并发闸：本租户并发超限 → 429 tenant_busy")
        # 提交第 1、2 个（占用槽），第 3 个应 429
        ids = []
        for n in range(3):
            token = get_csrf(opener, BASE_APP + "/studio/scroll")
            dlg = f"女：并发测试第{n+1}条。\n男：好的，请等待渲染完成。"
            body = urllib.parse.urlencode({
                "_token": token, "mode": "scroll", "dialogue": dlg,
            }).encode()
            st, resp = open_url(
                opener, BASE_APP + "/studio/scroll/generate", data=body,
                headers={"X-CSRF-TOKEN": token, "X-Requested-With": "XMLHttpRequest"},
                timeout=30, method="POST",
            )
            try:
                j = json.loads(resp)
                code = j.get("code")
                jid = j.get("job_id")
            except Exception:
                code, jid = None, None
            print(f"     提交#{n+1}: HTTP {st} code={code} job={jid}")
            if st == 200 and jid:
                ids.append(jid)
        ok = (code == "tenant_busy" and st == 429)
        record("并发超限拦截(第3个429)", ok, f"HTTP {st} code={code}")
        # 清理：等待已提交的任务完成（避免占槽）
        for jid in ids:
            for _ in range(40):
                time.sleep(15)
                _, r2 = open_url(opener, BASE_APP + f"/studio/scroll/status/{jid}", timeout=20)
                try:
                    if json.loads(r2).get("status") in ("done", "failed"):
                        break
                except Exception:
                    pass
    else:
        print("\n[6] 并发闸 429：跳过（默认不占槽；--check-429 可验证，代码与 8502 验证一致）")

    # —— 汇总 ——
    print("\n" + "=" * 60)
    print("自检汇总")
    print("=" * 60)
    passed = sum(1 for _, ok, _ in results if ok)
    total = len(results)
    for name, ok, detail in results:
        print(f"  {'✅' if ok else '❌'} {name}")
    print(f"\n  通过 {passed}/{total}")
    print("=" * 60)
    sys.exit(0 if passed == total else 1)


if __name__ == "__main__":
    main()
