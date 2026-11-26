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

### 仓库与站点是什么关系

| 对象 | 角色 |
|---|---|
| 本仓库 | 源码。主题、模块、部署脚本，**不含**数据库、上传文件、任何密钥 |
| 主站 `zhangmingshan.wwh.us.ci` | 唯一运行环境。PHP 8.2 + Typecho + SQLite，所有写入都在这里 |
| Pages `zhangmingshan1089.github.io/blog` | 只读静态快照，给人看、给搜索引擎看、当备份 |

### 同步方向

| 方向 | 方式 | 频率 |
|---|---|---|
| 内容：主站 → Pages | Actions 抓静态快照 | 每天 05:00，也可手动 |
| 代码：GitHub → 服务器 | 手动拉取 / 上传 | 按需 |
| 内容：Pages → 主站 | **不支持** | — |

手动同步一次：

```bash
gh workflow run mirror.yml -R zhangmingshan1089/blog
```

**为什么只有单向**：GitHub Pages 是纯静态托管，没有 PHP、没有 SQLite，写不进 Typecho。评论、搜索、云盘、音乐接口在镜像上都是死的。主站始终是唯一数据源，镜像只是它的照片。

想要"一发文章就同步"，可以在 Typecho 的发布钩子里用 `curl` 调 GitHub API 触发 `workflow_dispatch`，token 存在数据库里，不进仓库。

### 镜像会做什么

`tools/postprocess.py` 在三件事上兜底：

1. wget 会把 query 塞进文件名（`tech.js?v=3`），GitHub Pages 按扩展名给 MIME，没有 `.js` 的文件会被当成 `octet-stream`，浏览器拒绝执行 → 剥掉 `?v=NN`
2. 后台、云盘这些动态入口在镜像上必然 404 → 链接指回主站
3. 加 `.nojekyll`，避免 Jekyll 吃掉带下划线的目录

## 许可

代码采用 [MIT](LICENSE)。`butterfly` 风格的版式参考了 [codeNoob2281/Typecho-Butterfly](https://github.com/codeNoob2281/Typecho-Butterfly)，样式为本项目自行实现；文中图片、背景与个人数据不在授权范围内。
