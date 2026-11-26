/**
 * mirror-bridge.js · 让 GitHub Pages 静态镜像「活」起来
 * ------------------------------------------------------------------
 * 镜像上跑的是 wget 抓下来的 HTML 快照，没有 PHP、没有数据库。
 * 这个脚本在浏览器里补上那半边：
 *
 *   1. 文章列表 / 正文  ← mirror-api.php?action=posts|post
 *   2. 弹幕（读 + 发）  ← 拦截 /danmaku/ 请求转到主站（主站已开 CORS）
 *   3. 音乐库           ← 构建期已把 player.js 指回主站，音频直连主站
 *   4. 云盘             ← 列出主站已公开分享的文件，下载跳主站
 *   5. 动态入口         ← /drive /music /moments /timeline /panel /admin 全部改回主站
 *
 * 任何一步失败都静默退回静态快照，绝不让页面变白。
 * 部署位置：主站根目录 /mirror-bridge.js，镜像页面用绝对地址引用，
 * 所以改这个文件不需要重建 Pages。
 */
(function () {
  'use strict';

  var SITE = 'https://zhangmingshan.wwh.us.ci';
  var API = SITE + '/mirror-api.php';

  /* 只在镜像域名上干活；主站自己不需要这层 */
  var host = location.hostname;
  if (/(^|\.)zhangmingshan\.wwh\.us\.ci$/.test(host)) return;
  if (!/github\.io$|pages\.dev$|netlify\.app$|vercel\.app$|localhost$|127\.0\.0\.1$/.test(host)) return;

  /* ---------- 0. 先把 /danmaku/ 的请求改到主站 ----------
     footer.php 里的弹幕模块是在页面底部内联执行的，等不到 DOMContentLoaded。
     所以这里同步覆写 fetch，让它拿到的相对地址自动指回主站。 */
  var rawFetch = window.fetch;
  if (typeof rawFetch === 'function') {
    window.fetch = function (input, init) {
      try {
        var url = typeof input === 'string' ? input : (input && input.url) || '';
        if (url && !/^https?:|^\/\//i.test(url)) {
          /* wget --convert-links 把绝对地址改成了相对路径（'danmaku/'、'../danmaku/'），
             所以先按当前页面解析成绝对路径，再判断是不是这些动态模块 */
          var u = new URL(url, location.href);
          var p = u.pathname.replace(/^\/blog(?=\/|$)/, '');
          if (/^\/(danmaku|music|moments|timeline|drive)(\/|$)/i.test(p)) {
            input = SITE + p + u.search;
          }
        }
      } catch (e) {}
      return rawFetch.call(window, input, init);
    };
  }

  /* ---------- 小工具 ---------- */
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  function getJSON(url, timeout) {
    var ctl = typeof AbortController === 'function' ? new AbortController() : null;
    var t = setTimeout(function () { if (ctl) ctl.abort(); }, timeout || 6000);
    return rawFetch(url, { mode: 'cors', credentials: 'omit', signal: ctl ? ctl.signal : undefined })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { clearTimeout(t); return d; })
      .catch(function (e) { clearTimeout(t); throw e; });
  }
  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }
  function fmtSize(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB';
    return (n / 1073741824).toFixed(2) + ' GB';
  }

  /* ---------- 1. 动态入口改回主站 ---------- */
  var SITE_HOST = SITE.replace(/^https?:\/\//, '');
  var DYN = /^\/(drive|music|moments|timeline|danmaku|panel|admin|publish|action|install|feed|sitemap\.xml|index\.php\/action)/i;
  function rewriteLinks() {
    var as = document.querySelectorAll('a[href]');
    for (var i = 0; i < as.length; i++) {
      var a = as[i], href = a.getAttribute('href');
      if (!href || /^(#|mailto:|javascript:|data:)/i.test(href)) continue;
      if (a.getAttribute('data-wb-abs')) continue;
      var u;
      try { u = new URL(href, location.href); } catch (e) { continue; }
      if (u.hostname === SITE_HOST) continue;            // 已经是主站绝对地址
      if (u.origin !== location.origin) continue;        // 外部站点不动
      /* wget --convert-links 会把站内链接改成 ../../drive/ 这种相对路径，统一解析后判断 */
      var p = u.pathname.replace(/^\/blog(?=\/|$)/, '');
      if (DYN.test(p)) {
        a.setAttribute('href', SITE + p + u.search + u.hash);
        a.setAttribute('data-wb-abs', '1');
      }
    }
  }

  /* ---------- 2. 文章列表 ---------- */
  function postItem(p, idx, hideAfter) {
    var cover = p.cover
      ? '<span class="r-cover"><img src="' + esc(p.cover) + '" alt="" loading="lazy" decoding="async">' +
        (p.tags && p.tags[0] ? '<span class="r-cover-tag">' + esc(p.tags[0]) + '</span>' : '') + '</span>'
      : '<span class="r-cover gen" style="background-image:linear-gradient(135deg,hsl(' + p.hue + ',66%,58%),hsl(' + (p.hue + 12) + ',72%,46%))">' +
        '<span class="r-cover-ch">' + esc(p.ch) + '</span>' +
        (p.tags && p.tags[0] ? '<span class="r-cover-tag">' + esc(p.tags[0]) + '</span>' : '') + '</span>';

    var tags = (p.tags || []).map(function (t) { return '<span class="r-tag">' + esc(t) + '</span>'; }).join('');
    var cls = 'recent-row' + (hideAfter && idx >= 3 ? ' r-extra' : '');

    return '<li class="' + cls + '"><a href="' + esc(p.url) + '" data-wb-abs="1">' + cover +
      '<span class="r-date"><time datetime="' + esc(p.date) + '">' + esc(p.date) + '</time></span>' +
      '<span class="r-body"><span class="r-title">' + esc(p.title) + '</span>' +
      (p.brief ? '<span class="r-brief">' + esc(p.brief) + '</span>' : '') +
      '<span class="r-meta">' + tags +
      (tags ? '<i class="r-dot" aria-hidden="true"></i>' : '') +
      '<span>' + p.mins + ' 分钟读完</span></span></span></a></li>';
  }

  function syncList() {
    var list = document.querySelector('.recent-list');
    if (!list) return;
    var style = document.documentElement.getAttribute('data-style');
    var isPaper = style === 'paper';
    getJSON(API + '?action=posts&limit=12', 7000).then(function (d) {
      if (!d || !d.ok || !d.list || !d.list.length) return;
      var html = d.list.map(function (p, i) { return postItem(p, i, !isPaper); }).join('');
      list.innerHTML = html;
      list.setAttribute('data-live', '1');
      var more = document.querySelector('.sec-more');
      if (more && d.total) {
        more.textContent = '看全部 ' + d.total + ' 篇 →';
        more.setAttribute('href', SITE + '/index.php/archives.html');
      }
      rewriteLinks();
    }).catch(function () {});
  }

  /* ---------- 3. 文章正文 ---------- */
  function syncArticle() {
    var body = document.getElementById('articleBody');
    if (!body) return;
    var m = location.pathname.match(/\/archives\/(\d+)/);
    if (!m) return;
    var cid = m[1];
    getJSON(API + '?action=post&cid=' + cid, 8000).then(function (d) {
      if (!d || !d.ok || !d.html) return;
      body.innerHTML = d.html;
      body.setAttribute('data-live', '1');

      var h1 = document.querySelector('.article-head h1') || document.querySelector('article h1');
      if (h1 && d.title) h1.textContent = d.title;

      var t = document.querySelector('.article-meta time');
      if (t && d.date) {
        t.setAttribute('datetime', d.date);
        t.textContent = d.date;
      }
      /* 正文里的站内图片、附件补成主站绝对地址 */
      var imgs = body.querySelectorAll('img[src], a[href]');
      for (var i = 0; i < imgs.length; i++) {
        var el = imgs[i], v = el.getAttribute(el.tagName === 'IMG' ? 'src' : 'href');
        if (!v || /^(https?:|data:|#|mailto:)/i.test(v)) continue;
        el.setAttribute(el.tagName === 'IMG' ? 'src' : 'href', SITE + v.replace(/^\/blog/, ''));
      }
      rewriteLinks();
    }).catch(function () {});
  }

  /* ---------- 4. 云盘 ---------- */
  function driveBlock(files, totalAll) {
    var anchor = document.querySelector('.facts') || document.querySelector('footer') || document.body;
    var box = document.createElement('section');
    box.className = 'mirror-drive';
    box.setAttribute('data-live', '1');

    if (!files.length) {
      /* 云盘是私人的：没生成分享直链的文件不会出现在镜像上。
         这里只报个数量，把入口指回主站。 */
      box.innerHTML =
        '<h2>云盘<span>主站里有 ' + totalAll + ' 个文件，需登录访问</span></h2>' +
        '<p class="mirror-drive-tip">镜像站不缓存私人文件。查看或下载请到主站 → ' +
        '<a href="' + SITE + '/drive/" data-wb-abs="1">' + SITE.replace(/^https?:\/\//, '') + '/drive/</a></p>';
    } else {
      var rows = files.slice(0, 8).map(function (f) {
        return '<li><a href="' + esc(f.url) + '" data-wb-abs="1" target="_blank" rel="noopener">' +
          esc(f.name) + '</a><span>' + fmtSize(f.size) + '</span></li>';
      }).join('');
      box.innerHTML =
        '<h2>云盘 · 公开文件<span>来自主站，共 ' + files.length + ' 个</span></h2>' +
        '<ul>' + rows + '</ul>' +
        '<p class="mirror-drive-tip">下载与管理请到主站 → <a href="' + SITE + '/drive/" data-wb-abs="1">' +
        SITE.replace(/^https?:\/\//, '') + '/drive/</a></p>';
    }
    if (anchor.parentNode) anchor.parentNode.insertBefore(box, anchor);
  }

  function syncDrive() {
    if (!document.querySelector('.recent-list')) return;      // 只在首页出现
    getJSON(API + '?action=drive', 6000).then(function (d) {
      if (!d || !d.ok) return;
      if (d.list && d.list.length) {
        driveBlock(d.list, d.total);
        return;
      }
      /* 没有公开分享时，问一下总数，至少把「有几个文件」同步过来 */
      getJSON(API + '?action=stats', 5000).then(function (s) {
        if (s && s.ok && s.driveFiles > 0) driveBlock([], s.driveFiles);
      }).catch(function () {});
    }).catch(function () {});
  }

  /* ---------- 5. 状态徽标 ---------- */
  function injectStyle() {
    if (document.getElementById('wb-mirror-css')) return;
    var css = [
      '.mirror-drive{max-width:920px;margin:46px auto 0;padding:0 22px;box-sizing:border-box}',
      '.mirror-drive h2{font-size:15px;font-weight:600;letter-spacing:.3px;margin:0 0 12px;',
      '  display:flex;align-items:baseline;gap:10px;opacity:.92}',
      '.mirror-drive h2 span{font-size:11.5px;font-weight:400;opacity:.55;letter-spacing:0}',
      '.mirror-drive ul{list-style:none;margin:0;padding:0;display:grid;gap:1px;',
      '  background:rgba(127,140,160,.16);border-radius:10px;overflow:hidden}',
      '.mirror-drive li{display:flex;justify-content:space-between;gap:14px;align-items:center;',
      '  padding:11px 14px;background:rgba(255,255,255,.9);font-size:13.5px}',
      'html[data-theme="dark"] .mirror-drive li{background:rgba(18,24,34,.92)}',
      '.mirror-drive li a{text-decoration:none;color:inherit;overflow:hidden;',
      '  text-overflow:ellipsis;white-space:nowrap}',
      '.mirror-drive li a:hover{text-decoration:underline}',
      '.mirror-drive li span{font-size:11.5px;opacity:.5;flex:none;font-variant-numeric:tabular-nums}',
      '.mirror-drive-tip{margin:10px 2px 0;font-size:11.5px;opacity:.5}',
      '.mirror-drive-tip a{color:inherit}',
      '@media(max-width:640px){.mirror-drive{margin-top:34px;padding:0 16px}.mirror-drive li{font-size:12.5px}}'
    ].join('\n');
    var s = document.createElement('style');
    s.id = 'wb-mirror-css';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function badge(text, live) {
    var a = document.createElement('a');
    a.className = 'wb-mirror-badge';
    a.href = SITE + '/';
    a.rel = 'noopener';
    a.setAttribute('data-live', live ? '1' : '0');
    a.innerHTML = text;
    a.style.cssText = [
      'position:fixed', 'left:14px', 'bottom:14px', 'z-index:80',
      'display:inline-flex', 'align-items:center', 'gap:6px',
      'padding:6px 11px', 'border-radius:999px',
      'font:500 11.5px/1 -apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif',
      'text-decoration:none', 'letter-spacing:.2px',
      'background:rgba(12,18,28,.72)', 'color:#e8eef8',
      'border:1px solid rgba(255,255,255,.16)',
      'box-shadow:0 4px 14px rgba(0,0,0,.24)',
      'backdrop-filter:blur(8px)', '-webkit-backdrop-filter:blur(8px)',
      'opacity:.66', 'transition:opacity .2s'
    ].join(';');
    a.onmouseenter = function () { a.style.opacity = '1'; };
    a.onmouseleave = function () { a.style.opacity = '.66'; };
    document.body.appendChild(a);
    return a;
  }

  function stamp() {
    var d = new Date();
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /* ---------- 启动 ---------- */
  ready(function () {
    injectStyle();
    rewriteLinks();
    syncList();
    syncArticle();
    syncDrive();

    /* 徽标：先显示「镜像」，拿到数据后变成「已同步」 */
    var b = badge('◐ 静态镜像 · 内容实时取自主站', false);
    getJSON(API + '?action=stats', 5000).then(function (d) {
      if (!d || !d.ok) return;
      b.innerHTML = '● 已同步主站 · ' + d.posts + ' 篇 · ' + d.music + ' 首 · ' + d.danmaku + ' 条 · ' + stamp();
      b.setAttribute('data-live', '1');
    }).catch(function () {
      b.innerHTML = '○ 离线快照 · 主站暂时连不上';
    });

    /* 切风格后列表结构会变（paper 显示全部 / tech 只显示前 3），重绘一次 */
    var st = document.getElementById('styleBtn');
    if (st) st.addEventListener('click', function () { setTimeout(syncList, 120); });

    /* 每 90 秒对一次弹幕与文章数，够用又不吵 */
    setInterval(function () {
      getJSON(API + '?action=stats', 5000).then(function (d) {
        if (!d || !d.ok) return;
        b.innerHTML = '● 已同步主站 · ' + d.posts + ' 篇 · ' + d.music + ' 首 · ' + d.danmaku + ' 条 · ' + stamp();
        b.setAttribute('data-live', '1');
      }).catch(function () {});
    }, 90000);
  });
})();
