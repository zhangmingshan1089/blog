<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/* ============================================================
   页面上下文 / 导航状态
   ============================================================ */
$isHome   = $this->is('index');
$cssVer   = '22';            // 破缓存版本号：改样式后 +1
$wbOpt    = $this->options;
$wbBase   = rtrim((string)$wbOpt->siteUrl, '/');
$siteName = trim((string)$wbOpt->title);
$siteDesc = trim((string)$wbOpt->description);

/* 品牌：把「MC.跃小叮当的博客」拆成 wordmark + 尾巴，移动端只留 wordmark */
$brand     = wbclean_brand($siteName);
$brandMain = $brand['main'];
$brandTail = $brand['tail'];

/* 导航高亮判定（按 slug 精确匹配，避免所有独立页面都点亮「关于」） */
$wbPath  = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$slugNow = '';
try { $slugNow = trim((string)$this->slug); } catch (\Throwable $e) {}
$navOn = [
    'posts'     => ($slugNow === 'archives')
                   || $this->is('category') || $this->is('tag') || $this->is('search') || $this->is('post'),
    'moments'   => strpos($wbPath, '/moments') === 0,
    'drive'     => strpos($wbPath, '/drive') === 0,
    'music'     => strpos($wbPath, '/music') === 0,
    'timeline'  => strpos($wbPath, '/timeline') === 0,
    'links'     => ($slugNow === 'links'),
    'about'     => ($slugNow === 'about'),
    'guestbook' => ($slugNow === 'guestbook'),
];

/* ============================================================
   SEO：title / description / canonical / 索引策略 / OG / 结构化数据
   ============================================================ */
$pageTitle = $siteName;
$metaDesc  = $siteDesc !== '' ? $siteDesc : $siteName . '：记录、存档与分享';
$canonical = $wbBase . '/';
$noindex   = false;
$ogType    = 'website';
$ogImage   = wbclean_og_image();
$pubTime   = '';
$modTime   = '';
$jsonld    = '';

if ($isHome) {
    $pageTitle = $siteName . ($siteDesc !== '' ? ' · ' . $siteDesc : '');
    if (preg_match('#/page/\d+#', $wbPath)) $noindex = true;
} elseif ($this->is('post')) {
    $pt        = trim((string)$this->title);
    $pageTitle = $pt . ' · ' . $siteName;
    $plain     = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$this->text)));
    if ($plain !== '') $metaDesc = mb_substr($plain, 0, 110, 'UTF-8');
    $canonical = (string)$this->permalink;
    $ogType    = 'article';
    $pubTime   = date('c', (int)$this->created);
    $modTime   = date('c', (int)($this->modified ?: $this->created));
    $jsonld    = [
        '@context'      => 'https://schema.org',
        '@type'         => 'BlogPosting',
        'headline'      => $pt,
        'description'   => $metaDesc,
        'datePublished' => $pubTime,
        'dateModified'  => $modTime,
        'inLanguage'    => 'zh-CN',
        'author'        => ['@type' => 'Person', 'name' => (string)$this->author->screenName],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
        'image'         => $ogImage,
    ];
} elseif ($this->is('page')) {
    $pt        = trim((string)$this->title);
    $pageTitle = $pt . ' · ' . $siteName;
    $canonical = (string)$this->permalink;
    if ($slugNow === 'archives') {
        $metaDesc = '全部文章：按年份与月份归档，可按分类与标签筛选，最新的排在最前面。';
    } elseif ($slugNow === 'about') {
        $metaDesc = '关于这个站和写字的人：写什么、多久更一次、怎么订阅、怎么联系。';
    } elseif ($slugNow === 'links') {
        $metaDesc = '友链：Floyd の博客、星穹旅驿——一个记自建部署与排障现场，一个是本站所在机器的生存服官网。';
    } elseif ($slugNow === 'guestbook') {
        $metaDesc = '留言板：不用注册，留个名字就能说话。这里不即时回复，慢慢看。';
    }
} elseif ($this->is('category') || $this->is('tag')) {
    $noindex = true;
    $pageTitle = trim(strip_tags((string)$this->archiveTitle([
        'category' => _t('分类：%s'),
        'tag'      => _t('标签：%s'),
        'author'   => _t('作者：%s'),
    ], '', '', false))) . ' · ' . $siteName;
} elseif ($this->is('search')) {
    $noindex   = true;
    $pageTitle = '搜索 · ' . $siteName;
} elseif ($this->is('archive')) {
    $pageTitle = '全部文章 · ' . $siteName;
    $canonical = $wbBase . '/index.php/archives.html';
}

$ogTitle = $pageTitle;

/* 首屏文案：默认值改了，旧默认值视为未设置 */
$heroT = trim((string)($this->options->heroTitle ?? ''));
if ($heroT === '' || in_array($heroT, ['记录 · 存档 · 分享', '记录·存档·分享'], true)) {
    $heroT = '写在自己的地方';
}
$heroS = trim((string)($this->options->heroSub ?? ''));
if ($heroS === '' || in_array($heroS, ['把想留住的，都留在这里', '写在自己的地方'], true)) {
    $heroS = '记录、存档与分享 —— 挑一个入口开始吧。';
}
ob_start();   // 整页缓冲：footer 结尾统一落盘做页面缓存
?><!DOCTYPE html>
<html lang="zh-CN" data-style="tech" data-theme="light">
<head>
    <meta charset="<?php $this->options->charset(); ?>">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <script>(function(){try{
  /* 三套风格：tech = 全屏封面 + 弹幕，paper = 纸质档案，butterfly = 卡片流 + 侧栏。
     首次来访 = tech（全屏封面 + 弹幕）+ 白天模式；访客自己切过就永久记住。 */
  var s,t,fresh=false;
  try{
    /* wb-def2：默认配置升级标记。每人只重置一次，之后完全尊重访客的选择 */
    if(!localStorage.getItem('wb-def2')){
      localStorage.setItem('wb-def2','1');
      localStorage.setItem('wb-style','tech');
      localStorage.setItem('wb-theme','light');
      fresh=true;
    }
  }catch(e){}
  s=fresh?'tech':localStorage.getItem('wb-style');
  if(s!=='paper'&&s!=='tech'&&s!=='butterfly') s='tech';
  t=fresh?'light':localStorage.getItem('wb-theme');
  /* 默认白天模式，不跟系统走（iOS 夜间自动切深色会让首屏变黑）；访客手动切过才用他选的 */
  if(t!=='light'&&t!=='dark') t='light';
  var d=document.documentElement;
  d.setAttribute('data-style',s); d.setAttribute('data-theme',t);
  /* 省流 / 2G·3G / 低内存：关掉大背景动画，别让首屏和滚动掉帧 */
  try{
    var c=navigator.connection||{};
    if(c.saveData||(c.effectiveType&&/2g|3g/.test(c.effectiveType))||(navigator.deviceMemory&&navigator.deviceMemory<=2)) d.classList.add('wb-lite');
  }catch(e){}
  /* 动效开关：系统「减少动态效果」时不加这个类，入场动画退回静态 */
  if(!(window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches)) d.classList.add('wb-motion');
}catch(e){}})();</script>
    <meta name="theme-color" content="#f4f6f9">

    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($noindex): ?>
    <meta name="robots" content="noindex, follow">
    <?php else: ?>
    <meta name="robots" content="index, follow, max-image-preview:large">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>

    <meta property="og:site_name" content="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:type" content="<?php echo $ogType; ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($ogTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:locale" content="zh_CN">
    <?php if ($pubTime): ?>
    <meta property="article:published_time" content="<?php echo $pubTime; ?>">
    <meta property="article:modified_time" content="<?php echo $modTime; ?>">
    <?php endif; ?>
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="<?php $this->options->themeUrl('assets/favicon-64.png'); ?>" type="image/png" sizes="64x64">
    <link rel="apple-touch-icon" href="<?php $this->options->themeUrl('assets/apple-touch-180.png'); ?>">
    <link rel="sitemap" type="application/xml" href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/sitemap.xml">

    <?php $bgData = wbclean_bg_data(); ?>
    <?php if ($isHome): ?>
        <?php if (!$bgData['custom']): ?>
        <link rel="preload" as="image" type="image/webp"
              href="<?php echo htmlspecialchars($bgData['webp'], ENT_QUOTES); ?>"
              imagesrcset="<?php echo htmlspecialchars($bgData['srcset'], ENT_QUOTES); ?>" imagesizes="100vw"
              fetchpriority="high">
        <?php else: ?>
        <link rel="preload" as="image" href="<?php echo htmlspecialchars($bgData['src'], ENT_QUOTES); ?>" fetchpriority="high">
        <?php endif; ?>
    <?php endif; ?>

    <?php
    /* 样式内联：跨境访问时 RTT 很贵，CSS 又是阻塞渲染的。
       直接嵌进 HTML，省一次往返；失败时回退到外链。 */
    $wbCssFile = __DIR__ . '/style.css';
    /* 应急精简模式：带 ?lite=1 时不内联 style.css（省 82KB 主文档体积），
       改成 preload + 外链。用于跨境链路不稳、大页面被掐断时兜底。
       正常访问仍走内联，首屏更快。 */
    $wbLite = isset($_GET['lite']);
    if (!$wbLite && is_readable($wbCssFile)) {
        echo '<style id="wb-css">' . file_get_contents($wbCssFile) . '</style>';
    } else {
        /* 注意：themeUrl() 在 Typecho 里是「echo 型」方法，直接调用会把 URL 输出到页面上
           （精简模式顶部那串裸网址就是它干的）。这里改成用 siteUrl 拼，无副作用。 */
        $cssBase = rtrim((string)$this->options->siteUrl, '/') . '/usr/themes/wbclean';
        $cssHref = htmlspecialchars($cssBase . '/style.css?v=' . $cssVer, ENT_QUOTES);
        echo '<link rel="preload" as="style" href="' . $cssHref . '">';
        echo '<link rel="stylesheet" href="' . $cssHref . '">';
    }
    ?>
    <?php
    /* 第三套「蝴蝶卡片」样式：只在切到 butterfly 时生效，用 media=print 异步加载，
       不阻塞首屏；切换时若刚好没下完，最差是一次样式跳变，不影响功能。
       注意：options->themeUrl 是 echo 型方法，取字符串要用属性。 */
    $bfCss = rtrim((string)$this->options->themeUrl, '/') . '/butterfly.css';
    ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($bfCss, ENT_QUOTES); ?>?v=<?php echo $cssVer; ?>"
          media="print" onload="this.media='all';this.onload=null">
    <noscript><link rel="stylesheet" href="<?php echo htmlspecialchars($bfCss, ENT_QUOTES); ?>?v=<?php echo $cssVer; ?>"></noscript>
    <link rel="alternate" type="application/rss+xml" title="<?php echo htmlspecialchars($siteName, ENT_QUOTES); ?> RSS" href="<?php $this->options->feedUrl(); ?>">

    <?php if ($jsonld): ?>
    <script type="application/ld+json"><?php echo json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <?php elseif ($isHome): ?>
    <script type="application/ld+json"><?php echo json_encode([
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => $siteName,
        'url'      => $wbBase . '/',
        'inLanguage' => 'zh-CN',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
    <?php endif; ?>

    <?php
    /* Typecho 自带的 description 会用正文摘要再输出一条，和我们算好的 metaDesc 重复，这里剔掉 */
    ob_start();
    $this->header();
    $wbHead = (string)ob_get_clean();
    echo preg_replace('#<meta\s+name="description"[^>]*>\s*#i', '', $wbHead);
    ?>
</head>
<body class="<?php echo $isHome ? 'is-home' : 'is-inner'; ?>">

<a class="skip-link" href="#main">跳到正文</a>

<header class="topbar" id="topbar">
    <div class="topbar-inner">
        <a class="brand" href="<?php $this->options->siteUrl(); ?>" aria-label="<?php echo htmlspecialchars($siteName, ENT_QUOTES); ?> 首页">
            <?php
        $__avSmall = wbclean_avatar();
        $__avSmallF = __DIR__ . '/assets/avatar-72.webp';
        if (strpos($__avSmall, 'assets/avatar') !== false && is_readable($__avSmallF)) {
            $__avSmall = 'data:image/webp;base64,' . base64_encode((string)file_get_contents($__avSmallF));
        }
        ?>
        <img class="brand-avatar" src="<?php echo htmlspecialchars($__avSmall, ENT_QUOTES); ?>" alt="" width="30" height="30" decoding="async">
            <span class="brand-mark"><?php echo htmlspecialchars($brandMain, ENT_QUOTES); ?></span>
            <span class="brand-tail"><?php echo htmlspecialchars($brandTail, ENT_QUOTES); ?></span>
        </a>

        <?php if (!$isHome): ?>
        <a class="home-fab" href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/" aria-label="返回首页" title="返回首页">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10.6 12 3.6l8.5 7"/><path d="M5.6 9.6V20h12.8V9.6"/></svg>
        </a>
        <?php endif; ?>

        <nav class="nav" id="nav" aria-label="主导航">
            <?php if (!$isHome): ?>
            <a class="nav-home" href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10.6 12 3.6l8.5 7"/><path d="M5.6 9.6V20h12.8V9.6"/></svg>
                <span><?php _e('首页'); ?></span>
            </a>
            <?php endif; ?>
            <?php if (function_exists('wb_is_owner') && wb_is_owner()): ?>
            <a class="nav-write" href="/publish/">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19.2 8.8a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="m14.6 7.4 2 2"/></svg>
                <span><?php _e('写一条'); ?></span>
            </a>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/index.php/archives.html" class="<?php echo $navOn['posts'] ? 'active' : ''; ?>"><?php _e('文章'); ?></a>
            <a href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/moments/" class="<?php echo $navOn['moments'] ? 'active' : ''; ?>"><?php _e('朋友圈'); ?></a>
            <a href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/drive/" class="<?php echo $navOn['drive'] ? 'active' : ''; ?>"><?php _e('云盘'); ?></a>
            <a href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/music/" class="<?php echo $navOn['music'] ? 'active' : ''; ?>"><?php _e('音乐'); ?></a>
            <a href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/index.php/links.html" class="<?php echo $navOn['links'] ? 'active' : ''; ?>"><?php _e('友链'); ?></a>
            <a href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/index.php/about.html" class="<?php echo $navOn['about'] ? 'active' : ''; ?>"><?php _e('关于'); ?></a>
            <a href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/index.php/guestbook.html" class="<?php echo $navOn['guestbook'] ? 'active' : ''; ?>"><?php _e('留言'); ?></a>
        </nav>

        <?php if (function_exists('wb_is_owner') && wb_is_owner()): ?>
        <a class="write-btn" href="/publish/" aria-label="写一条" title="写一条">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19.2 8.8a2.1 2.1 0 0 0-3-3L5 17v3z"/><path d="m14.6 7.4 2 2"/></svg>
        </a>
        <?php endif; ?>
        <button class="style-btn" id="styleBtn" type="button" aria-label="切换界面风格：全屏封面 / 纸质档案 / 蝴蝶卡片" title="切换界面风格">
            <span class="i-paper" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3.5h9.2L19 8.3V20.5H5z"/><path d="M14 3.7V8.4h4.7"/><path d="M8 12.5h8M8 16h5.5"/></svg>
            </span>
            <span class="i-tech" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2v17.6M3.2 12h17.6"/><path d="M6.4 6.4l11.2 11.2M17.6 6.4L6.4 17.6" opacity=".55"/></svg>
            </span>
            <span class="i-butterfly" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.2v12.2"/><path d="M12 9.2C10.4 6.6 8 4.6 6.3 5.1 4.3 5.7 3.7 8.6 4.6 10.9c.8 2.1 3 3.2 5.2 2.9 1-.1 1.8-.6 2.2-1.3"/><path d="M12 9.2c1.6-2.6 4-4.6 5.7-4.1 2 .6 2.6 3.5 1.7 5.8-.8 2.1-3 3.2-5.2 2.9-1-.1-1.8-.6-2.2-1.3"/><path d="M12 14.6c-1.5 1.9-3.5 3.3-4.8 3-1.4-.3-1.9-2.2-1.3-3.8M12 14.6c1.5 1.9 3.5 3.3 4.8 3 1.4-.3 1.9-2.2 1.3-3.8"/></svg>
            </span>
        </button>
        <button class="theme-btn" id="themeBtn" type="button" aria-label="切换深浅色" title="切换深浅色">
            <span class="i-moon" aria-hidden="true">☾</span>
            <span class="i-sun" aria-hidden="true">☀</span>
        </button>
        <button class="nav-toggle" id="navToggle" type="button" aria-label="展开菜单" aria-expanded="false" aria-controls="nav">☰</button>
    </div>
</header>
<div class="nav-scrim" id="navScrim" hidden></div>

<?php if ($isHome): ?>
<!-- ============ 风格 C：蝴蝶卡片（Butterfly） ============ -->
<section class="bf-banner" id="bfBanner" aria-label="站点介绍">
    <div class="bf-banner-bg" aria-hidden="true">
        <?php if (!$bgData['custom']): ?>
        <picture>
            <source type="image/webp" srcset="<?php echo htmlspecialchars($bgData['srcset'], ENT_QUOTES); ?>" sizes="100vw">
            <img src="<?php echo htmlspecialchars($bgData['src'], ENT_QUOTES); ?>" alt="" fetchpriority="high" decoding="async">
        </picture>
        <?php else: ?>
        <img src="<?php echo htmlspecialchars($bgData['src'], ENT_QUOTES); ?>" alt="" fetchpriority="high" decoding="async">
        <?php endif; ?>
    </div>

    <div class="bf-banner-in">
        <?php
        $__bfAv = wbclean_avatar();
        $__bfF  = __DIR__ . '/assets/avatar-176.webp';
        if (strpos($__bfAv, 'assets/avatar') !== false && is_readable($__bfF)) {
            $__bfAv = 'data:image/webp;base64,' . base64_encode((string)file_get_contents($__bfF));
        }
        ?>
        <img class="bf-banner-avatar" src="<?php echo htmlspecialchars($__bfAv, ENT_QUOTES); ?>"
             alt="<?php echo htmlspecialchars($siteName, ENT_QUOTES); ?>" width="92" height="92" decoding="async">
        <h1 class="bf-banner-title"><?php echo htmlspecialchars($heroT, ENT_QUOTES); ?></h1>
        <p class="bf-banner-sub" data-bf-text="<?php echo htmlspecialchars($heroS, ENT_QUOTES); ?>"><span class="bf-typing"><?php echo htmlspecialchars($heroS, ENT_QUOTES); ?></span><i class="bf-caret" aria-hidden="true"></i></p>
        <div class="bf-banner-meta">
            <span><?php echo (int)wbclean_post_count(); ?> 篇文章</span>
            <i aria-hidden="true"></i>
            <span><?php echo date('Y'); ?> 年起</span>
            <i aria-hidden="true"></i>
            <span>仍在写</span>
        </div>
        <div class="bf-banner-cta">
            <a class="bf-btn" href="#main"><?php _e('开始阅读'); ?></a>
            <a class="bf-btn ghost" href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/index.php/archives.html"><?php _e('全部文章'); ?></a>
        </div>
    </div>

    <a class="bf-scroll" href="#main" aria-label="向下滚动看内容"></a>
</section>

<!-- ============ 风格 A：纸质档案 ============ -->
<section class="hero hero-paper" id="hero">
    <div class="hero-in">
        <div class="hero-copy">
            <p class="hero-kicker">Welcome</p>
            <h1 class="hero-title"><?php echo htmlspecialchars($heroT, ENT_QUOTES); ?></h1>
            <p class="hero-sub"><?php echo htmlspecialchars($heroS, ENT_QUOTES); ?></p>
            <p class="hero-meta">
                <span><?php echo date('Y'); ?> 年起</span>
                <i aria-hidden="true"></i>
                <span><?php echo (int)wbclean_post_count(); ?> 篇文章</span>
                <i aria-hidden="true"></i>
                <span>仍在写</span>
            </p>
        </div>

        <figure class="hero-photo">
            <?php if (!$bgData['custom']): ?>
            <img src="<?php echo htmlspecialchars($bgData['webp'], ENT_QUOTES); ?>"
                 srcset="<?php echo htmlspecialchars($bgData['srcset'], ENT_QUOTES); ?>" sizes="340px"
                 alt="本站封面" width="340" height="255" fetchpriority="high" decoding="async">
            <?php else: ?>
            <img src="<?php echo htmlspecialchars($bgData['src'], ENT_QUOTES); ?>" alt="本站封面" width="340" height="255" fetchpriority="high" decoding="async">
            <?php endif; ?>
            <figcaption>某个下午，随手拍的</figcaption>
        </figure>
    </div>

    <a class="hero-scroll" href="#recent">往下翻，看最近写的<span class="arw" aria-hidden="true">↓</span></a>
</section>

<!-- ============ 风格 B：全屏封面 + 弹幕（原来那套） ============ -->
<section class="hero-tech" id="heroTech">
    <picture class="ht-bg" id="heroBg">
        <?php if (!$bgData['custom']): ?>
        <source type="image/webp" srcset="<?php echo htmlspecialchars($bgData['srcset'], ENT_QUOTES); ?>" sizes="100vw">
        <?php endif; ?>
        <img src="<?php echo htmlspecialchars($bgData['src'], ENT_QUOTES); ?>" alt="" fetchpriority="high" decoding="async">
    </picture>
    <div class="ht-shade"></div>

    <div class="dm-layer" id="dmLayer" aria-hidden="true"></div>

    <div class="ht-inner">
        <?php
        /* 默认头像内联成 data URI：省一次请求，也避免首屏「先空后图」的闪 */
        $__avUrl = wbclean_avatar();
        $__avFile = __DIR__ . '/assets/avatar-176.webp';
        if (strpos($__avUrl, 'assets/avatar') !== false && is_readable($__avFile)) {
            $__avUrl = 'data:image/webp;base64,' . base64_encode((string)file_get_contents($__avFile));
        }
        ?>
        <img class="ht-avatar" src="<?php echo htmlspecialchars($__avUrl, ENT_QUOTES); ?>"
             alt="<?php echo htmlspecialchars($siteName, ENT_QUOTES); ?>" width="88" height="88" decoding="async">
        <h1 class="ht-title"><?php echo htmlspecialchars($heroT, ENT_QUOTES); ?></h1>
        <p class="ht-sub"><?php echo htmlspecialchars($heroS, ENT_QUOTES); ?></p>
        <div class="ht-cta">
            <a class="btn btn-hero" href="#hub"><?php _e('随便逛逛'); ?></a>
            <a class="btn btn-hero ghost" href="<?php echo htmlspecialchars($wbBase, ENT_QUOTES); ?>/index.php/archives.html"><?php _e('看全部文章'); ?></a>
        </div>
    </div>

    <form class="dm-form" id="dmForm" autocomplete="off">
        <label class="sr-only" for="dmText">发一条弹幕</label>
        <input type="text" name="text" id="dmText" maxlength="40" placeholder="发条弹幕，让它飘过封面…">
        <button type="submit" id="dmSend">发送</button>
    </form>

    <a class="ht-scroll" href="#hub" aria-label="向下滚动看内容"><span aria-hidden="true">↓</span></a>
</section>
<?php endif; ?>

<main class="main" id="main">
    <div class="wrap">
