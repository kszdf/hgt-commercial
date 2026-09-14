# -*- coding: utf-8 -*-
"""抖音多应用授权 — 一键自检 + 生成可粘贴的授权链接。

用法（先用管理员 PowerShell 重启 8500：Restart-Service HGTCommercial8500）：
    D:/heygem/py310/Scripts/python.exe D:/heygem_data/hgt-commercial/抖音账号自检.py

检查 5 件事：
  1. 8500 是否跑的是新代码（POST /oauth/authorize/{platform} 是否可用）
  2. Laravel 从数据库解密出来的 client_key 是否与各账号对应（真实生产路径）
  3. 回调地址是否指向备案域名 https://zmgen.cn（抖音不接受 IP+端口）
  4. 公网回调端点是否可达
  5. 输出 4 条可直接粘到浏览器的授权链接（用于定位「缺少参数」）
"""
import json
import subprocess
import sys
import urllib.error
import urllib.request

sys.stdout.reconfigure(encoding="utf-8", errors="replace")

BASE = "http://127.0.0.1:8500"
PUBLIC = "https://zmgen.cn"
CALLBACK = PUBLIC + "/oauth/callback/douyin"
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))

# (账号 id, 应用名, 期望的 client_key)
ACCOUNTS = [
    (19, "慧根堂财税咨询",     "aww2zbo42pwp16tq"),
    (20, "慧根堂注册营业执照", "awquogtwjkqstajl"),
    (21, "慧根堂建筑财税",     "awhsehwc59koc82d"),
    (22, "慧根堂财税",         "awbsp7852up0o0p4"),
]

ok_all = True


def post(url, payload, timeout=20):
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"},
    )
    with opener.open(req, timeout=timeout) as r:
        return r.status, r.read().decode("utf-8", errors="replace")


def get(url, timeout=15):
    with opener.open(url, timeout=timeout) as r:
        return r.status, r.read().decode("utf-8", errors="replace")


def authorize(account_id, client_key):
    """调 8500 生成授权链接（带账号级应用凭证）。"""
    st, body = post(BASE + "/oauth/authorize/douyin",
                    {"account_id": account_id, "client_key": client_key})
    return st, json.loads(body)


def laravel_decrypted_keys():
    """走 Laravel 模型读取解密后的 client_key（验证数据库加密存储可用）。"""
    code = (
        "foreach (\\App\\Models\\PlatformAccount::where('platform','douyin')->get() as $a) {"
        " $i = $a->account_info ?: [];"
        " echo $a->id.'|'.(string)($i['client_key'] ?? $i['app_id'] ?? '').PHP_EOL; }"
    )
    try:
        out = subprocess.run(
            ["docker", "exec", "hgt-commercial-app-1", "php", "artisan",
             "tinker", "--execute=" + code],
            capture_output=True, text=True, encoding="utf-8",
            errors="replace", timeout=120,
        )
        kv = {}
        for line in (out.stdout or "").splitlines():
            if "|" in line:
                k, v = line.strip().split("|", 1)
                if k.isdigit():
                    kv[int(k)] = v
        return kv
    except Exception as e:  # noqa: BLE001
        print("   （读取数据库失败：%s）" % e)
        return {}


print("=" * 62)
print("1) 8500 是否跑新代码（POST /oauth/authorize/douyin）")
print("=" * 62)
try:
    st, d = authorize(19, ACCOUNTS[0][2])
    print("   HTTP", st, "app =", d.get("app"))
    if st < 400 and d.get("authorize_url"):
        print("   ✓ 新代码已生效")
    else:
        ok_all = False
        print("   ✗ 异常返回:", json.dumps(d, ensure_ascii=False)[:200])
except urllib.error.HTTPError as e:
    ok_all = False
    print("   ✗ HTTP", e.code, "—", e.read().decode("utf-8", errors="replace")[:160])
    print()
    print("   请先执行（管理员 PowerShell）：Restart-Service HGTCommercial8500")
    sys.exit(1)

print()
print("=" * 62)
print("2) Laravel 解密出的 client_key 是否与各账号对应（真实生产路径）")
print("=" * 62)
db_keys = laravel_decrypted_keys()
for aid, name, ck in ACCOUNTS:
    got = db_keys.get(aid, "")
    if not got:
        print(f"   [{aid}] {name:<12} ✗ 数据库里读不到 client_key")
        ok_all = False
    elif got == ck:
        print(f"   [{aid}] {name:<12} ✓ key 正确（{got}）")
    else:
        print(f"   [{aid}] {name:<12} ✗ key 不符，期望 {ck}，实际 {got}")
        ok_all = False

print()
print("=" * 62)
print("3) 回调地址是否为备案域名（抖音硬性要求：不接受 IP+端口）")
print("=" * 62)
red = d.get("redirect_uri", "")
print("   redirect_uri =", red)
if red == CALLBACK:
    print("   ✓ 已指向备案域名")
else:
    ok_all = False
    print("   ✗ 应为", CALLBACK)

print()
print("=" * 62)
print("4) 公网回调端点是否可达（抖音服务器要能访问）")
print("=" * 62)
try:
    st, _ = get(PUBLIC + "/oauth/status/douyin")
    print("   GET", PUBLIC + "/oauth/status/douyin ->", st)
    print("   ✓ 公网可达" if st < 500 else "   ✗ 公网不通，检查 frp 隧道与云 nginx")
    if st >= 500:
        ok_all = False
except Exception as e:  # noqa: BLE001
    print("   ✗ 公网不可达:", e)
    ok_all = False

print()
print("=" * 62)
print("5) 4 条可粘贴到浏览器的授权链接（★「缺少参数」定位用）")
print("=" * 62)
print("   抖音官方 FAQ：Web 授权页报「缺少参数」= 网站应用回调地址校验不过。")
print("   2023-06-12 起校验规则从「只校验域名」升级为「校验域名 + path」，")
print("   必须到控制台填【完整 URL】，只填 zmgen.cn 是无效的。")
print()
print("   → 控制台 / 我的应用 / 网站应用 / 设置 / 开发配置 / 授权回调地址")
print("     填：https://zmgen.cn/oauth/callback/douyin")
print("   4 个应用都要填这一条，保存后等 1-2 分钟生效。")
print("   另需确认：应用类型是「网站应用」，且已申请并通过 video.create 权限。")
print()
print("   下面每条链接粘到浏览器地址栏打开，只看是否出【二维码】：")
print("   - 出二维码 → 回调配置已正确，回平台点「去授权」扫码即可")
print("   - 仍报「缺少参数」→ 该应用回调没填/填错，或应用类型不是网站应用")
print("   - 这些链接 state 仅 10 分钟有效，只看页面、不要拿它扫码")
print("-" * 62)
for aid, name, ck in ACCOUNTS:
    try:
        st2, d2 = authorize(aid, ck)
        print(f"   [{aid}] {name}")
        print("   " + d2.get("authorize_url", ""))
    except Exception as e:  # noqa: BLE001
        print(f"   [{aid}] {name} 生成失败: {e}")
print("-" * 62)

print()
print("=" * 62)
if ok_all:
    print("前 4 项全部通过 ✓ — 4 个应用都填好完整回调 URL 后，去平台账号页点「去授权」")
else:
    print("存在未通过项 ✗ — 请按上方提示处理")
print("=" * 62)
