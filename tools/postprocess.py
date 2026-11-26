#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
镜像后处理：把 wget 抓下来的静态站修成「在 GitHub Pages 上能直接用」的样子。

wget 会把 query string 塞进文件名（`tech.js%3Fv=3`、`butterfly.css%3Fv=22.css`），
GitHub Pages 按扩展名给 Content-Type，没有 .js 的文件会被当成 octet-stream，
浏览器直接拒绝执行 —— 于是风格切换、弹幕、播放器全哑。

这里做三件事：
1. 文件名里的 `%3Fv=NN` 剥掉（`tech.js%3Fv=3` → `tech.js`）
2. HTML 里对应的引用同步剥掉
3. 动态模块（后台、云盘）的链接指回主站，别在镜像里点出 404
"""
import os
import re

OUT = 'public'
SITE = 'https://zhangmingshan.wwh.us.ci'
VER_RE = re.compile(r'%3Fv=\d+(\.\w+)?$')
VER_IN_HTML = re.compile(r'%3Fv=\d+(\.\w+)?')

renamed = 0
for root, _dirs, files in os.walk(OUT):
    for name in files:
        if '%3Fv=' not in name:
            continue
        new = VER_RE.sub('', name)
        if not new or new == name:
            continue
        src = os.path.join(root, name)
        dst = os.path.join(root, new)
        if os.path.exists(dst):
            os.remove(src)
        else:
            os.rename(src, dst)
            renamed += 1

DYNAMIC = [
    (r'href="(?:\.\./)*admin/login\.php[^"]*"', 'href="%s/admin/login.php"' % SITE),
    (r'href="(?:\.\./)*panel/[^"]*"', 'href="%s/panel/"' % SITE),
    (r'href="(?:\.\./)*drive/[^"]*"', 'href="%s/drive/"' % SITE),
    (r'href="(?:\.\./)*publish/[^"]*"', 'href="%s/publish/"' % SITE),
]

touched = 0
for root, _dirs, files in os.walk(OUT):
    for name in files:
        if not name.endswith('.html'):
            continue
        p = os.path.join(root, name)
        try:
            s = open(p, encoding='utf-8', errors='ignore').read()
        except OSError:
            continue
        s2 = VER_IN_HTML.sub('', s)
        for pat, rep in DYNAMIC:
            s2 = re.sub(pat, rep, s2)
        if s2 != s:
            open(p, 'w', encoding='utf-8').write(s2)
            touched += 1

print('renamed %d files, rewrote %d html' % (renamed, touched))
