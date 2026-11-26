/* =========================================================
   后台美化 · wb-admin.js
   在顶部导航里插「写一条 / 管理台 / 音乐库」入口
   ========================================================= */
(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState !== 'loading') fn();
    else document.addEventListener('DOMContentLoaded', fn);
  }

  ready(function () {
    var nav = document.querySelector('.typecho-head-nav .operate');
    if (!nav) return;

    if (!nav.querySelector('.wb-music-entry')) {
      var m = document.createElement('a');
      m.className = 'wb-music-entry';
      m.href = '/music/';
      m.title = '全站播放器的曲库';
      m.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/></svg>音乐库';
      nav.insertBefore(m, nav.firstChild);
    }

    if (!nav.querySelector('.wb-write-entry')) {
      var w = document.createElement('a');
      w.className = 'wb-write-entry';
      w.href = '/publish/';
      w.title = '写一条：文章与朋友圈同时发布';
      w.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19.2 8.8a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="m14.6 7.4 2 2"/></svg><span>写一条</span>';
      nav.insertBefore(w, nav.firstChild);
    }

    /* 撰写页（新建）：给一句引导，告诉站主还有更省事的「写一条」 */
    if (/write-post\.php/.test(location.pathname) && !/[?&]cid=/.test(location.search)) {
      var area = document.querySelector('.typecho-post-area');
      if (area && !document.querySelector('.wb-pub-tip')) {
        var tip = document.createElement('div');
        tip.className = 'wb-pub-tip';
        tip.innerHTML = '<span>想一次发到文章和朋友圈？用 <a href="/publish/">写一条</a>：长文自动成文章，照片和视频只留在朋友圈。在这里写也可以，发布后文章会自动出现在朋友圈。</span><a class="wb-pub-tip-x" href="#" aria-label="关闭">&times;</a>';
        area.parentNode.insertBefore(tip, area);
        tip.querySelector('.wb-pub-tip-x').addEventListener('click', function (e) {
          e.preventDefault();
          tip.remove();
        });
      }
    }

    if (!nav.querySelector('.wb-panel-entry')) {
      var a = document.createElement('a');
      a.className = 'wb-panel-entry';
      a.href = '/panel/';
      a.title = '服务器状态与快捷入口';
      a.innerHTML = '<span class="wb-dot"></span>管理台';
      /* 放在最左边，紧挨着用户名之前；没有用户名就插到第一个位置 */
      nav.insertBefore(a, nav.firstChild);
    }
  });
})();
