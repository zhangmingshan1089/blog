<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $fBase = rtrim((string)$this->options->siteUrl, '/'); ?>
    </div><!-- /.wrap -->
</main>

<footer class="site-footer">
    <div class="wrap footer-in">
        <div class="footer-main">
            <p class="footer-copy">
                &copy; <?php echo date('Y'); ?>
                <a href="<?php $this->options->siteUrl(); ?>"><?php $this->options->title(); ?></a>
                · <?php _e('由'); ?> <a href="https://typecho.org" rel="nofollow noopener" target="_blank">Typecho</a> <?php _e('驱动'); ?>
            </p>
            <p class="footer-note">
                <?php _e('除特别注明外，本站文字采用'); ?>
                <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/deed.zh" rel="license nofollow noopener" target="_blank">CC BY-NC-SA 4.0</a>
                <?php _e('授权，转载请注明出处。'); ?>
            </p>
        </div>

        <nav class="footer-links" aria-label="页脚导航">
            <a href="<?php echo htmlspecialchars($fBase, ENT_QUOTES); ?>/index.php/feed/" target="_blank" rel="noopener">RSS ↗</a>
            <a href="<?php echo htmlspecialchars($fBase, ENT_QUOTES); ?>/index.php/about.html"><?php _e('关于'); ?></a>
            <a href="<?php echo htmlspecialchars($fBase, ENT_QUOTES); ?>/timeline/"><?php _e('时间轴'); ?></a>
            <a href="<?php echo htmlspecialchars($fBase, ENT_QUOTES); ?>/sitemap.xml"><?php _e('站点地图'); ?></a>
            <?php if (!empty($this->options->icp)): ?>
            <a href="https://beian.miit.gov.cn" rel="nofollow noopener" target="_blank"><?php $this->options->icp(); ?></a>
            <?php endif; ?>
        </nav>

        <div class="footer-admin">
            <?php if ($this->user->hasLogin()): ?>
                <a href="<?php $this->options->adminUrl(); ?>"><?php _e('后台'); ?></a>
            <?php else: ?>
                <a href="<?php $this->options->adminUrl('login.php'); ?>" rel="nofollow"><?php _e('管理'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</footer>

<button class="to-top" id="toTop" type="button" aria-label="回到顶部" title="回到顶部">↑</button>
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="查看大图">
    <button class="lb-close" type="button" aria-label="关闭">×</button>
    <img src="" alt="">
</div>

<script>
(function(){
  var docEl = document.documentElement;

  /* ---------- 深浅色 ---------- */
  function syncMetaColor(){
    var s = docEl.getAttribute('data-style'), t = docEl.getAttribute('data-theme');
    var map = {'tech-light':'#f4f6f9','tech-dark':'#04060B','paper-light':'#FAF9F6','paper-dark':'#1B1B19','butterfly-light':'#f7f9fe','butterfly-dark':'#121212'};
    var c = map[s + '-' + t] || '#f4f6f9';
    var ms = document.querySelectorAll('meta[name="theme-color"]');
    for (var i = 0; i < ms.length; i++) ms[i].setAttribute('content', c);
  }
  var tBtn = document.getElementById('themeBtn');
  if (tBtn) {
    tBtn.addEventListener('click', function(){
      var next = docEl.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      docEl.setAttribute('data-theme', next);
      try { localStorage.setItem('wb-theme', next); } catch(e) {}
      syncMetaColor();
    });
  }

  /* ---------- 风格切换：全屏封面（弹幕） / 纸质档案 ---------- */
  var sBtn = document.getElementById('styleBtn');

  /* 弹幕只在「全屏封面」风格 + 首页才跑 */
  var dmLayer = document.getElementById('dmLayer');
  var DM = null;
  if (dmLayer) {
    DM = (function(layer){
      var API = '<?php echo htmlspecialchars($fBase, ENT_QUOTES); ?>/danmaku/';
      var seen = {}, lastT = 0, active = 0, started = false, timer = null;
      var MAX_ON = 12, LANES = [3.5, 8, 12.5, 17, 21.5, 26], laneFree = [];

      function pickLane(){
        var now = Date.now();
        for (var i = 0; i < LANES.length; i++) if (!laneFree[i] || laneFree[i] <= now) return i;
        return Math.floor(Math.random() * LANES.length);
      }
      function spawn(item){
        if (!item || !item.text || seen[item.id]) return;
        seen[item.id] = 1;
        if (active >= MAX_ON) return;
        var el = document.createElement('div');
        el.className = 'dm-item c' + (parseInt(item.c, 10) || 0);
        var q = '"' + String(item.text).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\r\n]/g, ' ') + '"';
        el.style.setProperty('--dm', q);
        el.setAttribute('aria-hidden', 'true');
        var dur = Math.max(8, Math.min(17, 7 + item.text.length * 0.3));
        var lane = pickLane();
        laneFree[lane] = Date.now() + dur * 1000 + 700;
        active++;
        layer.appendChild(el);
        /* 现代主题走 JS 抛物线弹道；拿不到（旧缓存/纸质风格）就退回 CSS 直线 */
        var MOT = window.WBTechMotion;
        if (!(MOT && MOT.fly && MOT.fly(el, { dur: dur, lane: LANES[lane], dir: (LANES[lane] < 10 ? -1 : (lane % 2 ? 1 : -1)) }))) {
          el.style.top = LANES[lane].toFixed(1) + '%';
          el.style.animationDuration = dur.toFixed(1) + 's';
          el.style.animationDelay = (Math.random() * 0.8).toFixed(2) + 's';
        }
        window.setTimeout(function(){
          if (el.parentNode) el.parentNode.removeChild(el);
          active--;
        }, (dur + 1.5) * 1000);
      }
      function load(){
        if (docEl.getAttribute('data-style') !== 'tech') return;
        fetch(API + '?since=' + lastT, { credentials: 'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(d){
            if (!d || !d.ok) return;
            (d.list || []).forEach(function(it, i){
              if (!seen[it.id]) window.setTimeout(function(){ spawn(it); }, i * 1100);
              if ((it.t || 0) > lastT) lastT = it.t;
            });
          })
          .catch(function(){});
      }
      /* 有人在场才开始拉数据：首屏先渲染封面与标题，弹幕不算进 LCP */
      function start(){
        if (started) return;
        started = true;
        ['pointermove','pointerdown','scroll','touchstart','keydown'].forEach(function(ev){
          window.addEventListener(ev, function(){
            if (docEl.getAttribute('data-style') === 'tech') load();
          }, { passive: true, once: true });
        });
        window.setTimeout(function(){ if (docEl.getAttribute('data-style') === 'tech') load(); }, 9000);
        timer = window.setInterval(load, 25000);
      }
      function kick(){
        layer.innerHTML = ''; seen = {}; lastT = 0; active = 0; laneFree = [];
        if (!started) start(); else load();
      }
      function stop(){
        layer.innerHTML = ''; active = 0; laneFree = [];
        if (timer) { window.clearInterval(timer); timer = null; }
        started = false;
      }
      var form = document.getElementById('dmForm');
      if (form) {
        form.addEventListener('submit', function(e){
          e.preventDefault();
          var input = document.getElementById('dmText');
          var btn = document.getElementById('dmSend');
          var text = (input.value || '').trim();
          if (!text) { input.focus(); return; }
          btn.disabled = true;
          var old = btn.textContent;
          btn.textContent = '…';
          var fd = new FormData();
          fd.append('act', 'send');
          fd.append('text', text);
          fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
              if (d && d.ok) { input.value = ''; spawn(d.item); }
              else { alert((d && d.msg) || '发送失败'); }
            })
            .catch(function(){ alert('网络异常，稍后再试'); })
            .then(function(){ btn.disabled = false; btn.textContent = old; });
        });
      }
      return { kick: kick, stop: stop, start: start };
    })(dmLayer);
  }

  if (sBtn) {
    sBtn.addEventListener('click', function(){
      /* 三套风格循环：全屏封面 → 纸质档案 → 蝴蝶卡片 → 回到封面 */
      var ORDER = ['tech','paper','butterfly'];
      var cur = docEl.getAttribute('data-style');
      var at  = ORDER.indexOf(cur);
      var next = ORDER[(at < 0 ? 0 : at + 1) % ORDER.length];
      docEl.setAttribute('data-style', next);
      try { localStorage.setItem('wb-style', next); } catch(e) {}
      syncMetaColor();
      if (next === 'tech') { if (DM) DM.kick(); }
      else if (DM) { DM.stop(); }
      document.dispatchEvent(new CustomEvent('wb:style', { detail: next }));
      if (next !== 'butterfly') window.scrollTo({ top: 0, behavior: 'auto' });
    });
  }
  syncMetaColor();
  if (docEl.getAttribute('data-style') === 'tech' && DM) DM.start();

  /* ---------- 蝴蝶风格的 banner 打字机 ---------- */
  (function(){
    var el = document.querySelector('.bf-banner-sub');
    if (!el) return;
    var span = el.querySelector('.bf-typing');
    var txt  = el.getAttribute('data-bf-text') || (span ? span.textContent : '');
    if (!span || !txt) return;

    var timer = null, done = false, shown = false;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function run(){
      if (shown || done) return;
      shown = true;
      clearTimeout(timer);
      var i = 0;
      span.textContent = '';
      (function step(){
        /* 打字途中被切走就停下，切回来再重来 */
        if (docEl.getAttribute('data-style') !== 'butterfly') { shown = false; return; }
        span.textContent = txt.slice(0, ++i);
        if (i < txt.length) timer = setTimeout(step, 66);
        else { done = true; shown = false; }
      })();
    }

    /* banner 真正可见（异步 CSS 到位）再打，避免字打完了才显示 */
    function whenVisible(cb, tries){
      tries = tries || 0;
      if (!el || el.offsetHeight > 0 || tries > 60) { cb(); return; }
      setTimeout(function(){ whenVisible(cb, tries + 1); }, 60);
    }

    if (reduce) { span.textContent = txt; done = true; }
    else if (docEl.getAttribute('data-style') === 'butterfly' && el.offsetHeight > 0) whenVisible(run);

    document.addEventListener('wb:style', function(e){
      if (e.detail !== 'butterfly' || reduce) return;
      done = false; shown = false;
      whenVisible(run);
    });
  })();

  /* ---------- 移动端：底部半屏抽屉 ---------- */
  var t = document.getElementById('navToggle'), n = document.getElementById('nav');
  var scrim = document.getElementById('navScrim');
  var lastFocus = null;

  function setNav(open){
    if (!n || !t) return;
    n.classList.toggle('open', open);
    t.setAttribute('aria-expanded', open ? 'true' : 'false');
    t.setAttribute('aria-label', open ? '收起菜单' : '展开菜单');
    document.body.classList.toggle('nav-open', open);
    if (scrim) { scrim.hidden = !open; scrim.classList.toggle('on', open); }
    if (open) { lastFocus = document.activeElement; var a = n.querySelector('a'); if (a) a.focus(); }
    else if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }
  if (t && n) {
    t.addEventListener('click', function(e){ e.stopPropagation(); setNav(!n.classList.contains('open')); });
    if (scrim) scrim.addEventListener('click', function(){ setNav(false); });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && n.classList.contains('open')) setNav(false);
    });
    /* 桌面尺寸下打开过抽屉，拉宽后要复位，避免残留 fixed 定位 */
    window.addEventListener('resize', function(){
      if (window.innerWidth >= 768 && n.classList.contains('open')) setNav(false);
    }, { passive: true });
  }

  /* ---------- 顶栏滚动态 ---------- */
  var topbar = document.getElementById('topbar');
  var toTop  = document.getElementById('toTop');
  var bar    = null;
  var article = document.querySelector('.post-content');
  if (article) {
    bar = document.createElement('div');
    bar.className = 'read-progress';
    document.body.appendChild(bar);
  }
  var ticking = false;
  function onScroll(){
    var y = window.pageYOffset || docEl.scrollTop || 0;
    if (topbar) topbar.classList.toggle('scrolled', y > 6);
    if (toTop) toTop.classList.toggle('on', y > 700 && !!article);
    if (bar && article) {
      var r = article.getBoundingClientRect();
      var total = article.offsetHeight - window.innerHeight + 160;
      var p = total > 0 ? Math.min(1, Math.max(0, (-r.top + 120) / total)) : 0;
      bar.style.width = (p * 100).toFixed(2) + '%';
    }
    ticking = false;
  }
  window.addEventListener('scroll', function(){
    if (!ticking) { ticking = true; window.requestAnimationFrame(onScroll); }
  }, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  onScroll();

  if (toTop) toTop.addEventListener('click', function(){
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });

  /* ---------- 目录高亮 ---------- */
  var tocLinks = document.querySelectorAll('.toc a[href^="#"]');
  if (tocLinks.length) {
    var heads = [];
    tocLinks.forEach(function(a){
      var el = document.getElementById(a.getAttribute('href').slice(1));
      if (el) heads.push({ el: el, a: a });
    });
    if (heads.length) {
      var io = new IntersectionObserver(function(entries){
        entries.forEach(function(en){
          if (!en.isIntersecting) return;
          tocLinks.forEach(function(a){ a.classList.remove('on'); });
          var hit = heads.filter(function(h){ return h.el === en.target; })[0];
          if (hit) hit.a.classList.add('on');
        });
      }, { rootMargin: '-80px 0px -70% 0px', threshold: 0 });
      heads.forEach(function(h){ io.observe(h.el); });
    }
  }

  /* ---------- 代码块：一键复制 ---------- */
  document.querySelectorAll('.post-content pre').forEach(function(pre){
    var code = pre.querySelector('code');
    if (!code) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'copy-code';
    btn.textContent = '复制';
    btn.addEventListener('click', function(){
      var txt = code.innerText;
      var done = function(){
        btn.textContent = '已复制'; btn.classList.add('done');
        setTimeout(function(){ btn.textContent = '复制'; btn.classList.remove('done'); }, 1600);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done, function(){ fallback(txt, done); });
      } else { fallback(txt, done); }
    });
    pre.appendChild(btn);
  });
  function fallback(txt, done){
    var ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.left = '-9999px';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch(e) {}
    document.body.removeChild(ta);
  }

  /* ---------- 图片点击放大 ---------- */
  var lb = document.getElementById('lightbox');
  if (lb) {
    var lbImg = lb.querySelector('img');
    var closeLb = function(){ lb.classList.remove('on'); document.body.style.overflow = ''; };
    document.querySelectorAll('.post-content img').forEach(function(img){
      if (img.closest('a')) return;
      img.addEventListener('click', function(){
        lbImg.src = img.currentSrc || img.src;
        lbImg.alt = img.alt || '';
        lb.classList.add('on');
        document.body.style.overflow = 'hidden';
      });
    });
    lb.addEventListener('click', closeLb);
    lb.querySelector('.lb-close').addEventListener('click', function(e){ e.stopPropagation(); closeLb(); });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && lb.classList.contains('on')) closeLb();
    });
  }

  /* ---------- 正文外链：新窗口 + ↗ ---------- */
  if (article) {
    article.querySelectorAll('a[href^="http"]').forEach(function(a){
      try {
        var u = new URL(a.href, location.href);
        if (u.hostname !== location.hostname) {
          a.classList.add('ext');
          a.target = '_blank';
          a.rel = 'noopener nofollow';
        }
      } catch(e) {}
    });
  }

  /* ---------- 站内预取：指针悬停 90ms 就悄悄取回下一页 ----------
     跨境一个来回 0.4s 起，预取能把「点击 → 出页」压到几乎为 0。
     只预取同域、无查询串的普通页面；省流模式关闭。 */
  (function () {
    if (document.documentElement.classList.contains('wb-lite')) return;
    var seen = {}, timer = null, pending = null;
    var ok = function (a) {
      if (!a || !a.href) return '';
      try {
        var u = new URL(a.href, location.href);
        if (u.origin !== location.origin) return '';
        if (u.search || u.hash) return '';
        if (/\/(admin|action|publish|drive|music|usr|api)\b/.test(u.pathname)) return '';
        if (/\.(xml|json|txt|zip|jpg|png|webp|mp3|mp4)$/i.test(u.pathname)) return '';
        if (a.target && a.target !== '_self') return '';
        return u.href;
      } catch (e) { return ''; }
    };
    var go = function (href) {
      if (!href || seen[href]) return;
      seen[href] = 1;
      var l = document.createElement('link');
      l.rel = 'prefetch'; l.as = 'document'; l.href = href;
      document.head.appendChild(l);
    };
    document.addEventListener('pointerover', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      var href = ok(a);
      if (!href || href === pending) return;
      pending = href;
      clearTimeout(timer);
      timer = setTimeout(function () { go(href); }, 90);
    }, { passive: true });
    document.addEventListener('pointerout', function () { clearTimeout(timer); pending = null; }, { passive: true });
    /* 触摸设备没有 hover，按下就取，通常还能赶上 */
    document.addEventListener('touchstart', function (e) {
      var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      go(ok(a));
    }, { passive: true });
  })();

  /* ---------- 首页下滑提示：只在首屏可见时显示 ---------- */
  var arw = document.querySelector('.hero-scroll');
  if (arw) {
    var hideArw = function(){
      var y = window.pageYOffset || 0;
      arw.style.opacity = Math.max(0, 1 - y / 380).toFixed(2);
      arw.style.pointerEvents = y > 340 ? 'none' : '';
    };
    window.addEventListener('scroll', hideArw, { passive: true });
    hideArw();
  }

})();
</script>
<?php /* ---------- 现代主题动效层：抛物轨迹 / 玻璃光场（纸质档案下自动空转） ---------- */ ?>
<script src="<?php $this->options->themeUrl('assets/tech.js'); ?>?v=3" defer></script>
<?php /* ---------- 全站音乐播放器（右下角，样式与脚本都在 /music/） ---------- */ ?>
<?php
/* 播放器样式内联：16KB 换一次往返，跨境这笔账划算；文件缺失就退回外链 */
$__wbp = dirname(dirname(dirname(__DIR__))) . '/music/player.css';
if (is_readable($__wbp)) {
    echo '<style id="wb-player-css">' . file_get_contents($__wbp) . '</style>';
} else {
    echo '<link rel="stylesheet" href="' . htmlspecialchars($fBase, ENT_QUOTES) . '/music/player.css?v=8">';
}
?>
<script src="<?php echo htmlspecialchars($fBase, ENT_QUOTES); ?>/music/player.js?v=9" defer></script>
<?php $this->footer(); ?>
</body>
</html>
<?php wb_cache_end(); ?>

