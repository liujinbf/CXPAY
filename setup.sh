#!/usr/bin/env bash
# =============================================================================
#  CXPAY 宝塔面板 · 前置初始化脚本 v2
#  用法：在项目根目录执行  bash setup.sh
#  功能：检测 PHP/扩展 → 安装 Composer → 安装依赖 → 创建目录 → 设置权限
# =============================================================================

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ── 颜色 ──────────────────────────────────────────────────────────────────────
RED='\033[0;31m'
GRN='\033[0;32m'
YLW='\033[1;33m'
BLU='\033[0;34m'
CYN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

ok()   { echo -e "${GRN}  ✓ $*${NC}"; }
warn() { echo -e "${YLW}  ⚠ $*${NC}"; }
err()  { echo -e "${RED}  ✗ $*${NC}"; }
info() { echo -e "${BLU}  → $*${NC}"; }
head() { echo -e "\n${BOLD}${CYN}══ $* ══${NC}"; }

PASS=0
FAIL=0
track_ok()  { PASS=$((PASS+1)); }
track_err() { FAIL=$((FAIL+1)); }

# ── Banner ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYN}"
echo "  ██████╗██╗  ██╗██████╗  █████╗ ██╗   ██╗"
echo "  ██╔════╝╚██╗██╔╝██╔══██╗██╔══██╗╚██╗ ██╔╝"
echo "  ██║      ╚███╔╝ ██████╔╝███████║ ╚████╔╝ "
echo "  ██║      ██╔██╗ ██╔═══╝ ██╔══██║  ╚██╔╝  "
echo "  ╚██████╗██╔╝ ██╗██║     ██║  ██║   ██║   "
echo "   ╚═════╝╚═╝  ╚═╝╚═╝     ╚═╝  ╚═╝   ╚═╝   "
echo -e "${NC}"
echo -e "  ${BOLD}前置初始化脚本${NC} — 宝塔面板部署专用"
echo "  ────────────────────────────────────────"
echo ""

# ── Step 1: PHP 版本 ───────────────────────────────────────────────────────────
head "Step 1  PHP 版本检测"

PHP_BIN=$(command -v php 2>/dev/null || true)
if [ -z "$PHP_BIN" ]; then
    err "未找到 php 命令。宝塔面板：软件商店 → PHP 版本 → 安装 PHP 8.1，并在【设置 → 命令行版本】设为默认。"
    track_err; exit 1
fi

PHP_VER=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
PHP_MAJOR=$(echo "$PHP_VER" | cut -d. -f1)
PHP_MINOR=$(echo "$PHP_VER" | cut -d. -f2)

if [ "$PHP_MAJOR" -gt 8 ] || ( [ "$PHP_MAJOR" -eq 8 ] && [ "$PHP_MINOR" -ge 1 ] ); then
    ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')  满足 ≥ 8.1 要求"
    track_ok
else
    err "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')  不满足要求，需要 PHP 8.1+"
    warn "宝塔面板：软件商店 → PHP 版本 → 安装 8.1 或 8.2，再切换命令行版本"
    track_err; exit 1
fi

# ── Step 2: PHP 扩展检测 ────────────────────────────────────────────────────────
head "Step 2  PHP 扩展检测"

REQUIRED_EXTS=("pdo_mysql" "redis" "bcmath" "mbstring" "curl" "openssl" "pcntl" "json")
EXT_MISSING=()

for ext in "${REQUIRED_EXTS[@]}"; do
    if "$PHP_BIN" -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null; then
        ok "扩展 ${ext}"
        track_ok
    else
        err "扩展 ${ext}  ← 缺失"
        EXT_MISSING+=("$ext")
        track_err
    fi
done

if [ ${#EXT_MISSING[@]} -gt 0 ]; then
    echo ""
    warn "检测到以下扩展缺失，请在宝塔面板安装后重新运行此脚本："
    echo ""
    echo -e "  ${BOLD}宝塔操作路径：${NC}"
    echo "  软件商店 → PHP 8.x → 安装扩展 → 搜索并安装以下扩展："
    for ext in "${EXT_MISSING[@]}"; do
        echo -e "    ${RED}•${NC} $ext"
    done
    echo ""
    warn "特别注意：宝塔安装的 redis 扩展名称可能为 'redis'，pcntl 可能需要重新编译 PHP"
    warn "pcntl 无法从扩展商店直接安装时，请用宝塔的 PHP 编译安装（勾选 pcntl）"
    echo ""
    exit 1
fi

echo ""
ok "所有必需扩展均已加载"

# ── Step 3: Composer 检测与安装 ─────────────────────────────────────────────────
head "Step 3  Composer 检测与安装"

COMPOSER_CMD=""

# 优先使用全局 composer
if command -v composer &>/dev/null; then
    COMPOSER_CMD="composer"
    ok "找到全局 composer：$(composer --version 2>/dev/null | head -1)"
    track_ok
# 其次检查项目目录的 composer.phar
elif [ -f "$SCRIPT_DIR/composer.phar" ]; then
    COMPOSER_CMD="$PHP_BIN $SCRIPT_DIR/composer.phar"
    ok "找到本地 composer.phar"
    track_ok
else
    warn "未找到 Composer，正在下载 composer.phar..."
    # 优先从阿里云镜像下载（国内宝塔服务器）
    COMPOSER_DOWNLOAD_URL="https://mirrors.aliyun.com/composer/composer.phar"
    COMPOSER_FALLBACK_URL="https://getcomposer.org/composer.phar"

    if command -v curl &>/dev/null; then
        curl -sSL "$COMPOSER_DOWNLOAD_URL" -o "$SCRIPT_DIR/composer.phar" 2>/dev/null \
            || curl -sSL "$COMPOSER_FALLBACK_URL" -o "$SCRIPT_DIR/composer.phar"
    elif command -v wget &>/dev/null; then
        wget -q "$COMPOSER_DOWNLOAD_URL" -O "$SCRIPT_DIR/composer.phar" 2>/dev/null \
            || wget -q "$COMPOSER_FALLBACK_URL" -O "$SCRIPT_DIR/composer.phar"
    else
        err "curl 和 wget 均不可用，无法下载 Composer。请手动安装。"
        track_err; exit 1
    fi

    if [ -f "$SCRIPT_DIR/composer.phar" ]; then
        chmod +x "$SCRIPT_DIR/composer.phar"
        COMPOSER_CMD="$PHP_BIN $SCRIPT_DIR/composer.phar"
        ok "composer.phar 下载成功"
        track_ok
    else
        err "Composer 下载失败，请手动安装后重试"
        track_err; exit 1
    fi
fi

# 配置国内镜像（阿里云），加速宝塔服务器安装
info "配置 Packagist 阿里云镜像加速..."
$COMPOSER_CMD config -g repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true

# ── Step 4: 安装 PHP 依赖 ────────────────────────────────────────────────────────
head "Step 4  安装 PHP 依赖 (composer install)"

if [ -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
    info "vendor/ 目录已存在，执行 composer install 确认依赖完整性..."
else
    info "vendor/ 目录不存在，正在安装依赖..."
fi

$COMPOSER_CMD install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist \
    2>&1

if [ -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
    ok "composer install 完成，vendor/autoload.php 就绪"
    track_ok
else
    err "composer install 失败，vendor/autoload.php 不存在"
    track_err; exit 1
fi

# ── Step 5: 创建运行时目录 ────────────────────────────────────────────────────────
head "Step 5  创建运行时目录"

for dir in "runtime" "runtime/logs"; do
    if [ ! -d "$SCRIPT_DIR/$dir" ]; then
        mkdir -p "$SCRIPT_DIR/$dir"
        ok "创建目录：$dir"
    else
        ok "目录已存在：$dir"
    fi
    track_ok
done

# ── Step 6: 设置文件权限 ────────────────────────────────────────────────────────
head "Step 6  设置文件权限"

# 检测宝塔运行用户（通常为 www）
if id "www" &>/dev/null; then
    WEB_USER="www"
    WEB_GROUP="www"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
    WEB_GROUP="nginx"
elif id "apache" &>/dev/null; then
    WEB_USER="apache"
    WEB_GROUP="apache"
else
    WEB_USER=$(whoami)
    WEB_GROUP=$(id -gn)
fi

info "检测到 Web 运行用户：${WEB_USER}:${WEB_GROUP}"

chmod -R 775 "$SCRIPT_DIR/runtime/" 2>/dev/null && ok "runtime/ 权限设为 775" || warn "无法修改 runtime/ 权限（非 root）"
chmod -R 755 "$SCRIPT_DIR/vendor/"  2>/dev/null && ok "vendor/ 权限设为 755"  || warn "无法修改 vendor/ 权限（非 root）"
chmod 644 "$SCRIPT_DIR/composer.json" 2>/dev/null

# 尝试设置所有者（需要 root）
if [ "$(id -u)" -eq 0 ]; then
    chown -R "${WEB_USER}:${WEB_GROUP}" "$SCRIPT_DIR/runtime/" 2>/dev/null && ok "runtime/ 所有者设为 ${WEB_USER}:${WEB_GROUP}"
    chown -R "${WEB_USER}:${WEB_GROUP}" "$SCRIPT_DIR/vendor/"  2>/dev/null && ok "vendor/ 所有者设为 ${WEB_USER}:${WEB_GROUP}"
    track_ok
else
    warn "当前非 root 用户，跳过 chown（权限问题请在宝塔面板手动处理）"
fi

# ── Step 7: .env 文件检测 ────────────────────────────────────────────────────────
head "Step 7  .env 配置文件"

if [ -f "$SCRIPT_DIR/.env" ]; then
    ok ".env 文件已存在，跳过创建"
    track_ok
else
    if [ -f "$SCRIPT_DIR/.env.example" ]; then
        cp "$SCRIPT_DIR/.env.example" "$SCRIPT_DIR/.env"
        chmod 640 "$SCRIPT_DIR/.env"
        ok ".env 从 .env.example 复制完成"
        warn ".env 仅为模板，请通过浏览器安装向导（/install）完成真实配置"
        track_ok
    else
        warn ".env.example 不存在，请通过安装向导自动生成 .env"
    fi
fi

# ── Step 8: 生成 Supervisor 配置 ──────────────────────────────────────────────────
head "Step 8  生成 Supervisor 配置文件（可选）"

SUPERVISOR_CONF="$SCRIPT_DIR/cxpay-webman.supervisor.conf"
cat > "$SUPERVISOR_CONF" << SUPEOF
[program:cxpay-webman]
command=${PHP_BIN} ${SCRIPT_DIR}/start.php start
directory=${SCRIPT_DIR}
autostart=true
autorestart=true
startretries=3
stderr_logfile=${SCRIPT_DIR}/runtime/logs/supervisor-stderr.log
stdout_logfile=${SCRIPT_DIR}/runtime/logs/supervisor-stdout.log
user=${WEB_USER}
stopasgroup=true
killasgroup=true
SUPEOF

ok "Supervisor 配置已生成：cxpay-webman.supervisor.conf"
info "使用方法：cp cxpay-webman.supervisor.conf /etc/supervisor/conf.d/ && supervisorctl update"
track_ok

# ── 最终摘要 ────────────────────────────────────────────────────────────────────
echo ""
echo -e "${BOLD}${CYN}══════════════════════════════════════════════════${NC}"
echo -e "  ${BOLD}初始化结果摘要${NC}"
echo -e "${BOLD}${CYN}══════════════════════════════════════════════════${NC}"
echo -e "  ${GRN}通过${NC}：${PASS} 项     ${RED}失败${NC}：${FAIL} 项"
echo ""

if [ "$FAIL" -eq 0 ]; then
    echo -e "  ${GRN}${BOLD}✓ 前置初始化全部完成！${NC}"
    echo ""
    echo -e "  ${BOLD}下一步操作：${NC}"
    echo ""
    echo -e "  ${BLU}1.${NC} 启动 Webman 服务："
    echo -e "     ${BOLD}${PHP_BIN} ${SCRIPT_DIR}/start.php start -d${NC}"
    echo ""
    echo -e "  ${BLU}2.${NC} 在宝塔面板配置 Nginx 反向代理："
    echo -e "     网站 → 设置 → 反向代理 → 代理名称随意 → 目标 URL：${BOLD}http://127.0.0.1:8787${NC}"
    echo -e "     ${YLW}或直接使用安装向导第2步生成的完整 Nginx 配置文件${NC}"
    echo ""
    echo -e "  ${BLU}3.${NC} 浏览器访问 ${BOLD}http://你的域名/install${NC} 完成安装向导"
    echo ""
    echo -e "  ${BLU}4.${NC} 安装完成后重启 Webman："
    echo -e "     ${BOLD}${PHP_BIN} ${SCRIPT_DIR}/start.php stop && ${PHP_BIN} ${SCRIPT_DIR}/start.php start -d${NC}"
    echo ""
else
    echo -e "  ${RED}${BOLD}✗ 有 ${FAIL} 项检查未通过，请按上方提示逐一修复后重新运行此脚本。${NC}"
    echo ""
fi

echo -e "${BOLD}${CYN}══════════════════════════════════════════════════${NC}"
echo ""
