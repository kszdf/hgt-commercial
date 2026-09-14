import json, time, urllib.request

BASE = "http://127.0.0.1:8500"
TENANT = "huigentang"
HEAD = {"Content-Type": "application/json"}

def post(path, data):
    req = urllib.request.Request(BASE + path, data=json.dumps(data).encode(), headers=HEAD)
    return json.loads(urllib.request.urlopen(req, timeout=30).read().decode())

def new_sid():
    r = post("/chat/session/create", {"tenant": TENANT, "title": "e2e-probe"})
    return r.get("session_id") or r.get("sid") or r.get("id")

def get_status(sid):
    return json.loads(urllib.request.urlopen(BASE + "/chat/status/" + sid, timeout=30).read().decode())

def chat(sid, message, action=None, timeout=300):
    r = post("/chat", {"session_id": sid, "message": message, "action": action, "tenant": TENANT})
    if r.get("stage") == "async":
        deadline = time.time() + timeout
        while time.time() < deadline:
            time.sleep(5)
            s = get_status(sid)
            st = s.get("stage")
            if st in ("pending",):
                continue
            return s
        return {"stage": "poll_timeout"}
    return r

def dump(name, r):
    print("\n==== %s ====" % name)
    print(json.dumps(r, ensure_ascii=False, indent=2)[:2500])

sid = new_sid()
print("SID=", sid)
dump("短问答: 金税四期到底查什么", chat(sid, "金税四期到底查什么"))
dump("空白写稿: 帮我写个口播稿", chat(sid, "帮我写个口播稿"))
dump("给主题: 讲老板用个人卡收货款的税务风险", chat(sid, "讲老板用个人卡收货款的税务风险"))
dump("改参数: 改成2000字", chat(sid, "改成2000字"))

sid2 = new_sid()
dump("能力-出片: 帮我出个数字人视频", chat(sid2, "帮我出个数字人视频"))
dump("卡片后续: 去发布", chat(sid2, "去发布"))

sid3 = new_sid()
dump("能力-小红书: 帮我做小红书", chat(sid3, "帮我做小红书"))
sid4 = new_sid()
dump("能力-公众号: 帮我写篇公众号文章", chat(sid4, "帮我写篇公众号文章"))
sid5 = new_sid()
dump("规划: 帮我规划本周财税内容", chat(sid5, "帮我规划本周财税内容"))
sid6 = new_sid()
dump("异常: 嗯", chat(sid6, "嗯"))
dump("异常: 1", chat(sid6, "1"))
