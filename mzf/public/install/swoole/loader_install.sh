#!/bin/bash
# =====================================================================
# Swoole Compiler Loader 安装脚本（需 root 执行）
# 用法：
#   sudo bash loader_install.sh <phpver>        # 例：sudo bash loader_install.sh 82
#   sudo bash loader_install.sh <phpver> --check # 仅测试提权是否可用（供网页探测）
#
# 逻辑：据 /www/server/php/<ver>/ 推导 扩展目录/php.ini/php-cli.ini/php-fpm 服务，
#       自动识别 nts/zts，选用同目录内置的 swoole_loader<ver>_nts.so(nts)/swoole_loader<ver>_zts.so(zts)，
#       拷入扩展目录 + 幂等追加 extension 到 php.ini 与 php-cli.ini + reload php-fpm + 验证。
#       幂等：重复执行安全。
# =====================================================================
set -o pipefail

VER="$1"
DIR="$(cd "$(dirname "$0")" && pwd)"

# 参数校验：仅允许两三位数字（如 74/80/82/83）
if ! [[ "$VER" =~ ^[0-9]{2,3}$ ]]; then
    echo "ERR: 无效的 PHP 版本参数（应形如 82）"; exit 1
fi

PHPROOT="/www/server/php/$VER"
PHPBIN="$PHPROOT/bin/php"
INI_FPM="$PHPROOT/etc/php.ini"
INI_CLI="$PHPROOT/etc/php-cli.ini"
FPM="/etc/init.d/php-fpm-$VER"

if [[ ! -x "$PHPBIN" ]]; then echo "ERR: 未找到 PHP 可执行文件 $PHPBIN"; exit 1; fi

# --check：仅验证能以 root 跑到这里（网页据此判断 sudoers 是否配好）
if [[ "$2" == "--check" ]]; then echo "OK"; exit 0; fi

# 线程安全 → nts / zts
if "$PHPBIN" -i 2>/dev/null | grep -qi 'Thread Safety.*enabled'; then SAFE="zts"; else SAFE="nts"; fi
# 扩展目录
EXTDIR="$("$PHPBIN" -i 2>/dev/null | grep -i '^extension_dir' | head -1 | awk -F'=> ' '{print $2}' | tr -d ' ')"
if [[ -z "$EXTDIR" || ! -d "$EXTDIR" ]]; then echo "ERR: 无法确定扩展目录（$EXTDIR）"; exit 1; fi

# nts → swoole_loader<ver>_nts.so ；zts → swoole_loader<ver>_zts.so
if [[ "$SAFE" == "zts" ]]; then SO="swoole_loader${VER}_zts.so"; else SO="swoole_loader${VER}_nts.so"; fi
SRC="$DIR/$SO"
if [[ ! -f "$SRC" ]]; then
    echo "ERR: 缺少内置扩展文件 $SRC"
    echo "     请将对应版本 .so 放入：$DIR/  （命名：$SO）"
    exit 2
fi

# 已加载则直接成功（幂等）
if "$PHPBIN" -m 2>/dev/null | grep -qix 'swoole_loader'; then
    echo "OK: swoole_loader 已加载，无需重复安装"; exit 0
fi

# 1) 拷贝 .so
cp -f "$SRC" "$EXTDIR/$SO" && chmod 644 "$EXTDIR/$SO"
echo "· 已放置扩展：$EXTDIR/$SO"

# 2) 幂等写入 php.ini / php-cli.ini（FPM 与 CLI/worker 都需要）
for INI in "$INI_FPM" "$INI_CLI"; do
    [[ -f "$INI" ]] || continue
    if ! grep -q "extension=$SO" "$INI"; then
        printf '\n; swoole_loader (加密核心运行前置)\nextension=%s\n' "$SO" >> "$INI"
        echo "· 已写入配置：$INI"
    else
        echo "· 配置已存在：$INI"
    fi
done

# 3) reload php-fpm
if [[ -x "$FPM" ]]; then
    "$FPM" reload >/dev/null 2>&1 || "$FPM" restart >/dev/null 2>&1
    echo "· 已重载 php-fpm-$VER"
fi
sleep 1

# 4) 验证（CLI）
if "$PHPBIN" -m 2>/dev/null | grep -qix 'swoole_loader'; then
    echo "OK: swoole_loader 安装成功"; exit 0
else
    echo "WARN: 已写入配置，但未检测到扩展加载。请检查是否有互斥扩展(xdebug/ionCube)或手动重启 PHP。"; exit 3
fi
