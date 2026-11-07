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

## 9. v1.1 迭代新增（28 项）

### 直链与分享（6）
1. **文件直链复制对话框**：文件列表/分享页一键打开，提供 分享页 / 直链 / `<img>` / `<video>` / `<audio>` / Markdown / BBCode / CSS 背景 / 缩略直链 / 视频封面 十种格式，逐行复制；图片视频优先展示。
2. **时效签名直链**：`?e=过期时间&s=HMAC签名`，可选 1h/1d/7d/30d；篡改 403、过期 410；后台可开启「直链强制签名」。
3. **图片参数直链**：`?w=&h=&q=&crop=1` 服务端缩放裁切并缓存（GD 可用时），缺 GD 自动回原图。
4. **视频封面直链**：`?poster=1` 输出封面 JPG，供 `<video poster>` 与 og:image 使用。
5. **批量直链导出**：勾选文件或整目录 → TXT / Markdown / HTML 列表 / 图片墙相册 / JSON（[export.php](export.php)）。
6. **分享页复制 + 幻灯查看**：游客无需登录即可复制直链；图片支持全屏幻灯片（左右键翻页、背景高斯模糊）。

### 安全加固（9）
7. **全站安全响应头**：CSP（off/basic/strict 三档）、X-Frame-Options、Referrer-Policy、Permissions-Policy、nosniff、隐藏 X-Powered-By、可选 HSTS(+preload)。
8. **CSRF 双轨**：表单一次性 token（30 分钟、用过即废）+ 会话级 token（供连续上传的 AJAX）；重放一次性 token 被拒。
9. **会话指纹绑定**：UA 家族+IP 段（loose）或完整 UA+IP（strict），突变强制下线并写日志。
10. **敏感操作密码重认证**：密钥轮换等需 15 分钟内验证过密码，否则弹出确认表单。
11. **上传深度校验**：`getimagesize` 真图校验、扩展名与真实类型一致性、SVG 脚本/事件/外部引用消毒、HTML 消毒、图片边长上限。
12. **data 目录暴露自检**：后台一键探测 `data/storage/...` 是否可被直接下载并给出结论。
13. **密钥一键轮换**：缩略图/头像签名密钥 + 直链签名密钥 + CSRF 重置，旧签名立即失效。
14. **登录失败指数退避可视化**：账号+IP 双维度计数，后台日志可查。
15. **后台 IP 白名单**：支持 IP/通配/CIDR；可选「已登录管理员豁免」防自锁。

### 防爬虫（6）
16. **反爬引擎**（[core/guard.php](core/guard.php)）：UA 规则库（高危工具/采集库）、空 UA、无 Accept、每分钟频率（页面/文件/接口三档）、5 分钟页面深度、连续请求间隔节奏。
17. **JS 挑战通行证**：可疑请求返回自动写 cookie 的验证页，纯脚本拿不到通行证；有效期可配。
18. **蜜罐链接**：页脚隐藏链接（`/nodelay.php`），爬虫跟进即封禁。
19. **蜘蛛白名单 + 反向 DNS**：放行真实 Google/Bing/Baidu 等；UA 声称蜘蛛但 DNS 校验失败按伪装封禁。
20. **封禁管理面板**（[admin/antiscan.php](admin/antiscan.php)）：近 7 天命中/封禁趋势、当前封禁列表、手动封禁/解封/转永久黑名单、IP 白名单。
21. **精简壳页模式**：对可疑 UA 可返回不含数据的壳页；命中全部写入 `wc_log(type=guard)`。

### UI 与动效（7）
22. **mdui 2 Material Design 3 深色皮肤**：top-app-bar / navigation-drawer / layout / card / list / menu / dropdown / dialog / snackbar / fab / bottom-navigation-bar；前后台统一；后台「界面与皮肤」可切默认皮肤，访客页脚/顶栏可自行切换（cookie 覆盖），经典 2005 皮肤保留。
23. **高斯模糊**：应用栏、抽屉、对话框面板、菜单、遮罩、幻灯片背景均 `backdrop-filter: blur()+saturate()`；滚动时应用栏模糊加深。
24. **骨架屏**：列表/表格加载占位 shimmer 动画，数据就绪淡出；懒加载图片占位；`prefers-reduced-motion` 自动关闭动效。
25. **MD3 主题色板**：深色/浅色/跟随系统三态，覆盖 mdui 全套 `--mdui-color-*` token。
26. **本地 SVG 图标系统**：40+ 图标以 CSS mask 内联，不依赖 Google Fonts / 外部 CDN；mdui 本体也已本地化到 `assets/vendor/`（可切 CDN）。
27. **全局快捷键**：`/` 搜索、`u` 上传、`g f` 文件、`g s` 分享、`c` 复制直链、`?` 帮助提示。
28. **拖拽移动 + 复制反馈**：文件行拖到文件夹即移动；所有复制/操作结果走 snackbar 提示，破坏性操作走 mdui 确认对话框。

### 新增/改动文件
`core/security.php`、`core/guard.php`、`export.php`、`nodelay.php`、`admin/antiscan.php`、`admin/security.php`、
`assets/mdui-dark.css`、`assets/js/ui.js`、`assets/vendor/mdui.css|mdui.global.js`（mdui 2.1.5, MIT）；
改动 `core/template.php`、`admin/common.php`、`core/bootstrap.php`、`core/functions.php`、`core/storage.php`、`core/thumb.php`、`d.php`、`api.php`、`files.php`、`s.php`、`admin/settings.php`、`install/defaults.php`。

### 验证
三套自动化共 **164 项断言全部通过**（基础流程 77 / 后台与 SEO 51 / 安全与防爬 36），
另用 Chrome DevTools 实测：深色外壳、应用栏 blur(18px)、对话框 blur(24px)、骨架屏淡出、
直链复制对话框 9 行格式、深浅主题切换、后台深色 KPI 卡片，页面零 JS 报错。

## 9. 常见问题

### 9.1 报 `Fatal error: Cannot redeclare go()` / `Cannot redeclare class KV`

这是**与宿主站点或其他 PHP 程序同名冲突**导致的，常见于把本程序放在已有网站的某个子目录里（例如 `example.com/pan/`），
而宿主站点通过 `.htaccess` 的 `auto_prepend_file`、公共 `functions.php` 或入口文件预先定义了 `go()`、`url()`、`e()`、`db()` 等通用函数；
也可能是同一份代码被两个不同路径 `include` 了两次。

当前版本已从三个层面根治，升级后不会再出现：

1. **全局函数与类全部加前缀**：本程序的函数统一为 `wc_*`（如 `wc_go()`、`wc_url()`、`wc_e()`），类统一为 `Wc*`（`WcKV`、`WcZipStream`、`WcRedisLite`），
   与宿主站点的同名函数互不影响；宿主即使先定义了 `go()`，本程序也只会调用自己的 `wc_go()`。
2. **每个 core 文件都有 include 守卫**（`WC_CORE_*` 常量），被重复 include 时直接 `return`，不会二次声明。
3. **`core/bootstrap.php` 有重入守卫**（`WC_BOOTED`），并且所有核心文件改用 `require_once`，不同路径引用也不会重复加载。

升级方式：用新包覆盖站点里的代码文件即可，**不要覆盖** `config/config.php` 与 `data/` 目录（zip 内也不包含它们）。

### 9.2 页面 500 / 白屏

查看 `data/logs/php_error.log` 与宝塔的 PHP 错误日志；常见原因是 `data` 目录不可写、PHP 缺少 `pdo_mysql`/`fileinfo`，或伪静态未生效。

### 9.3 收不到验证邮件

后台「邮件配置」先用「仅记录模式」确认模板与变量正常，再切 SMTP 并点「测试发信」；QQ/163 需要授权码而非登录密码，465 端口必须选 SSL。
