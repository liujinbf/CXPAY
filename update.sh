#!/bin/bash
# =============================================================
# CXPAY 在线一键更新脚本 (update.sh)
# 适用：Linux 生产服务器，Webman 常驻协程架构
# 用法：bash update.sh [--skip-db] [--force]
# =============================================================

set -e  # 任意命令失败立即终止

# ─── 可配置参数 ───────────────────────────────────────────────
APP_DIR="/www/cxpay"          # 项目根目录，按实际修改
PHP_BIN="php"                  # PHP 可执行路径
GIT_BRANCH="main"              # 跟踪的远端分支
BACKUP_DIR="/www/backups/cxpay"
DB_NAME="${DB_NAME:-cxpay}"    # 数据库名（优先读环境变量）
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
LOG_FILE="${APP_DIR}/runtime/update.log"
# ─────────────────────────────────────────────────────────────

SKIP_DB=false
FORCE=false
for arg in "$@"; do
  [[ "$arg" == "--skip-db" ]] && SKIP_DB=true
  [[ "$arg" == "--force"   ]] && FORCE=true
done

TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
ROLLBACK_TAG="rollback_${TIMESTAMP}"

log() { echo -e "\033[1;36m[$(date '+%H:%M:%S')] $*\033[0m" | tee -a "$LOG_FILE"; }
ok()  { echo -e "\033[1;32m✓ $*\033[0m" | tee -a "$LOG_FILE"; }
err() { echo -e "\033[1;31m✗ $*\033[0m" | tee -a "$LOG_FILE"; exit 1; }
warn(){ echo -e "\033[1;33m⚠ $*\033[0m" | tee -a "$LOG_FILE"; }

mkdir -p "$BACKUP_DIR" "$(dirname $LOG_FILE)"
log "====== CXPAY 在线更新开始 ======"

# ─── Step 1: 检查工作区是否干净 ──────────────────────────────
cd "$APP_DIR"
if [[ -n "$(git status --porcelain)" ]] && [[ "$FORCE" != "true" ]]; then
  warn "工作区存在未提交的本地修改，请先处理或使用 --force 强制覆盖"
  git status --short
  exit 1
fi

# ─── Step 2: 打 Git Tag 作为回滚锚点 ─────────────────────────
log "Step 2/8: 创建回滚 Tag [${ROLLBACK_TAG}]..."
git tag "$ROLLBACK_TAG"
ok "回滚 Tag 已创建，紧急回滚命令：git checkout ${ROLLBACK_TAG}"

# ─── Step 3: 备份数据库 ──────────────────────────────────────
if [[ "$SKIP_DB" != "true" ]]; then
  log "Step 3/8: 备份数据库..."
  DB_BACKUP="${BACKUP_DIR}/db_${TIMESTAMP}.sql.gz"
  mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" \
    --single-transaction --quick "$DB_NAME" | gzip > "$DB_BACKUP"
  ok "数据库备份完成：${DB_BACKUP}"
else
  warn "Step 3/8: 已跳过数据库备份 (--skip-db)"
fi

# ─── Step 4: 拉取最新代码 ────────────────────────────────────
log "Step 4/8: 拉取远端最新代码 [${GIT_BRANCH}]..."
git fetch origin
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse "origin/${GIT_BRANCH}")

if [[ "$LOCAL" == "$REMOTE" ]]; then
  ok "代码已是最新版本 (${LOCAL:0:8})，无需更新"
  git tag -d "$ROLLBACK_TAG" 2>/dev/null || true
  exit 0
fi

log "  更新范围: ${LOCAL:0:8} → ${REMOTE:0:8}"
git log --oneline "${LOCAL}..${REMOTE}" | head -20

if [[ "$FORCE" == "true" ]]; then
  git reset --hard "origin/${GIT_BRANCH}"
else
  git pull origin "$GIT_BRANCH" --rebase
fi
ok "代码拉取完成"

# ─── Step 5: 检测是否有数据库迁移补丁 ───────────────────────
log "Step 5/8: 检测数据库迁移补丁..."
PATCH_FILES=$(git diff "${LOCAL}" HEAD --name-only | grep '^database/patch_' || true)

if [[ -n "$PATCH_FILES" && "$SKIP_DB" != "true" ]]; then
  warn "检测到数据库补丁文件，即将执行迁移："
  echo "$PATCH_FILES"
  for patch in $PATCH_FILES; do
    log "  执行补丁: ${patch}"
    mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "${APP_DIR}/${patch}" \
      && ok "  ${patch} 执行成功" \
      || err "  ${patch} 执行失败！已停止，请手动回滚"
  done
else
  ok "无数据库迁移补丁"
fi

# ─── Step 6: 安装/更新 PHP 依赖 ──────────────────────────────
log "Step 6/8: 更新 PHP Composer 依赖..."
if git diff "${LOCAL}" HEAD --name-only | grep -q 'composer.json'; then
  "$PHP_BIN" $(which composer) install --no-dev --optimize-autoloader --no-interaction
  ok "Composer 依赖已更新"
else
  ok "composer.json 无变更，跳过 composer install"
fi

# ─── Step 7: 平滑热重载（不中断在途支付请求）──────────────────
log "Step 7/8: Webman 平滑热重载（不中断服务）..."
"$PHP_BIN" "${APP_DIR}/start.php" reload
sleep 2  # 等待 Worker 完成当前请求后再继续

# 验证进程是否正常
WORKER_COUNT=$(pgrep -f "start.php" | wc -l)
if [[ "$WORKER_COUNT" -eq 0 ]]; then
  err "热重载后进程消失！正在尝试重启..."
  "$PHP_BIN" "${APP_DIR}/start.php" start -d
fi
ok "热重载完成，Worker 进程数：${WORKER_COUNT}"

# ─── Step 8: 更新后健康检查 ──────────────────────────────────
log "Step 8/8: 健康检查..."
SITE_URL="${SITE_URL:-http://127.0.0.1:8787}"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "${SITE_URL}/" || echo "000")

if [[ "$HTTP_CODE" == "200" || "$HTTP_CODE" == "301" || "$HTTP_CODE" == "302" ]]; then
  ok "健康检查通过 (HTTP ${HTTP_CODE})"
else
  err "健康检查失败 (HTTP ${HTTP_CODE})！请立即检查服务状态"
fi

# ─── 完成 ────────────────────────────────────────────────────
NEW_VERSION=$(git rev-parse HEAD | cut -c1-8)
log "====== 更新完成 ======"
ok "当前版本: ${NEW_VERSION}"
ok "回滚命令: cd ${APP_DIR} && git checkout ${ROLLBACK_TAG} && ${PHP_BIN} start.php reload"
echo ""
