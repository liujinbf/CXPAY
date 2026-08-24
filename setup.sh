#!/usr/bin/env bash
# =============================================================================
#  CXPAY 宝塔面板 · 一键全自动安装脚本 v3
#  用法：cd /www/wwwroot/你的项目目录 && bash setup.sh
#  功能：环境检测 → 安装依赖 → 交互配置 → 初始化数据库 →
#        生成 .env → 配置 Nginx 反向代理 → 配置 Supervisor → 启动 Webman
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ── 颜色 ──────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GRN='\033[0;32m'; YLW='\033[1;33m'
BLU='\033[0;34m'; CYN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

ok()     { echo -e "${GRN}  ✓ $*${NC}"; }
warn()   { echo -e "${YLW}  ⚠ $*${NC}"; }
err()    { echo -e "${RED}  ✗ $*${NC}"; echo ""; exit 1; }
info()   { echo -e "${BLU}  → $*${NC}"; }
hd()     { echo -e "\n${BOLD}${CYN}══ $* ══${NC}"; }
prompt() { printf "${BOLD}${YLW}  › %s${NC} " "$*"; }
sep()    { echo -e "${CYN}  ──────────────────────────────────────────────────${NC}"; }

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
echo -e "  ${BOLD}一键全自动安装脚本 v3${NC} — 宝塔面板专用"
sep
echo ""

# ── 防重复安装 ────────────────────────────────────────────────────────────────
LOCK_FILE="$SCRIPT_DIR/install.lock"
if [ -f "$LOCK_FILE" ]; then
    warn "检测到安装锁 install.lock，系统已安装完毕。"
    info "如需重装，请先备份数据库，再手动删除 install.lock 后重新运行。"
    echo ""
    exit 0
fi

# ── Step 1 & 2: PHP 版本 + 扩展联合检测（自动选最合适版本）────────────────────
hd "Step 1  自动选择 PHP 版本（含扩展验证）"

REQUIRED_EXTS=(pdo_mysql redis bcmath mbstring curl openssl pcntl json)
PHP_BIN=""
PHP_SKIP_REASONS=()

for candidate in \
    /www/server/php/83/bin/php \
    /www/server/php/82/bin/php \
    /www/server/php/81/bin/php \
    /www/server/php/80/bin/php \
    php8.3 php8.2 php8.1 php \
    /usr/local/php/bin/php \
    /usr/bin/php; do

    # 文件必须存在且可执行
    _bin=$(command -v "$candidate" 2>/dev/null || true)
    [ -z "$_bin" ] && continue

    # 检查版本 ≥ 8.1
    _ver=$("$_bin" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "0.0")
    _maj=$(echo "$_ver" | cut -d. -f1)
    _min=$(echo "$_ver" | cut -d. -f2)
    if ! ( [ "$_maj" -gt 8 ] || ( [ "$_maj" -eq 8 ] && [ "$_min" -ge 1 ] ) ); then
        PHP_SKIP_REASONS+=("$_bin (PHP $_ver < 8.1，跳过)")
        continue
    fi

    # 逐一检查所有必要扩展
    _missing=()
    for ext in "${REQUIRED_EXTS[@]}"; do
        "$_bin" -r "exit(extension_loaded('$ext') ? 0 : 1);" 2>/dev/null || _missing+=("$ext")
    done

    if [ ${#_missing[@]} -eq 0 ]; then
        PHP_BIN="$_bin"
        ok "已选择 PHP $("$PHP_BIN" -r 'echo PHP_VERSION;')  路径：$PHP_BIN"
        ok "所有必需扩展均已加载（${REQUIRED_EXTS[*]}）"
        break
    else
        PHP_SKIP_REASONS+=("$_bin (PHP $_ver，缺少扩展：${_missing[*]})")
    fi
done

if [ -z "$PHP_BIN" ]; then
    echo ""
    err_msg="未找到满足条件的 PHP 版本（需要 PHP 8.1+ 且包含所有必要扩展）"
    echo -e "${RED}  ✗ ${err_msg}${NC}"
    echo ""
    echo -e "  ${YLW}检测到的 PHP 版本不满足条件：${NC}"
    for reason in "${PHP_SKIP_REASONS[@]}"; do
        echo -e "    ${RED}✗${NC} $reason"
    done
    echo ""
    echo -e "  ${BOLD}宝塔修复步骤：${NC}"
    echo "  1. 软件商店 → 已安装 → 找到你的 PHP 8.x 版本"
    echo "  2. 点击「设置」→「安装扩展」"
    echo "  3. 安装所有缺失的扩展后重新运行 bash setup.sh"
    echo ""
    exit 1
fi


# ── Step 3: Composer 安装依赖 ────────────────────────────────────────────────────
hd "Step 3  PHP 依赖库检测与加载"

if [ -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
    ok "检测到安装包已内置完整 PHP 依赖库 (vendor/autoload.php 就绪)，免在线下载！"
else
    COMPOSER_CMD=""
    if command -v composer &>/dev/null; then
        COMPOSER_CMD="composer"
        ok "找到全局 composer：$(composer --version 2>/dev/null | head -1)"
    elif [ -f "$SCRIPT_DIR/composer.phar" ]; then
        COMPOSER_CMD="$PHP_BIN $SCRIPT_DIR/composer.phar"
        ok "找到本地 composer.phar"
    else
        info "下载 Composer（阿里云镜像）..."
        _downloaded=false
        if command -v curl &>/dev/null; then
            curl -sSL https://mirrors.aliyun.com/composer/composer.phar -o "$SCRIPT_DIR/composer.phar" 2>/dev/null \
                || curl -sSL https://getcomposer.org/composer.phar -o "$SCRIPT_DIR/composer.phar" 2>/dev/null \
                && _downloaded=true
        elif command -v wget &>/dev/null; then
            wget -q https://mirrors.aliyun.com/composer/composer.phar -O "$SCRIPT_DIR/composer.phar" 2>/dev/null \
                || wget -q https://getcomposer.org/composer.phar -O "$SCRIPT_DIR/composer.phar" 2>/dev/null \
                && _downloaded=true
        fi
        if [ "$_downloaded" = true ] && [ -f "$SCRIPT_DIR/composer.phar" ]; then
            chmod +x "$SCRIPT_DIR/composer.phar"
            COMPOSER_CMD="$PHP_BIN $SCRIPT_DIR/composer.phar"
            ok "Composer 下载完成"
        else
            err "Composer 下载失败，请手动安装后重试"
        fi
    fi

    $COMPOSER_CMD config -g repo.packagist composer https://mirrors.aliyun.com/composer/ 2>/dev/null || true
    info "执行 composer install（可能需要 1~3 分钟）..."
    $COMPOSER_CMD install --no-dev --optimize-autoloader --no-interaction --prefer-dist 2>&1

    if [ -f "$SCRIPT_DIR/vendor/autoload.php" ]; then
        ok "依赖安装完成，vendor/autoload.php 就绪"
    else
        err "composer install 失败，请检查网络或 PHP 扩展后重试"
    fi
fi


# 创建运行时目录
mkdir -p "$SCRIPT_DIR/runtime/logs"
ok "运行时目录就绪"

# 检测 Web 用户
WEB_USER="www"
id "$WEB_USER" &>/dev/null 2>&1 || WEB_USER=$(whoami)
chmod -R 775 "$SCRIPT_DIR/runtime/" 2>/dev/null || true
if [ "$(id -u)" -eq 0 ]; then
    chown -R "${WEB_USER}:${WEB_USER}" "$SCRIPT_DIR/runtime/" 2>/dev/null || true
fi

# ── Step 4: 交互式配置 ────────────────────────────────────────────────────────
hd "Step 4  填写配置信息"
echo ""
echo -e "  按提示输入，直接回车使用 ${BOLD}[默认值]${NC}"
echo ""

# 站点域名
prompt "站点域名（如 cs.fcwan.cn，不含 http/https）："
read -r INPUT_DOMAIN
DOMAIN="${INPUT_DOMAIN:-localhost}"
DOMAIN="${DOMAIN#http://}"; DOMAIN="${DOMAIN#https://}"; DOMAIN="${DOMAIN%%/*}"

SCHEME="https"
if [ "$DOMAIN" = "localhost" ] || echo "$DOMAIN" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
    SCHEME="http"
fi
APP_URL="${SCHEME}://${DOMAIN}"
ok "站点地址：$APP_URL"
echo ""

# MySQL
sep
echo -e "  ${BOLD}MySQL 数据库${NC}"
sep
prompt "MySQL 主机 [127.0.0.1]："
read -r INPUT_DB_HOST;  DB_HOST="${INPUT_DB_HOST:-127.0.0.1}"
prompt "MySQL 端口 [3306]："
read -r INPUT_DB_PORT;  DB_PORT="${INPUT_DB_PORT:-3306}"
prompt "数据库名称 [cxpay]："
read -r INPUT_DB_NAME;  DB_NAME="${INPUT_DB_NAME:-cxpay}"
prompt "数据库用户名 [root]："
read -r INPUT_DB_USER;  DB_USER="${INPUT_DB_USER:-root}"
prompt "数据库密码："
read -rs DB_PASS; echo ""
ok "数据库：${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo ""

# Redis
sep
echo -e "  ${BOLD}Redis${NC}"
sep
prompt "Redis 主机 [127.0.0.1]："
read -r INPUT_REDIS_HOST; REDIS_HOST="${INPUT_REDIS_HOST:-127.0.0.1}"
prompt "Redis 端口 [6379]："
read -r INPUT_REDIS_PORT; REDIS_PORT="${INPUT_REDIS_PORT:-6379}"
prompt "Redis 密码（无密码直接回车）："
read -rs REDIS_PASS; echo ""
ok "Redis：${REDIS_HOST}:${REDIS_PORT}"
echo ""

# 管理员
sep
echo -e "  ${BOLD}管理员账号${NC}"
sep
prompt "管理员用户名 [admin]："
read -r INPUT_ADMIN_USER; ADMIN_USER="${INPUT_ADMIN_USER:-admin}"

while true; do
    prompt "管理员密码（≥6位）："
    read -rs ADMIN_PASS; echo ""
    _len=${#ADMIN_PASS}
    if [ "$_len" -lt 6 ]; then
        warn "密码至少 6 位，请重新输入"; continue
    fi
    break
done
ok "管理员账号：$ADMIN_USER"
echo ""

# ── Step 5: 数据库初始化 (使用 PHP PDO 原生执行，杜绝依赖系统 mysql 命令) ─────────────────
hd "Step 5  数据库初始化与表结构导入"

info "使用 PHP PDO 连接并初始化数据库..."

export SETUP_DB_HOST="${DB_HOST}"
export SETUP_DB_PORT="${DB_PORT}"
export SETUP_DB_USER="${DB_USER}"
export SETUP_DB_PASS="${DB_PASS}"
export SETUP_DB_NAME="${DB_NAME}"
export SETUP_ADMIN_USER="${ADMIN_USER}"
export SETUP_ADMIN_PASS="${ADMIN_PASS}"
export SETUP_SCRIPT_DIR="${SCRIPT_DIR}"

DB_INIT_OUTPUT=$("$PHP_BIN" -r '
try {
    $host = getenv("SETUP_DB_HOST") ?: "127.0.0.1";
    $port = (int)(getenv("SETUP_DB_PORT") ?: 3306);
    $user = getenv("SETUP_DB_USER") ?: "root";
    $pass = (string)getenv("SETUP_DB_PASS");
    $dbName = getenv("SETUP_DB_NAME") ?: "cxpay";
    $adminUser = getenv("SETUP_ADMIN_USER") ?: "admin";
    $adminPass = (string)getenv("SETUP_ADMIN_PASS");
    $scriptDir = getenv("SETUP_SCRIPT_DIR") ?: __DIR__;

    $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);

    // 1. 创建数据库
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbName}`");

    // 2. 检查现有表数量
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"{$dbName}\"");
    $tableCount = (int)$stmt->fetchColumn();

    if ($tableCount > 0) {
        echo "EXISTS:" . $tableCount;
    } else {
        $sqlFile = $scriptDir . "/database/install.sql";
        if (!file_exists($sqlFile)) {
            throw new Exception("未找到 database/install.sql 基础表结构文件");
        }

        $sql = file_get_contents($sqlFile);
        // 执行主表结构
        $pdo->exec($sql);

        // 执行增量 patches
        $patches = glob($scriptDir . "/database/patch_v*.sql");
        if (!empty($patches)) {
            sort($patches, SORT_NATURAL);
            foreach ($patches as $patch) {
                try {
                    $pSql = file_get_contents($patch);
                    if (trim($pSql) !== "") {
                        $pdo->exec($pSql);
                    }
                } catch (Throwable $e) {
                    // 忽略重复列或已存在索引错误
                }
            }
        }

        // 3. 写入管理员账号密码哈希 (同时写入系统配置表 cx_config 与 RBAC 账号表 cx_admin)
        $adminHash = password_hash($adminPass, PASSWORD_BCRYPT, ["cost" => 12]);
        $tokenSalt = bin2hex(random_bytes(16));

        $stmt = $pdo->prepare("INSERT INTO cx_config (name, value, title) VALUES 
            (\"admin_account\", :acc, \"管理员账号\"),
            (\"admin_password_hash\", :pwd, \"管理员密码 Bcrypt 哈希\"),
            (\"token_salt\", :salt, \"Token HMAC 签名盐值\")
            ON DUPLICATE KEY UPDATE value=VALUES(value)");
        $stmt->execute([
            "acc"  => $adminUser,
            "pwd"  => $adminHash,
            "salt" => $tokenSalt,
        ]);

        try {
            $stmtAdmin = $pdo->prepare("INSERT INTO cx_admin (username, password_hash, role, display_name, status, create_time) 
                VALUES (:u, :p, \"root\", \"超级管理员\", 1, UNIX_TIMESTAMP()) 
                ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), role=\"root\"");
            $stmtAdmin->execute([
                "u" => $adminUser,
                "p" => $adminHash,
            ]);
        } catch (Throwable $e) {}

        $stmtCount = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=\"{$dbName}\"");
        $createdCount = (int)$stmtCount->fetchColumn();
        echo "SUCCESS:" . $createdCount;
    }
} catch (Throwable $e) {
    echo "ERROR:" . $e->getMessage();
}
' 2>&1)


DB_INIT_SUCCESS=false

if [[ "$DB_INIT_OUTPUT" == SUCCESS:* ]]; then
    _count="${DB_INIT_OUTPUT#SUCCESS:}"
    ok "数据库 ${DB_NAME} 初始化完成，成功导入并创建 ${_count} 张数据表！"
    ok "初始管理员账号 [${ADMIN_USER}] 配置就绪"
    DB_INIT_SUCCESS=true
elif [[ "$DB_INIT_OUTPUT" == EXISTS:* ]]; then
    _count="${DB_INIT_OUTPUT#EXISTS:}"
    warn "数据库 ${DB_NAME} 已有 ${_count} 张数据表，已保留现有数据"
    DB_INIT_SUCCESS=true
else

    _err="${DB_INIT_OUTPUT#ERROR:}"
    err "数据库初始化失败：${_err}"
    echo ""
    echo -e "  ${BOLD}请检查：${NC}"
    echo "  1. MySQL 服务是否已在宝塔中启动"
    echo "  2. 填写的数据库主机、端口、用户名和密码是否正确"
    echo "  3. 数据库用户是否具有建表和写入权限"
    echo ""
    exit 1
fi


# ── Step 6: 智能选择与生成 .env 配置文件 ──────────────────────────────────────
hd "Step 6  生成 .env 配置文件"

APP_KEY=$(openssl rand -hex 32 2>/dev/null \
    || "$PHP_BIN" -r "echo bin2hex(random_bytes(32));")

# 智能检测端口可用性（默认 8787，若被占用自动寻找 8788, 8789...）
WEBMAN_PORT=8787
while true; do
    if ss -tlnp 2>/dev/null | grep -q ":${WEBMAN_PORT} "; then
        WEBMAN_PORT=$((WEBMAN_PORT + 1))
    elif netstat -tlnp 2>/dev/null | grep -q ":${WEBMAN_PORT} "; then
        WEBMAN_PORT=$((WEBMAN_PORT + 1))
    else
        break
    fi
done

if [ "$WEBMAN_PORT" -ne 8787 ]; then
    info "检测到默认 8787 端口已被占用，已自动为本站点分配空闲端口：${WEBMAN_PORT}"
else
    ok "分配 Webman 监听端口：${WEBMAN_PORT}"
fi

cat > "$SCRIPT_DIR/.env" <<ENVEOF
APP_DEBUG=false
APP_URL="${APP_URL}"
APP_KEY="${APP_KEY}"
ALLOW_PRIVATE_CALLBACKS=false
SYSTEM_UPDATE_ENABLED=false
HOST=127.0.0.1
PORT=${WEBMAN_PORT}
WEBMAN_WORKERS=4

DB_HOST="${DB_HOST}"
DB_PORT="${DB_PORT}"
DB_DATABASE="${DB_NAME}"
DB_USERNAME="${DB_USER}"
DB_PASSWORD="${DB_PASS}"

REDIS_HOST="${REDIS_HOST}"
REDIS_PORT="${REDIS_PORT}"
REDIS_PASSWORD="${REDIS_PASS}"
REDIS_DB=0
ENVEOF

chmod 640 "$SCRIPT_DIR/.env"
ok ".env 写入完成（APP_KEY 已随机生成，端口：${WEBMAN_PORT}）"

# 写入安装锁（只要数据库初始化成功或保留现有表时均锁定）
if [ "$DB_INIT_SUCCESS" = true ]; then
    { echo "$(date -Iseconds)"; echo "STATUS=INSTALLED_AND_LOCKED"; } > "$LOCK_FILE"
    ok "install.lock 已创建，安装入口已安全锁定"
fi


# ── Step 6.5: 从官方云端拉取并安装基础支付插件 (纯插件化架构) ────────────────────
hd "Step 6.5  从官方云端拉取并初始化官方支付插件"

$PHP_BIN -r '
require_once "'"$SCRIPT_DIR"'/vendor/autoload.php";
require_once "'"$SCRIPT_DIR"'/support/bootstrap.php";

use app\payment\Plugin\PluginPackageInstaller;
use app\payment\Plugin\PluginManager;

$cloudBase = rtrim(getenv("CLOUD_CONTROL_URL") ?: "https://cloud.fcwan.cn", "/");
$plugins = [
    "cxpay.driver.alipay_face_pay",
    "cxpay.driver.alipay_cookie_cloud",
    "cxpay.app_asst_universal",
    "cxpay.driver.wechat_dy_bill",
    "cxpay.alipay.scan_monitor",
    "cxpay.wxpay.clerk_adapter",
    "cxpay.wxpay.cloud_adapter",
    "cxpay.alipay.accountlog_monitor",
];

$installer = new PluginPackageInstaller(
    (string)config("payment_plugin.path", base_path() . "/plugin/cxpay"),
    (string)config("payment_plugin.trusted_keys", base_path() . "/config/plugin_keys"),
    PluginManager::registry()
);

$reg = PluginManager::registry();
$tmpDir = sys_get_temp_dir() . "/cxpay_setup_plugins_" . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0777, true);

$successCount = 0;
foreach ($plugins as $pluginId) {
    $url = $cloudBase . "/downloads/plugins/" . $pluginId . ".cxpay-plugin";
    $target = $tmpDir . "/" . $pluginId . ".cxpay-plugin";
    
    $ctx = stream_context_create(["http" => ["timeout" => 10, "ignore_errors" => true], "ssl" => ["verify_peer" => false, "verify_peer_name" => false]]);
    $content = @file_get_contents($url, false, $ctx);
    if ($content !== false && strlen($content) > 100) {
        file_put_contents($target, $content);
        try {
            $installer->install($target);
            $reg->setEnabled($pluginId, true);
            echo "  ✓ [云端拉取并验签成功] " . $pluginId . "\n";
            $successCount++;
        } catch (Throwable $e) {
            echo "  ⚠️ [安装异常] " . $pluginId . ": " . $e->getMessage() . "\n";
        }
    }
}
@system("rm -rf " . escapeshellarg($tmpDir));
'

ok "官方基础支付插件已由云端拉取并完成公钥验签与热加载就绪！"

# ── Step 7: 配置 Nginx 反向代理 ───────────────────────────────────────────────
hd "Step 7  配置 Nginx 反向代理"

NGINX_VHOST_DIR="/www/server/panel/vhost/nginx"
NGINX_CONF="${NGINX_VHOST_DIR}/${DOMAIN}.conf"
NGINX_BIN=$(command -v nginx 2>/dev/null || echo "/www/server/nginx/sbin/nginx")

# 从现有配置中提取 SSL 证书路径（防止覆盖已有证书）
SSL_CERT=""
SSL_KEY=""
if [ -f "$NGINX_CONF" ]; then
    info "检测到已有 Nginx 配置，提取 SSL 证书路径..."
    SSL_CERT=$(grep -E '^\s*ssl_certificate\s' "$NGINX_CONF" 2>/dev/null \
        | grep -v '_key' | head -1 | awk '{print $2}' | tr -d ';' | tr -d ' ' || true)
    SSL_KEY=$(grep -E '^\s*ssl_certificate_key\s' "$NGINX_CONF" 2>/dev/null \
        | head -1 | awk '{print $2}' | tr -d ';' | tr -d ' ' || true)
fi

# 未提取到则使用宝塔默认路径
SSL_CERT="${SSL_CERT:-/www/server/panel/vhost/cert/${DOMAIN}/fullchain.pem}"
SSL_KEY="${SSL_KEY:-/www/server/panel/vhost/cert/${DOMAIN}/privkey.pem}"

if [ "$SCHEME" = "https" ]; then
    NGINX_NEW_CONF="server {
    listen 80;
    server_name ${DOMAIN};
    return 301 https://\$host\$request_uri;
}

server {
    listen 443 ssl http2;
    server_name ${DOMAIN};

    ssl_certificate     ${SSL_CERT};
    ssl_certificate_key ${SSL_KEY};
    ssl_protocols       TLSv1.1 TLSv1.2 TLSv1.3;
    ssl_ciphers         EECDH+CHACHA20:EECDH+CHACHA20-draft:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache   shared:SSL:10m;
    ssl_session_timeout 10m;
    add_header Strict-Transport-Security \"max-age=31536000\";
    error_page 497 https://\$host\$request_uri;

    client_max_body_size 6m;

    location / {
        proxy_pass         http://127.0.0.1:${WEBMAN_PORT};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
        proxy_http_version 1.1;
        proxy_read_timeout 60s;
    }
}"
else
    NGINX_NEW_CONF="server {
    listen 80;
    server_name ${DOMAIN};

    client_max_body_size 6m;

    location / {
        proxy_pass         http://127.0.0.1:${WEBMAN_PORT};
        proxy_set_header   Host \$host;
        proxy_set_header   X-Real-IP \$remote_addr;
        proxy_set_header   X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto http;
        proxy_http_version 1.1;
        proxy_read_timeout 60s;
    }
}"
fi

if [ -d "$NGINX_VHOST_DIR" ]; then
    # 备份原配置
    if [ -f "$NGINX_CONF" ]; then
        cp "$NGINX_CONF" "${NGINX_CONF}.bak.$(date +%Y%m%d%H%M%S)"
        ok "原 Nginx 配置已备份"
    fi
    printf '%s\n' "$NGINX_NEW_CONF" > "$NGINX_CONF"
    ok "Nginx 配置已写入：$NGINX_CONF"

    # 测试并重载
    if "$NGINX_BIN" -t >/dev/null 2>&1; then
        "$NGINX_BIN" -s reload >/dev/null 2>&1 \
            && ok "Nginx 已重载" \
            || warn "Nginx 重载失败，请手动执行：nginx -s reload"
    else
        warn "Nginx 配置测试未通过，请手动检查："
        warn "nginx -t && nginx -s reload"
    fi
else
    warn "未找到宝塔 Nginx vhost 目录（${NGINX_VHOST_DIR}），跳过自动配置"
    info "请手动将以下内容粘贴到宝塔「网站 → ${DOMAIN} → 配置文件」："
    echo ""
    echo "─────────────────── 复制以下内容 ───────────────────"
    echo "$NGINX_NEW_CONF"
    echo "─────────────────────────────────────────────────────"
    echo ""
fi

# ── Step 8: Supervisor 守护进程 ───────────────────────────────────────────────
hd "Step 8  配置 Supervisor 守护进程"

SUPERVISOR_CONF_CONTENT="[program:cxpay-webman]
command=${PHP_BIN} ${SCRIPT_DIR}/start.php start
directory=${SCRIPT_DIR}
autostart=true
autorestart=true
startretries=3
stderr_logfile=${SCRIPT_DIR}/runtime/logs/supervisor-stderr.log
stdout_logfile=${SCRIPT_DIR}/runtime/logs/supervisor-stdout.log
user=${WEB_USER}
stopasgroup=true
killasgroup=true"

# 保存到项目目录
SUPERVISOR_LOCAL="$SCRIPT_DIR/cxpay-webman.supervisor.conf"
printf '%s\n' "$SUPERVISOR_CONF_CONTENT" > "$SUPERVISOR_LOCAL"
ok "Supervisor 配置文件已生成：cxpay-webman.supervisor.conf"

# 自动安装到系统 Supervisor
SUPERVISOR_CONF_DIR=""
for _d in /www/server/supervisor/conf /etc/supervisor/conf.d /etc/supervisord.d; do
    [ -d "$_d" ] && { SUPERVISOR_CONF_DIR="$_d"; break; }
done

SUPERVISORCTL=$(command -v supervisorctl 2>/dev/null || true)

if [ -n "$SUPERVISOR_CONF_DIR" ] && [ "$(id -u)" -eq 0 ]; then
    cp "$SUPERVISOR_LOCAL" "${SUPERVISOR_CONF_DIR}/cxpay-webman.conf"
    ok "已安装到 ${SUPERVISOR_CONF_DIR}/cxpay-webman.conf"
    if [ -n "$SUPERVISORCTL" ]; then
        $SUPERVISORCTL update >/dev/null 2>&1 && ok "supervisorctl update 完成" || true
    fi
elif [ -n "$SUPERVISOR_CONF_DIR" ]; then
    info "检测到 Supervisor 目录，但当前非 root，请手动安装："
    echo "  sudo cp cxpay-webman.supervisor.conf ${SUPERVISOR_CONF_DIR}/cxpay-webman.conf"
    echo "  sudo supervisorctl update"
elif [ -z "$SUPERVISORCTL" ]; then
    info "未检测到 Supervisor，建议在宝塔「软件商店」中安装 Supervisor 后运行："
    echo "  sudo cp cxpay-webman.supervisor.conf /www/server/supervisor/conf/cxpay-webman.conf"
    echo "  sudo supervisorctl update"
fi

# ── Step 9: 启动 Webman ───────────────────────────────────────────────────────
hd "Step 9  启动 Webman 服务"

# 先尝试通过 Supervisor 启动
_started_by_supervisor=false
if [ -n "$SUPERVISORCTL" ] && $SUPERVISORCTL status cxpay-webman >/dev/null 2>&1; then
    $SUPERVISORCTL restart cxpay-webman >/dev/null 2>&1 \
        && ok "Webman 已通过 Supervisor 重启" \
        && _started_by_supervisor=true || true
fi

# 备用：直接后台启动
if [ "$_started_by_supervisor" = false ]; then
    "$PHP_BIN" "$SCRIPT_DIR/start.php" stop >/dev/null 2>&1 || true
    sleep 1
    "$PHP_BIN" "$SCRIPT_DIR/start.php" start -d >/dev/null 2>&1 \
        && ok "Webman 已在后台启动（端口 8787）" \
        || warn "Webman 启动失败，请查看日志后手动执行：php start.php start -d"
fi

# 验证端口
sleep 2
_port_ok=false
if ss -tlnp 2>/dev/null | grep -q ':8787'; then
    _port_ok=true
elif netstat -tlnp 2>/dev/null | grep -q ':8787'; then
    _port_ok=true
fi

if [ "$_port_ok" = true ]; then
    ok "端口 8787 监听正常，Webman 运行中"
else
    warn "未检测到 8787 端口，Webman 可能启动失败"
    info "请查看日志：tail -f ${SCRIPT_DIR}/runtime/logs/workerman.log"
fi

# ── 完成摘要 ─────────────────────────────────────────────────────────────────
echo ""
sep
echo -e "  ${GRN}${BOLD}✓ CXPAY 安装完成！${NC}"
sep
echo ""
echo -e "  ${BOLD}🔗 管理后台地址：${NC}"
echo -e "     ${GRN}${BOLD}${APP_URL}/admin_login.html${NC}"
echo ""
echo -e "  ${BOLD}👤 账号信息：${NC}"
echo -e "     用户名：${BOLD}${ADMIN_USER}${NC}"
echo -e "     密  码：你填写的管理员密码"
if [ "$DB_INIT_SUCCESS" = false ]; then
    echo -e "  ${YLW}⚠ 数据库未自动初始化，请访问以下地址完成：${NC}"
    echo -e "     ${BOLD}${APP_URL}/install${NC}"
    echo ""
fi


echo -e "  ${BOLD}🔄 重启 Webman：${NC}"
echo -e "     ${BLU}${PHP_BIN} ${SCRIPT_DIR}/start.php stop && ${PHP_BIN} ${SCRIPT_DIR}/start.php start -d${NC}"
echo ""
echo -e "  ${BOLD}📄 查看日志：${NC}"
echo -e "     ${BLU}tail -f ${SCRIPT_DIR}/runtime/logs/workerman.log${NC}"
echo ""
sep
echo ""
