#!/bin/bash

set -Eeuo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
cd "$script_dir"

for required_command in php wget grep; do
    if ! command -v "$required_command" > /dev/null 2>&1; then
        echo "安装失败：缺少命令 $required_command。"
        exit 1
    fi
done

php_version_id="$(php -r 'echo PHP_VERSION_ID;')"
if [ "$php_version_id" -lt 80100 ]; then
    echo "安装失败：当前 PHP 版本为 $(php -r 'echo PHP_VERSION;')，最低要求 PHP 8.1。"
    exit 1
fi

for required_extension in fileinfo pcntl posix redis; do
    if ! php -m | grep -Fxiq "$required_extension"; then
        echo "安装失败：PHP CLI 缺少 $required_extension 扩展。"
        exit 1
    fi
done

if ! php -c cli-php.ini -r '
foreach (["fileinfo", "pcntl", "posix", "redis"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "安装失败：cli-php.ini 未加载 {$extension} 扩展。\n");
        exit(1);
    }
}
'; then
    exit 1
fi

if [ ! -f composer.lock ]; then
    echo "安装失败：缺少 composer.lock，无法保证依赖版本一致。"
    exit 1
fi

if [ -f .env ]; then
    echo "安装失败：检测到 .env；如需重新安装，请先备份并清理现有环境和数据库。"
    exit 1
fi

composer_tmp="$(mktemp /tmp/v2board-composer.XXXXXX)"
cleanup() {
    exit_code=$?
    trap - EXIT
    if [ -f "$composer_tmp" ]; then
        rm -f "$composer_tmp"
    fi
    exit "$exit_code"
}
trap cleanup EXIT

wget -q https://github.com/composer/composer/releases/latest/download/composer.phar -O "$composer_tmp"
php "$composer_tmp" --version > /dev/null
mv "$composer_tmp" composer.phar

php composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction

php artisan v2board:install

if [ "$(id -u)" -eq 0 ] && id www > /dev/null 2>&1; then
    www_group="$(id -gn www)"
    chown -R "www:$www_group" storage bootstrap/cache
fi

trap - EXIT
echo "安装完成，请按照部署文档启动 Workerman 和 Horizon。"
