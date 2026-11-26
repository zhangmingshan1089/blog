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

## 关于 GitHub Pages

Typecho 是 PHP + SQLite 的动态站，**GitHub Pages 只能托管静态文件，跑不了 PHP**。

所以本仓库的 Pages 是一个**静态镜像**：由 GitHub Actions 定时抓取线上站点生成，只读、供预览与备份用，不承担写入。动态功能（搜索、评论、云盘、音乐播放接口）在镜像上不可用。真正的站点仍跑在自己的服务器上。

## 许可

代码采用 [MIT](LICENSE)。`butterfly` 风格的版式参考了 [codeNoob2281/Typecho-Butterfly](https://github.com/codeNoob2281/Typecho-Butterfly)，样式为本项目自行实现；文中图片、背景与个人数据不在授权范围内。
