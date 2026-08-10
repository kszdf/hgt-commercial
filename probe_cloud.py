import urllib.request, urllib.parse, re
BASE = "http://124.222.33.233:8080"
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(), urllib.request.ProxyHandler({}))
def get(url): return opener.open(url, timeout=10)
def post(url, data, headers=None):
    req = urllib.request.Request(url, data=urllib.parse.urlencode(data).encode(), headers=headers or {})
    return opener.open(req, timeout=10)
html = get(BASE + "/login").read().decode("utf-8", "ignore")
m = re.search(r'name="_token" value="([^"]+)"', html)
token = m.group(1) if m else ""
print("token_len", len(token))
resp = post(BASE + "/login", {"_token": token, "email": "admin@huigentang.com", "password": "admin888"})
print("login_status", resp.status, "final_url", resp.geturl())
try:
    r = post(BASE + "/studio/topic/generate", {"platform": "shipinhao", "hotness": "3", "hook": "1", "count": "3"},
             headers={"X-CSRF-TOKEN": token, "Accept": "application/json"})
    print("topic_status", r.status, "body", r.read().decode("utf-8", "ignore")[:150])
except urllib.error.HTTPError as e:
    print("topic_status", e.code, "body", e.read().decode("utf-8", "ignore")[:150])
