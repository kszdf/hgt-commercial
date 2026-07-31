# 部署执行包 · 混合云（Web 上云 + 渲染留本地）

> 配套文件：`docker-compose.prod.yml`（云服务器栈）、`frpc-local.toml`（本机穿透）、`.env`（云环境）。
> 目标：把 Laravel Web 层部署到 hgtcs 云服务器，本地 Windows 继续出片，经 frp 打通。

---

## 一、需要你提供（执行前必填）

1. **hgtcs SSH 访问**：`ssh 用户@124.222.33.233 -p <端口>`，密钥或密码；以及系统版本（Ubuntu/CentOS？）。
2. **是否已有 ICP 备案域名**：有 → 给域名（用于 APP_URL + 后续 HTTPS）；无 → 先用 `http://124.222.33.233` 试跑。
3. **frps 实际配置**：hgtcs 上 frps 的 `bind_port`（默认 7000？）、是否允许 `remote_port=8500`（或指定其他端口，改 `frpc-local.toml` 的 remotePort）。
4. **hgtcs 是否已装 Docker**：未装 → 首次部署需 `curl -fsSL https://get.docker.com | sh` + `compose` 插件。
5. **云服务器规格**：盘/RAM（Web 层 2C2G 足够；确认盘 ≥ 20GB 余量）。

---

## 二、云服务器 .env（关键差异，其余沿用本地）

```
APP_ENV=production
APP_DEBUG=false
APP_URL=http://124.222.33.233          # 有域名则改 https://your-domain.com
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=hgt_commercial
DB_USERNAME=hgt
DB_PASSWORD=<强密码>
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
PYTHON_PIPELINE_URL=http://124.222.33.233:8500   # 指向 frp 暴露的本地出片微服务
```

> APP_KEY 沿用本地已生成的（或 `php artisan key:generate` 重新生成）。

---

## 三、部署步骤（在 hgtcs 上）

```bash
# 1. 取代码（仓库已含本目录；或 git clone）
cd /opt && git clone <repo> hgt-commercial && cd hgt-commercial

# 2. 写云 .env（按第二节填）
cp .env.example .env && nano .env

# 3. 起栈
docker compose -f docker-compose.prod.yml up -d --build

# 4. 初始化
docker compose -f docker-compose.prod.yml exec app php artisan key:generate
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
docker compose -f docker-compose.prod.yml exec app php artisan storage:link

# 5. 冒烟测试
curl -s -o /dev/null -w "login HTTP %{http_code}\n" http://127.0.0.1/login
```

---

## 四、本机穿透（本地 Windows）

```powershell
# 本机启动出片微服务（已在跑可跳过）
D:/heygem/py310/Scripts/python.exe D:/heygem_data/hgt-commercial/python-pipeline/server.py

# 本机启动 frpc（按第一节确认 serverPort/remotePort）
frpc.exe -c D:/heygem_data/hgt-commercial/frpc-local.toml
```

验证云→本机通：在 hgtcs 上 `curl -s http://127.0.0.1:8500/health` 应返回 `{"status":"ok"}`
（frp 打通后云端 localhost 即映射到本机 8500）。

---

## 五、HTTPS（有备案域名后）

最简：在 hgtcs 加一个 Caddy 容器反代 nginx:80，自动签发 Let's Encrypt。
或 nginx 容器补 `listen 443 ssl` + 证书。生产建议走 HTTPS（浏览器混合内容/安全校验）。

---

## 六、8385 退役（验证无功能缺口后再执行）

1. 新平台经真实商用试跑无缺口（选题/二创暂未上线，可保留 8385 只读）。
2. 公告用户迁移 → `Stop-Service HGTStudio` → `sc delete HGTStudio`。
3. 源码留档 GitHub，不删。

> 退役只在「新平台真实试跑通过且无功能性缺口」后进行，避免业务中断。
