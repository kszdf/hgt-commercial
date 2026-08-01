# 慧根堂商用平台 · 云端部署指南（混合云路线 A）

> 路线 A：**Web 层上云 + 出片微服务留本机经 frp 穿透**。
> 原因：HEYGEM 数字人渲染需 GPU + 本机 Windows 依赖（ffmpeg 绝对路径 / 本地 qwen_tts / PY310），
> 小/廉价/免费云服务器跑不了，故渲染与 TTS 永远留本机或租 GPU 云（成本高）。

---

## 一、架构

```
[用户浏览器] ──HTTPS──> [云服务器 124.222.33.233:8080]
                            │  Laravel + nginx + mysql + redis（Docker）
                            │  PYTHON_PIPELINE_URL=http://host.docker.internal:8500
                            │
                            └──frp 内网穿透──> [本机 Windows :8500]
                                                  │  NSSM 服务 HGTCommercial8500
                                                  │  make_scroll_video.py + CosyVoice 真实配音
```

- 8500 **不暴露公网**，仅经 frp 给云端 Web 层调用，渲染密钥不外泄。
- 成品视频落本机 `python-pipeline/jobs/{jobId}/`，云端如需对外分发再传对象存储（COS/OSS/七牛）。

---

## 二、前置条件（卡用户）

1. ✅ ICP 备案通过（国内 B 端获客必须，否则域名无法解析/被墙）
2. ✅ SSL 证书（备案后申请，Nginx 配 HTTPS，否则 cookie/接口明文）
3. ✅ 云服务器已就绪（124.222.33.233，frps 已起 token `hgt2026studio#K8mPq`）
4. ✅ 本机 8500 常驻服务 `HGTCommercial8500` RUNNING（已装好）

---

## 三、云端部署步骤

```bash
# 1. 云服务器登录后
git clone git@github.com:kszdf/hgt-commercial.git
cd hgt-commercial
cp .env.example .env
# 2. 改 .env 生产值（见 .env.example 底部「上线前必改清单」）
#    APP_ENV=production APP_DEBUG=false
#    APP_URL=https://你的备案域名
#    APP_KEY=php artisan key:generate --show 生成后填入
#    DB_PASSWORD / DB_ROOT_PASSWORD 强随机
#    PYTHON_PIPELINE_URL=http://host.docker.internal:8500
docker compose up -d --build
docker exec -it hgt-commercial-app-1 php artisan key:generate
docker exec -it hgt-commercial-app-1 php artisan migrate --force
docker exec -it hgt-commercial-app-1 php artisan storage:link
docker exec -it hgt-commercial-app-1 php artisan config:cache
# 3. 浏览器访问 https://你的域名:8080/login，用 admin 登录验证
```

> ⚠️ 若云端访问 502：几乎都是 app 容器 opcache / 配置未刷新，登云端执行
> `docker restart hgt-commercial-app-1` 即可（php-fpm 的 opcache 不按 mtime 重校验）。

---

## 四、本机 frp 穿透打通（让云端能调本机 8500）

1. 确认本机有 frpc 客户端（如 `D:/frp/frpc.exe`；若缺失需重新下载 frp 0.61+）
2. 配置文件已就绪：`deploy/frpc.toml`（serverAddr=124.222.33.233, token 一致）
3. 启动穿透（本机，管理员或登录启动）：
   ```powershell
   D:/frp/frpc.exe -c D:/heygem_data/hgt-commercial/deploy/frpc.toml
   ```
4. 验证：云端容器内 `curl http://host.docker.internal:8500/health` 返回 `{"status":"ok"}`

> 本机 8500 由 NSSM 服务常驻，开机自启；frpc 也建议配成开机自启（管理员 NSSM 或登录启动 bat）。

---

## 五、上线验证清单

- [ ] ICP 备案号已挂在网站底部
- [ ] HTTPS 正常（证书有效，无混合内容）
- [ ] 登录页可访问，admin 密码已改（非 admin888）
- [ ] 选题 → 二创 → 出片 全链路在云端跑通
- [ ] 出片时云端能经 frp 调通本机 8500（health 绿）
- [ ] 真实出片产出有声视频，男声按 D 基调情绪起伏
- [ ] 配额计量正常扣减
- [ ] 8385 老平台已按 `docs/8385-RETIREMENT.md` 退役

---

## 六、回滚预案

- Web 层出问题：云端 `docker compose down` 回退到 8385 老平台（Parallel 运行期互不干扰）
- 本机 8500 挂：frp 断开 → 云端出片失败但页面/数据完好，修好本机服务即恢复
- 数据库：云端 mysql 每日自动备份卷 `mysql_data`
