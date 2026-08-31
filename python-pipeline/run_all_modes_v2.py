# -*- coding: utf-8 -*-
"""全模式组合出片 v2: 带状态追踪的串行执行。
- 每个任务: 若对应 job_id 已 done 则跳过; failed/缺失则重新提交
- 并发上限2, 串行保序
用法: python run_all_modes_v2.py
"""
import json
import os
import time
import urllib.request

BASE = "http://127.0.0.1:8500"
VOICE_M = "cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d"
VOICE_F = "cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd"

# (key, job) — key 用于状态追踪
JOBS = [
    ("manga_scene", {"mode": "manga", "title": "个人微信收款被查-漫剧", "dialogue": "李老板的公司最近收到税务稽查通知，原来是他前年把公司收入直接打进个人微信收款，没有申报。稽查人员查到了流水，要求补税还要罚款。老板们记住，公司收入一定要走对公账户，别图省事用个人卡。", "male_voice": VOICE_M, "i2v": False}),
    ("manga_i2v", {"mode": "manga", "title": "个人微信收款被查-AI动效", "dialogue": "李老板的公司最近收到税务稽查通知，原来是他前年把公司收入直接打进个人微信收款，没有申报。稽查人员查到了流水，要求补税还要罚款。老板们记住，公司收入一定要走对公账户，别图省事用个人卡。", "male_voice": VOICE_M, "i2v": True}),
    ("manga_explain", {"mode": "manga", "title": "公司注销三步-讲解式", "dialogue": "公司不经营了想注销，其实就三步。第一步，账结清，该补的税补掉，该报的报表报完。第二步，公示四十五天，公告债权债务，没人来找麻烦。第三步，注销登记，税务注销完再做工商注销。拿到注销通知书，公司才算真正注销完。", "male_voice": VOICE_M, "i2v": False}),
    ("whiteboard", {"mode": "whiteboard", "title": "个人卡收款风险-白板图解", "dialogue": "老板把公司货款打到个人微信收款，不申报，被税务稽查查到流水，要求补税加罚款。公司收入一定要走对公账户，个人卡收款风险很大。", "male_voice": VOICE_M}),
    ("motion_male", {"mode": "motion", "title": "老板借款不还的风险-男声", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税，千万别踩这个坑。", "male_voice": VOICE_M, "voice_form": "male_mono"}),
    ("motion_female", {"mode": "motion", "title": "老板借款不还的风险-女声", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税，千万别踩这个坑。", "female_voice": VOICE_F, "voice_form": "female_mono"}),
    ("motion_dialogue", {"mode": "motion", "title": "老板借款对话-男女对话", "dialogue": "女：张老师，老板从公司借钱不还，会有什么风险？男：风险很大。公司借款给老板个人，长期不还又不申报，会被视同分红。女：那要交多少税？男：按20%补缴个人所得税，借款超过一年不还就要交。", "male_voice": VOICE_M, "female_voice": VOICE_F, "voice_form": "dialogue"}),
    ("scroll", {"mode": "scroll", "title": "借款不还滚动字幕", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税。", "male_voice": VOICE_M, "voice_form": "male_mono"}),
    ("avatar", {"mode": "avatar", "title": "老板借款数字人", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税，千万别踩这个坑。", "male_voice": VOICE_M, "model": "office_a"}),
]

STATE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "all_modes_state.json")


def post(path, data):
    req = urllib.request.Request(BASE + path, data=json.dumps(data).encode("utf-8"),
                                 headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode("utf-8"))


def get(path):
    with urllib.request.urlopen(BASE + path, timeout=15) as r:
        return json.loads(r.read().decode("utf-8"))


def load_state():
    if os.path.exists(STATE_FILE):
        with open(STATE_FILE, encoding="utf-8-sig") as f:  # 兼容 PowerShell Out-File 的 BOM
            return json.load(f)
    return {}


def save_state(st):
    with open(STATE_FILE, "w", encoding="utf-8") as f:
        json.dump(st, f, ensure_ascii=False, indent=2)


def main():
    import sys
    sys.stdout.reconfigure(encoding="utf-8")
    st = load_state()
    for key, job in JOBS:
        jid = st.get(key)
        if jid:
            try:
                s = get(f"/status/{jid}")
                if s.get("status") == "done":
                    print(f"[跳过] {key} ({jid}) 已完成")
                    continue
                if s.get("status") == "rendering":
                    print(f"[等待] {key} ({jid}) 仍在渲染")
                    while True:
                        s = get(f"/status/{jid}")
                        if s.get("status") in ("done", "failed"):
                            break
                        time.sleep(20)
                    if s.get("status") == "done":
                        print(f"[跳过] {key} 完成后确认")
                        continue
                    print(f"[重跑] {key} 上次失败: {str(s.get('error'))[:80]}")
            except Exception:
                pass
        # 排队
        while True:
            m = get("/metrics")
            if m.get("active_jobs", 0) < 2:
                break
            time.sleep(20)
        print(f"[提交] {key}: {job['mode']} - {job['title']}")
        for attempt in range(10):
            try:
                resp = post("/generate", job)
                jid = resp.get("job_id")
                st[key] = jid
                save_state(st)
                print(f"  -> {jid}")
                break
            except Exception as e:
                print(f"  重试{attempt+1}: {e}")
                time.sleep(10)
        # 等完成
        while True:
            s = get(f"/status/{jid}")
            if s.get("status") in ("done", "failed"):
                dur = (s.get("qc_video") or {}).get("duration")
                print(f"  [{key}] {s.get('status')} dur={dur} err={str(s.get('error'))[:100]}")
                break
            time.sleep(20)
    print("\n=== 全部完成 ===")
    for key, job in JOBS:
        jid = st.get(key)
        if jid:
            s = get(f"/status/{jid}")
            print(f"{key}: {s.get('status')} | {job['mode']} | {job['title']}")


if __name__ == "__main__":
    main()
