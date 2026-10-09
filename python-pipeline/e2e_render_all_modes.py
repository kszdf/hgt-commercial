# -*- coding: utf-8 -*-
"""
端到端出片回归：把平台所有「生成视频」的形态逐个真跑一遍，并校验产物。

用法：
  python e2e_render_all_modes.py                       # 跑全部 6 种形态
  python e2e_render_all_modes.py --modes scroll,card    # 只跑指定形态
  python e2e_render_all_modes.py --parallel 2           # 并发提交（受 8500 租户并发闸限制）

判定标准（任一不满足即 FAIL）：
  1. /generate 提交成功拿到 job_id
  2. 轮询 /status 终态为 done（非 failed / 卡死超时）
  3. jobs/<job_id>/out.mp4 存在且大小 > 0
  4. ffprobe：时长 > 3 秒、有视频流、有音频流
"""
import argparse
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.request

BASE = "http://127.0.0.1:8500"
JOBS_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "jobs")
TENANT = "e2e_render_test"

# 短文案：够短（约 15 秒成片）以压低耗时，但覆盖真实财税口播场景
DIALOGUE = (
    "老板们注意，公司账上的钱转给自己，年底不还，税务会当成分红，"
    "要补20%个人所得税。别等稽查通知书到了才想办法，那时候补税加滞纳金，一起算。"
)
TITLE = "公转私的分红坑"

MODES = ["scroll", "card", "motion", "whiteboard", "manga", "avatar"]

# 变体：同一引擎的不同真实用法（前端预设里确实存在的组合）
DIALOGUE_DUAL = (
    "女：张老师，公司账上的钱转到老板个人卡上，年底没还，会有什么问题？\n"
    "男：税务会把它视同分红，要补20%的个人所得税，严重的还要加滞纳金。\n"
    "女：那已经转了怎么办？\n"
    "男：年底前归还，或者走分红流程把个税缴掉，别拖到稽查通知书上门。"
)
DIALOGUE_LONG = (
    "老板们，今天把公司账上的钱怎么合法拿到个人卡上讲透。"
    "第一种是发工资薪金，走综合所得，依法代扣个税，这是最干净的路径；"
    "第二种是分红，交完企业所得税之后再缴20%个人所得税，合法到手；"
    "第三种是真实借款，签合同、付利息、按期归还，账上要经得起查。"
    "最容易出事的是用个人卡直接收营业款，金税四期下平台数据、银行流水、发票信息三流合一比对，"
    "这种操作一旦被预警，稽查会顺着私户流水倒查，补税、滞纳金、罚款三件套一起上。"
    "已经发生的，年底前归还或者补做分红申报，把风险敞口收掉，别等通知书到了才想办法。"
)

# 漫剧专用文案：剧情型（漫剧对法条/政策类内容会按业务规则拦截，属预期行为）
DIALOGUE_MANGA = (
    "小李刚接手公司的账，翻出去年一沓发票，越看越心慌。"
    "老会计端着茶杯走过来，只说了一句：先别慌，把单据按月份理一遍，问题自己就跳出来了。"
)

VARIANTS = {
    # 漫剧：用剧情型文案验证引擎本体可用（非法条类）
    "manga_story": {"mode": "manga", "dialogue": DIALOGUE_MANGA},
    # 前端预设「男女对话幕后音·动态画面」（scroll.blade.php preset scroll_dual）
    "dual_talk": {"mode": "motion", "voice_form": "dialogue", "dialogue": DIALOGUE_DUAL},
    # 长稿触发图解版自动分段渲染（card 分段 + 拼接）
    "long_card": {"mode": "card", "dialogue": DIALOGUE_LONG},
}

_opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))


def _http(path, payload=None, timeout=30):
    url = BASE + path
    if payload is None:
        req = urllib.request.Request(url)
    else:
        req = urllib.request.Request(
            url, data=json.dumps(payload).encode("utf-8"),
            headers={"Content-Type": "application/json"})
    with _opener.open(req, timeout=timeout) as r:
        return r.status, json.loads(r.read().decode("utf-8"))


def probe(path):
    """ffprobe 检查产物：时长 / 视频流 / 音频流。"""
    if not os.path.isfile(path):
        return {"exists": False}
    try:
        out = subprocess.run(
            ["ffprobe", "-v", "error", "-show_entries",
             "format=duration:stream=codec_type", "-of", "json", path],
            capture_output=True, timeout=60)
        info = json.loads(out.stdout.decode("utf-8"))
    except Exception as e:  # noqa: BLE001
        return {"exists": True, "error": str(e)}
    streams = [s.get("codec_type") for s in info.get("streams", [])]
    return {
        "exists": True,
        "size_kb": round(os.path.getsize(path) / 1024, 1),
        "duration": round(float(info.get("format", {}).get("duration") or 0), 1),
        "video": "video" in streams,
        "audio": "audio" in streams,
    }


def tail_log(job_id, n=12):
    p = os.path.join(JOBS_DIR, job_id, "render.log")
    if not os.path.isfile(p):
        return ""
    try:
        with open(p, "r", encoding="utf-8", errors="ignore") as f:
            lines = f.readlines()
        return "".join(lines[-n:]).strip()
    except Exception:  # noqa: BLE001
        return ""


def run_mode(mode, timeout_sec):
    print("[%s] 提交…" % mode, flush=True)
    t0 = time.time()
    payload = {"mode": mode, "dialogue": DIALOGUE, "title": TITLE, "tenant_id": TENANT}
    if mode in VARIANTS:
        payload.update(VARIANTS[mode])
    try:
        st, r = _http("/generate", payload)
    except Exception as e:  # noqa: BLE001
        return {"mode": mode, "ok": False, "error": "提交异常: %s" % e}
    if st != 200 or not r.get("job_id"):
        return {"mode": mode, "ok": False, "error": "提交失败 HTTP=%s %s" % (st, r)}
    jid = r["job_id"]
    print("[%s] job_id=%s" % (mode, jid), flush=True)

    last_step = ""
    while time.time() - t0 < timeout_sec:
        time.sleep(10)
        try:
            st2, s = _http("/status/" + jid, timeout=30)
        except Exception as e:  # noqa: BLE001
            print("  [%s] 轮询异常: %s" % (mode, e), flush=True)
            continue
        if st2 != 200:
            continue
        status = s.get("status")
        step = s.get("step") or ""
        if step != last_step:
            print("  [%s] %s / %s  (%.0fs)" % (mode, status, step, time.time() - t0), flush=True)
            last_step = step
        if status in ("done", "failed"):
            dur = time.time() - t0
            info = probe(os.path.join(JOBS_DIR, jid, "out.mp4"))
            ok = (status == "done" and info.get("exists")
                  and info.get("duration", 0) > 3
                  and info.get("video") and info.get("audio"))
            return {
                "mode": mode, "job_id": jid, "status": status, "ok": ok,
                "sec": round(dur, 1), "probe": info,
                "error": s.get("error"), "log": tail_log(jid) if not ok else "",
            }
    return {"mode": mode, "job_id": jid, "ok": False,
            "error": "超时未终态（%ss）" % timeout_sec, "log": tail_log(jid)}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--modes", default=",".join(MODES))
    ap.add_argument("--timeout", type=int, default=1800)
    args = ap.parse_args()
    modes = [m.strip() for m in args.modes.split(",") if m.strip()]

    results = []
    for m in modes:
        results.append(run_mode(m, args.timeout))

    print("\n" + "=" * 78)
    print("出片形态端到端汇总")
    print("=" * 78)
    fails = 0
    for r in results:
        tag = "PASS" if r.get("ok") else "FAIL"
        if not r.get("ok"):
            fails += 1
        p = r.get("probe") or {}
        print("%-4s %-11s %-7s %6ss  %s  %sx%s" % (
            tag, r["mode"], r.get("status", "-"), r.get("sec", "-"),
            r.get("job_id", "-")[:8],
            p.get("duration", "-"), "有音轨" if p.get("audio") else "无音轨"))
        if not r.get("ok"):
            print("     └─ %s" % (r.get("error") or "产物校验不通过"))
            if r.get("log"):
                print("     log: %s" % r["log"].replace("\n", "\n          "))
    print("-" * 78)
    print("%d/%d 通过" % (len(results) - fails, len(results)))
    with open(os.path.join(os.path.dirname(os.path.abspath(__file__)),
                           "e2e_render_all_modes.json"), "w", encoding="utf-8") as f:
        json.dump(results, f, ensure_ascii=False, indent=2)
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
