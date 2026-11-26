/* =========================================================
   wbclean v17 · 现代主题动效层
   ---------------------------------------------------------
   只做三件事：曲线、玻璃、跟手。
   - 弹幕沿抛物线飞（y = y0 - 4a·p(1-p)，切线角用 atan 求）
   - 首屏元素沿抛物线落位（x 线性、y 随 x² 收敛）
   - 卡片随指针做小角度 3D 倾斜（线性映射 + 阻尼）
   全部只在 html[data-style="tech"] 下工作，纸质档案零改动。
   尊重 prefers-reduced-motion：命中就整个不启动。
   ========================================================= */
(function () {
  'use strict';

  var docEl = document.documentElement;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduce) return;

  var raf = window.requestAnimationFrame ? window.requestAnimationFrame.bind(window) : function (f) { return setTimeout(f, 16); };
  var now = function () { return (window.performance && performance.now) ? performance.now() : Date.now(); };
  var fine = window.matchMedia ? window.matchMedia('(hover:hover) and (pointer:fine)').matches : true;

  function isTech() { return docEl.getAttribute('data-style') === 'tech'; }
  function clamp(v, a, b) { return v < a ? a : (v > b ? b : v); }
  function qa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /* =======================================================
     一、弹幕抛物线弹道
     ======================================================= */
  var flights = [], fRaf = 0;

  function flightStep(t) {
    var keep = [], i, f, p;
    for (i = 0; i < flights.length; i++) {
      f = flights[i];
      p = (t - f.t0) / f.dur;
      if (p < 0) { keep.push(f); continue; }            // 还没起飞
      if (p >= 1) { f.el.style.opacity = '0'; continue; } // 到岸，交给原定时器回收
      var x = f.x0 - f.span * p;
      var y = f.y0 - f.amp * 4 * p * (1 - p);           // ← 抛物线：顶点在 p=0.5
      var slope = 4 * f.amp * (1 - 2 * p) / f.span;     // dy/dx，切线斜率
      var rot = Math.atan(slope) * 180 / Math.PI;       // 让文字顺着轨迹轻微侧倾
      if (rot > 10) rot = 10; else if (rot < -10) rot = -10;
      f.el.style.transform = 'translate3d(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px,0) rotate(' + rot.toFixed(2) + 'deg)';
      // 两端淡入淡出，避免文字凭空出现/消失
      var o = p < 0.08 ? p / 0.08 : (p > 0.9 ? (1 - p) / 0.1 : 1);
      f.el.style.opacity = o.toFixed(2);
      keep.push(f);
    }
    flights = keep;
    fRaf = flights.length ? raf(flightStep) : 0;
  }

  function fly(el, opt) {
    opt = opt || {};
    var layer = el && el.parentNode;
    if (!layer || !isTech()) return false;
    var W = layer.clientWidth || window.innerWidth;
    var H = layer.clientHeight || window.innerHeight;
    var tw = el.offsetWidth || 140;
    var lanePct = (typeof opt.lane === 'number') ? opt.lane : 20;
    var dir = opt.dir || (Math.random() < 0.5 ? 1 : -1);
    var amp = H * (0.045 + Math.random() * 0.05) * dir;   // 弧高：屏高的 4.5%~9.5%
    flights.push({
      el: el,
      t0: now() + (opt.delay || 0),
      dur: (opt.dur || 10) * 1000,
      x0: W + 16,
      span: W + tw + 48,
      y0: H * lanePct / 100,
      amp: amp
    });
    el.classList.add('fly');
    el.style.top = '0px';
    el.style.left = '0px';
    el.style.animation = 'none';
    el.style.opacity = '0';
    if (!fRaf) fRaf = raf(flightStep);
    return true;
  }

  /* =======================================================
     二、首屏：指针视差 + 光斑 + 抛物线入场
     ======================================================= */
  var hero = null, inner = null, hx = 0, hy = 0, tx = 0, ty = 0, hRaf = 0, enterToken = 0;

  function heroKick() { if (!hRaf && hero && isTech()) hRaf = raf(heroLoop); }

  function heroLoop() {
    hRaf = 0;
    if (!hero || !isTech()) return;
    hx += (tx * 34 - hx) * 0.12;      // 一阶惯性：每次逼近目标的 12%
    hy += (ty * 26 - hy) * 0.12;
    hero.style.setProperty('--hx', hx.toFixed(2) + 'px');
    hero.style.setProperty('--hy', hy.toFixed(2) + 'px');
    if (Math.abs(tx * 34 - hx) > 0.2 || Math.abs(ty * 26 - hy) > 0.2) heroKick();
  }

  function heroScroll() {
    if (!hero || !isTech()) return;
    var y = window.pageYOffset || 0;
    var h = hero.offsetHeight || 1;
    hero.style.setProperty('--hsy', (y * 0.18).toFixed(1) + 'px');
    if (inner) inner.style.opacity = clamp(1 - y / (h * 0.85), 0, 1).toFixed(2);
  }

  function heroEnter() {
    if (!hero || !inner || !isTech()) return;
    var tok = ++enterToken;
    var kids = qa('.ht-avatar,.ht-title,.ht-sub,.ht-cta', inner);
    var t0 = now(), D = 880;
    inner.style.opacity = '0';

    function step(t) {
      if (tok !== enterToken) return;                 // 被后来的入场顶掉
      var raw = clamp((t - t0) / D, 0, 1);
      inner.style.opacity = clamp(raw * 2.4, 0, 1).toFixed(3);
      for (var i = 0; i < kids.length; i++) {
        var d = i * 0.09;
        var p = clamp((raw - d) / (1 - d), 0, 1);
        if (p <= 0) { kids[i].style.opacity = '0'; continue; }
        var e = 1 - Math.pow(1 - p, 2);               // 二次缓出
        var x0 = (i % 2 ? 1 : -1) * (54 + i * 9);     // 左右交替起跳
        var y0 = -82 - i * 11;
        var dx = x0 * (1 - e);
        var dy = y0 * (1 - e) * (1 - e);              // y ∝ x²，空间上就是一条抛物线
        kids[i].style.translate = dx.toFixed(1) + 'px ' + dy.toFixed(1) + 'px';
        kids[i].style.opacity = clamp(p * 2.2, 0, 1).toFixed(2);
      }
      if (raw < 1) raf(step);
      else {
        for (var j = 0; j < kids.length; j++) { kids[j].style.translate = ''; kids[j].style.opacity = ''; }
        inner.style.opacity = '';
      }
    }
    raf(step);
  }

  function heroSetup() {
    hero = document.getElementById('heroTech');
    if (!hero) return;
    inner = hero.querySelector('.ht-inner');

    hero.addEventListener('pointermove', function (e) {
      if (!isTech()) return;
      var r = hero.getBoundingClientRect();
      tx = (e.clientX - r.left) / r.width - 0.5;
      ty = (e.clientY - r.top) / r.height - 0.5;
      hero.style.setProperty('--spx', (e.clientX - r.left).toFixed(0) + 'px');
      hero.style.setProperty('--spy', (e.clientY - r.top).toFixed(0) + 'px');
      if (!hero.classList.contains('ptr')) hero.classList.add('ptr');
      heroKick();
    }, { passive: true });

    hero.addEventListener('pointerleave', function () {
      tx = 0; ty = 0; hero.classList.remove('ptr'); heroKick();
    }, { passive: true });

    window.addEventListener('scroll', heroScroll, { passive: true });
    heroScroll();
  }

  /* 一条装饰抛物线 + 沿曲线滑行的小点（SMIL，不占主线程） */
  function arcInit() {
    if (!hero || hero.querySelector('.ht-arc')) return;
    var NS = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(NS, 'svg');
    svg.setAttribute('class', 'ht-arc');
    svg.setAttribute('viewBox', '0 0 1200 800');
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('aria-hidden', 'true');
    svg.style.opacity = '1';
    var d = 'M -40 700 Q 600 -90 1240 700';
    var path = document.createElementNS(NS, 'path');
    path.setAttribute('d', d);
    path.style.stroke = 'rgba(206,229,255,.20)';
    path.style.strokeWidth = '1.1';
    svg.appendChild(path);
    /* 三个点错开相位，形成一点残影 */
    [[0, 4, 1], [-2.4, 3, .45], [-4.8, 2, .2]].forEach(function (cfg) {
      var dot = document.createElementNS(NS, 'circle');
      dot.setAttribute('r', cfg[1]);
      dot.setAttribute('opacity', cfg[2]);
      var am = document.createElementNS(NS, 'animateMotion');
      am.setAttribute('dur', '8s');
      am.setAttribute('begin', cfg[0] + 's');
      am.setAttribute('repeatCount', 'indefinite');
      am.setAttribute('path', d);
      dot.appendChild(am);
      svg.appendChild(dot);
    });
    var dm = hero.querySelector('.dm-layer');
    if (dm) hero.insertBefore(svg, dm); else hero.appendChild(svg);
  }

  /* =======================================================
     三、卡片 3D 倾斜 + 镜面高光
     ======================================================= */
  function bindTilt() {
    if (!fine) return;
    qa('.hub-card, .card').forEach(function (card) {
      card.addEventListener('pointermove', function (e) {
        if (!isTech()) return;
        var r = card.getBoundingClientRect();
        var dx = (e.clientX - r.left) / r.width - 0.5;
        var dy = (e.clientY - r.top) / r.height - 0.5;
        if (card.classList.contains('hub-card')) {
          card.style.setProperty('--rx', (-dy * 8).toFixed(2) + 'deg');
          card.style.setProperty('--ry', (dx * 9).toFixed(2) + 'deg');
        }
        card.style.setProperty('--mx', ((dx + 0.5) * 100).toFixed(1) + '%');
        card.style.setProperty('--my', ((dy + 0.5) * 100).toFixed(1) + '%');
      }, { passive: true });
      card.addEventListener('pointerleave', function () {
        card.style.setProperty('--rx', '0deg');
        card.style.setProperty('--ry', '0deg');
      }, { passive: true });
    });
  }

  /* 磁吸按钮：位移 = 指针偏移 × 0.22，并夹在 ±9px 内 */
  function bindMagnetic() {
    if (!fine) return;
    qa('.ht-cta .btn').forEach(function (btn) {
      btn.addEventListener('pointermove', function (e) {
        if (!isTech()) return;
        var r = btn.getBoundingClientRect();
        var dx = e.clientX - (r.left + r.width / 2);
        var dy = e.clientY - (r.top + r.height / 2);
        btn.style.translate = clamp(dx * 0.22, -9, 9).toFixed(1) + 'px ' + clamp(dy * 0.3, -6, 6).toFixed(1) + 'px';
      }, { passive: true });
      btn.addEventListener('pointerleave', function () { btn.style.translate = ''; }, { passive: true });
    });
  }

  /* =======================================================
     四、滚动入场：整组沿抛物线依次落位
     ======================================================= */
  function revealInit() {
    if (!('IntersectionObserver' in window)) return;
    var groups = [
      ['.hub-card', 34, 40],
      ['.recent-row', 12, 34],
      ['.post-grid .card', 14, 30],
      ['.sec-head', 0, 22],
      ['.facts', 0, 18],
      ['.page-head', 0, 22],
      ['.article-head', 0, 20],
      ['.toc', 0, 16],
      ['.post-nav a', 10, 24],
      ['.related li', 8, 18],
      ['.comment-item', 8, 18]
    ];
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (en.isIntersecting) {
          en.target.classList.add('rv-in');
          io.unobserve(en.target);
        }
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });

    groups.forEach(function (g) {
      var els = qa(g[0]);
      var center = (els.length - 1) / 2;
      els.forEach(function (el, i) {
        var dx = clamp((i - center) * g[1], -42, 42);
        // 离中心越远，起点越低 —— 落下时形成一条抛物线前沿
        var dy = g[2] + Math.abs(dx) * 0.55;
        el.classList.add('rv');
        el.style.setProperty('--rvx', dx.toFixed(1) + 'px');
        el.style.setProperty('--rvy', dy.toFixed(1) + 'px');
        el.style.setProperty('--rvd', (Math.min(i, 6) * 70) + 'ms');
        io.observe(el);
      });
    });
  }

  /* =======================================================
     五、数字滚动（首页那行「共 N 篇」）
     ======================================================= */
  function countInit() {
    var els = qa('.facts b');
    if (!els.length || !('IntersectionObserver' in window)) return;
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        var el = en.target;
        var to = parseInt(el.textContent.replace(/[^\d]/g, ''), 10);
        if (isNaN(to) || to <= 0) return;
        var t0 = now(), D = 900;
        (function tick(t) {
          var p = clamp((t - t0) / D, 0, 1);
          var e = 1 - Math.pow(2, -10 * p);           // easeOutExpo
          el.textContent = Math.round(to * e);
          if (p < 1) raf(tick); else el.textContent = to;
        })(t0);
      });
    }, { threshold: 0.4 });
    els.forEach(function (el) { io.observe(el); });
  }

  /* =======================================================
     六、导航魔毯：一块玻璃片跟着指针滑
     ======================================================= */
  var pill = null, nav = null, activeLink = null;

  function movePill(a) {
    if (!pill) return;
    if (!isTech() || !a || window.innerWidth < 768) { pill.classList.remove('on'); return; }
    pill.style.width = a.offsetWidth + 'px';
    pill.style.left = a.offsetLeft + 'px';
    pill.classList.add('on');
  }

  function pillInit() {
    nav = document.getElementById('nav');
    if (!nav || nav.querySelector('.nav-pill')) return;
    pill = document.createElement('span');
    pill.className = 'nav-pill';
    pill.setAttribute('aria-hidden', 'true');
    nav.insertBefore(pill, nav.firstChild);
    activeLink = nav.querySelector('a.active');
    qa('a', nav).forEach(function (a) {
      a.addEventListener('pointerenter', function () { movePill(a); });
      a.addEventListener('focus', function () { movePill(a); });
    });
    nav.addEventListener('pointerleave', function () { movePill(activeLink); });
    movePill(activeLink);
  }

  /* =======================================================
     七、按钮涟漪 + 压光条
     ======================================================= */
  function bindRipple() {
    qa('.btn, .dm-form button, .to-top').forEach(function (btn) {
      if (btn.querySelector('.shine')) return;
      var s = document.createElement('span');
      s.className = 'shine';
      s.setAttribute('aria-hidden', 'true');
      btn.appendChild(s);
      btn.addEventListener('pointerdown', function (e) {
        if (!isTech()) return;
        var r = btn.getBoundingClientRect();
        var size = Math.max(r.width, r.height) * 2.4;
        var sp = document.createElement('span');
        sp.className = 'ripple';
        sp.style.width = sp.style.height = size + 'px';
        sp.style.left = (e.clientX - r.left || r.width / 2) + 'px';
        sp.style.top = (e.clientY - r.top || r.height / 2) + 'px';
        btn.appendChild(sp);
        setTimeout(function () { if (sp.parentNode) sp.parentNode.removeChild(sp); }, 700);
      }, { passive: true });
    });
  }

  /* =======================================================
     八、启动 / 风格切换
     ======================================================= */
  function resetInline() {
    qa('.hub-card').forEach(function (c) {
      c.style.setProperty('--rx', '0deg');
      c.style.setProperty('--ry', '0deg');
    });
    if (hero) { hero.style.setProperty('--hx', '0px'); hero.style.setProperty('--hy', '0px'); }
  }

  function boot() {
    heroSetup();
    arcInit();
    bindTilt();
    bindMagnetic();
    bindRipple();
    revealInit();
    countInit();
    pillInit();
    if (isTech()) heroEnter();
  }

  document.addEventListener('wb:style', function (e) {
    var next = e && e.detail;
    if (next === 'tech') {
      raf(function () { movePill(activeLink); heroEnter(); });
    } else {
      if (pill) pill.classList.remove('on');
      resetInline();
    }
  });

  window.addEventListener('resize', function () {
    flights = [];
    movePill(activeLink);
  }, { passive: true });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  /* 交给 footer 里的弹幕引擎调用 */
  window.WBTechMotion = { fly: fly, heroEnter: heroEnter };
})();
