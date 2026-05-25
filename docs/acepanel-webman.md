# AcePanel 部署 V2Board Webman 版教程

本文说明如何在 AcePanel 中部署 V2Board，并使用 `webman.php` 以
`Adapterman + Workerman` 长驻进程模式运行 Laravel。

示例配置：

```text
站点名：v2board-site
站点域名：example.com
站点目录：/opt/ace/sites/v2board-site
项目目录：/opt/ace/sites/v2board-site/v2board
运行目录：/opt/ace/sites/v2board-site/v2board/public
Webman 监听地址：127.0.0.1:6600
```

## 1. 安装运行环境

进入：

```text
应用 -> 原生应用
```

安装：

```text
Nginx
MySQL
Redis
```

进入：

```text
应用 -> 运行环境 -> PHP
```

安装：

```text
PHP 8.2
```

进入：

```text
应用 -> 运行环境 -> PHP -> 管理 -> 模块管理
```

确保以下模块为启用状态：

```text
redis
fileinfo
pcntl
posix
```

## 2. 创建 PHP 网站

进入：

```text
网站 -> PHP -> 创建网站
```

填写：

```text
名称：v2board-site
域名：example.com
端口：80
PHP 版本：PHP 8.2
网站目录：/opt/ace/sites/v2board-site
数据库：创建 MySQL 数据库
```

创建后进入网站编辑页，在基础设置中设置：

```text
网站目录：/opt/ace/sites/v2board-site/v2board
运行目录：/public
默认文档：index.php index.html
```

## 3. 安装 V2Board

进入站点目录：

```bash
cd /opt/ace/sites/v2board-site
```

拉取项目：

```bash
git clone https://github.com/your-org/v2board.git v2board
cd /opt/ace/sites/v2board-site/v2board
```

执行安装：

```bash
sh init.sh
```

按安装向导完成数据库和管理员账号配置。

## 4. 配置 Nginx 转发到 Webman

进入网站编辑页，打开 `自定义配置`，新增下面的 Nginx 配置：

```nginx
location /downloads {
}

location / {
    try_files $uri @webman;
}

location @webman {
    proxy_pass http://127.0.0.1:6600;
    proxy_http_version 1.1;

    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    proxy_read_timeout 300;
    proxy_send_timeout 300;
}

location ~ .*\.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf)?$ {
    expires 1h;
    error_log off;
    access_log /dev/null;
}
```

检查并重载 Nginx：

```bash
nginx -t
nginx -s reload
```

## 5. 创建 Webman 项目

进入：

```text
项目 -> 创建项目
```

填写：

```text
项目名称：v2board-webman
项目目录：/opt/ace/sites/v2board-site/v2board
运行用户：www
启动命令：php -c cli-php.ini webman.php start
开机自启：启用
重启策略：失败时重启
```

启动命令不要加 `-d`。

## 6. 创建 Horizon 队列项目

继续进入：

```text
项目 -> 创建项目
```

填写：

```text
项目名称：v2board-horizon
项目目录：/opt/ace/sites/v2board-site/v2board
运行用户：www
启动命令：php artisan horizon
开机自启：启用
重启策略：失败时重启
```

## 7. 配置计划任务

进入：

```text
任务 -> 计划任务 -> 创建任务
```

填写：

```text
名称：v2board-schedule
类型：Shell 脚本
执行周期：每 1 分钟
```

脚本内容：

```bash
cd /opt/ace/sites/v2board-site/v2board && php artisan schedule:run
```

## 8. 验证

检查 Webman：

```bash
cd /opt/ace/sites/v2board-site/v2board
php -c cli-php.ini webman.php status
```

检查 Horizon：

```bash
cd /opt/ace/sites/v2board-site/v2board
php artisan horizon:status
```

访问：

```text
https://example.com
```

前台、后台和节点 API 均正常时，部署完成。
