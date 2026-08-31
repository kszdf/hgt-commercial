# -*- coding: utf-8 -*-
"""全模式组合出片: 串行提交 9 个任务(平台并发上限2), 每个完成后提交下一个。
输出: jobs.txt (job_id -> 描述) 供后续收集。"""
import json
import time
import urllib.request

BASE = "http://127.0.0.1:8500"
VOICE_M = "cosyvoice-v3-plus-zhangc2-28a7c3541e1c45518a03046c11baeb1d"
VOICE_F = "cosyvoice-v3-plus-jiangnv3-991b204c1d564ac7a60f0cb9a8fd78bd"

JOBS = [
    {"mode": "manga", "title": "个人微信收款被查-漫剧", "dialogue": "李老板的公司最近收到税务稽查通知，原来是他前年把公司收入直接打进个人微信收款，没有申报。稽查人员查到了流水，要求补税还要罚款。老板们记住，公司收入一定要走对公账户，别图省事用个人卡。", "male_voice": VOICE_M, "i2v": False},
    {"mode": "manga", "title": "个人微信收款被查-AI动效", "dialogue": "李老板的公司最近收到税务稽查通知，原来是他前年把公司收入直接打进个人微信收款，没有申报。稽查人员查到了流水，要求补税还要罚款。老板们记住，公司收入一定要走对公账户，别图省事用个人卡。", "male_voice": VOICE_M, "i2v": True},
    {"mode": "manga", "title": "公司注销三步-讲解式", "dialogue": "公司不经营了想注销，其实就三步。第一步，账结清，该补的税补掉，该报的报表报完。第二步，公示四十五天，公告债权债务，没人来找麻烦。第三步，注销登记，税务注销完再做工商注销。拿到注销通知书，公司才算真正注销完。", "male_voice": VOICE_M, "i2v": False},
    {"mode": "whiteboard", "title": "个人卡收款风险-白板图解", "dialogue": "老板把公司货款打到个人微信收款，不申报，被税务稽查查到流水，要求补税加罚款。公司收入一定要走对公账户，个人卡收款风险很大。", "male_voice": VOICE_M},
    {"mode": "motion", "title": "老板借款不还的风险-男声", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税，千万别踩这个坑。", "male_voice": VOICE_M, "voice_form": "male_mono"},
    {"mode": "motion", "title": "老板借款不还的风险-女声", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税，千万别踩这个坑。", "female_voice": VOICE_F, "voice_form": "female_mono"},
    {"mode": "motion", "title": "老板借款对话-男女对话", "dialogue": "女：张老师，老板从公司借钱不还，会有什么风险？男：风险很大。公司借款给老板个人，长期不还又不申报，会被视同分红。女：那要交多少税？男：按20%补缴个人所得税，借款超过一年不还就要交。", "male_voice": VOICE_M, "female_voice": VOICE_F, "voice_form": "dialogue"},
    {"mode": "scroll", "title": "借款不还滚动字幕", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税。", "male_voice": VOICE_M, "voice_form": "male_mono"},
    {"mode": "avatar", "title": "老板借款数字人", "dialogue": "老板们注意了，公司借款给老板个人，长期不还又不申报，会被视同分红交个税。借款超过一年不还，就要按20%补缴个人所得税，千万别踩这个坑。", "male_voice": VOICE_M, "model": "office_a"},
]


def post(path, data):
    req = urllib.request.Request(BASE + path, data=json.dumps(data).encode("utf-8"),
                                 headers={"Content-Type": "application/json"}, method="POST")
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read().decode("utf-8"))


def get(path):
    with urllib.request.urlopen(BASE + path, timeout=15) as r:
        return json.loads(r.read().decode("utf-8"))


def main():
    import sys
    sys.stdout.reconfigure(encoding="utf-8")
    results = []
    for i, job in enumerate(JOBS):
        print(f"\n[{i+1}/{len(JOBS)}] 提交: {job['mode']} - {job['title']}")
        # 排队: 当前租户任务 >= 2 就等待
        while True:
            try:
                metrics = get("/metrics")
            except Exception:
                time.sleep(5); continue
            active = metrics.get("active_jobs", 0)
            if active < 2:
                break
            print(f"  并发 {active}/2, 等待 20s ...")
            time.sleep(20)
        for attempt in range(10):
            try:
                resp = post("/generate", job)
                jid = resp.get("job_id")
                results.append((jid, job["mode"], job["title"]))
                print(f"  入队: {jid}")
                break
            except Exception as e:
                print(f"  提交失败({attempt+1}): {e}, 10s后重试")
                time.sleep(10)
        # 等待本任务完成再提交下一个(保序, 避免并发混跑)
        if results:
            jid = results[-1][0]
            while True:
                try:
                    st = get(f"/status/{jid}")
                except Exception:
                    time.sleep(10); continue
                if st.get("status") in ("done", "failed"):
                    print(f"  完成: status={st.get('status')} duration={st.get('qc_video', {}).get('duration')}")
                    break
                time.sleep(15)
    with open("jobs.txt", "w", encoding="utf-8") as f:
        for jid, mode, title in results:
            f.write(f"{jid}\t{mode}\t{title}\n")
    print("\n全部提交完成, 清单见 jobs.txt")


if __name__ == "__main__":
    main()

