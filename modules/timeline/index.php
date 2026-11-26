<?php
/**
 * 时间轴 · 单文件
 * 文章与手工记录合并成一条真轴：按年月分组、左侧竖线 + 圆点。
 * 记录数据：data.json　管理：?act=admin（密码同云盘）
 */
declare(strict_types=1);
session_name('ZMS_TIMELINE_SESS');
session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');

const T_DATA  = __DIR__ . '/data.json';
const T_PWD   = '20100809aaa';
const T_MAX   = 500;

function t_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function t_base(): string { return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/timeline/index.php')), '/'); }
function t_load(): array {
    if (!is_file(T_DATA)) return [];
    $d = json_decode((string)@file_get_contents(T_DATA), true);
    return is_array($d) ? $d : [];
}
function t_save(array $d): bool {
    $tmp = T_DATA . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    return @rename($tmp, T_DATA);
}
function t_pwd_ok(string $p): bool {
    foreach ([__DIR__ . '/.passwd', __DIR__ . '/../drive/.passwd'] as $f) {
        if (is_file($f)) {
            $v = trim((string)@file_get_contents($f));
            if ($v === '') continue;
            return str_starts_with($v, '$2') ? password_verify($p, $v) : hash_equals($v, $p);
        }
    }
    return hash_equals(T_PWD, $p);
}
function t_logged(): bool { return !empty($_SESSION['t_logged']); }
function t_csrf(): string {
    if (empty($_SESSION['t_csrf'])) $_SESSION['t_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['t_csrf'];
}
function t_csrf_ok(): bool {
    return hash_equals((string)($_SESSION['t_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''));
}
function t_sort(array $d): array {
    usort($d, function ($a, $b) {
        $x = (string)($a['date'] ?? ''); $y = (string)($b['date'] ?? '');
        if ($x === $y) return strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
        return strcmp($y, $x);
    });
    return $d;
}

/** Typecho 文章（读不到就只要手工记录，页面不崩） */
function t_posts(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    $cfg = dirname(__DIR__) . '/config.inc.php';
    if (!is_file($cfg)) return $cache;
    $old = error_reporting(0);
    try {
        if (!defined('__TYPECHO_ROOT_DIR__')) require_once $cfg;
        if (!class_exists('\Typecho\Db')) return $cache;
        $db = \Typecho\Db::get();
        if (!$db) return $cache;
        $rows = $db->fetchAll($db->select('cid', 'title', 'created', 'text')
            ->from('table.contents')
            ->where('type = ?', 'post')->where('status = ?', 'publish')
            ->order('created', \Typecho\Db::SORT_DESC)->limit(200));
        foreach ($rows as $r) {
            $txt = preg_replace('/\s+/u', ' ', strip_tags((string)($r['text'] ?? ''))) ?? '';
            $cache[] = [
                'cid'   => (int)$r['cid'],
                'title' => trim((string)$r['title']) !== '' ? (string)$r['title'] : '无标题',
                'time'  => (int)$r['created'],
                'brief' => mb_substr(trim($txt), 0, 110, 'UTF-8'),
                'url'   => '/index.php/archives/' . (int)$r['cid'] . '/',
            ];
        }
    } catch (\Throwable $e) {
    } finally {
        error_reporting($old);
    }
    return $cache;
}

$act  = (string)($_REQUEST['act'] ?? '');
$base = t_base();
$err  = '';

/* ---------- 登录 ---------- */
if ($act === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $pwd = (string)($_POST['pwd'] ?? '');
    $_SESSION['t_fail'] = (int)($_SESSION['t_fail'] ?? 0);
    if ($_SESSION['t_fail'] >= 8) { $err = '尝试次数过多，请稍后再试'; }
    elseif (t_pwd_ok($pwd)) { $_SESSION['t_logged'] = true; $_SESSION['t_fail'] = 0; header('Location: ' . $base . '/?act=admin'); exit; }
    else { $_SESSION['t_fail']++; $err = '密码错误'; }
}
if ($act === 'logout') { $_SESSION['t_logged'] = false; header('Location: ' . $base . '/'); exit; }

/* ---------- 保存 ---------- */
if ($act === 'save' && t_logged() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!t_csrf_ok()) { $err = '表单已过期，请刷新重试'; }
    else {
        $date  = trim((string)($_POST['date'] ?? ''));
        $title = trim((string)($_POST['title'] ?? ''));
        $desc  = trim((string)($_POST['desc'] ?? ''));
        $tag   = trim((string)($_POST['tag'] ?? ''));
        $id    = trim((string)($_POST['id'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $date)) $err = '日期格式应为 2026-11 或 2026-11-24';
        elseif ($title === '') $err = '标题不能为空';
        else {
            $d = t_load();
            $row = [
                'id'    => preg_match('/^[a-f0-9]{12}$/', $id) ? $id : bin2hex(random_bytes(6)),
                'date'  => $date,
                'title' => mb_substr($title, 0, 120, 'UTF-8'),
                'desc'  => mb_substr($desc, 0, 1000, 'UTF-8'),
                'tag'   => mb_substr($tag, 0, 20, 'UTF-8'),
            ];
            $hit = false;
            foreach ($d as $i => $v) { if (($v['id'] ?? '') === $row['id']) { $d[$i] = $row; $hit = true; break; } }
            if (!$hit) $d[] = $row;
            $d = t_sort($d);
            if (count($d) > T_MAX) $d = array_slice($d, 0, T_MAX);
            if (t_save($d)) { header('Location: ' . $base . '/?act=admin&ok=1'); exit; }
            $err = '保存失败，请检查 timeline 目录权限';
        }
    }
}

/* ---------- 删除 ---------- */
if ($act === 'delete' && t_logged() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (t_csrf_ok()) {
        $id = (string)($_POST['id'] ?? '');
        $d = array_values(array_filter(t_load(), fn($r) => ($r['id'] ?? '') !== $id));
        t_save($d);
    }
    header('Location: ' . $base . '/?act=admin'); exit;
}

$notes = t_sort(t_load());
$posts = t_posts();
$edit  = null;
if ($act === 'admin' && !empty($_GET['edit'])) {
    foreach ($notes as $r) { if (($r['id'] ?? '') === $_GET['edit']) { $edit = $r; break; } }
}
$err = $err ?? '';
$isAdmin = ($act === 'admin');

/* ---------- 合并成一条轴 ---------- */
$items = [];
foreach ($posts as $p) {
    $items[] = [
        'date'  => date('Y-m-d', $p['time']),
        'title' => $p['title'],
        'desc'  => $p['brief'],
        'tag'   => '文章',
        'kind'  => 'post',
        'url'   => $p['url'],
        'nid'   => '',
    ];
}
foreach ($notes as $r) {
    $items[] = [
        'date'  => (string)($r['date'] ?? ''),
        'title' => (string)($r['title'] ?? ''),
        'desc'  => (string)($r['desc'] ?? ''),
        'tag'   => (string)($r['tag'] ?? ''),
        'kind'  => 'note',
        'url'   => '',
        'nid'   => (string)($r['id'] ?? ''),
    ];
}
usort($items, function ($a, $b) {
    $x = $a['date'] . (strlen($a['date']) === 7 ? '-00' : '');
    $y = $b['date'] . (strlen($b['date']) === 7 ? '-00' : '');
    return strcmp($y, $x);
});

$countPost = count($posts);
$countNote = count($notes);
$countAll  = $countPost + $countNote;

$years = [];
foreach ($items as $it) {
    $y = substr($it['date'], 0, 4);
    $m = strlen($it['date']) >= 7 ? (int)substr($it['date'], 5, 2) : 0;
    $years[$y][$m][] = $it;
}
?><!DOCTYPE html>
<html lang="zh-CN" data-style="tech" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<script>(function(){try{var t=localStorage.getItem('wb-theme');if(t!=='light'&&t!=='dark'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}var d=document.documentElement,d2=localStorage.getItem('wb-style');if(d2!=='paper'&&d2!=='tech')d2='tech';d.setAttribute('data-theme',t);d.setAttribute('data-style',d2);}catch(e){}})();</script>
<title>时间轴 · MC.跃小叮当的博客</title>
<meta name="description" content="按年月排下来的记录：写过什么、什么时候。文章与随手记都在这一条轴上。">
<link rel="canonical" href="https://zhangmingshan.wwh.us.ci/timeline/">
<meta name="robots" content="<?php echo $isAdmin ? 'noindex, nofollow' : 'index, follow'; ?>">
<meta property="og:site_name" content="MC.跃小叮当的博客">
<meta property="og:type" content="website">
<meta property="og:title" content="时间轴 · MC.跃小叮当的博客">
<meta property="og:description" content="按年月排下来的记录。">
<meta property="og:url" content="https://zhangmingshan.wwh.us.ci/timeline/">
<meta property="og:image" content="https://zhangmingshan.wwh.us.ci/usr/themes/wbclean/assets/og-cover.jpg">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/usr/themes/wbclean/assets/favicon-64.png" type="image/png">
<link rel="alternate" type="application/rss+xml" title="MC.跃小叮当的博客 RSS" href="/index.php/feed/">
<style>
:root{
  --paper:#FAF9F6;--paper-2:#F4F2EB;--paper-3:#EDEAE0;--card:#FFF;
  --ink:#2B2B2B;--ink-2:#56534C;--ink-3:#8A867D;--rule:#E5E1D8;--rule-2:#D6D0C2;
  --accent:#3F6B5B;--accent-2:#33574A;--accent-weak:#E9EFEA;
  --serif:"Noto Serif SC","Noto Serif CJK SC","Songti SC","STSong","SimSun",Georgia,serif;
  --sans:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
  --topbar-h:58px;
}
html[data-theme="dark"]{
  --paper:#1B1B19;--paper-2:#222220;--paper-3:#2A2A27;--card:#262623;
  --ink:#E8E5DE;--ink-2:#BDB8AC;--ink-3:#8A867D;--rule:#33322E;--rule-2:#46443D;
  --accent:#8FB6A3;--accent-2:#A9C8B8;--accent-weak:#242E29;
}
*{box-sizing:border-box}
body{margin:0;font-family:var(--sans);font-size:15.5px;line-height:1.85;background:var(--paper);color:var(--ink);-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
img{max-width:100%}
button{font:inherit}

.topbar{position:fixed;top:0;left:0;right:0;z-index:50;height:var(--topbar-h);background:var(--paper);border-bottom:1px solid transparent}
.topbar.scrolled{border-bottom-color:var(--rule)}
.topbar-inner{max-width:1000px;height:100%;margin:0 auto;padding:0 24px;display:flex;align-items:center;gap:18px}
.brand{display:inline-flex;align-items:baseline;gap:6px;white-space:nowrap}
.brand-mark{font-family:var(--serif);font-size:15px;font-weight:600;letter-spacing:.04em}
.brand-tail{font-size:11.5px;color:var(--ink-3)}
.nav{display:flex;align-items:center;gap:2px;margin-left:auto}
.nav a{position:relative;font-size:13.5px;line-height:1;padding:9px 11px;color:var(--ink-2);white-space:nowrap}
.nav a::after{content:'';position:absolute;left:11px;right:11px;bottom:2px;height:1px;background:var(--accent);transform:scaleX(0);transform-origin:left;transition:transform .2s}
.nav a:hover{color:var(--ink)}
.nav a:hover::after,.nav a.on::after{transform:scaleX(1)}
.nav a.on{color:var(--accent)}
.theme-btn{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--rule);border-radius:50%;background:transparent;color:var(--ink-2);font-size:14px;cursor:pointer}
.theme-btn .i-sun{display:none}
html[data-theme="dark"] .theme-btn .i-moon{display:none}
html[data-theme="dark"] .theme-btn .i-sun{display:inline}
.nav-toggle{display:none;width:34px;height:34px;border:1px solid var(--rule);border-radius:50%;background:transparent;color:var(--ink-2);cursor:pointer}

.wrap{max-width:760px;margin:0 auto;padding:0 24px}
.tl-head{padding:calc(var(--topbar-h) + 54px) 0 22px;margin-bottom:30px;border-bottom:1px solid var(--rule)}
.tl-kicker{font-size:10.5px;letter-spacing:.26em;text-transform:uppercase;color:var(--ink-3);margin:0 0 12px}
.tl-head h1{margin:0;font-family:var(--serif);font-size:clamp(24px,3.4vw,32px);font-weight:600;line-height:1.4}
.tl-head p{margin:10px 0 0;font-size:13.5px;color:var(--ink-3)}
.tl-bar{display:flex;gap:8px;margin-top:18px;flex-wrap:wrap}

.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border:1px solid var(--ink);border-radius:3px;background:var(--ink);color:var(--paper);font-size:12.5px;cursor:pointer}
.btn:hover{background:var(--accent);border-color:var(--accent);color:#fff}
.btn.ghost{background:transparent;color:var(--ink);border-color:var(--rule-2)}
.btn.ghost:hover{border-color:var(--ink);color:var(--ink);background:transparent}

.axis{position:relative;padding:8px 0 40px 30px;margin:0;list-style:none}
.axis::before{content:'';position:absolute;left:7px;top:12px;bottom:14px;width:1px;background:var(--rule-2)}
.ax-year{position:relative;margin:30px 0 6px;font-family:var(--serif);font-size:15px;font-weight:600;letter-spacing:.12em;color:var(--ink);list-style:none}
.ax-year:first-child{margin-top:6px}
.ax-year::before{content:'';position:absolute;left:-30px;top:.6em;width:9px;height:9px;border-radius:50%;background:var(--accent);box-shadow:0 0 0 4px var(--paper)}
.ax-year span{font-family:var(--sans);font-size:11.5px;font-weight:400;color:var(--ink-3);margin-left:10px;letter-spacing:.02em}
.ax-month{position:relative;margin:14px 0 4px;font-size:10.5px;letter-spacing:.18em;color:var(--ink-3);list-style:none}
.ax-month::before{content:'';position:absolute;left:-27px;top:.55em;width:5px;height:5px;border-radius:50%;background:var(--rule-2);box-shadow:0 0 0 3px var(--paper)}
.ax-item{position:relative;padding:9px 0 14px;list-style:none}
.ax-item::before{content:'';position:absolute;left:-27px;top:21px;width:6px;height:6px;border-radius:50%;background:var(--rule-2);border:0;transition:background .18s}
.ax-item:hover::before{background:var(--accent)}
.ax-date{font-size:11.5px;color:var(--ink-3);font-variant-numeric:tabular-nums;letter-spacing:.03em}
.ax-title{display:inline-block;margin:2px 0 0;font-family:var(--serif);font-size:15.5px;font-weight:600;line-height:1.6}
.ax-item:hover .ax-title{color:var(--accent)}
.ax-desc{margin:4px 0 0;font-size:13px;color:var(--ink-2);line-height:1.8;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.ax-tag{display:inline-block;font-size:10.5px;color:var(--ink-3);border:1px solid var(--rule);border-radius:2px;padding:0 6px;margin-left:8px;vertical-align:1px;letter-spacing:.02em}
.ax-tag.is-post{color:var(--accent);border-color:var(--rule-2)}
.ax-ops{margin-top:6px;display:flex;gap:12px;font-size:11.5px}
.ax-ops a,.ax-ops button{color:var(--ink-3);background:none;border:0;padding:0;cursor:pointer;font-size:11.5px}
.ax-ops a:hover,.ax-ops button:hover{color:var(--ink)}
.ax-start{position:relative;margin:16px 0 0;font-size:12px;color:var(--ink-3);list-style:none}
.ax-start::before{content:'';position:absolute;left:-27px;top:.7em;width:5px;height:5px;border-radius:50%;border:1px dashed var(--rule-2)}

.tl-empty{border:1px solid var(--rule);border-radius:4px;padding:40px 24px;text-align:center;color:var(--ink-3);font-size:13.5px}

.form{background:var(--card);border:1px solid var(--rule);border-radius:4px;padding:22px;margin:0 0 26px}
.form h2{margin:0 0 16px;font-family:var(--serif);font-size:15px;letter-spacing:.08em;font-weight:600}
.form label{display:block;font-size:11.5px;color:var(--ink-3);margin:0 0 5px;letter-spacing:.04em}
.form input[type=text],.form input[type=password],.form input[type=date],.form textarea{width:100%;padding:9px 12px;border:1px solid var(--rule);border-radius:2px;background:var(--paper);color:var(--ink);font:inherit;font-size:14px;margin-bottom:12px;outline:none}
.form input:focus,.form textarea:focus{border-color:var(--accent)}
.form textarea{min-height:84px;resize:vertical}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.msg{padding:10px 14px;border-left:2px solid var(--accent);background:var(--accent-weak);font-size:13px;margin:0 0 16px}

.site-footer{border-top:1px solid var(--rule);margin-top:40px;padding:26px 0}
.footer-in{max-width:760px;margin:0 auto;padding:0 24px;display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--ink-3)}
.footer-links{display:flex;gap:16px;flex-wrap:wrap}
.footer-links a:hover{color:var(--ink)}

@media(max-width:767px){
  .topbar-inner{padding:0 18px}
  .brand-tail{display:none}
  .nav{position:fixed;left:0;right:0;bottom:0;top:auto;z-index:65;margin:0;
    flex-direction:column;align-items:stretch;background:var(--paper);border-top:1px solid var(--rule);
    border-radius:14px 14px 0 0;padding:10px 20px calc(22px + env(safe-area-inset-bottom));
    transform:translateY(102%);transition:transform .28s cubic-bezier(.22,.61,.36,1);box-shadow:0 -8px 40px rgba(0,0,0,.12)}
  .nav.open{transform:translateY(0)}
  .nav a{font-size:15px;padding:13px 4px;border-bottom:1px solid var(--rule);text-align:left}
  .nav a::after{display:none}
  .nav-toggle{display:inline-flex}
  .theme-btn{margin-left:auto}
  .wrap,.footer-in{padding:0 18px}
  .axis{padding-left:26px}
  .ax-year::before{left:-26px}
  .ax-month::before{left:-23px}
  .ax-item::before{left:-22px}
  .ax-start::before{left:-23px}
  .row2{grid-template-columns:1fr}
}
@media(prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}

/* ---- 风格 B：科技玻璃 ---- */
html[data-style="tech"][data-theme="light"]{--paper:#f4f6f9;--paper-2:#eef1f6;--paper-3:#e6e9ef;--card:#fff;--ink:#1d2430;--ink-2:#5a6577;--ink-3:#667085;--rule:#e6e9ef;--rule-2:#ccd4e0;--accent:#2f6fed;--accent-2:#1d4fd0;--accent-weak:#eaf1ff;--serif:var(--sans)}
html[data-style="tech"][data-theme="dark"]{--paper:#0e1319;--paper-2:#131a24;--paper-3:#1a2330;--card:#161e29;--ink:#e9eef6;--ink-2:#a6b2c4;--ink-3:#7c8a9e;--rule:#222c3a;--rule-2:#334054;--accent:#5b94ff;--accent-2:#83adff;--accent-weak:#16233a;--serif:var(--sans)}

/* ---- 风格切换：顶栏按钮 ---- */
.style-btn{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;flex:none;
  border:1px solid var(--rule);border-radius:50%;background:transparent;color:var(--ink-2);cursor:pointer}
.style-btn span{display:inline-flex;align-items:center;justify-content:center}
.style-btn .i-tech{display:none}
html[data-style="tech"] .style-btn .i-paper{display:none}
html[data-style="tech"] .style-btn .i-tech{display:inline-flex}
html[data-style="tech"] .style-btn,html[data-style="tech"] .theme-btn,html[data-style="tech"] .nav-toggle{
  background:rgba(255,255,255,.55);border-color:rgba(16,24,40,.08)}
html[data-style="tech"][data-theme="dark"] .style-btn,
html[data-style="tech"][data-theme="dark"] .theme-btn,
html[data-style="tech"][data-theme="dark"] .nav-toggle{background:rgba(255,255,255,.07);border-color:rgba(255,255,255,.12)}
</style>
</head>
<body>

<header class="topbar" id="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/"><span class="brand-mark">跃小叮当</span><span class="brand-tail">· 的博客</span></a>
    <nav class="nav" id="nav">
      <a href="/index.php/archives.html">文章</a>
      <a href="/moments/">朋友圈</a>
      <a href="/drive/">云盘</a>
      <a href="/index.php/links.html">友链</a>
      <a href="/index.php/about.html">关于</a>
      <a href="/index.php/guestbook.html">留言</a>
    </nav>
    <button class="style-btn" id="styleBtn" type="button" aria-label="切换界面风格：纸质 / 全屏封面" title="切换界面风格"><span class="i-paper"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3.5h9.2L19 8.3V20.5H5z"/><path d="M14 3.7V8.4h4.7"/><path d="M8 12.5h8M8 16h5.5"/></svg></span><span class="i-tech"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2v17.6M3.2 12h17.6"/><path d="M6.4 6.4l11.2 11.2M17.6 6.4L6.4 17.6" opacity=".55"/></svg></span></button>
    <button class="theme-btn" id="themeBtn" type="button" aria-label="切换深浅色"><span class="i-moon">☾</span><span class="i-sun">☀</span></button>
    <button class="nav-toggle" id="navToggle" type="button" aria-label="菜单">☰</button>
  </div>
</header>

<main class="wrap">
  <div class="tl-head">
    <p class="tl-kicker">Timeline</p>
    <h1>时间轴</h1>
    <p>
      <?php if ($countAll > 0): ?>
        共 <?php echo $countAll; ?> 条 · 文章 <?php echo $countPost; ?> 篇 · 随手记 <?php echo $countNote; ?> 条
      <?php else: ?>
        还没开始记。第一篇写下来，这里就有了起点。
      <?php endif; ?>
    </p>
    <div class="tl-bar">
      <a class="btn ghost" href="/index.php/archives.html">按分类看</a>
      <?php if ($isAdmin): ?>
        <?php if (t_logged()): ?>
          <a class="btn ghost" href="<?php echo t_h($base); ?>/?act=logout">退出管理</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($err !== ''): ?><div class="msg"><?php echo t_h($err); ?></div><?php endif; ?>
  <?php if (!empty($_GET['ok'])): ?><div class="msg">已保存</div><?php endif; ?>

  <?php if ($isAdmin): ?>
    <?php if (!t_logged()): ?>
      <form class="form" method="post" action="<?php echo t_h($base); ?>/?act=login">
        <h2>管理员登录</h2>
        <label>密码</label>
        <input type="password" name="pwd" autocomplete="current-password" required>
        <button class="btn" type="submit">登录</button>
      </form>
    <?php else: ?>
      <form class="form" method="post" action="<?php echo t_h($base); ?>/?act=save">
        <h2><?php echo $edit ? '编辑记录' : '新增记录'; ?></h2>
        <input type="hidden" name="csrf" value="<?php echo t_h(t_csrf()); ?>">
        <input type="hidden" name="id" value="<?php echo t_h((string)($edit['id'] ?? '')); ?>">
        <div class="row2">
          <div>
            <label>日期（2026-11 或 2026-11-24）</label>
            <input type="text" name="date" placeholder="2026-11-24" value="<?php echo t_h((string)($edit['date'] ?? date('Y-m-d'))); ?>" required>
          </div>
          <div>
            <label>标签（可选）</label>
            <input type="text" name="tag" placeholder="建站 / 学习 / 生活" value="<?php echo t_h((string)($edit['tag'] ?? '')); ?>">
          </div>
        </div>
        <label>标题</label>
        <input type="text" name="title" placeholder="把博客搬到了新服务器" value="<?php echo t_h((string)($edit['title'] ?? '')); ?>" required>
        <label>说明（可选）</label>
        <textarea name="desc" placeholder="多写几句当时的情况…"><?php echo t_h((string)($edit['desc'] ?? '')); ?></textarea>
        <button class="btn" type="submit">保存</button>
        <?php if ($edit): ?><a class="btn ghost" href="<?php echo t_h($base); ?>/?act=admin" style="margin-left:8px">取消编辑</a><?php endif; ?>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($countAll === 0): ?>
    <div class="tl-empty">还没有记录<?php echo $isAdmin ? '，用上面的表单写第一条吧' : '。等第一篇写下来。'; ?></div>
  <?php else: ?>
  <ol class="axis">
    <?php foreach ($years as $year => $months): ?>
      <li class="ax-year"><?php echo t_h($year); ?><span><?php $c = 0; foreach ($months as $l) { $c += count($l); } echo $c; ?> 条</span></li>
      <?php foreach ($months as $mon => $list): ?>
        <?php if ($mon > 0): ?><li class="ax-month"><?php echo $mon; ?> 月</li><?php endif; ?>
        <?php foreach ($list as $it): ?>
          <li class="ax-item">
            <div class="ax-date"><?php echo t_h($it['date']); ?></div>
            <?php if ($it['url'] !== ''): ?>
              <a class="ax-title" href="<?php echo t_h($it['url']); ?>"><?php echo t_h($it['title']); ?></a>
            <?php else: ?>
              <div class="ax-title"><?php echo t_h($it['title']); ?></div>
            <?php endif; ?>
            <?php if ($it['tag'] !== ''): ?><span class="ax-tag<?php echo $it['kind'] === 'post' ? ' is-post' : ''; ?>"><?php echo t_h($it['tag']); ?></span><?php endif; ?>
            <?php if ($it['desc'] !== ''): ?><p class="ax-desc"><?php echo t_h($it['desc']); ?></p><?php endif; ?>
            <?php if ($isAdmin && t_logged() && $it['kind'] === 'note'): ?>
              <div class="ax-ops">
                <a href="<?php echo t_h($base); ?>/?act=admin&edit=<?php echo t_h($it['nid']); ?>">编辑</a>
                <form method="post" action="<?php echo t_h($base); ?>/?act=delete" onsubmit="return confirm('确定删除这条记录？')" style="display:inline">
                  <input type="hidden" name="csrf" value="<?php echo t_h(t_csrf()); ?>">
                  <input type="hidden" name="id" value="<?php echo t_h($it['nid']); ?>">
                  <button type="submit">删除</button>
                </form>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if ($countAll <= 2): ?>
      <li class="ax-start"><?php echo date('Y'); ?> · 开始记录</li>
    <?php endif; ?>
  </ol>
  <?php endif; ?>
</main>

<footer class="site-footer">
  <div class="footer-in">
    <span>&copy; <?php echo date('Y'); ?> MC.跃小叮当的博客</span>
    <nav class="footer-links">
      <a href="/index.php/about.html">关于</a>
      <a href="/index.php/feed/">RSS ↗</a>
      <a href="/sitemap.xml">站点地图</a>
      <a href="<?php echo t_h($base); ?>/?act=admin"><?php echo t_logged() ? '管理' : '管理入口'; ?></a>
    </nav>
  </div>
</footer>

<script>
(function(){
  var d=document.documentElement;
  var tb=document.getElementById('themeBtn');
  if(tb) tb.addEventListener('click',function(){
    var n=d.getAttribute('data-theme')==='dark'?'light':'dark';
    d.setAttribute('data-theme',n); try{localStorage.setItem('wb-theme',n);}catch(e){}
  });
  var t=document.getElementById('navToggle'),n=document.getElementById('nav');
  if(t&&n){
    t.addEventListener('click',function(e){e.stopPropagation();var o=n.classList.toggle('open');t.setAttribute('aria-expanded',o?'true':'false');});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&n.classList.contains('open')){n.classList.remove('open');t.focus();}});
    document.addEventListener('click',function(e){if(n.classList.contains('open')&&!n.contains(e.target)&&!t.contains(e.target))n.classList.remove('open');});
  }
  var top=document.getElementById('topbar'),tick=false;
  window.addEventListener('scroll',function(){
    if(tick)return; tick=true;
    requestAnimationFrame(function(){if(top)top.classList.toggle('scrolled',(window.pageYOffset||0)>6);tick=false;});
  },{passive:true});
})();
</script>

<link rel="stylesheet" href="/music/player.css?v=6">
<script src="/music/player.js?v=6" defer></script>
<script>(function(){var b=document.getElementById('styleBtn');if(!b)return;
b.addEventListener('click',function(){var d=document.documentElement;var n=d.getAttribute('data-style')==='tech'?'paper':'tech';
d.setAttribute('data-style',n);try{localStorage.setItem('wb-style',n);}catch(e){window.scrollTo(0,0);}});})();</script>
</body>
</html>
