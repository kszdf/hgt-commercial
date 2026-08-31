# -*- coding: utf-8 -*-
"""全出片组合回归测试：覆盖所有 mode × 声线 × 参数组合，逐个提交并记录结果。
发现失败/异常组合输出报告，供修复。
用法: python regression_all_modes.py [--limit N]"""
import json
import sys
import time
import urllib.request
import argparse

BASE = "http://127.0.0.1:8500"
VOICE_M = "cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d"
VOICE_F = "cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd"

# 场景剧内容(触发 scene)
TXT_SCENE = "李老板的公司最近收到税务稽查通知，原来是他前年把公司收入直接打进个人微信收款，没有申报。稽查人员查到了流水，要求补税还要罚款。老板们记住，公司收入一定要走对公账户。"
# 讲解式内容(触发 explain)
TXT_EXPLAIN = "公司不经营了想注销，其实就三步。第一步，账结清，该补的税补掉。第二步，公示四十五天，公告债权债务。第三步，注销登记，先税务后工商。拿到注销通知书才算真正注销完。"
# 法条内容(触发 lecture 拦截)
TXT_LECTURE = "根据刑法第二百零五条规定，虚开增值税专用发票的，处三年以下有期徒刑或者拘役，并处二万元以上二十万元以下罚金。"
# 白板内容
TXT_WB = "老板把公司货款打到个人微信收款，不申报，被税务稽查查到流水，要求补税加罚款。公司收入一定要走对公账户。"
# 普通口播
TXT_MONO = "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税。"


def post(path, data):
    req = urllib.request.Request(BASE + path, data=json.dumps(data).encode("utf-8"),
                                 headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode("utf-8"))


def get(path):
    with urllib.request.urlopen(BASE + path, timeout=15) as r:
        return json.loads(r.read().decode("utf-8"))


def build_cases():
    """全部组合矩阵。"""
    cases = []
    def add(name, mode, dialogue, **kw):
        payload = {"mode": mode, "dialogue": dialogue, "male_voice": VOICE_M,
                   "female_voice": VOICE_F, "title": f"回归-{name}"}
        payload.update(kw)
        cases.append((name, payload))

    # ---- scroll 滚动字幕卡 × 4 声线 ----
    for vf in ("male_mono", "female_mono", "dialogue", "mono"):
        add(f"scroll_{vf}", "scroll", TXT_MONO, voice_form=vf)
    # ---- avatar 数字人（独白，natural 开关）----
    add("avatar_plain", "avatar", TXT_MONO)
    add("avatar_natural", "avatar", TXT_MONO, natural=True)
    # ---- motion 幕后音动态画面 × 3 声线 × edit_style ----
    for vf in ("male_mono", "female_mono", "dialogue"):
        add(f"motion_{vf}", "motion", TXT_MONO, voice_form=vf)
    for es in ("fast", "artistic", "vlog"):
        add(f"motion_es_{es}", "motion", TXT_MONO, voice_form="male_mono", edit_style=es)
    # ---- manga 漫剧 ----
    add("manga_scene", "manga", TXT_SCENE)                       # 场景剧
    add("manga_explain", "manga", TXT_EXPLAIN)                   # 讲解式
    add("manga_i2v", "manga", TXT_SCENE, i2v=True)               # AI 动效
    add("manga_lecture", "manga", TXT_LECTURE)                   # 法条→应拦截
    # ---- whiteboard 白板 ----
    add("whiteboard", "whiteboard", TXT_WB)
    # ---- 异常参数组合（不科学场景检测）----
    add("scroll_with_i2v", "scroll", TXT_MONO, voice_form="male_mono", i2v=True)  # scroll 传 i2v 应忽略
    add("avatar_with_dual", "avatar", "女：张老师，借款不还什么风险？\n男：会被视同分红交个税。", voice_form="dialogue")  # avatar 传对话应去前缀
    add("motion_with_dual_text", "motion", "女：张老师，借款不还什么风险？\n男：会被视同分红交个税。", voice_form="dialogue")  # motion 对话
    add("manga_lecture_i2v", "manga", TXT_LECTURE, i2v=True)     # 法条+i2v 应拦截
    return cases


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0, help="只跑前 N 条(0=全部)")
    args = ap.parse_args()
    sys.stdout.reconfigure(encoding="utf-8")
    cases = build_cases()
    if args.limit:
        cases = cases[:args.limit]

    print(f"共 {len(cases)} 个组合")
    results = []
    for i, (name, payload) in enumerate(cases):
        print(f"\n[{i+1}/{len(cases)}] {name}: mode={payload['mode']} "
              f"vf={payload.get('voice_form','-')} es={payload.get('edit_style','-')} "
              f"i2v={payload.get('i2v','-')} natural={payload.get('natural','-')}")
        # 排队（租户并发 ≤2）
        while True:
            try:
                m = get("/metrics")
                if m.get("active_jobs", 0) < 2:
                    break
            except Exception:
                pass
            time.sleep(15)
        jid = None
        for attempt in range(5):
            try:
                r = post("/generate", payload)
                jid = r.get("job_id")
                break
            except Exception as e:
                print(f"  提交失败({attempt+1}): {e}")
                time.sleep(8)
        if not jid:
            results.append({"name": name, "status": "submit_failed"})
            continue
        # 等完成
        while True:
            try:
                s = get(f"/status/{jid}")
            except Exception:
                time.sleep(15)
                continue
            if s.get("status") in ("done", "failed", "cancelled"):
                break
            time.sleep(15)
        dur = (s.get("qc_video") or {}).get("duration")
        qc = (s.get("qc_video") or {}).get("status")
        err = (s.get("error") or "")[:200]
        results.append({"name": name, "mode": payload["mode"], "status": s.get("status"),
                        "qc": qc, "dur": dur, "error": err})
        print(f"  -> {s.get('status')} qc={qc} dur={dur}"
              + (f" err={err[:100]}" if err else ""))

    print("\n===== 回归报告 =====")
    ok = [r for r in results if r["status"] == "done"]
    bad = [r for r in results if r["status"] != "done"]
    print(f"成功 {len(ok)} / {len(results)}")
    if bad:
        print("\n--- 异常组合 ---")
        for r in bad:
            print(f"  {r['name']}: {r['status']} | {r.get('error','')[:150]}")
    else:
        print("全部成功，无异常")
    # 输出 JSON 报告
    with open("regression_report.json", "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2)
    print("\n报告: regression_report.json")


if __name__ == "__main__":
    main()
