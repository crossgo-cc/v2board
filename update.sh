#!/bin/bash

set -Eeuo pipefail

if [ ! -d ".git" ]; then
    echo "更新失败：请使用 Git 部署项目。"
    exit 1
fi

for required_command in git php wget; do
    if ! command -v "$required_command" > /dev/null 2>&1; then
        echo "更新失败：缺少命令 $required_command。"
        exit 1
    fi
done

php_version_id="$(php -r 'echo PHP_VERSION_ID;')"
if [ "$php_version_id" -lt 80100 ]; then
    echo "更新失败：当前 PHP 版本为 $(php -r 'echo PHP_VERSION;')，最低要求 PHP 8.1。"
    exit 1
fi

project_dir="$(pwd -P)"
if ! git config --global --get-all safe.directory 2>/dev/null | grep -Fqx "$project_dir"; then
    git config --global --add safe.directory "$project_dir"
fi

if [ "$(git branch --show-current)" != "master" ]; then
    echo "更新失败：当前分支不是 master。"
    exit 1
fi

if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "更新失败：检测到未提交的已跟踪文件修改，请先提交或备份。"
    exit 1
fi

composer_tmp="$(mktemp /tmp/v2board-composer.XXXXXX)"
maintenance_enabled=0
worker_stopped=0

restore_application() {
    if [ "$maintenance_enabled" -eq 1 ]; then
        if ! php artisan up > /dev/null 2>&1; then
            rm -f storage/framework/down
        fi
        maintenance_enabled=0
    fi
}

cleanup() {
    exit_code=$?
    trap - EXIT
    set +e
    if [ -n "$composer_tmp" ] && [ -f "$composer_tmp" ]; then
        rm -f "$composer_tmp"
    fi
    restore_application
    if [ "$exit_code" -ne 0 ]; then
        echo "更新失败，已退出 Laravel 维护模式。"
        if [ "$worker_stopped" -eq 1 ]; then
            echo "Workerman 已停止，请修复错误后在进程管理器中重新启动。"
        fi
    fi
    exit "$exit_code"
}

trap cleanup EXIT

git fetch origin master
if ! git merge-base --is-ancestor HEAD origin/master; then
    echo "更新失败：本地 master 与 origin/master 已分叉，拒绝覆盖本地提交。"
    exit 1
fi

wget -q https://github.com/composer/composer/releases/latest/download/composer.phar -O "$composer_tmp"
php "$composer_tmp" --version > /dev/null
mv "$composer_tmp" composer.phar
composer_tmp=""

if [ ! -f storage/framework/down ]; then
    php artisan down --retry=60
    maintenance_enabled=1
fi

if [ -s workerman.webman.php.pid ]; then
    worker_stopped=1
    php -c cli-php.ini webman.php stop
fi

git merge --ff-only origin/master
php composer.phar install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan v2board:update

if [ -f "/etc/init.d/bt" ]; then
    chown -R www "$project_dir"
fi

restore_application
trap - EXIT

echo "代码与数据库更新完成。"
if [ "$worker_stopped" -eq 1 ]; then
    echo "请在进程管理器中重新启动 Workerman 项目。"
else
    echo "Workerman 更新前未运行，请按部署配置启动项目。"
fi
