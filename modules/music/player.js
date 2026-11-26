/* =========================================================
   全站音乐播放器 · player.js
   依赖 /music/?api=list 与 /music/?get=<id>
   无依赖、无框架，约 9 KB
   ========================================================= */
(function () {
  'use strict';

  var script = document.currentScript;
  if (!script) {
    var ss = document.getElementsByTagName('script');
    for (var i = ss.length - 1; i >= 0; i--) {
      if (/\/player\.js(\?|$)/.test(ss[i].src)) { script = ss[i]; break; }
    }
  }
  if (!script || document.getElementById('muRoot')) return;
  var API = script.src.replace(/\/player\.js(\?.*)?$/, '');

  var LS = 'wb_music_v1';
  var TIP = 'wb_music_tip_v1';

  /* ---------------- 图标 ---------------- */
  var I = {
    note: '<svg class="ico-note" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/></svg>',
    pauseFab: '<svg class="ico-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="7.5" y="5" width="3.4" height="14" rx="1.2"/><rect x="13.1" y="5" width="3.4" height="14" rx="1.2"/></svg>',
    play: '<svg class="ico-play" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.5 5.4v13.2L19 12z"/></svg>',
    pause: '<svg class="ico-pause" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="7" y="5" width="3.6" height="14" rx="1.2"/><rect x="13.4" y="5" width="3.6" height="14" rx="1.2"/></svg>',
    prev: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M7 5.5h2.2v13H7zM19 5.8v12.4L9.6 12z"/></svg>',
    next: '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14.8 5.5H17v13h-2.2zM5 5.8v12.4L14.4 12z"/></svg>',
    loop: '<svg class="ico-loop" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3.5 20 6.5 17 9.5"/><path d="M20 6.5H8.5A4.5 4.5 0 0 0 4 11v.6"/><path d="M7 20.5 4 17.5 7 14.5"/><path d="M4 17.5h11.5A4.5 4.5 0 0 0 20 13v-.6"/></svg>',
    one: '<svg class="ico-one" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3.5 20 6.5 17 9.5"/><path d="M20 6.5H8.5A4.5 4.5 0 0 0 4 11v.6"/><path d="M7 20.5 4 17.5 7 14.5"/><path d="M4 17.5h11.5A4.5 4.5 0 0 0 20 13v-.6"/><path d="M11.4 10.6l1.4-.9v5"/></svg>',
    shuffle: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 4l3 3-3 3"/><path d="M17 14l3 3-3 3"/><path d="M20 7h-3.2c-1.6 0-3 .8-3.8 2.2L9.2 15c-.9 1.4-2.2 2.2-3.8 2.2H3"/><path d="M3 7h2.4c1.6 0 3 .8 3.8 2.1"/><path d="M20 17h-3.2c-1.6 0-3-.8-3.8-2.1"/></svg>',
    vol: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5.5 6.8 9H4v6h2.8L11 18.5z"/><path d="M15 9.2a3.8 3.8 0 0 1 0 5.6"/><path d="M17.6 6.6a7.6 7.6 0 0 1 0 10.8"/></svg>',
    mute: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5.5 6.8 9H4v6h2.8L11 18.5z"/><path d="M16 9.5l5 5M21 9.5l-5 5"/></svg>',
    close: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    chev: '<svg class="chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 14.5 12 8.5l6 6"/></svg>',
    ch: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.2 12.8a8 8 0 0 1 15.6 0"/><path d="M6.6 13v4.6M17.4 13v4.6"/><rect x="3.4" y="13.4" width="3.2" height="5.2" rx="1.4"/><rect x="17.4" y="13.4" width="3.2" height="5.2" rx="1.4"/></svg>',
    out: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 15v-3a8 8 0 0 1 16 0v3"/><path d="M4 15h2.6v5H5.2A1.2 1.2 0 0 1 4 18.8z"/><path d="M20 15h-2.6v5h1.4a1.2 1.2 0 0 0 1.2-1.2z"/></svg>',
    x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
    check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 12.5 9.5 17.5 19.5 6.5"/></svg>',
    bt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.5 7.5 15.5 16.5 12 20V4l3.5 3.5L8.5 16.5"/></svg>',
    noteBig: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/></svg>'
  };

  /* 封面统一走冷色（青 196° → 紫 292°），和站点的蓝色主色待在一起不打架 */
  function coverGrad(id) {
    var h = 196 + (hashStr(id) % 96);
    return 'linear-gradient(140deg,hsl(' + h + ',76%,63%),hsl(' + (h + 32) + ',66%,45%))';
  }

  /** 有自定义封面就用图，没有就回到同色系渐变 */
  function paintCover(t) {
    if (!elCover) return;
    var cv = t && t.cover ? String(t.cover) : '';
    if (cv) {
      elCover.classList.add('has-img');
      elCover.style.background = '';
      elCover.style.backgroundImage = 'url("' + cv.replace(/"/g, '%22') + '")';
    } else {
      elCover.classList.remove('has-img');
      elCover.style.backgroundImage = '';
      elCover.style.background = coverGrad(t ? t.id : 'x');
    }
  }

  /* ---------------- 状态 ---------------- */
  var tracks = [];
  var idx = -1;
  var mode = 'order';
  var vol = 0.8;
  var muted = false;
  var seeking = false;
  var loading = false;
  var tried = false;

  try {
    var st = JSON.parse(localStorage.getItem(LS) || '{}');
    if (st && typeof st === 'object') {
      if (typeof st.vol === 'number' && st.vol >= 0 && st.vol <= 1) vol = st.vol;
      if (typeof st.idx === 'number' && st.idx >= 0) idx = st.idx;
      if (st.mode === 'one' || st.mode === 'shuffle' || st.mode === 'order') mode = st.mode;
    }
  } catch (e) {}

  function persist() {
    try {
      localStorage.setItem(LS, JSON.stringify({ vol: vol, idx: idx, mode: mode }));
    } catch (e) {}
  }

  /* ---------------- DOM ---------------- */
  var root = document.createElement('div');
  root.className = 'mu';
  root.id = 'muRoot';
  root.innerHTML =
    '<div class="mu-scrim" data-close="1"></div>' +
    '<div class="mu-panel" role="dialog" aria-label="音乐播放器" aria-modal="false">' +
      '<div class="mu-head">' +
        '<div class="mu-cover" id="muCover">' + I.noteBig + '</div>' +
        '<div class="mu-info">' +
          '<p class="mu-title" id="muTitle">还没有曲子</p>' +
          '<p class="mu-artist" id="muArtist">去后台音乐库上传</p>' +
        '</div>' +
        '<button class="mu-head-btn" id="muClose" type="button" aria-label="关闭并停止播放" title="关闭并停止">' + I.close + '</button>' +
      '</div>' +
      '<div class="mu-seek-wrap">' +
        '<input class="mu-seek" id="muSeek" type="range" min="0" max="1000" value="0" step="1" aria-label="播放进度">' +
        '<div class="mu-time"><span id="muCur">0:00</span><span id="muDur">0:00</span></div>' +
      '</div>' +
      '<div class="mu-ctrl">' +
        '<div class="mu-ctrl-side">' +
          '<button class="mu-btn mu-mode" id="muMode" type="button" aria-label="循环方式" title="列表循环">' + I.loop + I.one + I.shuffle + '</button>' +
        '</div>' +
        '<div class="mu-ctrl-mid">' +
          '<button class="mu-btn" id="muPrev" type="button" aria-label="上一首" title="上一首">' + I.prev + '</button>' +
          '<button class="mu-play" id="muPlay" type="button" aria-label="播放" title="播放">' + I.play + I.pause + '</button>' +
          '<button class="mu-btn" id="muNext" type="button" aria-label="下一首" title="下一首">' + I.next + '</button>' +
        '</div>' +
        '<div class="mu-ctrl-side mu-ctrl-right">' +
          '<div class="mu-vol">' +
            '<button class="mu-vol-btn" id="muVolBtn" type="button" aria-label="静音" title="静音">' + I.vol + I.mute + '</button>' +
            '<input class="mu-vol-slider" id="muVol" type="range" min="0" max="100" value="80" aria-label="音量">' +
          '</div>' +
        '</div>' +
      '</div>' +
      '<div class="mu-tools">' +
        '<button class="mu-chip" id="muChBtn" type="button" aria-expanded="false" title="声道控制">' +
          I.ch + '<span id="muChLabel">立体声</span>' +
        '</button>' +
        '<button class="mu-chip" id="muOutBtn" type="button" aria-expanded="false" title="播放设备">' +
          I.out + '<span id="muOutLabel">本机</span>' +
        '</button>' +
        '<span class="mu-q" id="muQ"></span>' +
      '</div>' +
      '<p class="mu-note is-empty" id="muNote"></p>' +
      '<div class="mu-sheet" id="muChSheet">' +
        '<div class="mu-sheet-h">' +
          '<b>声道</b><span class="mu-sheet-s" id="muChHint">直通</span>' +
          '<button class="mu-x" type="button" data-fold="ch" aria-label="收起">' + I.x + '</button>' +
        '</div>' +
        '<div class="mu-seg" id="muChSeg" role="group" aria-label="声道模式">' +
          '<button type="button" data-ch="stereo" class="on">立体声</button>' +
          '<button type="button" data-ch="mono">单声道</button>' +
          '<button type="button" data-ch="left" title="只从左耳出声">仅左</button>' +
          '<button type="button" data-ch="right" title="只从右耳出声">仅右</button>' +
        '</div>' +
        '<div class="mu-meters" aria-hidden="true">' +
          '<span class="mu-mk">L</span>' +
          '<span class="mu-meter"><i id="muMeterL"></i></span>' +
          '<span class="mu-meter"><i id="muMeterR"></i></span>' +
          '<span class="mu-mk">R</span>' +
        '</div>' +
        '<label class="mu-bal">' +
          '<span>左</span>' +
          '<input id="muBal" type="range" min="-100" max="100" step="1" value="0" aria-label="左右平衡">' +
          '<span>右</span>' +
        '</label>' +
        '<p class="mu-sheet-f">只改这台设备听到的混音，不动文件本身；默认直通不做任何处理。</p>' +
      '</div>' +
      '<div class="mu-sheet" id="muOutSheet">' +
        '<div class="mu-sheet-h">' +
          '<b>播放设备</b><span class="mu-sheet-s" id="muOutState"></span>' +
          '<button class="mu-x" type="button" data-fold="out" aria-label="收起">' + I.x + '</button>' +
        '</div>' +
        '<div class="mu-dev" id="muDevList"></div>' +
        '<div class="mu-sheet-a">' +
          '<button type="button" id="muPickDev">选择设备</button>' +
          '<button type="button" id="muScanDev">重新扫描</button>' +
        '</div>' +
        '<p class="mu-sheet-f" id="muOutHint">蓝牙耳机连上后会出现在上面，播放中切换不打断。</p>' +
      '</div>' +
      '<div class="mu-list-wrap">' +
        '<button class="mu-list-toggle" id="muListBtn" type="button" aria-expanded="false">' +
          '<span id="muListName">播放列表</span> <b id="muCount">0</b>' + I.chev +
        '</button>' +
        '<ul class="mu-list" id="muList"></ul>' +
      '</div>' +
    '</div>' +
    '<button class="mu-fab" id="muFab" type="button" aria-label="打开音乐播放器" aria-expanded="false" title="听点东西">' +
      '<span class="mu-ring"></span>' + I.note + I.pauseFab +
    '</button>' +
    '<span class="mu-tip" id="muTip">点这里听点东西</span>';

  /* 单曲页已有 <audio id="songAudio">，直接复用它：
     页面内播放条和右下角播放器操作的是同一个音频，播放状态天然同步。
     其它页面才新建一个隐藏 audio。 */
  var audio = document.getElementById('songAudio') || new Audio();
  var reusedAudio = !!audio.parentNode;
  if (!audio.id) audio.id = 'muAudio';
  if (!reusedAudio) audio.preload = 'none';
  audio.volume = vol;

  function $(id) { return root.querySelector('#' + id); }
  var elFab = $('muFab'), elPanel = root.querySelector('.mu-panel'), elTitle = $('muTitle'),
      elArtist = $('muArtist'), elCover = $('muCover'), elSeek = $('muSeek'),
      elCur = $('muCur'), elDur = $('muDur'), elPlay = $('muPlay'), elMode = $('muMode'),
      elNote = $('muNote'), elList = $('muList'), elCount = $('muCount'),
      elVol = $('muVol'), elVolBtn = $('muVolBtn'), elTip = $('muTip'),
      elQ = $('muQ'), elChBtn = $('muChBtn'), elChLabel = $('muChLabel'),
      elOutBtn = $('muOutBtn'), elOutLabel = $('muOutLabel'),
      elChSheet = $('muChSheet'), elOutSheet = $('muOutSheet'),
      elChHint = $('muChHint'), elOutState = $('muOutState'),
      elChSeg = $('muChSeg'), elBal = $('muBal'),
      elMeterL = $('muMeterL'), elMeterR = $('muMeterR'),
      elDevList = $('muDevList'), elOutHint = $('muOutHint');

  /* ---------------- 工具 ---------------- */
  function fmt(s) {
    if (!isFinite(s) || s < 0) s = 0;
    s = Math.round(s);
    return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  }
  function hashStr(s) {
    var h = 0;
    for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
    return Math.abs(h);
  }
  function setP(p) {
    if (p < 0) p = 0;
    if (p > 1000) p = 1000;
    root.style.setProperty('--mu-p', p.toFixed(1));
    elSeek.value = String(p);
  }
  function current() { return idx >= 0 && idx < tracks.length ? tracks[idx] : null; }

  function mimeShort(m) {
    m = String(m || '').toLowerCase();
    if (m.indexOf('mpeg') >= 0) return 'MP3';
    if (m.indexOf('mp4') >= 0) return 'M4A';
    if (m.indexOf('ogg') >= 0) return 'OGG';
    if (m.indexOf('flac') >= 0) return 'FLAC';
    if (m.indexOf('wav') >= 0) return 'WAV';
    if (m.indexOf('aac') >= 0) return 'AAC';
    return '';
  }

  /** 音质短标签：AAC-LC · 44.1k · 立体声 / 长版带码率 */
  function qualityText(t, long) {
    if (!t) return '';
    var p = [];
    var codec = t.codec || mimeShort(t.mime);
    if (codec) p.push(codec);
    var sr = t.sr | 0;
    if (sr > 0) {
      var k = sr / 1000;
      p.push((k % 1 === 0 ? k : parseFloat(k.toFixed(2))) + 'k');
    }
    var ch = t.ch | 0;
    if (ch === 1) p.push('单声道');
    else if (ch >= 2) p.push(long ? ch + ' 声道' : '立体声');
    if (long && t.kbps > 0) p.push(t.kbps + ' kbps');
    if (long && t.lossless) p.push('无损');
    return p.join(' · ');
  }

  /* ---------------- 渲染 ---------------- */
  function paintTrack() {
    var t = current();
    if (!t) {
      elTitle.textContent = tracks.length ? '选一首吧' : '还没有曲子';
      elArtist.textContent = tracks.length ? '点下面的播放列表' : '去后台音乐库上传';
      elCover.classList.remove('has-img');
      elCover.style.backgroundImage = '';
      elCover.style.background = 'linear-gradient(140deg,#c7d2e4,#8fa3bf)';
      elNote.className = 'mu-note is-empty';
      elNote.textContent = '';
      elCur.textContent = '0:00';
      elDur.textContent = '0:00';
      return;
    }
    elTitle.textContent = t.title || '未命名';
    elTitle.title = t.title || '';
    elArtist.textContent = t.artist || (t.tags && t.tags.length ? t.tags.join(' · ') : '未知来源');
    paintCover(t);
    elQ.textContent = qualityText(t);
    elQ.title = qualityText(t, true);
    elNote.textContent = t.note || '';
    elNote.className = 'mu-note' + (t.note ? '' : ' is-empty') + (t.note && t.note.length > 46 ? ' mu-note-long' : '');
    elNote.classList.remove('is-open');
    elDur.textContent = t.dur > 0 ? fmt(t.dur) : '0:00';
    paintList();
  }

  function paintList() {
    if (!tracks.length) {
      elList.innerHTML = '<li class="mu-empty">列表是空的。<br>登录后在 <a href="' + API + '/" target="_blank" rel="noopener">音乐库</a> 上传。</li>';
      elCount.textContent = '0';
      return;
    }
    var html = '';
    for (var i = 0; i < tracks.length; i++) {
      var t = tracks[i];
      html += '<li class="mu-item' + (i === idx ? ' is-cur' : '') + '" data-i="' + i + '" role="button" tabindex="0">' +
        (t.cover
          ? '<span class="mu-item-c" style="background-image:url(' + esc(t.cover) + ')"></span>'
          : '<span class="mu-item-n">' + (i + 1) + '</span>') +
        '<span class="mu-eq" aria-hidden="true"><i></i><i></i><i></i></span>' +
        '<span class="mu-item-main">' +
          '<span class="mu-item-t">' + esc(t.title || '未命名') + '</span>' +
          (t.artist ? '<span class="mu-item-a">' + esc(t.artist) + '</span>' : '') +
        '</span>' +
        '<span class="mu-item-d">' + (t.dur > 0 ? fmt(t.dur) : '') + '</span>' +
      '</li>';
    }
    elList.innerHTML = html;
    elCount.textContent = String(tracks.length);
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------------- 播放 ---------------- */
  /** 把某一首装进 audio，但不播放（preload=none，不占首屏带宽） */
  function arm(i) {
    var t = tracks[i];
    if (!t) return false;
    if (String(audio.getAttribute('data-id')) === String(t.id)) return true;
    /* 单曲页 SSR 已把这首写进 src，别再重设，避免把播放位置打回 0 */
    var s = audio.getAttribute('src') || '';
    if (s && s.indexOf('get=' + encodeURIComponent(t.id)) !== -1) {
      audio.setAttribute('data-id', t.id);
      return true;
    }
    audio.setAttribute('data-id', t.id);
    audio.src = t.url;
    return true;
  }

  function load(i, autoplay) {
    if (!tracks.length) return;
    if (i < 0) i = 0;
    if (i >= tracks.length) i = 0;
    idx = i;
    arm(idx);
    paintTrack();
    persist();
    if (autoplay !== false) play();
  }

  function play() {
    if (!tracks.length) { fetchList(true); return; }
    if (idx < 0) idx = 0;
    /* 关键：列表只恢复了「选中」，音源要等到真要播时才装上 */
    if (!arm(idx)) return;
    // 上次设过声道/平衡的，在用户手势里把图画起来
    if (chMode !== 'stereo' || balance !== 0) applyCh();
    var p = audio.play();
    if (p && p.catch) {
      p.catch(function () {
        // 被浏览器拦下或源不可用
        root.classList.remove('is-playing');
        elPlay.setAttribute('aria-label', '播放');
      });
    }
  }

  function toggle() {
    if (audio.paused) play();
    else audio.pause();
  }

  function next(auto) {
    if (!tracks.length) return;
    if (mode === 'one' && auto !== 'manual') {
      audio.currentTime = 0;
      play();
      return;
    }
    if (mode === 'shuffle' && tracks.length > 1) {
      var n = idx;
      while (n === idx) n = Math.floor(Math.random() * tracks.length);
      load(n);
      return;
    }
    load((idx + 1) % tracks.length);
  }

  function prev() {
    if (!tracks.length) return;
    if (audio.currentTime > 3) { audio.currentTime = 0; return; }
    if (mode === 'shuffle' && tracks.length > 1) {
      var n = idx;
      while (n === idx) n = Math.floor(Math.random() * tracks.length);
      load(n);
      return;
    }
    load((idx - 1 + tracks.length) % tracks.length);
  }

  /* ---------------- 事件 ---------------- */
  audio.addEventListener('play', function () {
    /* 单曲页原生播放条触发的播放：把索引对齐到 src 里的这首，右下角才能显示同一首 */
    var m = /[?&]get=([^&]+)/.exec(audio.currentSrc || audio.src || '');
    if (m) {
      var sid = decodeURIComponent(m[1]);
      if (String(audio.getAttribute('data-id')) !== sid) {
        ensureList(function () {
          for (var k = 0; k < tracks.length; k++) {
            if (String(tracks[k].id) === sid) {
              idx = k;
              audio.setAttribute('data-id', sid);
              paintTrack();
              persist();
              break;
            }
          }
        });
      }
    }
    root.classList.add('is-playing');
    root.classList.remove('is-paused');
    elPlay.setAttribute('aria-label', '暂停');
    elPlay.title = '暂停';
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'playing';
    setMedia();
  });
  audio.addEventListener('pause', function () {
    root.classList.remove('is-playing');
    root.classList.add('is-paused');
    elPlay.setAttribute('aria-label', '播放');
    elPlay.title = '播放';
    if ('mediaSession' in navigator) navigator.mediaSession.playbackState = 'paused';
  });
  audio.addEventListener('loadedmetadata', function () {
    if (isFinite(audio.duration)) {
      elDur.textContent = fmt(audio.duration);
      var t = current();
      if (t && (!t.dur || Math.abs(t.dur - audio.duration) > 2)) {
        t.dur = Math.round(audio.duration * 10) / 10;
        paintList();
      }
    }
  });
  audio.addEventListener('timeupdate', function () {
    if (seeking) return;
    var d = audio.duration;
    if (isFinite(d) && d > 0) {
      setP(audio.currentTime / d * 1000);
      elCur.textContent = fmt(audio.currentTime);
    }
    syncPosition();
  });
  audio.addEventListener('ended', function () {
    if (mode === 'one') { audio.currentTime = 0; play(); return; }
    next(true);
  });
  audio.addEventListener('error', function () {
    if (!tracks.length) return;
    root.classList.remove('is-playing');
    elArtist.textContent = '这首暂时放不了，跳下一首';
    setTimeout(function () {
      if (tracks.length > 1) next(true);
      else paintTrack();
    }, 900);
  });
  audio.addEventListener('volumechange', function () {
    vol = audio.volume;
    muted = audio.muted || audio.volume === 0;
    elVol.value = String(Math.round((muted ? 0 : vol) * 100));
    elVolBtn.innerHTML = muted ? I.mute : I.vol;
    elVolBtn.setAttribute('aria-label', muted ? '取消静音' : '静音');
    persist();
  });

  elPlay.addEventListener('click', toggle);
  $('muNext').addEventListener('click', function () { next('manual'); });
  $('muPrev').addEventListener('click', prev);

  elSeek.addEventListener('input', function () {
    seeking = true;
    setP(parseFloat(elSeek.value));
    var d = audio.duration;
    if (isFinite(d) && d > 0) elCur.textContent = fmt(elSeek.value / 1000 * d);
  });
  elSeek.addEventListener('change', function () {
    var d = audio.duration;
    if (isFinite(d) && d > 0) audio.currentTime = elSeek.value / 1000 * d;
    seeking = false;
  });

  elMode.addEventListener('click', function () {
    mode = mode === 'order' ? 'one' : mode === 'one' ? 'shuffle' : 'order';
    applyMode();
    persist();
  });
  function applyMode() {
    elMode.classList.toggle('is-one', mode === 'one');
    elMode.classList.toggle('is-on', mode !== 'order');
    elMode.innerHTML = mode === 'shuffle' ? I.shuffle : I.loop + I.one;
    elMode.title = mode === 'order' ? '列表循环' : mode === 'one' ? '单曲循环' : '随机播放';
    elMode.setAttribute('aria-label', '循环方式：' + elMode.title);
    audio.loop = false;
  }
  applyMode();

  elVol.addEventListener('input', function () {
    var v = parseFloat(elVol.value) / 100;
    audio.muted = false;
    audio.volume = v;
  });
  elVolBtn.addEventListener('click', function () {
    if (audio.muted || audio.volume === 0) {
      audio.muted = false;
      if (audio.volume === 0) audio.volume = 0.7;
    } else {
      audio.muted = true;
    }
  });

  elNote.addEventListener('click', function () {
    if (!elNote.classList.contains('mu-note-long')) return;
    elNote.classList.toggle('is-open');
  });

  elList.addEventListener('click', function (e) {
    var li = e.target.closest ? e.target.closest('.mu-item') : null;
    if (!li) return;
    load(parseInt(li.getAttribute('data-i'), 10), true);
  });
  elList.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' && e.key !== ' ') return;
    var li = e.target.closest ? e.target.closest('.mu-item') : null;
    if (!li) return;
    e.preventDefault();
    e.stopPropagation();
    load(parseInt(li.getAttribute('data-i'), 10), true);
  });

  $('muListBtn').addEventListener('click', function () {
    var on = root.classList.toggle('is-list');
    this.setAttribute('aria-expanded', on ? 'true' : 'false');
  });

  /* 开关面板 */
  function open() {
    root.classList.add('is-open');
    if (window.innerWidth <= 760) document.body.classList.add('mu-lock');
    elFab.setAttribute('aria-expanded', 'true');
    elTip.classList.remove('on');
    // 聚焦面板本身而不是播放键：免得一打开就顶着一圈焦点环
    setTimeout(function () {
      elPanel.setAttribute('tabindex', '-1');
      elPanel.focus({ preventScroll: true });
    }, 260);
    if (!tried) { tried = true; fetchList(false); }
    // 每次展开都轻量对一次列表：后台刚传的歌 / 刚改的备注，下次打开就能看到
    else fetchList(false);
  }
  function close() {
    root.classList.remove('is-open');
    document.body.classList.remove('mu-lock');
    elFab.setAttribute('aria-expanded', 'false');
    elFab.focus({ preventScroll: true });
  }

  /* 关闭 = 立刻停声。
     之前只收面板，声音会跟着 CSS 过渡慢慢淡出去，像卡住一样；
     这里直接 pause，不做任何音量渐变。点空白 / Esc 仍只是收起，继续放。 */
  function stopNow() {
    try { audio.pause(); } catch (e) {}
    root.classList.remove('is-playing');
    root.classList.add('is-paused');
    elPlay.setAttribute('aria-label', '播放');
    elPlay.title = '播放';
  }
  elFab.addEventListener('click', function () {
    if (root.classList.contains('is-open')) close(); else open();
  });
  $('muClose').addEventListener('click', function () {
    stopNow();
    close();
  });
  root.querySelector('.mu-scrim').addEventListener('click', close);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && root.classList.contains('is-open')) close();
  });
  elPanel.addEventListener('keydown', function (e) {
    if (e.code !== 'Space' && e.key !== ' ') return;
    var tag = (e.target.tagName || '').toLowerCase();
    if (tag === 'input' || tag === 'button' || tag === 'a' || tag === 'textarea') return;
    e.preventDefault();
    toggle();
  });

  /* 桌面点外部收起
     用 pointerdown 而不是 click：点列表项会立刻重绘列表，
     click 冒泡到 document 时目标已经从 DOM 里摘掉，contains 判断会误判成「点在外面」。 */
  document.addEventListener('pointerdown', function (e) {
    if (window.innerWidth <= 760) return;
    if (!root.classList.contains('is-open')) return;
    if (e.target && root.contains(e.target)) return;
    close();
  }, true);

  /* =========================================================
     声道工作室 + 播放设备
     ---------------------------------------------------------
     默认直通：不碰音频数据，蓝牙/锁屏行为与原生一致。
     只有用户真的动了声道或平衡，才接管成 Web Audio：
       src → splitter → 4 个增益 → 左右合并 → 输出
     「仅左/仅右」= 单声道化到两边，避免听着一边没声还以为是坏文件。
     ========================================================= */
  var chMode = 'stereo', balance = 0, chOn = false, sinkId = '';
  var audioCtx = null, merger = null, outL = null, outR = null;
  var gLL = null, gRL = null, gLR = null, gRR = null, anL = null, anR = null;
  var meterRaf = 0, peakL = 0, peakR = 0, balTick = 0;
  var devList = [];
  var sinkOK = (typeof audio.setSinkId === 'function');

  try {
    var saved = JSON.parse(localStorage.getItem('wb_music_out_v1') || '{}');
    if (saved && typeof saved === 'object') {
      if (saved.ch === 'mono' || saved.ch === 'left' || saved.ch === 'right' || saved.ch === 'stereo') chMode = saved.ch;
      if (typeof saved.bal === 'number') balance = Math.max(-100, Math.min(100, saved.bal));
      if (typeof saved.sink === 'string') sinkId = saved.sink;
    }
  } catch (e) {}

  function persistOut() {
    try {
      localStorage.setItem('wb_music_out_v1', JSON.stringify({ ch: chMode, bal: balance, sink: sinkId }));
    } catch (e) {}
  }

  /** 建立 Web Audio 图，只在用户手势里调用 */
  function ensureGraph() {
    if (chOn) return true;
    var AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) { elChHint.textContent = '这台浏览器不支持声道控制'; return false; }
    try {
      if (!audioCtx) {
        audioCtx = new AC();
        var src = audioCtx.createMediaElementSource(audio);
        var split = audioCtx.createChannelSplitter(2);
        merger = audioCtx.createChannelMerger(2);
        gLL = audioCtx.createGain(); gRL = audioCtx.createGain();
        gLR = audioCtx.createGain(); gRR = audioCtx.createGain();
        outL = audioCtx.createGain(); outR = audioCtx.createGain();
        anL = audioCtx.createAnalyser(); anL.fftSize = 128;
        anR = audioCtx.createAnalyser(); anR.fftSize = 128;
        src.connect(split);
        split.connect(gLL, 0); split.connect(gRL, 1);
        split.connect(gLR, 0); split.connect(gRR, 1);
        gLL.connect(outL); gRL.connect(outL);
        gLR.connect(outR); gRR.connect(outR);
        outL.connect(merger, 0, 0); outR.connect(merger, 0, 1);
        merger.connect(audioCtx.destination);
        outL.connect(anL); outR.connect(anR);
        if (sinkId && typeof audioCtx.setSinkId === 'function') {
          audioCtx.setSinkId(sinkId).catch(function () {});
        }
      }
      if (audioCtx.state === 'suspended') audioCtx.resume().catch(function () {});
      chOn = true;
      return true;
    } catch (e) {
      elChHint.textContent = '接管音频失败，已保持直通';
      return false;
    }
  }

  function applyCh() {
    if (!ensureGraph()) return;
    var t = current();
    var srcMono = !!(t && (t.ch | 0) === 1);
    var ll, rl, lr, rr;
    if (srcMono) {
      // 源只有一条声道：单声道模式对半分，仅左/仅右就是把这条送到对应耳朵
      if (chMode === 'left') { ll = 1; lr = 0; }
      else if (chMode === 'right') { ll = 0; lr = 1; }
      else if (chMode === 'mono') { ll = .5; lr = .5; }
      else { ll = 1; lr = 1; }
      rl = 0; rr = 0;
    } else if (chMode === 'mono') { ll = .5; rl = .5; lr = .5; rr = .5; }
    else if (chMode === 'left') { ll = 1; rl = 0; lr = 0; rr = 0; }   // 只从左耳出
    else if (chMode === 'right') { ll = 0; rl = 0; lr = 0; rr = 1; }  // 只从右耳出
    else { ll = 1; rl = 0; lr = 0; rr = 1; }                          // 正常立体声
    var b = balance / 100;
    var gl = b <= 0 ? 1 : 1 - b;
    var gr = b >= 0 ? 1 : 1 + b;
    var now = audioCtx.currentTime;
    gLL.gain.setTargetAtTime(ll * gl, now, .03);
    gRL.gain.setTargetAtTime(rl * gl, now, .03);
    gLR.gain.setTargetAtTime(lr * gr, now, .03);
    gRR.gain.setTargetAtTime(rr * gr, now, .03);
    setChLabel();
    if (elChSheet.classList.contains('on')) startMeter();
  }

  function setChLabel() {
    var txt;
    if (!chOn) {
      // 还没接管时，标签说源文件本身是什么，别让人以为点了才变立体声
      var t = current();
      txt = (t && (t.ch | 0) === 1) ? '单声道' : '立体声';
    } else {
      txt = chMode === 'mono' ? '单声道' : chMode === 'left' ? '仅左'
          : chMode === 'right' ? '仅右' : '立体声';
    }
    elChLabel.textContent = txt;
    elChBtn.classList.toggle('on', chMode !== 'stereo' || balance !== 0);
    var btns = elChSeg.querySelectorAll('button');
    for (var i = 0; i < btns.length; i++) {
      btns[i].classList.toggle('on', btns[i].getAttribute('data-ch') === chMode);
    }
    elBal.value = String(balance);
    elChHint.textContent = chOn ? '实时处理' : '直通';
    var mt = elChSheet.querySelector('.mu-meters');
    if (mt) mt.classList.toggle('is-idle', !chOn);
  }

  function rms(buf) {
    var s = 0;
    for (var i = 0; i < buf.length; i++) {
      var v = (buf[i] - 128) / 128;
      s += v * v;
    }
    return Math.sqrt(s / buf.length);
  }

  function startMeter() {
    if (!anL || meterRaf) return;
    var bl = new Uint8Array(anL.fftSize), br = new Uint8Array(anR.fftSize);
    var last = 0;
    (function tick(ts) {
      meterRaf = requestAnimationFrame(tick);
      if (ts - last < 55) return;
      last = ts;
      anL.getByteTimeDomainData(bl);
      anR.getByteTimeDomainData(br);
      peakL = Math.max(rms(bl), peakL * .84);
      peakR = Math.max(rms(br), peakR * .84);
      elMeterL.style.transform = 'scaleY(' + Math.min(1, peakL * 4.8).toFixed(3) + ')';
      elMeterR.style.transform = 'scaleY(' + Math.min(1, peakR * 4.8).toFixed(3) + ')';
    })(0);
  }

  function stopMeter() {
    if (meterRaf) { cancelAnimationFrame(meterRaf); meterRaf = 0; }
    peakL = peakR = 0;
    if (elMeterL) elMeterL.style.transform = 'scaleY(0)';
    if (elMeterR) elMeterR.style.transform = 'scaleY(0)';
  }

  /* ---------------- 设备 ---------------- */
  function btName(label) {
    return /bluetooth|蓝牙|airpods|buds|wh-|wf-|freebuds|soundcore|bose|jbl|beats|enco|qcy|edifier|漫步者|sony|galaxy/i.test(label || '');
  }

  function shortDev(s) {
    s = String(s || '本机').replace(/\s*[\(（].*?[\)）]\s*/g, ' ').replace(/\s{2,}/g, ' ').trim();
    return s.length > 11 ? s.slice(0, 11) + '…' : s;
  }

  function loadDevices() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
      elOutHint.textContent = '这台浏览器拿不到设备列表，声音会跟着系统默认走。';
      paintDevices();
      return;
    }
    navigator.mediaDevices.enumerateDevices().then(function (list) {
      devList = [];
      for (var i = 0; i < list.length; i++) {
        if (list[i].kind === 'audiooutput') devList.push(list[i]);
      }
      paintDevices();
    }).catch(function () {
      elOutHint.textContent = '读取设备列表被拒绝，点「选择设备」授权一次再试。';
    });
  }

  function paintDevices() {
    var cur = sinkId || 'default';
    var html = '';
    if (!devList.length) {
      html = '<p class="mu-dev-empty">还没有可切换的设备。<br>点下面的「选择设备」授权一次，蓝牙耳机就会出现在这里。</p>';
    } else {
      for (var i = 0; i < devList.length; i++) {
        var d = devList[i];
        var id = d.deviceId || 'default';
        var label = d.label || ('音频输出 ' + (i + 1));
        var bt = btName(d.label);
        html += '<button class="mu-dev-i' + (id === cur ? ' on' : '') + '" type="button" data-dev="' + esc(id) + '">' +
          '<span class="mu-dev-dot"></span>' +
          '<span class="mu-dev-n">' + esc(label) + (bt ? '<em>蓝牙</em>' : '') + '</span>' +
          (id === cur ? '<span class="mu-dev-c">' + I.check + '</span>' : '') +
        '</button>';
      }
    }
    elDevList.innerHTML = html;

    var label = '本机';
    for (var k = 0; k < devList.length; k++) {
      if ((devList[k].deviceId || 'default') === cur) {
        label = devList[k].label || '默认设备';
        break;
      }
    }
    elOutLabel.textContent = shortDev(label);
    elOutLabel.title = label;
    elOutState.textContent = sinkOK ? '' : '不支持切换';
    if (!sinkOK) {
      elOutHint.textContent = '这台浏览器不支持指定输出，声音跟着系统走——连上蓝牙一般会自动切过去。';
    } else if (btName(label)) {
      elOutHint.textContent = '正在走蓝牙，播放/暂停可以直接用耳机上的按键。';
    } else {
      elOutHint.textContent = '蓝牙耳机连上后会出现在上面，播放中切换不打断。';
    }
  }

  function setSink(id, label) {
    var want = (!id || id === 'default') ? '' : id;
    if (!sinkOK && !(audioCtx && typeof audioCtx.setSinkId === 'function')) {
      elOutHint.textContent = '这台浏览器不支持切换输出设备。';
      return;
    }
    sinkId = want;
    persistOut();
    var fail = false;
    var jobs = [];
    if (sinkOK) {
      jobs.push(audio.setSinkId(want || 'default').catch(function () { fail = true; }));
    }
    if (audioCtx && typeof audioCtx.setSinkId === 'function') {
      jobs.push(audioCtx.setSinkId(want || 'default').catch(function () {}));
    }
    Promise.all(jobs).then(function () {
      if (fail) {
        elOutHint.textContent = '切换失败：可能需要在系统里允许浏览器使用这个设备。';
        return;
      }
      paintDevices();
    });
  }

  /* ---------------- 交互 ---------------- */
  function foldCh(on) {
    elChSheet.classList.toggle('on', on);
    elChBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) {
      setChLabel();
      if (chOn) startMeter();
    } else stopMeter();
  }

  function foldOut(on) {
    elOutSheet.classList.toggle('on', on);
    elOutBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) loadDevices();
  }

  elChBtn.addEventListener('click', function () {
    var on = !elChSheet.classList.contains('on');
    if (on) foldOut(false);
    foldCh(on);
  });
  elOutBtn.addEventListener('click', function () {
    var on = !elOutSheet.classList.contains('on');
    if (on) foldCh(false);
    foldOut(on);
  });
  var xBtns = root.querySelectorAll('[data-fold]');
  for (var xi = 0; xi < xBtns.length; xi++) {
    xBtns[xi].addEventListener('click', function () {
      if (this.getAttribute('data-fold') === 'ch') foldCh(false);
      else foldOut(false);
    });
  }

  elChSeg.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('button[data-ch]') : null;
    if (!b) return;
    chMode = b.getAttribute('data-ch');
    persistOut();
    applyCh();
  });

  elBal.addEventListener('input', function () {
    balance = parseInt(elBal.value, 10) || 0;
    if (balTick) return;
    balTick = requestAnimationFrame(function () {
      balTick = 0;
      applyCh();
      elChHint.textContent = balance === 0 ? (chOn ? '实时处理' : '直通') : '偏' + (balance < 0 ? '左 ' + (-balance) : '右 ' + balance) + '%';
    });
  });
  elBal.addEventListener('dblclick', function () {
    balance = 0;
    elBal.value = '0';
    persistOut();
    applyCh();
  });

  elDevList.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-dev]') : null;
    if (!b) return;
    setSink(b.getAttribute('data-dev'), '');
  });

  $('muScanDev').addEventListener('click', loadDevices);
  $('muPickDev').addEventListener('click', function () {
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.selectAudioOutput !== 'function') {
      elOutHint.textContent = '这台浏览器不支持弹窗选择，直接点上面的设备就行。';
      return;
    }
    navigator.mediaDevices.selectAudioOutput().then(function (d) {
      setSink(d.deviceId, d.label);
      loadDevices();
    }).catch(function () {});
  });

  if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
    navigator.mediaDevices.addEventListener('devicechange', function () {
      if (elOutSheet.classList.contains('on')) loadDevices();
    });
  }

  setChLabel();
  paintDevices();

  /* ---------------- 媒体会话（手机锁屏 / 蓝牙按键） ---------------- */

  /** 用 canvas 画一张渐变封面，给锁屏和车机用 */
  function coverArt(t) {
    try {
      var cv = document.createElement('canvas');
      cv.width = 512; cv.height = 512;
      var c2 = cv.getContext('2d');
      var h = 196 + (hashStr(t.id) % 96);
      var grd = c2.createLinearGradient(0, 0, 512, 512);
      grd.addColorStop(0, 'hsl(' + h + ',76%,63%)');
      grd.addColorStop(1, 'hsl(' + ((h + 32) % 360) + ',66%,45%)');
      c2.fillStyle = grd;
      c2.fillRect(0, 0, 512, 512);
      c2.fillStyle = 'rgba(255,255,255,.9)';
      c2.font = '600 190px "Songti SC",Georgia,serif';
      c2.textAlign = 'center';
      c2.textBaseline = 'middle';
      c2.fillText('♪', 256, 268);
      return cv.toDataURL('image/png');
    } catch (e) {
      return '';
    }
  }

  var posTick = 0;

  function syncPosition() {
    if (!('mediaSession' in navigator) || !navigator.mediaSession.setPositionState) return;
    var d = audio.duration;
    if (!isFinite(d) || d <= 0) return;
    var now = Date.now();
    if (now - posTick < 900) return;
    posTick = now;
    try {
      navigator.mediaSession.setPositionState({
        duration: d,
        playbackRate: audio.playbackRate || 1,
        position: Math.min(d, Math.max(0, audio.currentTime))
      });
    } catch (e) {}
  }

  function setMedia() {
    if (!('mediaSession' in navigator)) return;
    var t = current();
    if (!t) return;
    try {
      var art = t.cover ? t.cover : coverArt(t);
      navigator.mediaSession.metadata = new window.MediaMetadata({
        title: t.title || '未命名',
        artist: t.artist || 'MC.跃小叮当的博客',
        album: qualityText(t) || '站点音乐',
        artwork: art ? (t.cover ? [{ src: art }] : [
          { src: art, sizes: '512x512', type: 'image/png' }
        ]) : []
      });
      navigator.mediaSession.setActionHandler('play', play);
      navigator.mediaSession.setActionHandler('pause', function () { audio.pause(); });
      navigator.mediaSession.setActionHandler('previoustrack', prev);
      navigator.mediaSession.setActionHandler('nexttrack', function () { next('manual'); });
      navigator.mediaSession.setActionHandler('seekto', function (d) {
        if (d && typeof d.seekTime === 'number' && isFinite(d.seekTime)) {
          audio.currentTime = Math.max(0, Math.min(audio.duration || 0, d.seekTime));
        }
      });
      navigator.mediaSession.setActionHandler('seekbackward', function (d) {
        var off = (d && d.seekOffset) || 10;
        audio.currentTime = Math.max(0, audio.currentTime - off);
      });
      navigator.mediaSession.setActionHandler('seekforward', function (d) {
        var off = (d && d.seekOffset) || 10;
        audio.currentTime = Math.min(audio.duration || 0, audio.currentTime + off);
      });
      navigator.mediaSession.setActionHandler('stop', function () { audio.pause(); });
      syncPosition();
    } catch (e) {}
  }


  /* ---------------- 拉列表 ---------------- */
  var lastFetch = 0;

  function fetchList(autoplay) {
    if (loading) return;
    loading = true;
    fetch(API + '/?api=list', { credentials: 'omit' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        loading = false;
        lastFetch = Date.now();
        if (!j || !j.ok || !j.tracks || !j.tracks.length) {
          tracks = [];
          paintTrack();
          paintList();
          return;
        }
        var keepId = current() ? String(current().id) : '';
        tracks = j.tracks;
        if (j.playlist && j.playlist.title) {
          var ln = $('muListName');
          if (ln) ln.textContent = j.playlist.title;
          elPanel.setAttribute('aria-label', j.playlist.title + ' · 音乐播放器');
        }
        // 后台可能刚改过顺序或删过歌：按 id 找回当前这首，别让选中跳到别人身上
        var found = -1;
        if (keepId) {
          for (var k = 0; k < tracks.length; k++) {
            if (String(tracks[k].id) === keepId) { found = k; break; }
          }
        }
        if (found >= 0) {
          idx = found;
        } else if (keepId) {
          // 正在听的这首被删了：停下来，别继续放一个不存在的地址
          try { audio.pause(); } catch (e) {}
          idx = 0;
        } else if (!(idx >= 0 && idx < tracks.length)) {
          idx = 0;
        }
        paintTrack();
        if (autoplay) play();
        maybeTip();
      })
      .catch(function () {
        loading = false;
      });
  }

  function maybeTip() {
    try {
      if (localStorage.getItem(TIP)) return;
      localStorage.setItem(TIP, '1');
    } catch (e) {}
    if (!tracks.length) return;
    setTimeout(function () {
      if (root.classList.contains('is-open')) return;
      elTip.classList.add('on');
      setTimeout(function () { elTip.classList.remove('on'); }, 5200);
    }, 2600);
  }

  /* ---------------- 对外接口（公开歌单 / 单曲页用） ---------------- */
  function ensureList(cb, tries) {
    if (tracks.length) { cb(); return; }
    if (!tries) fetchList(false);
    if ((tries || 0) > 40) return;
    setTimeout(function () { ensureList(cb, (tries || 0) + 1); }, 100);
  }

  window.WBMusic = {
    play: function (id) {
      ensureList(function () {
        var i = -1;
        for (var k = 0; k < tracks.length; k++) {
          if (String(tracks[k].id) === String(id)) { i = k; break; }
        }
        if (i < 0) return;
        load(i, true);
        open();
      });
    },
    playAll: function () {
      ensureList(function () { load(0, true); open(); });
    },
    open: open,
    close: function () { stopNow(); close(); }
  };

  /* ---------------- 挂载 ---------------- */
  function mount() {
    document.body.appendChild(root);
    if (!audio.parentNode) document.body.appendChild(audio);
    audio.volume = vol;
    // 首屏稳定后再拉列表，不抢带宽
    var kick = function () { fetchList(false); };
    if ('requestIdleCallback' in window) {
      window.requestIdleCallback(kick, { timeout: 3500 });
    } else {
      setTimeout(kick, 1800);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
