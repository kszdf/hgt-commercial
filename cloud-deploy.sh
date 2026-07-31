#!/usr/bin/env bash
# ============================================================
# 慧根堂商用平台 — 云端一键部署脚本（混合云路线 A）
# 用法（在腾讯云遨驰终端 ORCaTerm 粘贴）：
#   curl -fsSL https://ghproxy.com/https://raw.githubusercontent.com/USER/REPO/main/cloud-deploy.sh \
#     | bash -s -- https://github.com/USER/REPO.git
# 第二个参数可选填域名/IP，不填则自动取服务器公网 IP。
# 全程无需人工干预，约 3~8 分钟（取决于拉镜像速度）。
# ============================================================
set -euo pipefail

REPO_URL="${1:?用法: bash cloud-deploy.sh <github仓库地址> [域名或IP]}"
APP_DOMAIN="${2:-}"
DEPLOY_DIR="/opt/hgt-commercial"

echo "==> [1/8] 检测系统并安装 Docker"
if ! command -v docker >/dev/null 2>&1; then
  if [ -f /etc/os-release ] && grep -qi "ubuntu\|debian" /etc/os-release; then
    apt-get update -y
    apt-get install -y ca-certificates curl gnupg
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://mirrors.cloud.tencent.com/docker-ce/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://mirrors.cloud.tencent.com/docker-ce/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
  else
    # CentOS / TencentOS
    yum install -y yum-utils
    yum-config-manager --add-repo https://mirrors.cloud.tencent.com/docker-ce/linux/centos/docker-ce.repo
    yum install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
  fi
  systemctl enable --now docker
else
  echo "    Docker 已安装，跳过"
fi

echo "==> [2/8] 克隆代码（自动走 ghproxy 加速国内拉取）"
rm -rf "$DEPLOY_DIR"
mkdir -p "$DEPLOY_DIR"
cd "$DEPLOY_DIR"
if [[ "$REPO_URL" == *github.com* ]]; then
  CLONE_URL="https://ghproxy.com/${REPO_URL}"
else
  CLONE_URL="$REPO_URL"
fi
git clone --depth 1 "$CLONE_URL" . || git clone "$CLONE_URL" .

echo "==> [3/8] 生成生产 .env"
cp .env.example .env
# 随机强密码
DB_PASS="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 24)"
ROOT_PASS="$(openssl rand -base64 18 | tr -dc 'A-Za-z0-9' | head -c 24)"
sed -i "s#^APP_ENV=.*#APP_ENV=production#" .env
sed -i "s#^APP_DEBUG=.*#APP_DEBUG=false#" .env
sed -i "s#^APP_URL=.*#APP_URL=http://${APP_DOMAIN:-$(curl -fsSL ifconfig.me || echo 127.0.0.1)}#" .env
sed -i "s#^DB_PASSWORD=.*#DB_PASSWORD=${DB_PASS}#" .env
sed -i "s#^DB_ROOT_PASSWORD=.*#DB_ROOT_PASSWORD=${ROOT_PASS}#" .env
# 出片地址：云服务器宿主机的 frp 穿透端口（本地 Windows 经 frpc 连上来）
sed -i "s#^PYTHON_PIPELINE_URL=.*#PYTHON_PIPELINE_URL=http://host.docker.internal:8500#" .env

echo "==> [4/8] 构建并启动生产栈（nginx+app+mysql+redis）"
docker compose -f docker-compose.prod.yml up -d --build

echo "==> [5/8] 生成 APP_KEY 并等待 MySQL 就绪"
docker compose -f docker-compose.prod.yml exec -T app php artisan key:generate --force
for i in $(seq 1 30); do
  if docker compose -f docker-compose.prod.yml exec -T mysql mysqladmin ping -p"${DB_PASS}" -h localhost 2>/dev/null | grep -q "alive"; then
    echo "    MySQL 就绪"; break
  fi
  sleep 3
done

echo "==> [6/8] 数据库迁移 + 种子数据（huigentang 租户）"
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan db:seed --force

echo "==> [7/8] 开放防火墙 80 端口"
if command -v ufw >/dev/null 2>&1; then ufw allow 80/tcp || true; fi
if command -v firewall-cmd >/dev/null 2>&1; then firewall-cmd --permanent --add-port=80/tcp && firewall-cmd --reload || true; fi

echo "==> [8/8] 冒烟测试"
sleep 5
HTTP_CODE="$(curl -s -o /dev/null -w '%{http_code}' http://localhost:80/login || echo 000)"
echo "    登录页 HTTP 状态: $HTTP_CODE"
if [ "$HTTP_CODE" = "200" ]; then
  echo "============================================================"
  echo " 部署成功！"
  echo " 访问: http://${APP_DOMAIN:-$(curl -fsSL ifconfig.me || echo '<服务器公网IP>')}/login"
  echo " 账号: admin@huigentang.com / admin888"
  echo " 下一步：在本机 Windows 运行 frpc 把出片微服务穿透到本服务器 8500 端口"
  echo "============================================================"
else
  echo "⚠️ 冒烟测试未返回 200，请查看日志: docker compose -f docker-compose.prod.yml logs"
fi
