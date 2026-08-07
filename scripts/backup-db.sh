#!/usr/bin/env bash
#
# 追梦平台 — MySQL 每日备份脚本
# 用法：bash scripts/backup-db.sh
# 说明：运行时从 app 容器读取数据库凭据（不硬编码密码），
#       由 mysql 容器执行 mysqldump 并 gzip 落盘到 storage/backups/，
#       自动保留最近 7 份，其余删除。
#
set -euo pipefail

APP_CONTAINER="hgt-commercial-app-1"
MYSQL_CONTAINER="hgt-commercial-mysql-1"

# 项目根（脚本位于 scripts/ 下，上级即项目根）
PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="$PROJECT_ROOT/storage/backups"
KEEP_DAYS=7

mkdir -p "$BACKUP_DIR"

echo "[$(date '+%F %T')] 读取数据库配置..."
# 通过 tinker 引导 Laravel 读取配置（php -r 无法加载框架辅助函数）
DB_DATABASE="$(docker exec "$APP_CONTAINER" php artisan tinker --execute="echo config('database.connections.mysql.database');" 2>/dev/null | tr -d '\r\n')"
DB_USERNAME="$(docker exec "$APP_CONTAINER" php artisan tinker --execute="echo config('database.connections.mysql.username');" 2>/dev/null | tr -d '\r\n')"
DB_PASSWORD="$(docker exec "$APP_CONTAINER" php artisan tinker --execute="echo config('database.connections.mysql.password');" 2>/dev/null | tr -d '\r\n')"

if [ -z "$DB_DATABASE" ] || [ -z "$DB_USERNAME" ]; then
  echo "  ERROR: 无法读取数据库配置，请确认容器 $APP_CONTAINER 正在运行。" >&2
  exit 1
fi

TS="$(date '+%Y%m%d_%H%M%S')"
OUT="$BACKUP_DIR/db_${TS}.sql.gz"

echo "[$(date '+%F %T')] 导出数据库 $DB_DATABASE ..."
# 使用 MYSQL_PWD 传递密码避免在进程列表暴露；--single-transaction 保证一致性
# --no-tablespaces 避免非 root 账号缺少 PROCESS 权限导致的 tablespaces 报错（MySQL 8+）
docker exec -e MYSQL_PWD="$DB_PASSWORD" "$MYSQL_CONTAINER" \
  mysqldump --single-transaction --no-tablespaces --routines --triggers -u "$DB_USERNAME" "$DB_DATABASE" \
  | gzip > "$OUT"

if [ ! -s "$OUT" ]; then
  echo "  ERROR: 备份文件为空，导出失败。" >&2
  rm -f "$OUT"
  exit 1
fi

SIZE="$(du -h "$OUT" | cut -f1)"
echo "[$(date '+%F %T')] 备份完成：$OUT ($SIZE)"

# 清理：保留最近 KEEP_DAYS 份（用 mapfile 避免 Git Bash 下 xargs 的 environment too large 问题）
echo "[$(date '+%F %T')] 清理旧备份（保留 $KEEP_DAYS 份）..."
mapfile -t FILES < <(ls -tp "$BACKUP_DIR"/*.sql.gz 2>/dev/null | grep -v '/$')
if [ "${#FILES[@]}" -gt "$KEEP_DAYS" ]; then
  for f in "${FILES[@]:$KEEP_DAYS}"; do
    rm -f "$f"
  done
fi
echo "[$(date '+%F %T')] 当前备份列表："
ls -lt "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -n "$KEEP_DAYS"
echo "DONE"
