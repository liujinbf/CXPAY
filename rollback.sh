#!/bin/bash
# =============================================================
# CXPAY 紧急一键回滚脚本 (rollback.sh)
# 用法：bash rollback.sh [tag名称]
#       不传 tag 则列出所有可用的回滚锚点
# =============================================================

APP_DIR="/www/cxpay"
PHP_BIN="php"
DB_BACKUP_DIR="/www/backups/cxpay"

cd "$APP_DIR"

log() { echo -e "\033[1;31m[ROLLBACK] $*\033[0m"; }
ok()  { echo -e "\033[1;32m✓ $*\033[0m"; }

if [[ -z "$1" ]]; then
  echo ""
  echo "可用回滚 Tag（最近10条）："
  git tag --sort=-creatordate | grep "^rollback_" | head -10
  echo ""
  echo "可用数据库备份："
  ls -lht "${DB_BACKUP_DIR}"/*.sql.gz 2>/dev/null | head -10
  echo ""
  echo "用法：bash rollback.sh rollback_20260727_191500"
  exit 0
fi

TARGET_TAG="$1"
log "开始回滚到 Tag: ${TARGET_TAG}"

# 检查 Tag 是否存在
if ! git rev-parse "$TARGET_TAG" >/dev/null 2>&1; then
  echo "错误：Tag [${TARGET_TAG}] 不存在"
  exit 1
fi

# 回滚代码
log "回滚代码..."
git checkout "$TARGET_TAG"
ok "代码已回滚到 ${TARGET_TAG}"

# 热重载
log "热重载 Webman..."
"$PHP_BIN" "${APP_DIR}/start.php" reload
ok "服务已重载"

log "====== 回滚完成 ======"
echo ""
echo "如需同步回滚数据库，请手动执行："
echo "  gunzip < ${DB_BACKUP_DIR}/db_<timestamp>.sql.gz | mysql -u root -p <dbname>"
echo ""
