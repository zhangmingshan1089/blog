# MC Blog · wbclean

一个跑在 Typecho 上的个人博客，包含**三套可实时切换的界面风格**，以及一整套自己写的模块：云盘、音乐播放器、朋友圈、时间轴、首页弹幕、发布台、管理面板。

> 线上站点：<https://zhangmingshan.wwh.us.ci>
> 静态镜像：见仓库右侧的 Pages 链接

![桌面首屏](docs/screenshots/desktop.jpg)

## 三套界面，一个 DOM

切换按钮循环三套风格，深浅色各自独立记忆，存在 `localStorage` 里：

| 风格 | 特征 | 截图 |
|---|---|---|
| `tech` | 全屏封面 + 首页弹幕 + 深色玻璃顶栏 | 上图 |
| `paper` | 纸质档案：衬线标题、骑缝线、纸张纹理 | — |
| `butterfly` | 卡片流 + 300px 侧栏 + 2.9:1 封面（参考 [Typecho-Butterfly](https://github.com/codeNoob2281/Typecho-Butterfly) 的版式） | ![](docs/screenshots/butterfly.jpg) |

实现方式不是三套模板，而是**单 DOM + 属性驱动**：

```html
<html data-style="tech" data-theme="light">
```

`paper` 是基础样式，`tech` / `butterfly` 只写覆盖层，切换时只改两个属性，零刷新、零请求。

| 手机 390px | iPad 768px（Safari 内核） |
|---|---|
| ![](docs/screenshots/mobile.jpg) | ![](docs/screenshots/ipad.jpg) |

## 功能模块

| 模块 | 路径 | 说明 |
|---|---|---|
| 主题 | `theme/wbclean` | 三风格、双主题、页面缓存、OG/JSON-LD、sitemap |
| 云盘 | `modules/drive` | 单文件 PHP，密码哈希存 `.passwd`，支持限时分享直链 |
| 音乐 | `modules/music` | 歌单 + 上传 + 封面 + 单曲页 + m3u + 全站悬浮播放器 |
| 朋友圈 | `modules/moments` | 短动态流，独立于文章 |
| 时间轴 | `modules/timeline` | 按年月归档的时间轴视图 |
| 弹幕 | `modules/danmaku` | 首页封面上的滚动弹幕，PHP + JSON 存储 |
| 发布 | `modules/publish` | 不進后台直接写一条动态 |
| 面板 | `modules/panel` | 站点状态、访问统计、快捷入口 |
| 后台美化 | `modules/admin` | Typecho 后台的 CSS/JS 覆盖 |

### mirror · 静态镜像实时同步

把 GitHub Pages 上的镜像从「每天一张照片」变成「实时页面」：
主站提供只读 JSON 网关 `mirror-api.php`，镜像页面加载 `mirror-bridge.js`，
把文章列表、正文、弹幕、音乐库、云盘当场接回来。见 [`modules/mirror/`](modules/mirror/README.md)。

## 部署路径

仓库只放**主题与模块源码**，不含 Typecho 核心、数据库和上传文件。按下面的映射放进去即可：

```
theme/wbclean/       →  usr/themes/wbclean/
modules/drive/       →  drive/
modules/publish/     →  publish/
modules/moments/     →  moments/
modules/timeline/    →  timeline/
modules/danmaku/     →  danmaku/
modules/panel/       →  panel/
modules/music/       →  music/
modules/admin/       →  usr/themes/wbclean/assets/admin/（或按需引入后台）
modules/root/        →  站点根目录（403/404/robots/sitemap）
```

## 环境

- PHP 8.2+（用到 `password_hash`、`match`、联合类型）
- Typecho 1.3.0
- SQLite（`usr/*.db`，表前缀自定义）
- Nginx（需要一条伪静态，见 `docs/部署说明.md`）

## 本地跑起来

```bash
git clone <this-repo>
cp -r theme/wbclean  /path/to/typecho/usr/themes/
cp -r modules/music  /path/to/typecho/music/
php -S 127.0.0.1:8899 -t /path/to/typecho
```

首次访问 `music/` 会提示初始化歌单；`drive/` 会提示设置管理员密码。

## 性能取舍

- 首页 CSS **内联**进 HTML（跨境 RTT 贵，省一次阻塞请求），带 `?lite=1` 时可切回外链，体积 185KB → 99KB
- 首页背景用 `<picture>` + WebP，手机只下 76KB，不触发 304KB 的 JPG
- 静态资源 `max-age=43200`，改样式必须升 `$cssVer`
- 页面缓存落在 `usr/cache/wbpage/`，按 URL 哈希，发布/评论时自动失效
- 慢网/省流（`saveData`、2G/3G、`deviceMemory<=2`）自动关掉背景动画

## 部署与同步

### 三个角色

| 对象 | 角色 |
|---|---|
| 本仓库 | 源码。主题、模块、部署脚本，**不含**数据库、上传文件、任何密钥 |
| 主站 `zhangmingshan.wwh.us.ci` | 唯一数据源。PHP 8.2 + Typecho + SQLite，所有写入都在这里 |
| Pages `zhangmingshan1089.github.io/blog` | 静态外壳 + 实时数据。骨架是抓的，内容是当场从主站拉的 |

### 镜像为什么不是「死的照片」

GitHub Pages 跑不了 PHP，但浏览器可以跨域 `fetch`。所以主站多了一个只读网关
`/mirror-api.php`，镜像页面加载时由 `/mirror-bridge.js` 把数据接回来：

| 内容 | 镜像上的表现 | 实现 |
|---|---|---|
| 文章列表 / 正文 | 实时 | `?action=posts`、`?action=post&cid=` 覆盖 DOM |
| 弹幕 | 实时，可发送 | 主站 `/danmaku/` 已开 CORS，footer 原逻辑直接用 |
| 音乐库 | 实时 | `player.js` 由主站加载，`API` 基址自动指向主站，音频流直连 |
| 云盘 | 实时 | `?action=drive` 列出已公开分享的文件，下载走主站 |
| 朋友圈 / 时间轴 / 后台 | 跳主站 | 这些是完整 PHP 页面，镜像里没有等价物 |

网关只读、允许任意来源、60–120 秒缓存，不暴露密码、后台、私有文件。
`mirror-bridge.js` 放在**主站**而不是仓库里，所以改它不需要重建 Pages。
构建时按文件内容哈希带版本号（`?v=<md5>`），绕过 12 小时静态缓存。

任何一步失败都静默退回静态快照，页面不会白。

### 同步方向

| 方向 | 方式 | 频率 |
|---|---|---|
| 内容：主站 → 镜像 | 页面打开时实时拉取 | 即时 |
| 弹幕：镜像 → 主站 | 表单直发 `/danmaku/`（带 IP 限流） | 即时 |
| 骨架：主站 → 镜像 | Actions 抓静态快照重建 | 每天 05:00，也可手动 |
| 代码：GitHub → 服务器 | 手动拉取 / 上传 | 按需 |

手动重建一次骨架：

```bash
gh workflow run mirror.yml -R zhangmingshan1089/blog
```

**仍然做不到的**：在镜像上写文章、改主题、管理云盘。Pages 没有文件系统，
这些必须回主站。真正「两边都能写」需要把 PHP 后端搬到支持 PHP 的托管平台，
那时 GitHub 只当代码源，就不再是 Pages 了。

### 镜像构建做的事

`tools/postprocess.py`：

1. 剥掉 `?v=NN` —— wget 会把 query 塞进文件名，Pages 按扩展名给 MIME，`tech.js?v=3` 会被当成 `octet-stream`，浏览器拒绝执行
2. 后台、云盘等动态入口的链接指回主站
3. **反向还原**必须实时的资源：`player.js`、`/danmaku/` 指回主站绝对地址
4. 给每个页面注入 `<script src=".../mirror-bridge.js?v=<hash>" defer>`
5. 加 `.nojekyll`，避免 Jekyll 吃掉带下划线的目录

## 许可

代码采用 [MIT](LICENSE)。`butterfly` 风格的版式参考了 [codeNoob2281/Typecho-Butterfly](https://github.com/codeNoob2281/Typecho-Butterfly)，样式为本项目自行实现；文中图片、背景与个人数据不在授权范围内。
