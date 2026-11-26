# mirror · 让静态镜像实时同步主站

GitHub Pages 只能托管静态文件，跑不了 PHP。但**浏览器可以跨域 fetch**，
于是把「渲染」拆成两半：

```
静态骨架（wget 每天抓一次）  +  实时数据（打开页面时向主站要）
```

| 文件 | 放哪 | 干什么 |
|---|---|---|
| `mirror-api.php` | 主站**根目录** | 只读 JSON 网关：文章、弹幕、音乐库、云盘 |
| `mirror-bridge.js` | 主站**根目录** | 在镜像页面上跑，把数据填回 DOM |

把 bridge 放在主站而不是仓库，是为了**改了立刻生效**——不用重建 Pages。

## 接口

```
/mirror-api.php?action=stats                 统计
/mirror-api.php?action=posts&limit=10        文章列表
/mirror-api.php?action=post&cid=12           单篇正文（HTML）
/mirror-api.php?action=music                 歌单 + 曲目（地址已绝对化）
/mirror-api.php?action=danmaku&since=0       弹幕
/mirror-api.php?action=drive                 已公开分享的云盘文件
/mirror-api.php?action=bootstrap             一次取回上面几种
```

全部返回 `{ok:true, ...}`，带 `Access-Control-Allow-Origin: *`，
缓存 60–120 秒，`X-Robots-Tag: noindex`（别让搜索引擎把 JSON 当页面收录）。

## 数据库自适配

不写死文件名和表前缀，按三步探测：

1. 从 `config.inc.php` 抠 `.db` 路径与 `new Db('Pdo_SQLite', 'xdy_')` 里的前缀
2. 抠不到就在 `usr/` 里找体积最大的 `.db`
3. 前缀还不对，就找 `sqlite_master` 里以 `options` 结尾的表反推

只读：连上先 `PRAGMA query_only = 1`。

## 镜像侧要配合的三件事

`tools/postprocess.py` 在构建时做：

1. 把 `player.js`、`/danmaku/` 的引用还原成主站绝对地址
   （wget `--convert-links` 会把它们改成相对路径，播放在镜像上就死了）
2. 每页注入 `<script src=".../mirror-bridge.js?v=<主站文件 md5>" defer>`
3. 动态入口（`/drive` `/panel` `/admin`）的链接改回主站

## 安全边界

- 网关只读，天然不能写
- 弹幕发送走主站 `/danmaku/`，沿用原有的 IP 限流（25s）与屏蔽词
- 云盘只返回**已生成分享直链**的文件；私人文件只报数量，不列名字、不给链接
- 不暴露 `config.inc.php`、数据库、后台路径

## 部署

```bash
# 主站根目录
/mirror-api.php
/mirror-bridge.js
```

主站若开了 CORS 白名单（不是 `*`），需要放行 Pages 域名
`https://<user>.github.io`。
