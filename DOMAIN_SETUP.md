# 域名接入方案（让网站用一级域名访问）

> 目标：把 `http://124.222.33.233:8080/login` 升级为 `https://video.你的域名.com/login`
> 现状：云端 Web 层已跑通（Docker 4 容器 Up，端口 8080）；服务器 80 端口被「建税盾」占用。
> 适用：国内 B 端获客，必须 ICP 备案 + HTTPS，否则微信内打开会被拦。

---

## 一、整体架构（反向代理合并）

```
用户 → https://video.你的域名.com  (80/443)
            │
       【前台 nginx 反向代理】(服务器 80/443)
            ├─ video.你的域名.com  →  127.0.0.1:8080  (我们的 Laravel 平台容器)
            └─ 建税盾域名/路径      →  建税盾原后端    (不动它现有 80 逻辑，改由代理转发)
```

我们的 Docker nginx 仍监听 8080（仅内网），对外统一由前台 nginx 在 80/443 收口。

---

## 二、执行步骤（按顺序）

### 步骤 1：注册域名（你操作，~50-100 元/年）
- 腾讯云：https://dnspod.cloud.tencent.com → 域名注册
- 或阿里云：https://wanwang.aliyun.com
- 建议 `.com` 或 `.cn`，名称如 `huigentang.com`
- 注册时完成**域名实名认证**（否则无法备案/解析）

### 步骤 2：ICP 备案（强制，~1-2 周，0 元）
- 腾讯云：控制台 → 网站备案 → 首次备案
- 需材料：营业执照（慧根堂主体）、法人身份证、手机号、核验照
- 备案类型为「单位备案」，域名需已实名且归属本单位
- 备案期间网站可继续用 IP:8080 访问，不影响业务
- ⚠️ 备案成功前，域名不能指向国内服务器（会被拦），所以解析放到步骤 4 做

### 步骤 3：准备服务器反向代理配置（可现在做，不依赖备案）
在云服务器 `/opt/hgt-commercial/nginx-front/` 下放前台 nginx 配置（见附录 A），
用 `docker run` 起一个独立 nginx 容器监听 80/443，反代到我们的 8080 容器。
- 建税盾：保留其原 80 监听，或在代理里为它配 `server_name jianhuishuo.com` 转发到其 upstream（需确认建税盾后端端口）。

### 步骤 4：DNS 解析（备案通过后做）
- 域名控制台 → 解析 → 添加 A 记录：
  - 主机记录 `video` → 记录值 `124.222.33.233` → 线路默认 → TTL 600
- 若建税盾也要域名：再加一条 `www` 或 `jian` → 同 IP
- 生效 5 分钟~几小时

### 步骤 5：申请 SSL 证书（备案后，0 元）
- 腾讯云 SSL 控制台申请免费 DV 证书（TrustAsia/Let's Encrypt），绑定 `video.你的域名.com`
- 下载 nginx 格式证书，放到 `/opt/hgt-commercial/nginx-front/certs/`
- 或在服务器用 `certbot` 自动申请（见附录 B）

### 步骤 6：启用
- 前台 nginx 加载配置 + 证书，`docker restart` 或 `nginx -s reload`
- 验证：浏览器开 `https://video.你的域名.com/login` → 应显示登录页（带锁）
- 出片：本机 `start_frpc.bat` 保持运行，渲染链路通

---

## 三、前置检查清单（动手前确认）

- [ ] 域名已注册 + 实名认证
- [ ] ICP 备案已完成（单位备案，慧根堂主体）
- [ ] DNS A 记录已指向 124.222.33.233
- [ ] 云服务器防火墙已放通 80 + 443
- [ ] SSL 证书已申请并放到服务器
- [ ] 建税盾的访问方式已确认（子域名 or 路径），不冲突

---

## 四、成本与耗时

| 项目 | 费用 | 耗时 |
|---|---|---|
| 域名注册 | ~50-100 元/年 | 即时 |
| ICP 备案 | 0 元 | 1-2 周 |
| DNS 解析 | 0 元 | 5分~几小时 |
| SSL 证书 | 0 元（免费 DV） | 即时 |
| 服务器/额外资源 | 0 元（用现有 hgtcs） | - |
| **合计** | **~100 元/年** | **≈2 周（主要等备案）** |

---

## 五、风险与注意

1. **备案是硬门槛**：未备案域名指向国内服务器会被运营商拦截，微信打开直接红牌。务必先备后指。
2. **建税盾共存**：反向代理合并需确认建税盾后端端口，避免改坏它。建议先单独配我们的 `video` 子域名，建税盾暂维持原样，验证无影响后再考虑合并。
3. **端口冲突**：我们 Docker 容器保持 8080 内网，前台 nginx 独占 80/443，互不占。
4. **HTTPS 强制**：Laravel `.env` 里 `APP_URL` 改为 `https://video.你的域名.com`，避免混合内容告警。

---

## 附录 A：前台 nginx 反向代理配置（示例）

```nginx
# /opt/hgt-commercial/nginx-front/conf.d/video.conf
server {
    listen 80;
    server_name video.你的域名.com;
    # 备案期间可临时返回 200 或跳转，备案后删掉这段
    location /.well-known/acme-challenge/ { root /var/www/certbot; }
    location / { return 301 https://$host$request_uri; }
}

server {
    listen 443 ssl;
    server_name video.你的域名.com;

    ssl_certificate     /etc/nginx/certs/video.你的域名.com/fullchain.pem;
    ssl_certificate_key /etc/nginx/certs/video.你的域名.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    client_max_body_size 100M;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }
}
```

> 我们的 Docker compose 里 nginx 已暴露 8080，前台代理 `proxy_pass` 指向宿主 `127.0.0.1:8080` 即可。

---

## 附录 B：certbot 自动申请 SSL（备选）

```bash
# 在云服务器执行（需先装 certbot + 80 端口可用）
certbot certonly --webroot -w /var/www/certbot -d video.你的域名.com
# 证书生成在 /etc/letsencrypt/live/video.你的域名.com/
# 配置里 ssl_certificate 指向该路径，nginx -s reload
```

---

## 六、当前可立即做 vs 需等备案

| 可现在做（不依赖备案） | 需备案后做 |
|---|---|
| 写前台 nginx 反代配置 | DNS 解析指向服务器 |
| 起反代容器监听 80/443（本地自签证书测试） | 申请正式 SSL 证书 |
| 确认建税盾后端端口 | 对外用域名访问 |
| 改 `.env` 的 APP_URL 为域名（暂不影响 IP 访问） | 微信内打开不被拦 |

**结论**：方案完全可行，技术零障碍。唯一耗时是 ICP 备案（1-2 周，你本人持营业执照操作）。
备案等待期网站照常用 IP:8080 跑，备案一下来按本方案 30 分钟内切换完毕。
