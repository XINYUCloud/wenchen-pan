# 文谶云盘 v1.0

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](LICENSE)

HTML + PHP 8 + MySQL + Redis（可自动降级）的多格式文件外链云盘，传统 2005 门户风格界面。
支持图片 / 视频 / 音频 / 文档 / 压缩包 / 代码文件的上传、管理、外链分享与在线预览，含前台、上传端、管理后台三部分。

## 1. 功能一览

前台
- 邮箱注册 + 验证邮件链接、找回密码、换绑邮箱、图形验证码、登录失败锁定、记住登录、退出所有设备
- 多级文件夹、列表/网格视图、排序、搜索、类型筛选、收藏、标签、说明
- 批量删除 / 移动 / 打包 ZIP、回收站（保留期可配，到期自动清理）
- 图片灯箱、视频拖动播放（Range）、音频播放、文本/代码/PDF 在线预览
- 外链分享：独立短码、有效期、访问密码、下载次数上限、直链开关、是否进入公开索引
- 文件夹外链 + 访客打包下载、访客转存、二维码、举报入口、访问日志
- 公告中心、站内消息、下载排行、站内搜索、帮助中心、服务条款、违规举报

上传端
- 传统 multipart 表单（无 JS 可用）
- 现代多队列上传：拖拽、Ctrl+V 粘贴截图、进度条、速度、剩余时间、暂停/继续/取消
- 大文件自动分片断点续传（分片会话保留 2 天）
- 秒传（SHA1 去重，命中只建引用）
- URL 转存（禁内网地址、超时与大小限制）
- 上传限制：类型大类、扩展名黑白名单、单文件、每日总量、总配额、危险 MIME 拦截

管理后台（/admin）
- 控制台：核心指标、近 7 天上传/下载趋势、运行环境检测、闭站开关、统计校准
- 用户管理：搜索筛选、封禁/解封（原因写入站内信）、配额、角色、重置密码、删除账号与文件、单人/全站通知、发邮件
- 文件管理：全库检索（用户/类型/扩展名/大小/状态）、详情（真实 MIME、MD5/SHA1、引用数）、审核处理、批量操作、访问日志
- 外链管理：筛选、关闭/恢复、清除密码、重置短码、公开索引开关、批量操作、访问日志
- 公告管理：富文本（自动清除 script/iframe/on*）、置顶、草稿/发布、推送站内信
- 举报处理：待处理队列、关闭外链、删除文件、驳回、处理留痕、通知分享者
- 日志审计：类型/关键词/UID/IP/时间筛选、按保留期清理、超管清空
- 站点设置：站点基本、上传与类型、外链与下载、注册与安全、SEO 优化、头像服务、文案与条款（7 个分组）
- SEO：首页标题/关键词/描述、内页标题格式、索引策略、外链页是否可收录、动态 robots.txt、sitemap.xml、第三方统计代码（仅超管可改）
- 头像服务：Gravatar 国内镜像 + 官方源自动降级 + 前端三级兜底
- 邮件配置：SMTP(SSL/TLS)/PHP mail()/仅记录三种方式、在线测试发信、模板编辑与恢复默认、发信日志尾部查看
- 系统工具：目录与数据库明细、批量重建缩略图、清理临时分片/过期外链、统计校准、测试 ffmpeg、孤儿文件扫描、OPTIMIZE、SQL 备份导出、IP 黑名单

## 2. 目录结构

```
├── index.php register.php login.php logout.php verify.php forgot.php   账号与首页
├── files.php trash.php shares.php search.php rank.php                  文件与分享管理
├── upload.php chunk.php api.php                                        上传与传统表单
├── s.php d.php img.php preview.php qrcode.php                          外链展示 / 直链 / 缩略图 / 预览
├── announce.php notice.php help.php terms.php report.php               内容页
├── robots.php sitemap.php                                               动态 robots.txt 与 sitemap.xml
├── core/            公共库：bootstrap db kv functions auth mailer storage thumb zip upload template
├── admin/           后台：common login index users files shares announces reports logs settings mail tools
├── install/         安装向导 index.php、数据库升级 upgrade.php、schema.sql、defaults.php
├── assets/          style.css（前台皮肤）admin.css（后台皮肤）install.css js/{common,upload,sha1}.js favicon.ico
├── data/            storage/ 文件实体　thumbs/ 缩略图　tmp/ 分片　avatars/ 头像　logs/ 日志
├── config/          config.php（安装时生成）config.sample.php
├── .htaccess        Apache/宝塔伪静态与目录保护
├── nginx.conf.example  Nginx 伪静态与上传参数示例
└── router.php       本机 php -S 用的路由（同样支持 /s/ /d/ 伪静态）
```

`data/` 与 `config/` 已带 `.htaccess` 拒绝直接访问，文件一律经 PHP 流式输出（支持 Range）。

## 3. 安装

### 宝塔 / Apache
1. 新建站点，PHP 建议 8.0–8.3，扩展需 `pdo_mysql`、`mbstring`、`fileinfo`、`gd`（缩略图）、`curl`（URL 转存）、`openssl`（SMTP SSL）。
2. 上传本目录全部文件到站点根目录，访问 `https://你的域名/install/index.php`。
3. 填写数据库、Redis（可选）、站点地址、管理员邮箱与密码 → 开始安装。
4. 安装成功后 **删除 install 目录**，到后台「邮件配置」填 SMTP 并测试发信。
5. PHP 参数建议：`upload_max_filesize=210M`、`post_max_size=220M`、`max_execution_time=600`、`memory_limit=256M`。
6. 伪静态：根目录 `.htaccess` 在 Apache + `AllowOverride All` 下自动生效；Nginx 参考 `nginx.conf.example`。

### 本机
```bash
php -S 127.0.0.1:8093 -t . router.php
# 浏览器打开 http://127.0.0.1:8093/install/index.php
```

### Redis 说明
`config/config.php` 里 `REDIS_ENABLE/REDIS_HOST/...` 控制。程序内置 RESP 客户端，**不依赖 phpredis 扩展**；
连接不通时自动降级为 MySQL 表 `wc_kv` 存放会话、验证码、限流计数与设置缓存，功能不受影响。
后台控制台与用户中心都会显示当前实际使用的后端。

## 4. 数据表（前缀 wc_）

`wc_user` 账号 · `wc_folder` 目录 · `wc_file` 文件（含软删除） · `wc_share` 外链 · `wc_share_log` 外链访问日志 ·
`wc_announce` 公告 · `wc_notice` 站内信 · `wc_setting` 站点参数 · `wc_mail_tpl` 邮件模板 ·
`wc_report` 举报 · `wc_log` 操作/登录/上传日志 · `wc_kv` 键值降级存储 · `wc_chunk`/`wc_chunk_part` 分片会话 · `wc_stat` 每日统计

秒传去重按 `sha1 + size` 复用实体文件，删除时按引用计数保留物理文件，因此同一内容多账号引用只占一份磁盘。

## 4.1 头像（Gravatar 三级降级）

优先级：**用户上传的自定义头像 > Gravatar > 昵称首字占位**。

- Gravatar 地址按 `md5(strtolower(trim(邮箱)))` 生成，请求带 `?s=尺寸&d=404`，因此没有 Gravatar 的邮箱不会显示陌生人的默认头像，而是回落到字母占位。
- 服务端探测：`core/functions.php` 的 `gravatar_mirror_alive()` 用 HEAD 请求探测镜像是否在线（能拿到任意 HTTP 状态码即视为在线，404 也算）。
  - 在线 → 输出 `https://gravatar.fohok.xin/avatar/{hash}`（镜像地址后台可改）
  - 不可达 → 自动改用 `https://www.gravatar.com/avatar/{hash}`，探测结果缓存：在线缓存 `gravatar_cache` 秒（默认 600），失败只缓存 30 秒，便于镜像恢复
- 前端兜底：`<img>` 带 `data-alt` 备用源与 `onerror="wcAvatarFail(this)"`，镜像 404/超时 → 切官方源 → 仍失败 → 隐藏图片显示字母占位，纯浏览器侧完成，不受服务端缓存影响
- 后台位置：站点设置 → 头像服务；系统工具 → 「探测 Gravatar 镜像」可强制重新探测并在提示里显示当前来源；控制台运行环境表也显示当前来源
- 用户中心头像区会显示当前 Gravatar 来源，上传自定义头像后自动优先本地头像，「清除头像」即回到 Gravatar

## 4.2 SEO

后台「站点设置 → SEO 优化」：

| 参数 | 作用 |
|---|---|
| `seo_title` | 首页 `<title>`，留空则用站点名 |
| `seo_keywords` / `seo_description` | 首页 meta，描述留空自动取首页公告条 |
| `seo_title_format` | 内页标题格式，支持 `{page}` `{site}` |
| `seo_robots_meta` | 内页 `<meta name="robots">`，可选 index,follow / noindex,follow / noindex,nofollow |
| `seo_index_share` | 是否允许搜索引擎收录 `/s/短码` 外链页，默认关闭（同时影响 robots.txt、sitemap.xml 与外链页 meta） |
| `seo_robots_file` | 是否输出动态 robots.txt |
| `seo_sitemap` / `seo_sitemap_days` | 是否输出 sitemap.xml，以及仅包含最近 N 天的公开外链 |
| `seo_stats_enable` / `seo_stats_code` | 第三方统计/分析代码，原样输出到前台页脚；**仅超级管理员可修改** |

实现要点：
- `robots.php`、`sitemap.php` 为动态入口，伪静态把 `/robots.txt`、`/sitemap.xml` 指过去（Apache、Nginx、本机 router.php 三种都已配好）
- sitemap 收录首页、固定内容页、已发布公告，以及 `pub=1 且 status=1 且文件未删除` 的公开外链，最多 5000 条，缓存 10 分钟
- 外链页额外输出 `og:title / og:description / og:url / og:image`（图片文件直链作为 og:image），便于社交与 IM 链接卡片
- 闭站维护时 robots.txt 自动变成 `Disallow: /` 且不含 Sitemap 行，恢复后自动还原

## 4.3 安全要点

- 全部 SQL 走 PDO 预处理；排序列名走白名单
- 所有表单 POST 带 CSRF token；GET 类修改动作要求 `k=` token
- 密码 `password_hash`（bcrypt），外链访问密码同样哈希存储
- 上传文件名随机化为存储路径，禁止路径穿越；`finfo` 真实 MIME 检测，脚本/可执行类型一律拒绝
- 危险扩展名默认黑名单：php/phtml/phar/asp/jsp/exe/bat/sh/msi/dll/htaccess 等
- 缩略图与头像地址带签名，避免自增 ID 枚举他人文件
- 支持 IP 黑名单、登录/注册/上传/下载分维度限流、反向代理真实 IP 开关
- 闭站维护开关、后台登录验证码、后台操作全部写入日志

## 6. 本机联调结论

在 PHP 8.3.35 + MariaDB 10.11.8（无 Redis，走 MySQL 降级）下跑通 99 项自动化检查，全部通过：
安装向导、首页与游客页面、后台 14 个页面、注册→验证邮件→登录、传统表单上传、秒传、分片断点续传（合并校验 SHA1）、
外链创建/浏览/下载/Range 206/密码校验/伪静态 /s/ /d/、文件夹与多选 ZIP、移动改名收藏标签复制、回收站还原、
SSRF 拦截、.php 上传拦截、越权访问拦截、后台检索与详情、SQL 备份导出、统计校准。
另用 Chrome DevTools 在真实浏览器中验证 upload.js：小文件走秒传+单请求、3MB 文件自动分片，均上传完成且无脚本错误。

**未在本机验证**（需要相应环境，逻辑已做兼容处理）：
- GD 图片缩略图与 PNG 验证码（本机 PHP 无 gd，程序自动退回 SVG 验证码、图片直出原图）
- ffmpeg 视频首帧截图（后台「系统工具」可测试）
- 真实 SMTP 发信（本机用「仅记录模式」验证了模板、变量替换与日志，发信链路需在服务器填写 SMTP 后用「测试发信」确认）
- phpredis 扩展模式（本机走内置 RESP 客户端与 MySQL 降级）

## 7. 运维

- 无需 crontab：有访问时最长每 30 分钟自动清理过期回收站、过期分片与旧日志（后台可关闭）
- 备份：后台「系统工具 → 下载 SQL」导出数据库，同时下载 `data/storage`、`data/thumbs`、`data/avatars`
- 换域名：改 `config/config.php` 的 `SITE_URL`（结尾带 /），旧外链短码继续可用
- 升级：覆盖代码后访问 `install/upgrade.php` 补表补字段（只 ADD 不 DROP），完成后删除 install 目录

## 8. 许可

本项目采用 **GNU General Public License v3.0（或更高版本）** 授权，详见 [LICENSE](LICENSE)。
Copyright (C) 2026 文谶 (fohok.xin)。

你可以自由地运行、研究、修改并再分发本软件；但任何分发修改版的行为都必须
在相同许可证下公开源代码并保留版权声明。在本仓库基础上闭源商用、去除署名或以更宽松许可证再授权均不符合 GPL-3.0 条款。

使用该代码搭建的站点，其用户上传的内容、数据库数据与服务器配置不属于本许可证的覆盖范围。
