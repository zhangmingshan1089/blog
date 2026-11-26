<?php
/**
 * 音乐库 · 公开页（歌单 / 单曲）
 * ------------------------------------------------------------------
 * 由 index.php 调用。访客看到的是可被搜索引擎抓取的静态化 HTML：
 * 服务端直出曲名、备注、时长、封面与结构化数据，不依赖 JS。
 * 页面风格跟随站点（玻璃 / 纸质 × 明暗），默认科技深空。
 */

declare(strict_types=1);

/* ---------------- 小工具 ---------------- */

/** 秒 → ISO 8601 时长（PT1M16S） */
function wb_pub_iso_dur(float $s): string
{
    $s = max(0, (int)round($s));
    if ($s === 0) return 'PT0S';
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;
    $out = 'PT';
    if ($h > 0) $out .= $h . 'H';
    if ($m > 0) $out .= $m . 'M';
    if ($sec > 0) $out .= $sec . 'S';
    return $out;
}

/** 时间戳 → 2026年11月25日 */
function wb_pub_date(int $ts): string
{
    if ($ts <= 0) return '';
    return date('Y年n月j日', $ts);
}

/** 2026-11-25（给 datetime 属性） */
function wb_pub_iso_date(int $ts): string
{
    return date('Y-m-d', max(1, $ts));
}

/** 总时长：1 小时 12 分 / 12 分 30 秒 */
function wb_pub_total_dur(array $tracks): string
{
    $sum = 0;
    foreach ($tracks as $t) $sum += (float)($t['dur'] ?? 0);
    $sum = (int)round($sum);
    if ($sum <= 0) return '—';
    $h = intdiv($sum, 3600);
    $m = intdiv($sum % 3600, 60);
    if ($h > 0) return $h . ' 小时 ' . $m . ' 分';
    if ($m > 0) return $m . ' 分 ' . ($sum % 60) . ' 秒';
    return $sum . ' 秒';
}

/** 把备注按空行/换行拆成段落 */
function wb_pub_paras(string $text): array
{
    $text = trim($text);
    if ($text === '') return [];
    $parts = preg_split('/\n\s*\n/u', $text) ?: [$text];
    $out = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/\s*\n\s*/u', ' ', $p) ?? $p);
        if ($p !== '') $out[] = $p;
    }
    return $out;
}

/** 从标签 + 曲名里凑关键词 */
function wb_pub_auto_keywords(array $tracks, string $playlist, string $siteName): string
{
    $tags = [];
    foreach ($tracks as $t) {
        foreach ((array)($t['tags'] ?? []) as $tg) {
            if (!is_string($tg)) continue;
            $tg = trim($tg);
            if ($tg !== '' && mb_strlen($tg) <= 12 && !in_array($tg, $tags, true)) $tags[] = $tg;
            if (count($tags) >= 8) break 2;
        }
    }
    $titles = [];
    foreach (array_slice($tracks, 0, 3) as $t) {
        $tt = trim((string)($t['title'] ?? ''));
        if ($tt !== '') $titles[] = $tt;
    }
    $base = array_merge([$playlist, '歌单', '音乐', '私人歌单'], $tags, $titles, [$siteName, '博客']);
    $base = array_values(array_unique(array_filter($base, static fn($x) => $x !== '')));
    return implode(',', array_slice($base, 0, 12));
}

/** 自动描述：不超过 150 字 */
function wb_pub_auto_desc(array $tracks, string $playlist, string $intro, string $siteName): string
{
    $n = count($tracks);
    $titles = [];
    foreach (array_slice($tracks, 0, 4) as $t) {
        $tt = trim((string)($t['title'] ?? ''));
        if ($tt !== '') $titles[] = '《' . $tt . '》';
    }
    $head = $intro !== '' ? $intro : ('《' . $playlist . '》是' . $siteName . '的私人歌单。');
    $mid = $n > 0 ? ('共 ' . $n . ' 首' . ($titles ? '：' . implode('、', $titles) : '') . '。') : '暂时还没有曲目。';
    $tail = '每首都有手写备注，按上传顺序排列。';
    $s = $head . $mid . $tail;
    if (mb_strlen($s) > 150) $s = mb_substr($s, 0, 148) . '…';
    return $s;
}

/* ---------------- 文档头 ---------------- */

function wb_pub_head(array $c): void
{
    $siteName = (string)$c['site']['title'];
    $pageTitle = (string)$c['page_title'];
    $desc = (string)$c['page_desc'];
    $kw = (string)$c['page_keywords'];
    $canonical = (string)$c['canonical'];
    $ogImage = (string)$c['og_image'];
    $ogType = $c['mode'] === 'song' ? 'music.song' : 'music.playlist';
    $base = (string)$c['base'];

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=120, stale-while-revalidate=600');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
<!DOCTYPE html>
<html lang="zh-CN" data-style="tech" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>(function(){var r=document.documentElement;try{var s=localStorage.getItem('wb-style'),t=localStorage.getItem('wb-theme');r.dataset.style=(s==='paper'||s==='tech')?s:'tech';if(t!=='light'&&t!=='dark'){t='dark';}r.dataset.theme=t;}catch(e){r.dataset.style='tech';r.dataset.theme='dark';}})();</script>
<title><?php echo h($pageTitle); ?></title>
<meta name="description" content="<?php echo h($desc); ?>">
<meta name="keywords" content="<?php echo h($kw); ?>">
<meta name="author" content="<?php echo h($siteName); ?>">
<meta name="robots" content="index,follow,max-image-preview:large,max-snippet:-1">
<link rel="canonical" href="<?php echo h($canonical); ?>">
<link rel="icon" href="<?php echo h($c['home']); ?>/usr/themes/wbclean/assets/favicon-64.png">
<link rel="apple-touch-icon" href="<?php echo h($c['home']); ?>/usr/themes/wbclean/assets/apple-touch-180.png">
<meta name="theme-color" content="#04060B">
<meta property="og:type" content="<?php echo h($ogType); ?>">
<meta property="og:site_name" content="<?php echo h($siteName); ?>">
<meta property="og:title" content="<?php echo h($pageTitle); ?>">
<meta property="og:description" content="<?php echo h($desc); ?>">
<meta property="og:url" content="<?php echo h($canonical); ?>">
<?php if ($ogImage !== ''): ?>
<meta property="og:image" content="<?php echo h($ogImage); ?>">
<meta property="og:image:alt" content="<?php echo h($c['page_heading']); ?>">
<?php endif; ?>
<meta name="twitter:card" content="<?php echo $ogImage !== '' ? 'summary_large_image' : 'summary'; ?>">
<meta name="twitter:title" content="<?php echo h($pageTitle); ?>">
<meta name="twitter:description" content="<?php echo h($desc); ?>">
<?php if ($ogImage !== ''): ?>
<meta name="twitter:image" content="<?php echo h($ogImage); ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo h($base); ?>/player.css?v=8">
<?php
    if (!empty($c['preload_image'])) {
        echo '<link rel="preload" as="image" href="' . h((string)$c['preload_image']) . '" fetchpriority="high">' . "\n";
    }
    foreach ((array)$c['jsonld'] as $block) {
        $json = json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json !== false) {
            echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
        }
    }
    ?>
<style>
<?php echo wb_pub_css(); ?>
</style>
</head>
<body class="mode-<?php echo $c['mode'] === 'song' ? 'song' : 'playlist'; ?>">
<div class="bg" aria-hidden="true"></div>
<?php
}

/* ---------------- 页头 ---------------- */

function wb_pub_header(array $c, string $active): void
{
    $home = (string)$c['home'];
    $base = (string)$c['base'];
    $siteName = (string)$c['site']['title'];
    ?>
<header class="ph">
  <div class="ph-in">
    <a class="brand" href="<?php echo h($home); ?>/" aria-label="<?php echo h($siteName); ?> 首页">
      <span class="brand-i" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/></svg>
      </span>
      <span class="brand-t">
        <b><?php echo h($siteName); ?></b>
        <small>MUSIC · 我喜欢的歌</small>
      </span>
    </a>
    <nav class="ph-nav" aria-label="导航">
      <a href="<?php echo h($home); ?>/">首页</a>
      <a href="<?php echo h($base); ?>/"<?php echo $active === 'playlist' ? ' class="on" aria-current="page"' : ''; ?>>歌单</a>
      <a href="<?php echo h($home); ?>/index.php/archives.html">文章</a>
      <a class="ph-admin" href="?admin=1" rel="nofollow">管理</a>
    </nav>
    <div class="ph-tools">
      <button class="tool" id="styleBtn" type="button" title="切换风格" aria-label="切换风格">玻璃</button>
      <button class="tool tool-ico" id="themeBtn" type="button" title="切换明暗" aria-label="切换明暗">
        <svg class="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.4 5.4l1.6 1.6M17 17l1.6 1.6M18.6 5.4 17 7M7 17l-1.6 1.6"/></svg>
        <svg class="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M20 14.4A8.4 8.4 0 0 1 9.6 4a8.4 8.4 0 1 0 10.4 10.4z"/></svg>
      </button>
    </div>
  </div>
</header>
<?php
}

/* ---------------- 页脚 ---------------- */

function wb_pub_footer(array $c): void
{
    $home = (string)$c['home'];
    $siteName = (string)$c['site']['title'];
    $base = (string)$c['base'];
    ?>
<footer class="pf">
  <div class="pf-in">
    <p class="pf-brand"><b><?php echo h($siteName); ?></b><span>写在自己的地方</span></p>
    <nav class="pf-nav" aria-label="页脚">
      <a href="<?php echo h($base); ?>/">歌单</a>
      <a href="<?php echo h($home); ?>/index.php/about.html">关于</a>
      <a href="<?php echo h($home); ?>/index.php/links.html">友链</a>
      <a href="<?php echo h($home); ?>/index.php/feed/">RSS</a>
    </nav>
  </div>
</footer>
<script>
(function(){
  var root = document.documentElement;
  var styleBtn = document.getElementById('styleBtn');
  var themeBtn = document.getElementById('themeBtn');

  function metaColor(){
    var m = document.querySelector('meta[name="theme-color"]');
    if (!m) return;
    var paper = root.dataset.style === 'paper';
    var dark = root.dataset.theme === 'dark';
    m.setAttribute('content', paper ? (dark ? '#1B1B19' : '#FAF9F6') : (dark ? '#04060B' : '#F2F5FA'));
  }
  function labels(){
    if (styleBtn) {
      styleBtn.textContent = root.dataset.style === 'paper' ? '纸质' : '玻璃';
      styleBtn.title = root.dataset.style === 'paper' ? '切换到玻璃风格' : '切换到纸质风格';
    }
    if (themeBtn) themeBtn.setAttribute('aria-pressed', root.dataset.theme === 'dark' ? 'true' : 'false');
  }
  function save(){
    try {
      localStorage.setItem('wb-style', root.dataset.style);
      localStorage.setItem('wb-theme', root.dataset.theme);
    } catch (e) {}
  }
  if (styleBtn) styleBtn.addEventListener('click', function(){
    root.dataset.style = root.dataset.style === 'paper' ? 'tech' : 'paper';
    labels(); metaColor(); save();
  });
  if (themeBtn) themeBtn.addEventListener('click', function(){
    root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    labels(); metaColor(); save();
  });
  labels(); metaColor();

  /* 列表里的播放键 → 交给全站播放器 */
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('[data-play]') : null;
    if (!b) return;
    e.preventDefault();
    var id = b.getAttribute('data-play');
    if (window.WBMusic && id) window.WBMusic.play(id);
  });

  var op = document.getElementById('openPlayer');
  if (op) op.addEventListener('click', function(){
    var id = op.getAttribute('data-play');
    if (window.WBMusic && id) { window.WBMusic.play(id); window.WBMusic.open(); }
  });

  var pa = document.getElementById('playAll');
  if (pa) pa.addEventListener('click', function(){
    if (window.WBMusic) window.WBMusic.playAll();
  });
})();
</script>
<script src="<?php echo h($base); ?>/player.js?v=9" defer></script>
</body>
</html>
<?php
}

/* ---------------- 封面方块 ---------------- */

function wb_pub_art(array $t, string $cls, string $size = ''): void
{
    $title = (string)($t['title'] ?? '未命名');
    $cv = cover_url($t);
    $grad = art_grad((string)($t['id'] ?? 'x'));
    $style = $cv !== ''
        ? 'background-image:url(' . h($cv) . ')'
        : 'background:' . h($grad);
    echo '<span class="' . h($cls) . ($cv !== '' ? ' has-img' : '') . '" style="' . $style . '" aria-hidden="true">';
    if ($cv === '') echo '<i>' . h(mb_substr($title, 0, 1)) . '</i>';
    echo '</span>';
}

/* ---------------- 歌单页 ---------------- */

function wb_pub_playlist(array $c): void
{
    $tracks = $c['tracks'];
    $settings = $c['settings'];
    $title = (string)$c['playlist_title'];
    $intro = (string)$c['playlist_intro'];
    $base = (string)$c['base'];
    $siteName = (string)$c['site']['title'];
    $n = count($tracks);
    $updated = (int)$c['updated_ts'];
    $firstCover = '';
    foreach ($tracks as $t) {
        $u = cover_url($t);
        if ($u !== '') { $firstCover = $u; break; }
    }
    ?>
<main id="main">
  <section class="hero">
    <div class="hero-txt">
      <p class="eyebrow"><span class="dot" aria-hidden="true"></span>PRIVATE PLAYLIST · 私人歌单</p>
      <h1><?php echo h($title); ?></h1>
      <?php if ($intro !== ''): ?><p class="lede"><?php echo h($intro); ?></p><?php endif; ?>
      <div class="hero-actions">
        <button class="btn btn-play" type="button" id="playAll"<?php echo $n ? '' : ' disabled'; ?>>
          <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.5 5.4v13.2L19 12z"/></svg>
          播放全部
        </button>
        <?php if ($n): ?>
        <a class="btn btn-ghost" href="<?php echo h($base); ?>/?playlist=m3u" download="playlist.m3u">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 19h14"/></svg>
          导出歌单
        </a>
        <?php endif; ?>
        <a class="btn btn-ghost" href="#list">按顺序看</a>
      </div>
      <dl class="hero-stats">
        <div><dt>曲目</dt><dd><?php echo $n; ?><i>首</i></dd></div>
        <div><dt>总时长</dt><dd><?php echo h(wb_pub_total_dur($tracks)); ?></dd></div>
        <div><dt>最近更新</dt><dd><?php echo $updated > 0 ? h(wb_pub_date($updated)) : '—'; ?></dd></div>
      </dl>
    </div>

    <div class="hero-art" aria-hidden="true">
      <span class="halo"></span>
      <?php
      $show = array_slice($tracks, 0, 3);
      $cls = ['cv1', 'cv2', 'cv3'];
      foreach ($show as $i => $t) {
          echo '<span class="cv ' . $cls[$i] . '">';
          $cv = cover_url($t);
          if ($cv !== '') {
              echo '<img src="' . h($cv) . '" alt="' . h((string)($t['title'] ?? '')) . ' 封面" width="260" height="260" loading="' . ($i === 0 ? 'eager' : 'lazy') . '">';
          } else {
              echo '<span class="cv-ph" style="background:' . h(art_grad((string)($t['id'] ?? 'x'))) . '">' . h(mb_substr((string)($t['title'] ?? '?'), 0, 1)) . '</span>';
          }
          echo '</span>';
      }
      if (!$show) {
          echo '<span class="cv cv1"><span class="cv-ph" style="background:linear-gradient(140deg,#4e86f7,#8b5cf6)">♪</span></span>';
      }
      ?>
    </div>
  </section>

  <section class="sec" id="list">
    <div class="sec-h">
      <h2>曲目</h2>
      <p>按上传顺序排列，和站上播放器里的顺序一致<?php echo $n ? '，共 ' . $n . ' 首' : ''; ?></p>
    </div>

    <?php if (!$n): ?>
      <div class="empty">
        <p>歌单还是空的。</p>
        <small>登录后可以在后台把歌拖进来，这里会自动出现。</small>
      </div>
    <?php else: ?>
    <ol class="songs">
      <?php foreach ($tracks as $i => $t):
          $tt = (string)($t['title'] ?? '未命名');
          $artist = trim((string)($t['artist'] ?? ''));
          $note = trim((string)($t['note'] ?? ''));
          $tags = array_values(array_filter((array)($t['tags'] ?? []), 'is_string'));
          $dur = (float)($t['dur'] ?? 0);
          $ql = quality_label($t);
          $songUrl = $base . '/?song=' . rawurlencode((string)$t['id']);
      ?>
      <li class="song">
        <span class="song-num" aria-hidden="true"><?php echo str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT); ?></span>
        <a class="song-art-link" href="<?php echo h($songUrl); ?>" tabindex="-1" aria-hidden="true">
          <?php wb_pub_art($t, 'song-art'); ?>
        </a>
        <div class="song-body">
          <h3><a href="<?php echo h($songUrl); ?>"><?php echo h($tt); ?></a></h3>
          <p class="song-meta">
            <?php if ($artist !== ''): ?><span class="who"><?php echo h($artist); ?></span><span class="sep" aria-hidden="true">·</span><?php endif; ?>
            <?php if ($dur > 0): ?><span><?php echo h(fmt_dur($dur)); ?></span><span class="sep" aria-hidden="true">·</span><?php endif; ?>
            <?php if ($ql !== ''): ?><span><?php echo h($ql); ?></span><?php endif; ?>
            <?php foreach ($tags as $tg): ?><span class="tag"><?php echo h($tg); ?></span><?php endforeach; ?>
          </p>
          <?php if ($note !== ''): ?>
            <p class="song-note"><?php echo h($note); ?></p>
          <?php endif; ?>
        </div>
        <div class="song-act">
          <button class="play-dot" type="button" data-play="<?php echo h((string)$t['id']); ?>" aria-label="播放 <?php echo h($tt); ?>" title="播放">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8.5 5.4v13.2L19 12z"/></svg>
          </button>
          <a class="more" href="<?php echo h($songUrl); ?>" aria-label="查看 <?php echo h($tt); ?> 的详情">详情</a>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
  </section>

  <section class="sec about">
    <div class="about-grid">
      <div>
        <h2>关于这份歌单</h2>
        <p>没有算法推荐，也没有按风格分类。加进来的标准只有一个：过一阵子还想再听。</p>
        <p>每首后面的备注写在当时，算是给以后的自己留个记号。上传或修改之后，这一页和右下角播放器会同时更新。</p>
        <p class="muted">歌单顺序就是上传顺序。想换顺序，在后台上下移动即可。</p>
      </div>
      <ul class="about-list">
        <li><span>曲目</span><b><?php echo $n; ?> 首</b></li>
        <li><span>总时长</span><b><?php echo h(wb_pub_total_dur($tracks)); ?></b></li>
        <li><span>最近更新</span><b><?php echo $updated > 0 ? h(wb_pub_date($updated)) : '—'; ?></b></li>
        <li><span>维护</span><b><?php echo h($siteName); ?></b></li>
      </ul>
    </div>
  </section>
</main>
<?php
}

/* ---------------- 单曲页 ---------------- */

function wb_pub_song(array $c): void
{
    $t = $c['track'];
    $tracks = $c['tracks'];
    $base = (string)$c['base'];
    $idx = (int)$c['index'];
    $n = count($tracks);
    $tt = (string)($t['title'] ?? '未命名');
    $artist = trim((string)($t['artist'] ?? ''));
    $note = trim((string)($t['note'] ?? ''));
    $tags = array_values(array_filter((array)($t['tags'] ?? []), 'is_string'));
    $dur = (float)($t['dur'] ?? 0);
    $ql = quality_label($t);
    $cover = cover_url($t);
    $playlistUrl = $base . '/';
    $prev = $idx > 0 ? $tracks[$idx - 1] : null;
    $next = $idx + 1 < $n ? $tracks[$idx + 1] : null;
    $related = [];
    for ($k = 1; $k <= 3 && $n > 1; $k++) {
        $j = ($idx + $k) % $n;
        if ($j !== $idx) $related[] = $tracks[$j];
    }
    ?>
<main id="main" class="song-main">
  <nav class="crumb" aria-label="面包屑">
    <a href="<?php echo h($c['home']); ?>/">首页</a>
    <span aria-hidden="true">/</span>
    <a href="<?php echo h($playlistUrl); ?>"><?php echo h((string)$c['playlist_title']); ?></a>
    <span aria-hidden="true">/</span>
    <span aria-current="page"><?php echo h($tt); ?></span>
  </nav>

  <article class="song-hero">
    <div class="song-cover">
      <?php if ($cover !== ''): ?>
        <img src="<?php echo h($cover); ?>" alt="<?php echo h($tt); ?> 的封面" width="360" height="360" fetchpriority="high">
      <?php else: ?>
        <span class="cv-ph" style="background:<?php echo h(art_grad((string)$t['id'])); ?>"><?php echo h(mb_substr($tt, 0, 1)); ?></span>
      <?php endif; ?>
    </div>
    <div class="song-head">
      <p class="eyebrow"><span class="dot" aria-hidden="true"></span>TRACK <?php echo str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT); ?> / <?php echo str_pad((string)$n, 2, '0', STR_PAD_LEFT); ?></p>
      <h1><?php echo h($tt); ?></h1>
      <p class="song-sub">
        <?php if ($artist !== ''): ?><span><?php echo h($artist); ?></span><span class="sep" aria-hidden="true">·</span><?php endif; ?>
        <?php if ($dur > 0): ?><span><?php echo h(fmt_dur($dur)); ?></span><span class="sep" aria-hidden="true">·</span><?php endif; ?>
        <span>收录于 <a href="<?php echo h($playlistUrl); ?>"><?php echo h((string)$c['playlist_title']); ?></a></span>
      </p>
      <?php if ($ql !== '' || $tags): ?>
      <p class="song-tags">
        <?php if ($ql !== ''): ?><span class="pill"><?php echo h($ql); ?></span><?php endif; ?>
        <?php foreach ($tags as $tg): ?><span class="pill pill-tag"><?php echo h($tg); ?></span><?php endforeach; ?>
      </p>
      <?php endif; ?>

      <audio id="songAudio" controls preload="metadata" src="<?php echo h($base . '/?get=' . rawurlencode((string)$t['id'])); ?>">
        你的浏览器不支持音频播放，可以<a href="<?php echo h($base . '/?get=' . rawurlencode((string)$t['id'])); ?>">直接下载这首</a>。
      </audio>

      <div class="song-actions">
        <button class="btn btn-play" type="button" id="openPlayer" data-play="<?php echo h((string)$t['id']); ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/></svg>
          在站上播放器里听
        </button>
        <a class="btn btn-ghost" href="<?php echo h($playlistUrl); ?>">← 回到歌单</a>
      </div>
    </div>
  </article>

  <section class="note-sec">
    <h2>关于这首</h2>
    <?php if ($note !== ''): ?>
      <?php foreach (wb_pub_paras($note) as $p): ?><p><?php echo h($p); ?></p><?php endforeach; ?>
    <?php else: ?>
      <p class="muted">这首还没有写备注。</p>
    <?php endif; ?>
    <p class="note-foot">
      收录时间：<time datetime="<?php echo h(wb_pub_iso_date((int)($t['added'] ?? 0))); ?>"><?php echo h(wb_pub_date((int)($t['added'] ?? 0))); ?></time>
      · 文件大小 <?php echo h(human_size((int)($t['size'] ?? 0))); ?>
      <?php if (!empty($t['src'])): ?> · 音轨提取自 <?php echo h((string)$t['src']); ?><?php endif; ?>
    </p>
  </section>

  <nav class="pager" aria-label="上一首 / 下一首">
    <?php if ($prev): ?>
      <a class="pager-a pager-prev" href="<?php echo h($base . '/?song=' . rawurlencode((string)$prev['id'])); ?>">
        <span>上一首</span>
        <b><?php echo h((string)($prev['title'] ?? '')); ?></b>
      </a>
    <?php else: ?>
      <a class="pager-a pager-prev" href="<?php echo h($playlistUrl); ?>">
        <span>回到</span>
        <b><?php echo h((string)$c['playlist_title']); ?></b>
      </a>
    <?php endif; ?>
    <?php if ($next): ?>
      <a class="pager-a pager-next" href="<?php echo h($base . '/?song=' . rawurlencode((string)$next['id'])); ?>">
        <span>下一首</span>
        <b><?php echo h((string)($next['title'] ?? '')); ?></b>
      </a>
    <?php else: ?>
      <a class="pager-a pager-next" href="<?php echo h($playlistUrl); ?>">
        <span>听完了，</span>
        <b>回到歌单</b>
      </a>
    <?php endif; ?>
  </nav>

  <?php if ($related): ?>
  <section class="related">
    <h2>接着听</h2>
    <ul>
      <?php foreach ($related as $r): ?>
        <li>
          <a href="<?php echo h($base . '/?song=' . rawurlencode((string)$r['id'])); ?>">
            <?php wb_pub_art($r, 'related-art'); ?>
            <span class="related-t">
              <b><?php echo h((string)($r['title'] ?? '')); ?></b>
              <small><?php echo h(quality_label($r) ?: (string)($r['artist'] ?? '')); ?></small>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</main>
<?php
}

/* ---------------- 入口 ---------------- */

function wb_public_render(array $c): void
{
    $settings = $c['settings'];
    $tracks = $c['tracks'];
    $siteName = (string)$c['site']['title'];
    $base = (string)$c['base'];
    $playlistTitle = (string)($settings['title'] ?? '');
    if ($playlistTitle === '') $playlistTitle = '我喜欢的歌';
    $intro = (string)($settings['intro'] ?? '');
    $updated = 0;
    foreach ($tracks as $t) $updated = max($updated, (int)($t['added'] ?? 0));
    if (is_file(DATA_FILE)) $updated = max($updated, (int)@filemtime(DATA_FILE));

    $c['playlist_title'] = $playlistTitle;
    $c['playlist_intro'] = $intro;
    $c['updated_ts'] = $updated;

    if ($c['mode'] === 'song' && $c['track']) {
        $t = $c['track'];
        $tt = (string)($t['title'] ?? '未命名');
        $note = trim((string)($t['note'] ?? ''));
        $artist = trim((string)($t['artist'] ?? ''));
        $c['page_heading'] = $tt;
        $c['page_title'] = $tt . ' · ' . $playlistTitle . ' · ' . $siteName;
        $desc = $note !== ''
            ? $tt . '：' . $note
            : ('《' . $tt . '》收录在' . $siteName . '的歌单《' . $playlistTitle . '》里。');
        if (mb_strlen($desc) > 150) $desc = mb_substr($desc, 0, 148) . '…';
        $c['page_desc'] = $desc;
        $tags = array_values(array_filter((array)($t['tags'] ?? []), 'is_string'));
        $c['page_keywords'] = implode(',', array_slice(array_merge(
            [$tt],
            $tags,
            [$playlistTitle, '歌单', $artist !== '' ? $artist : $siteName, '音乐', $siteName]
        ), 0, 12));
        $c['canonical'] = wb_abs_url($base . '/?song=' . rawurlencode((string)$t['id']));
        $cv = cover_url($t);
        $c['og_image'] = $cv !== '' ? wb_abs_url($cv) : $c['default_og'];
        $c['preload_image'] = $cv !== '' ? $cv : '';

        $songAbs = $c['canonical'];
        $audioAbs = wb_abs_url($base . '/?get=' . rawurlencode((string)$t['id']));
        $rec = [
            '@context' => 'https://schema.org',
            '@type' => 'MusicRecording',
            'name' => $tt,
            'url' => $songAbs,
            'audio' => [
                '@type' => 'AudioObject',
                'contentUrl' => $audioAbs,
                'encodingFormat' => (string)($t['mime'] ?? 'audio/mpeg'),
            ],
            'partOfSeries' => [
                '@type' => 'MusicPlaylist',
                'name' => $playlistTitle,
                'url' => wb_abs_url($base . '/'),
            ],
        ];
        $isoDur = wb_pub_iso_dur((float)($t['dur'] ?? 0));
        if ($isoDur !== 'PT0S') $rec['duration'] = $isoDur;
        if ($artist !== '') $rec['byArtist'] = ['@type' => 'Person', 'name' => $artist];
        if ($note !== '') $rec['description'] = $note;
        if ($cv !== '') $rec['image'] = wb_abs_url($cv);
        if (!empty($t['added'])) $rec['datePublished'] = date('c', (int)$t['added']);
        $c['jsonld'][] = $rec;
        $c['jsonld'][] = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => '首页', 'item' => wb_abs_url($c['home'] . '/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $playlistTitle, 'item' => wb_abs_url($base . '/')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $tt, 'item' => $songAbs],
            ],
        ];
    } else {
        $c['page_heading'] = $playlistTitle;
        $seoTitle = trim((string)($settings['seo_title'] ?? ''));
        $c['page_title'] = $seoTitle !== '' ? $seoTitle : ($playlistTitle . ' · 歌单 · ' . $siteName);
        $seoDesc = trim((string)($settings['seo_desc'] ?? ''));
        $c['page_desc'] = $seoDesc !== '' ? $seoDesc : wb_pub_auto_desc($tracks, $playlistTitle, $intro, $siteName);
        if (mb_strlen($c['page_desc']) > 160) $c['page_desc'] = mb_substr($c['page_desc'], 0, 158) . '…';
        $seoKw = trim((string)($settings['seo_keywords'] ?? ''));
        $c['page_keywords'] = $seoKw !== '' ? $seoKw : wb_pub_auto_keywords($tracks, $playlistTitle, $siteName);
        $c['canonical'] = wb_abs_url($base . '/');
        $firstCover = '';
        foreach ($tracks as $t) {
            $u = cover_url($t);
            if ($u !== '') { $firstCover = $u; break; }
        }
        $c['og_image'] = $firstCover !== '' ? wb_abs_url($firstCover) : $c['default_og'];
        $c['preload_image'] = $firstCover;

        $items = [];
        foreach ($tracks as $t) {
            $id = (string)($t['id'] ?? '');
            if ($id === '') continue;
            $item = [
                '@type' => 'MusicRecording',
                'name' => (string)($t['title'] ?? '未命名'),
                'url' => wb_abs_url($base . '/?song=' . rawurlencode($id)),
                'audio' => [
                    '@type' => 'AudioObject',
                    'contentUrl' => wb_abs_url($base . '/?get=' . rawurlencode($id)),
                    'encodingFormat' => (string)($t['mime'] ?? 'audio/mpeg'),
                ],
            ];
            $isoDur = wb_pub_iso_dur((float)($t['dur'] ?? 0));
            if ($isoDur !== 'PT0S') $item['duration'] = $isoDur;
            $ar = trim((string)($t['artist'] ?? ''));
            if ($ar !== '') $item['byArtist'] = ['@type' => 'Person', 'name' => $ar];
            $nt = trim((string)($t['note'] ?? ''));
            if ($nt !== '') $item['description'] = $nt;
            $u = cover_url($t);
            if ($u !== '') $item['image'] = wb_abs_url($u);
            $items[] = $item;
        }
        $c['jsonld'][] = [
            '@context' => 'https://schema.org',
            '@type' => 'MusicPlaylist',
            'name' => $playlistTitle,
            'description' => $c['page_desc'],
            'url' => $c['canonical'],
            'numTracks' => count($items),
            'track' => $items,
        ];
        $c['jsonld'][] = [
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $c['page_title'],
            'description' => $c['page_desc'],
            'url' => $c['canonical'],
            'inLanguage' => 'zh-CN',
            'dateModified' => date('c', max(1, $updated)),
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => wb_abs_url($c['home'] . '/'),
            ],
            'about' => ['@type' => 'MusicPlaylist', 'name' => $playlistTitle],
        ];
    }

    wb_pub_head($c);
    wb_pub_header($c, $c['mode'] === 'song' ? 'playlist' : 'playlist');
    if ($c['mode'] === 'song' && $c['track']) {
        wb_pub_song($c);
    } else {
        wb_pub_playlist($c);
    }
    wb_pub_footer($c);
}

/* ---------------- 样式 ---------------- */

function wb_pub_css(): string
{
    return <<<'CSS'
*,*::before,*::after{box-sizing:border-box}
:root{
  --sans:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
  --serif:"Noto Serif SC","Noto Serif CJK SC","Songti SC","STSong",Georgia,serif;
  --maxw:1120px;
  --ease:cubic-bezier(.2,.8,.25,1);
}
html{scroll-behavior:smooth;scroll-padding-top:84px}
html[data-theme="dark"]{color-scheme:dark}

/* 四套配色：与站点保持同一套变量语义 */
html[data-style="tech"][data-theme="dark"]{
  --bg:#04060B;--bg-2:#080C14;--card:rgba(17,24,37,.62);--card-solid:#0D141F;
  --line:rgba(255,255,255,.09);--line-2:rgba(255,255,255,.17);
  --ink:#EDF3FC;--ink-2:#B3C0D2;--ink-3:#7F8DA3;
  --accent:#79A9FF;--accent-2:#4E86F7;--on-accent:#07101E;--weak:rgba(121,169,255,.14);
  --head:rgba(5,8,13,.6);--shadow:0 20px 60px rgba(0,0,0,.55);
  --serif:var(--sans);
}
html[data-style="tech"][data-theme="light"]{
  --bg:#F2F5FA;--bg-2:#E9EEF7;--card:#FFFFFF;--card-solid:#FFFFFF;
  --line:rgba(16,24,40,.08);--line-2:rgba(16,24,40,.16);
  --ink:#141B26;--ink-2:#4B586C;--ink-3:#78859A;
  --accent:#2F6FED;--accent-2:#1D4FD0;--on-accent:#FFFFFF;--weak:#EAF1FF;
  --head:rgba(250,252,255,.82);--shadow:0 18px 46px rgba(16,24,40,.10);
  --serif:var(--sans);
}
html[data-style="paper"][data-theme="light"]{
  --bg:#FAF9F6;--bg-2:#F4F2EB;--card:#FFFDF8;--card-solid:#FFFDF8;
  --line:#E5E1D8;--line-2:#D6D0C2;
  --ink:#2B2B2B;--ink-2:#56534C;--ink-3:#8A867D;
  --accent:#3F6B5B;--accent-2:#33574A;--on-accent:#F8FBF9;--weak:#E9EFEA;
  --head:rgba(250,249,246,.86);--shadow:0 16px 40px rgba(60,52,36,.10);
}
html[data-style="paper"][data-theme="dark"]{
  --bg:#1B1B19;--bg-2:#222220;--card:#262623;--card-solid:#262623;
  --line:#33322E;--line-2:#46443D;
  --ink:#E8E5DE;--ink-2:#BDB8AC;--ink-3:#8A867D;
  --accent:#8FB6A3;--accent-2:#A9C8B8;--on-accent:#16201B;--weak:#242E29;
  --head:rgba(27,27,25,.86);--shadow:0 18px 44px rgba(0,0,0,.4);
}

body{
  margin:0;background:var(--bg);color:var(--ink);
  font-family:var(--sans);font-size:16px;line-height:1.75;
  -webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;
  overflow-x:hidden;
}
a{color:inherit;text-decoration:none}
button{font:inherit;cursor:pointer}
img{max-width:100%}
::selection{background:var(--accent);color:var(--on-accent)}

/* 背景层 */
.bg{position:fixed;inset:0;z-index:-2;pointer-events:none;overflow:hidden}
.bg::before{
  content:'';position:absolute;inset:-25%;
  background:
    radial-gradient(38% 38% at 16% 6%,rgba(90,130,255,.20),transparent 62%),
    radial-gradient(34% 34% at 86% 10%,rgba(150,90,255,.15),transparent 64%),
    radial-gradient(46% 46% at 52% 102%,rgba(40,190,210,.10),transparent 62%);
  filter:blur(18px);
}
html[data-style="tech"][data-theme="dark"] .bg{
  background-image:
    radial-gradient(1px 1px at 18px 26px,rgba(255,255,255,.55),transparent 1.6px),
    radial-gradient(1px 1px at 96px 74px,rgba(255,255,255,.36),transparent 1.6px),
    radial-gradient(1px 1px at 152px 34px,rgba(255,255,255,.44),transparent 1.6px),
    radial-gradient(1px 1px at 62px 152px,rgba(255,255,255,.30),transparent 1.6px),
    radial-gradient(1px 1px at 188px 168px,rgba(255,255,255,.42),transparent 1.6px),
    radial-gradient(1px 1px at 128px 116px,rgba(255,255,255,.24),transparent 1.6px);
  background-size:220px 220px;opacity:.65;
}
html[data-style="paper"] .bg::before{
  background:
    radial-gradient(40% 40% at 12% 4%,rgba(63,107,91,.09),transparent 66%),
    radial-gradient(36% 36% at 92% 12%,rgba(169,140,90,.08),transparent 66%);
  filter:blur(10px);
}
html[data-style="paper"] .bg::after{
  content:'';position:absolute;inset:0;opacity:.35;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='180' height='180'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3'/%3E%3C/filter%3E%3Crect width='180' height='180' filter='url(%23n)' opacity='.22'/%3E%3C/svg%3E");
  mix-blend-mode:multiply;
}
html[data-style="paper"][data-theme="dark"] .bg::after{mix-blend-mode:screen;opacity:.10}

/* 顶栏 */
.ph{
  position:sticky;top:0;z-index:60;
  background:var(--head);
  -webkit-backdrop-filter:saturate(170%) blur(18px);backdrop-filter:saturate(170%) blur(18px);
  border-bottom:1px solid var(--line);
}
.ph-in{
  max-width:var(--maxw);margin:0 auto;padding:0 24px;height:64px;
  display:flex;align-items:center;gap:22px;
}
.brand{display:flex;align-items:center;gap:11px;min-width:0}
.brand-i{
  width:34px;height:34px;border-radius:10px;flex:none;display:grid;place-items:center;
  background:var(--weak);color:var(--accent);border:1px solid var(--line);
}
.brand-i svg{width:17px;height:17px}
.brand-t{display:flex;flex-direction:column;line-height:1.25;min-width:0}
.brand-t b{font-size:14.5px;letter-spacing:.01em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.brand-t small{font-size:10.5px;letter-spacing:.12em;color:var(--ink-3);text-transform:uppercase}
.ph-nav{display:flex;align-items:center;gap:4px;margin-left:auto}
.ph-nav a{
  font-size:13.5px;color:var(--ink-2);padding:7px 12px;border-radius:9px;
  transition:background .18s,color .18s;
}
.ph-nav a:hover{background:var(--weak);color:var(--ink)}
.ph-nav a.on{background:var(--weak);color:var(--accent);font-weight:600}
.ph-nav a.ph-admin{color:var(--ink-3);font-size:12.5px}
.ph-tools{display:flex;align-items:center;gap:7px}
.tool{
  height:34px;padding:0 12px;border-radius:9px;border:1px solid var(--line);
  background:var(--card);color:var(--ink-2);font-size:12.5px;
  display:inline-flex;align-items:center;justify-content:center;gap:6px;
  transition:border-color .18s,color .18s,background .18s;
}
.tool:hover{border-color:var(--line-2);color:var(--ink)}
.tool-ico{width:34px;padding:0}
.tool svg{width:15px;height:15px}
html[data-theme="dark"] .i-sun{display:none}
html[data-theme="light"] .i-moon{display:none}

/* 首屏 */
.hero{
  max-width:var(--maxw);margin:0 auto;padding:clamp(48px,8vw,92px) 24px clamp(28px,4vw,48px);
  display:grid;grid-template-columns:minmax(0,1.12fr) minmax(0,.88fr);
  gap:clamp(24px,4vw,56px);align-items:center;
}
.eyebrow{
  margin:0 0 16px;font-size:11px;letter-spacing:.22em;text-transform:uppercase;
  color:var(--ink-3);display:flex;align-items:center;gap:9px;
}
.eyebrow .dot{width:5px;height:5px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 4px var(--weak)}
.hero h1{
  font-family:var(--serif);font-size:clamp(40px,6.6vw,70px);line-height:1.04;
  letter-spacing:-.025em;margin:0 0 18px;font-weight:700;
}
.lede{margin:0;max-width:46ch;font-size:clamp(15px,1.5vw,17.5px);line-height:1.8;color:var(--ink-2)}
.hero-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:28px 0 0}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  height:44px;padding:0 18px;border-radius:99px;border:1px solid transparent;
  font-size:14px;font-weight:600;letter-spacing:.01em;
  transition:transform .18s var(--ease),box-shadow .18s,background .18s,border-color .18s;
}
.btn svg{width:16px;height:16px}
.btn:hover{transform:translateY(-1px)}
.btn:active{transform:translateY(0) scale(.99)}
.btn-play{background:var(--accent);color:var(--on-accent);box-shadow:0 10px 26px -8px var(--accent)}
.btn-play:hover{background:var(--accent-2);box-shadow:0 14px 30px -8px var(--accent-2)}
.btn-ghost{background:var(--card);border-color:var(--line);color:var(--ink-2);font-weight:500}
.btn-ghost:hover{border-color:var(--line-2);color:var(--ink)}
.btn[disabled]{opacity:.45;pointer-events:none}
.hero-stats{display:flex;gap:30px;margin:30px 0 0;padding:18px 0 0;border-top:1px solid var(--line)}
.hero-stats div{min-width:0}
.hero-stats dt{font-size:11.5px;letter-spacing:.1em;color:var(--ink-3);text-transform:uppercase}
.hero-stats dd{margin:3px 0 0;font-size:16px;font-weight:600;font-variant-numeric:tabular-nums}
.hero-stats dd i{font-style:normal;font-size:12px;color:var(--ink-3);margin-left:2px;font-weight:400}

/* 封面堆 */
.hero-art{position:relative;height:clamp(280px,34vw,380px)}
.halo{
  position:absolute;left:50%;top:48%;width:74%;aspect-ratio:1;transform:translate(-50%,-50%);
  border-radius:50%;background:radial-gradient(circle,var(--weak) 0%,transparent 68%);
  filter:blur(14px);
}
.cv{
  position:absolute;border-radius:20px;overflow:hidden;border:1px solid var(--line-2);
  box-shadow:var(--shadow);background:var(--card-solid);
  animation:float 7s ease-in-out infinite;will-change:transform;
}
.cv img{width:100%;height:100%;object-fit:cover;display:block}
.cv-ph{width:100%;height:100%;display:grid;place-items:center;color:#fff;font-size:34px;font-weight:700}
.cv1{width:clamp(170px,20vw,236px);aspect-ratio:1;right:8%;top:4%;transform:rotate(6deg);animation-delay:-1.2s}
.cv2{width:clamp(130px,15vw,180px);aspect-ratio:1;left:2%;top:34%;transform:rotate(-7deg);animation-delay:-3.4s}
.cv3{width:clamp(104px,12vw,144px);aspect-ratio:1;right:26%;bottom:1%;transform:rotate(-3deg);animation-delay:-5s}
@keyframes float{
  0%,100%{translate:0 0}
  50%{translate:0 -9px}
}

/* 区块 */
.sec{max-width:var(--maxw);margin:0 auto;padding:24px 24px 40px}
.sec-h{display:flex;align-items:baseline;gap:16px;flex-wrap:wrap;padding-bottom:14px;border-bottom:1px solid var(--line);margin-bottom:6px}
.sec-h h2{margin:0;font-family:var(--serif);font-size:22px;letter-spacing:-.01em}
.sec-h p{margin:0;color:var(--ink-3);font-size:12.8px}

/* 曲目 */
.songs{list-style:none;margin:0;padding:0}
.song{
  display:grid;grid-template-columns:34px 60px minmax(0,1fr) auto;
  gap:16px;align-items:start;padding:20px 6px;border-bottom:1px solid var(--line);
  transition:background .2s;
}
.song:hover{background:linear-gradient(90deg,var(--weak),transparent 72%)}
.song-num{
  font-size:11.5px;color:var(--ink-3);font-variant-numeric:tabular-nums;
  padding-top:6px;letter-spacing:.06em;
}
.song-art-link{display:block}
.song-art{
  width:60px;height:60px;border-radius:13px;overflow:hidden;display:grid;place-items:center;
  background-size:cover;background-position:center;border:1px solid var(--line);
  box-shadow:0 4px 14px rgba(0,0,0,.16);
}
.song-art i{font-style:normal;font-size:22px;font-weight:700;color:#fff;text-shadow:0 2px 10px rgba(0,0,0,.2)}
.song-body{min-width:0}
.song-body h3{margin:0 0 3px;font-size:17.5px;line-height:1.45;font-weight:650;letter-spacing:-.005em}
.song-body h3 a{transition:color .18s}
.song-body h3 a:hover{color:var(--accent)}
.song-meta{
  margin:0;display:flex;align-items:center;gap:7px;flex-wrap:wrap;
  font-size:12.2px;color:var(--ink-3);
}
.song-meta .sep{opacity:.5}
.song-meta .tag{
  font-size:11px;padding:1px 7px;border-radius:99px;background:var(--weak);color:var(--accent);
}
.song-note{
  margin:7px 0 0;font-size:14px;line-height:1.78;color:var(--ink-2);
  max-width:74ch;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;
}
.song-act{display:flex;align-items:center;gap:6px;padding-top:2px}
.play-dot{
  width:38px;height:38px;border-radius:50%;border:1px solid var(--line-2);
  background:var(--card);color:var(--ink-2);display:grid;place-items:center;
  transition:background .18s,color .18s,transform .18s,border-color .18s;
}
.play-dot svg{width:15px;height:15px;margin-left:1px}
.play-dot:hover{background:var(--accent);border-color:var(--accent);color:var(--on-accent);transform:scale(1.06)}
.more{
  font-size:12px;color:var(--ink-3);padding:6px 8px;border-radius:8px;
  white-space:nowrap;transition:color .18s,background .18s;
}
.more:hover{color:var(--accent);background:var(--weak)}

.empty{padding:60px 0;text-align:center;color:var(--ink-3)}
.empty p{margin:0 0 6px;font-size:16px;color:var(--ink-2)}
.empty small{font-size:13px}

/* 关于 */
.about{padding-top:12px}
.about-grid{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,.6fr);gap:48px;padding:26px 0 10px;border-top:1px solid var(--line)}
.about h2{margin:0 0 12px;font-family:var(--serif);font-size:20px}
.about p{margin:0 0 12px;color:var(--ink-2);max-width:62ch;font-size:14.8px}
.about p.muted{color:var(--ink-3);font-size:13.5px}
.about-list{list-style:none;margin:0;padding:0;border-top:1px solid var(--line)}
.about-list li{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid var(--line);font-size:13.5px}
.about-list span{color:var(--ink-3)}
.about-list b{font-weight:600;font-variant-numeric:tabular-nums}

/* 页脚 */
.pf{border-top:1px solid var(--line);margin-top:20px;background:color-mix(in srgb,var(--bg) 82%,transparent)}
.pf-in{
  max-width:var(--maxw);margin:0 auto;padding:26px 24px 40px;
  display:flex;align-items:center;gap:18px;flex-wrap:wrap;
}
.pf-brand{margin:0;display:flex;flex-direction:column;line-height:1.4}
.pf-brand b{font-size:13.5px}
.pf-brand span{font-size:11.5px;color:var(--ink-3);letter-spacing:.04em}
.pf-nav{margin-left:auto;display:flex;gap:4px;flex-wrap:wrap}
.pf-nav a{font-size:12.5px;color:var(--ink-3);padding:5px 9px;border-radius:7px}
.pf-nav a:hover{color:var(--accent);background:var(--weak)}

/* 单曲页 */
.song-main{max-width:1000px;margin:0 auto;padding:34px 24px 50px}
.crumb{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--ink-3);flex-wrap:wrap}
.crumb a{color:var(--ink-3)}
.crumb a:hover{color:var(--accent)}
.crumb span[aria-current]{color:var(--ink-2)}
.song-hero{
  display:grid;grid-template-columns:minmax(220px,300px) minmax(0,1fr);
  gap:clamp(24px,4vw,48px);align-items:center;padding:34px 0 40px;
}
.song-cover{
  aspect-ratio:1;border-radius:22px;overflow:hidden;border:1px solid var(--line-2);
  box-shadow:var(--shadow);background:var(--card-solid);
}
.song-cover img{width:100%;height:100%;object-fit:cover;display:block}
.song-cover .cv-ph{font-size:76px}
.song-head h1{
  margin:0 0 10px;font-family:var(--serif);font-size:clamp(30px,4.6vw,46px);
  line-height:1.12;letter-spacing:-.02em;
}
.song-sub{margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:13.5px;color:var(--ink-3)}
.song-sub a{color:var(--accent)}
.song-sub .sep{opacity:.5}
.song-tags{display:flex;gap:6px;flex-wrap:wrap;margin:16px 0 0}
.pill{font-size:11.5px;padding:3px 9px;border-radius:99px;background:var(--weak);color:var(--accent)}
.pill-tag{background:transparent;border:1px solid var(--line);color:var(--ink-3)}
#songAudio{width:100%;margin:22px 0 0;height:44px;accent-color:var(--accent)}
.song-actions{display:flex;gap:9px;flex-wrap:wrap;margin:18px 0 0}
.note-sec{border-top:1px solid var(--line);padding:28px 0 6px}
.note-sec h2,.related h2{margin:0 0 14px;font-family:var(--serif);font-size:20px}
.note-sec p{margin:0 0 14px;font-size:15.5px;line-height:1.95;color:var(--ink-2);max-width:70ch}
.note-sec p.muted{color:var(--ink-3)}
.note-foot{font-size:12.3px!important;color:var(--ink-3)!important;border-top:1px dashed var(--line);padding-top:14px;margin-top:22px!important}
.pager{display:flex;gap:12px;border-top:1px solid var(--line);padding:20px 0 4px}
.pager-a{
  flex:0 1 48%;min-width:0;display:flex;flex-direction:column;gap:2px;padding:12px 14px;
  border:1px solid var(--line);border-radius:12px;background:var(--card);
  transition:border-color .18s,transform .18s;
}
.pager-a:hover{border-color:var(--line-2);transform:translateY(-1px)}
.pager-a span{font-size:11px;color:var(--ink-3);letter-spacing:.08em}
.pager-a b{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pager-next{text-align:right;align-items:flex-end;margin-left:auto}
.related{padding-top:34px}
.related ul{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.related a{
  display:flex;align-items:center;gap:11px;padding:10px;border:1px solid var(--line);
  border-radius:13px;background:var(--card);transition:border-color .18s,transform .18s;
}
.related a:hover{border-color:var(--line-2);transform:translateY(-1px)}
.related-art{width:44px;height:44px;border-radius:10px;flex:none;background-size:cover;background-position:center;display:grid;place-items:center}
.related-art i{font-style:normal;color:#fff;font-weight:700}
.related-t{min-width:0;display:flex;flex-direction:column;line-height:1.4}
.related-t b{font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.related-t small{font-size:11px;color:var(--ink-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* 焦点 */
a:focus-visible,button:focus-visible,audio:focus-visible{
  outline:2px solid var(--accent);outline-offset:3px;border-radius:6px;
}

@media (max-width:900px){
  .hero{grid-template-columns:1fr;gap:28px;padding-top:44px}
  .hero-art{height:260px;max-width:420px;margin:0 auto;width:100%}
  .about-grid{grid-template-columns:1fr;gap:26px}
  .song-hero{grid-template-columns:minmax(0,1fr);gap:24px}
  .song-cover{max-width:320px}
  .related ul{grid-template-columns:1fr}
}
@media (max-width:720px){
  .ph-in{padding:0 14px;height:58px;gap:8px}
  .brand{gap:9px;min-width:0;flex:1}
  .brand-t small{display:none}
  .brand-t b{font-size:14px}
  .ph-nav{display:none}
  .ph-tools{gap:5px;flex:none}
  .tool{height:32px;padding:0 9px;white-space:nowrap;flex:none;font-size:12px}
  .tool-ico{width:32px;padding:0}
  .hero{padding:36px 18px 24px}
  .hero h1{font-size:clamp(34px,9vw,48px)}
  .hero-art{height:230px;max-width:330px}
  .hero-stats{gap:14px;justify-content:space-between}
  .hero-stats dd{font-size:14.5px}
  .hero-actions{gap:8px;margin-top:22px}
  .btn{height:42px;padding:0 15px;font-size:13.5px}
  .sec{padding:20px 18px 32px}
  .song{grid-template-columns:46px minmax(0,1fr) auto;gap:12px;padding:16px 2px}
  .song-num{display:none}
  .song-art{width:46px;height:46px;border-radius:11px}
  .song-body h3{font-size:15.8px}
  .song-note{-webkit-line-clamp:4;font-size:13.4px}
  .song-act{flex-direction:column;align-items:flex-end;gap:2px}
  .more{display:none}
  .play-dot{width:34px;height:34px}
  .song-main{padding:24px 18px 40px}
  .song-cover{max-width:100%}
  .pf-in{padding:22px 18px 34px}
  .pf-nav{margin-left:0;width:100%}
}
@media (prefers-reduced-motion:reduce){
  *{animation:none!important;transition:none!important}
  html{scroll-behavior:auto}
}
@media print{
  .ph,.pf,.hero-art,.song-act,.pager{display:none}
  body{background:#fff;color:#000}
}
CSS;
}
