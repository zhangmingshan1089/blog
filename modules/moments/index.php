<?php
/**
 * 动态 · 单文件（说说 / 照片 / 视频 / 文章 合一）
 * - 游客：浏览动态流、图库、大图/视频
 * - 管理员：登录后发布（文字 + 多图 + 视频 + 备注 + 纪念日）、删除
 * - 数据：data.json，媒体：uploads/
 * - 文章：从 Typecho 数据库聚合（读不到就只显示本地动态）
 */
declare(strict_types=1);
session_name('ZMS_MOMENTS_SESS');
session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');

const M_DATA    = __DIR__ . '/data.json';
const M_UP      = __DIR__ . '/uploads';
const M_PWD     = '20100809aaa';
const M_MAXIMG  = 30;                        // 单次最多图片
const M_IMG_B   = 12 * 1024 * 1024;          // 单张图片上限
const M_MAXVID  = 3;                         // 单次最多视频
const M_KEEP    = 800;                       // 最多保留条数
const M_AVATAR  = '/usr/themes/wbclean/assets/avatar.jpg';

function m_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function m_base(): string { return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/moments/index.php')), '/'); }
function m_json(array $r, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function m_load(): array {
    if (!is_file(M_DATA)) return [];
    $d = json_decode((string)@file_get_contents(M_DATA), true);
    return is_array($d) ? $d : [];
}
function m_save(array $d): bool {
    $tmp = M_DATA . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    return @rename($tmp, M_DATA);
}
function m_pwd_ok(string $p): bool {
    foreach ([__DIR__ . '/.passwd', __DIR__ . '/../drive/.passwd'] as $f) {
        if (is_file($f)) {
            $v = trim((string)@file_get_contents($f));
            if ($v === '') continue;
            return str_starts_with($v, '$2') ? password_verify($p, $v) : hash_equals($v, $p);
        }
    }
    return hash_equals(M_PWD, $p);
}
function m_logged(): bool { return !empty($_SESSION['m_logged']); }
function m_csrf(): string {
    if (empty($_SESSION['m_csrf'])) $_SESSION['m_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['m_csrf'];
}
function m_csrf_ok(): bool { return hash_equals((string)($_SESSION['m_csrf'] ?? ''), (string)($_POST['csrf'] ?? '')); }
function m_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}
function m_mask(string $ip): string {
    if ($ip === '') return '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip); return $p[0] . '.**.*.' . $p[3];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $p = explode(':', $ip); return $p[0] . ':' . $p[1] . ':****';
    }
    return '***';
}
function m_ago(int $t): string {
    $d = time() - $t;
    if ($d < 60) return '刚刚';
    if ($d < 3600) return intdiv($d, 60) . ' 分钟前';
    if ($d < 86400) return intdiv($d, 3600) . ' 小时前';
    if ($d < 2592000) return intdiv($d, 86400) . ' 天前';
    return date('Y-m-d', $t);
}
function m_ini_bytes(string $v): int {
    $v = trim($v); if ($v === '') return 0;
    $u = strtolower(substr($v, -1)); $n = (int)$v;
    switch ($u) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return $n;
}
/** 服务端真实可接受的上限（取 php 配置与自身设定较小值） */
function m_video_limit(): int {
    static $v = null;
    if ($v !== null) return $v;
    $cap  = 96 * 1024 * 1024;
    $vals = [];
    $up = m_ini_bytes((string)ini_get('upload_max_filesize'));
    $po = m_ini_bytes((string)ini_get('post_max_size'));
    if ($up > 0) $vals[] = $up;
    if ($po > 0) $vals[] = $po;
    $v = $vals ? min($vals) : $cap;
    if ($v > $cap) $v = $cap;
    if ($v < 2 * 1024 * 1024) $v = 2 * 1024 * 1024;
    return $v;
}

/** 聚合 Typecho 文章（失败则返回空数组，页面不受影响） */
function m_posts(int $limit = 60): array {
    static $cache = [];
    if (isset($cache[$limit])) return $cache[$limit];
    $cache[$limit] = [];
    $cfg = dirname(__DIR__) . '/config.inc.php';
    if (!is_file($cfg)) return $cache[$limit];
    $oldEr = error_reporting(0);
    try {
        if (!defined('__TYPECHO_ROOT_DIR__')) require_once $cfg;
        if (!class_exists('\Typecho\Db')) return $cache[$limit];
        $db = \Typecho\Db::get();
        if (!$db) return $cache[$limit];
        $rows = $db->fetchAll($db->select('cid', 'title', 'created', 'text')
            ->from('table.contents')
            ->where('type = ?', 'post')->where('status = ?', 'publish')
            ->order('created', \Typecho\Db::SORT_DESC)->limit($limit));
        foreach ($rows as $r) {
            $txt = preg_replace('/\s+/u', ' ', strip_tags((string)($r['text'] ?? ''))) ?? '';
            $cache[$limit][] = [
                'cid'   => (int)$r['cid'],
                'title' => trim((string)$r['title']) !== '' ? (string)$r['title'] : '无标题',
                'time'  => (int)$r['created'],
                'brief' => mb_substr(trim($txt), 0, 120, 'UTF-8'),
                'full'  => mb_substr(trim($txt), 0, 2000, 'UTF-8'),
                'url'   => '/index.php/archives/' . (int)$r['cid'] . '/',
            ];
        }
    } catch (\Throwable $e) {
        // 静默降级：只显示本地动态
    } finally {
        error_reporting($oldEr);
    }
    return $cache[$limit];
}

$act  = (string)($_REQUEST['act'] ?? '');
$base = m_base();
$err  = '';

/* ---------------- 登录 ---------------- */
if ($act === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $_SESSION['m_fail'] = (int)($_SESSION['m_fail'] ?? 0);
    if ($_SESSION['m_fail'] >= 8) $err = '尝试次数过多，请稍后再试';
    elseif (m_pwd_ok((string)($_POST['pwd'] ?? ''))) { $_SESSION['m_logged'] = true; $_SESSION['m_fail'] = 0; header('Location: ' . $base . '/?act=admin'); exit; }
    else { $_SESSION['m_fail']++; $err = '密码错误'; }
}
if ($act === 'logout') { $_SESSION['m_logged'] = false; header('Location: ' . $base . '/'); exit; }

/* ---------------- 发布 ---------------- */
if ($act === 'post' && m_logged() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!m_csrf_ok()) m_json(['ok' => false, 'msg' => '表单已过期，请刷新重试']);
    $text  = mb_substr(trim((string)($_POST['text'] ?? '')), 0, 3000, 'UTF-8');
    $note  = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200, 'UTF-8');
    $anniv = trim((string)($_POST['anniv'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anniv)) $anniv = '';

    $hasImg = !empty($_FILES['images']['name'][0]);
    $hasVid = !empty($_FILES['videos']['name'][0]);
    if ($text === '' && !$hasImg && !$hasVid) m_json(['ok' => false, 'msg' => '写点什么，或选几张照片 / 一段视频']);

    if (!is_dir(M_UP)) @mkdir(M_UP, 0755, true);

    $images = [];
    if ($hasImg) {
        $f = $_FILES['images'];
        $n = min(count($f['name']), M_MAXIMG);
        for ($i = 0; $i < $n; $i++) {
            if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) continue;
            $tmp = (string)$f['tmp_name'][$i];
            $sz  = (int)$f['size'][$i];
            if ($sz <= 0 || $sz > M_IMG_B || !is_uploaded_file($tmp)) continue;
            $info = @getimagesize($tmp);
            if (!$info) continue;
            $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
            $ext = $map[$info[2]] ?? '';
            if ($ext === '') continue;
            $name = 'i' . bin2hex(random_bytes(10)) . '.' . $ext;
            if (@move_uploaded_file($tmp, M_UP . '/' . $name)) $images[] = 'uploads/' . $name;
        }
    }

    $videos = [];
    if ($hasVid) {
        $f = $_FILES['videos'];
        $limit = m_video_limit();
        $n = min(count($f['name']), M_MAXVID);
        for ($i = 0; $i < $n; $i++) {
            if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) continue;
            $tmp = (string)$f['tmp_name'][$i];
            $sz  = (int)$f['size'][$i];
            if ($sz <= 0 || $sz > $limit || !is_uploaded_file($tmp)) continue;
            $mime = '';
            if (function_exists('finfo_open')) {
                $fi = @finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) { $mime = (string)@finfo_file($fi, $tmp); @finfo_close($fi); }
            }
            $vmap = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-m4v' => 'm4v'];
            $ext = $vmap[$mime] ?? '';
            if ($ext === '') {
                $guess = strtolower(pathinfo((string)$f['name'][$i], PATHINFO_EXTENSION));
                if (in_array($guess, ['mp4', 'webm', 'mov', 'm4v'], true)) $ext = $guess;
            }
            if ($ext === '') continue;
            $name = 'v' . bin2hex(random_bytes(10)) . '.' . $ext;
            if (@move_uploaded_file($tmp, M_UP . '/' . $name)) $videos[] = 'uploads/' . $name;
        }
    }

    if ($text === '' && !$images && !$videos) {
        m_json(['ok' => false, 'msg' => '上传失败：文件可能超出服务器限制（当前上限 ' . round(m_video_limit() / 1048576) . ' MB）']);
    }

    $d = m_load();
    $type = $videos ? 'video' : ($images ? 'photo' : 'note');
    array_unshift($d, [
        'id'     => bin2hex(random_bytes(8)),
        'type'   => $type,
        'text'   => $text,
        'note'   => $note,
        'anniv'  => $anniv,
        'images' => $images,
        'videos' => $videos,
        'time'   => time(),
        'ip'     => m_ip(),
    ]);
    if (count($d) > M_KEEP) $d = array_slice($d, 0, M_KEEP);
    if (!m_save($d)) m_json(['ok' => false, 'msg' => '保存失败，请检查 moments 目录权限']);
    m_json(['ok' => true, 'msg' => '已发布']);
}

/* ---------------- 删除 ---------------- */
if ($act === 'delete' && m_logged() && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!m_csrf_ok()) m_json(['ok' => false, 'msg' => '表单已过期']);
    $id = (string)($_POST['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) m_json(['ok' => false, 'msg' => '参数错误']);
    $d = m_load(); $target = null;
    foreach ($d as $i => $row) { if (($row['id'] ?? '') === $id) { $target = $row; unset($d[$i]); break; } }
    if ($target === null) m_json(['ok' => false, 'msg' => '动态不存在']);
    foreach (array_merge((array)($target['images'] ?? []), (array)($target['videos'] ?? [])) as $rel) {
        if (preg_match('#^uploads/[iv][a-f0-9]{20}\.(jpg|png|gif|webp|mp4|webm|mov|m4v)$#', (string)$rel)) {
            @unlink(__DIR__ . '/' . $rel);
        }
    }
    m_save(array_values($d));
    m_json(['ok' => true, 'msg' => '已删除']);
}

if ($act === 'admin') { header('Location: /publish/'); exit; }
render_page('朋友圈', 'stream', $err);

/* ================= 渲染 ================= */

/** 一条时间线：碎片在前，文章随后补上（已在发布台同步过的不重复） */
function m_items(): array {
    $d     = m_load();
    $posts = m_posts(60);

    /* 同一段话先在朋友圈发过、后来又写成文章：按正文比对认出来，别显示两遍 */
    $norm = function (string $s): string {
        return preg_replace('/[\s　]+/u', '', $s) ?? '';
    };
    /* 分段比对：同一段话在两处常常被改过标点，直接整段包含会漏 */
    $sameText = function (string $mt, string $pf): bool {
        $len = mb_strlen($mt, 'UTF-8');
        if ($len < 40 || $pf === '') return false;
        $step = max(1, intdiv($len, 8));
        $total = $hit = 0;
        for ($i = 0; $i < $len; $i += $step) {
            $seg = mb_substr($mt, $i, 18, 'UTF-8');
            if (mb_strlen($seg, 'UTF-8') < 12) continue;
            $total++;
            if (mb_strpos($pf, $seg) !== false) $hit++;
        }
        return $total >= 3 && $hit >= (int)ceil($total * 0.55);
    };
    $fullTexts = [];
    foreach ($posts as $p) {
        $fullTexts[(int)$p['cid']] = $norm((string)($p['full'] ?? ''));
    }

    /* 已经明确关联过文章的动态，先占位 */
    $seen = [];
    foreach ($d as $r) {
        if (!empty($r['post_cid'])) $seen[(int)$r['post_cid']] = true;
    }

    $all = [];
    foreach ($d as $r) {
        if (empty($r['post_cid']) && mb_strlen($norm((string)($r['text'] ?? '')), 'UTF-8') >= 40) {
            $mt = $norm((string)$r['text']);
            foreach ($posts as $p) {
                $cid = (int)$p['cid'];
                if (!$sameText($mt, $fullTexts[$cid])) continue;
                if (isset($seen[$cid])) {
                    /* 这篇文章已经有卡片了：纯文字就直接让位，带图带视频的保留碎片 */
                    if (empty($r['images']) && empty($r['videos'])) $r['_drop'] = true;
                    break;
                }
                $r['post_cid'] = $cid;
                $r['post_url'] = (string)$p['url'];
                $r['title']    = (string)$p['title'];
                $r['dedup']    = true;
                $seen[$cid]    = true;
                break;
            }
        }
        if (!empty($r['_drop'])) continue;
        $r['kind'] = 'moment';
        $all[] = $r;
    }

    $linked = $seen;
    foreach ($posts as $p) {
        if (isset($linked[(int)$p['cid']])) continue;
        $p['kind'] = 'post';
        $all[] = $p;
    }
    usort($all, fn($a, $b) => (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0));
    return $all;
}

function m_trim(string $s, int $n = 140): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') . '…' : $s;
}

function m_media(array $items): array {
    $out = [];
    foreach ($items as $it) {
        foreach ((array)($it['images'] ?? []) as $img) {
            $out[] = ['src' => $img, 'type' => 'img', 't' => (int)($it['time'] ?? 0), 'text' => (string)($it['text'] ?? '')];
        }
        foreach ((array)($it['videos'] ?? []) as $vid) {
            $out[] = ['src' => $vid, 'type' => 'vid', 't' => (int)($it['time'] ?? 0), 'text' => (string)($it['text'] ?? '')];
        }
    }
    return $out;
}

function m_is_article(array $x): bool {
    if (($x['kind'] ?? '') === 'post') return true;
    return !empty($x['post_cid']) && empty($x['images']) && empty($x['videos']);
}

function render_page(string $title, string $view, string $err = ''): void {
    $logged = m_logged();
    $base   = m_base();
    $v      = (string)($_GET['view'] ?? 'all');
    $isGallery = ($view === 'stream' && $v === 'gallery');
    $isPosts   = ($view === 'stream' && $v === 'posts');
    $allItems  = m_items();
    $realPosts = m_posts(60);
    $items     = $isPosts
        ? array_map(function ($p) { $p['kind'] = 'post'; return $p; }, $realPosts)
        : $allItems;
    $media  = m_media($allItems);
    $limitMb = (int)round(m_video_limit() / 1048576);
    $momentCount = count(array_filter($allItems, fn($x) => ($x['kind'] ?? '') === 'moment' && !m_is_article($x)));
    $postCount   = count($realPosts);
?><!DOCTYPE html>
<html lang="zh-CN" data-style="tech" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?php echo m_h($title); ?> · MC.跃小叮当的博客</title>
<meta name="description" content="<?php echo m_h($isGallery ? '照片与视频的总览，点开可看大图、视频与当时的备注。' : '碎片记录：一句话、一张照片、一段视频，按时间倒序。完整的想法写在文章里。'); ?>">
<link rel="canonical" href="<?php echo m_h($base); ?>/<?php echo $isGallery ? '?view=gallery' : ''; ?>">
<meta name="robots" content="index, follow">
<meta property="og:site_name" content="MC.跃小叮当的博客">
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo m_h($title); ?> · MC.跃小叮当的博客">
<meta property="og:description" content="<?php echo m_h($isGallery ? '照片与视频的总览。' : '碎片记录，按时间倒序。'); ?>">
<meta property="og:url" content="<?php echo m_h($base); ?>/">
<meta property="og:image" content="https://zhangmingshan.wwh.us.ci/usr/themes/wbclean/assets/og-cover.jpg">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="/usr/themes/wbclean/assets/favicon-64.png" type="image/png">
<link rel="alternate" type="application/rss+xml" title="MC.跃小叮当的博客 RSS" href="/index.php/feed/">
<link rel="stylesheet" href="/usr/themes/wbclean/style.css?v=18">
<script>(function(){try{var t=localStorage.getItem('wb-theme');if(t!=='light'&&t!=='dark'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}var d=document.documentElement,d2=localStorage.getItem('wb-style');if(d2!=='paper'&&d2!=='tech')d2='tech';d.setAttribute('data-theme',t);d.setAttribute('data-style',d2);}catch(e){}})();</script>
<style>
:root{--brand:#3F6B5B;--line:#E5E1D8;--text:#2B2B2B;--text2:#56534C;--text3:#8A867D;--bg:#FAF9F6;--radius:5px;--card:#FFFFFF;--serif:"Noto Serif SC","Noto Serif CJK SC","Songti SC","STSong","SimSun",Georgia,serif}
html[data-theme="dark"]{--brand:#8FB6A3;--line:#33322E;--text:#E8E5DE;--text2:#BDB8AC;--text3:#8A867D;--bg:#1B1B19;--card:#262623}
*{box-sizing:border-box}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:var(--bg);color:var(--text);line-height:1.75;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}

.m-head{padding:98px 20px 26px;text-align:center;background:linear-gradient(180deg,#eaf1ff 0%,var(--bg) 100%)}
.m-head h1{margin:0 0 6px;font-size:clamp(24px,4vw,32px);letter-spacing:1px}
.m-head p{margin:0;color:var(--text2);font-size:14px}
.m-hint{display:inline-block;margin-left:6px;color:var(--text3)}
.m-hint a{color:var(--brand)}
.m-foot{border-top:1px solid var(--line);margin-top:34px;padding:22px 0;background:#fff}
.m-foot-in{max-width:760px;margin:0 auto;padding:0 16px;display:flex;justify-content:space-between;
  align-items:center;gap:12px 20px;flex-wrap:wrap;font-size:12.5px;color:var(--text3)}
.m-foot-nav{display:flex;gap:16px;flex-wrap:wrap}
.m-foot-nav a{color:var(--text3);transition:color .22s ease}
.m-foot-nav a:hover{color:var(--brand)}
.m-foot-admin{color:#9aa4b2}
.m-tabs{display:flex;gap:8px;justify-content:center;margin-top:18px;flex-wrap:wrap}
.m-tab{padding:8px 18px;border-radius:20px;background:#fff;border:1px solid var(--line);font-size:13.5px;color:var(--text2);transition:all .24s cubic-bezier(.2,.9,.25,1)}
.m-tab:hover{border-color:var(--brand);color:var(--brand);transform:translateY(-2px)}
.m-tab.on{background:var(--brand);border-color:var(--brand);color:#fff;box-shadow:0 6px 16px rgba(47,111,237,.28)}
.m-tab:active{transform:scale(.95)}

.m-wrap{max-width:760px;margin:0 auto;padding:0 18px 70px}
.m-wrap.wide{max-width:1040px}

.m-card{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:20px 22px;margin-bottom:16px;box-shadow:0 6px 22px rgba(16,24,40,.05);transition:box-shadow .3s ease,border-color .3s ease;content-visibility:auto;contain-intrinsic-size:auto 300px}
.m-card:hover{box-shadow:0 12px 34px rgba(16,24,40,.1);border-color:#dde4f2}
.mh{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.mh img{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.15)}
.mh .nm{font-weight:600;font-size:15px}
.mh .tm{font-size:12px;color:var(--text3)}
.badge{font-size:11px;padding:1px 8px;border-radius:20px;background:#eaf1ff;color:var(--brand);margin-left:6px}
.badge.art{background:#fff3e6;color:#d97706}
.badge.anniv{background:#ffe9f0;color:#e0407a}
.mtxt{font-size:15px;color:var(--text);white-space:pre-wrap;word-break:break-word;margin:0 0 12px}
.mnote{font-size:13px;color:var(--text2);background:#f7f9fc;border-left:3px solid #cfe0ff;padding:8px 12px;border-radius:0 8px 8px 0;margin:0 0 12px;white-space:pre-wrap}

.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;max-width:460px}
.grid.n1{grid-template-columns:1fr;max-width:340px}
.grid.n2{grid-template-columns:repeat(2,1fr);max-width:340px}
.grid img{width:100%;aspect-ratio:1;object-fit:cover;border-radius:8px;cursor:zoom-in;transition:transform .35s cubic-bezier(.2,.9,.25,1),filter .3s}
.grid img:hover{transform:scale(1.03);filter:brightness(1.05)}

.vid{max-width:460px;margin:0 0 10px}
.vid video{width:100%;border-radius:10px;background:#000;display:block;max-height:520px}

.m-ops{margin-top:10px;display:flex;gap:14px;font-size:12.5px;color:var(--text3);align-items:center}
.m-ops a,.m-ops button{color:var(--text3);background:none;border:0;font:inherit;cursor:pointer;padding:0;transition:color .2s,transform .2s}
.m-ops a:hover,.m-ops button:hover{color:var(--brand);transform:translateY(-1px)}

.art-title{font-size:18px;font-weight:700;margin:0 0 6px}
.art-title a:hover{color:var(--brand)}
.art-brief{color:var(--text2);font-size:14px;margin:0 0 10px}
.art-more{font-size:13.5px;color:var(--brand);font-weight:600;display:inline-block;transition:transform .24s cubic-bezier(.2,.9,.25,1)}
.art-more:hover{transform:translateX(4px)}

.compose{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:18px 20px;margin-bottom:18px;box-shadow:0 8px 26px rgba(16,24,40,.06)}
.compose textarea{width:100%;min-height:86px;border:1px solid var(--line);border-radius:12px;padding:12px 14px;font:inherit;font-size:15px;resize:vertical;outline:none;transition:border-color .2s,box-shadow .2s}
.compose textarea:focus{border-color:var(--brand);box-shadow:0 0 0 3px #eaf1ff}
.compose .bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}
.compose input[type=text],.compose input[type=date]{padding:8px 12px;border:1px solid var(--line);border-radius:9px;font:inherit;font-size:13.5px;outline:none}
.compose .file{font-size:13px;color:var(--text2)}
.compose .file input{margin-top:4px;font-size:12.5px}
.hint{font-size:12px;color:var(--text3)}

.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 18px;border:0;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;background:var(--brand);color:#fff;box-shadow:0 1px 2px rgba(47,111,237,.18);transition:background .22s,transform .24s cubic-bezier(.2,.9,.25,1),box-shadow .28s}
.btn:hover{background:#1d4fd0;transform:translateY(-2px);box-shadow:0 8px 20px rgba(47,111,237,.3)}
.btn:active{transform:scale(.96);transition-duration:.08s}
.btn.ghost{background:#f0f2f6;color:var(--text);box-shadow:none}
.btn.ghost:hover{background:#e6eaf1}

/* 图库 */
.gal{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}
.gal.one{grid-template-columns:minmax(0,460px)}
.gal.two{grid-template-columns:repeat(2,minmax(0,300px))}
.gal .cell{position:relative;border-radius:12px;overflow:hidden;background:#000;aspect-ratio:1;cursor:zoom-in;box-shadow:0 6px 20px rgba(16,24,40,.08);transition:transform .34s cubic-bezier(.2,.9,.25,1),box-shadow .34s}
.gal .cell:hover{transform:translateY(-4px) scale(1.01);box-shadow:0 16px 36px rgba(16,24,40,.18)}
.gal .cell img,.gal .cell video{width:100%;height:100%;object-fit:cover;transition:transform .5s cubic-bezier(.2,.9,.25,1)}
.gal .cell:hover img{transform:scale(1.06)}
.gal .cell .vtag{position:absolute;left:8px;top:8px;background:rgba(0,0,0,.55);color:#fff;font-size:11px;padding:2px 7px;border-radius:20px;backdrop-filter:blur(4px)}
.gal .cell .cap{position:absolute;left:0;right:0;bottom:0;padding:18px 10px 8px;font-size:12px;color:#fff;background:linear-gradient(transparent,rgba(0,0,0,.6));opacity:0;transition:opacity .3s}
.gal .cell:hover .cap{opacity:1}

.lightbox{position:fixed;inset:0;z-index:200;background:rgba(8,10,14,.94);display:none;align-items:center;justify-content:center;padding:20px}
.lightbox.show{display:flex}
.lightbox img{max-width:94vw;max-height:90vh;border-radius:8px;object-fit:contain}
.lightbox video{max-width:94vw;max-height:90vh;border-radius:8px}

.empty{text-align:center;padding:70px 20px;color:var(--text3)}
.empty .ico{font-size:40px;opacity:.55;margin-bottom:10px}

@media(max-width:620px){
  .m-wrap{padding:0 12px 60px}
  .m-card{padding:16px 15px;border-radius:14px}
  .grid{max-width:100%}
  .m-head{padding:84px 14px 20px}
}

/* ---- 纸质档案：覆盖旧卡片风格 ---- */
body{background:var(--bg);color:var(--text);font-size:15.5px;line-height:1.85}
.m-head{padding:98px 24px 18px;text-align:left;background:none;max-width:1000px;margin:0 auto;border-bottom:1px solid var(--line)}
.m-head h1{font-family:var(--serif);font-size:clamp(24px,3.4vw,32px);font-weight:600;letter-spacing:.01em;margin:0 0 8px}
.m-head p{margin:0;color:var(--text3);font-size:13.5px}
.m-hint{margin-left:4px;color:var(--text3)}
.m-hint a{color:var(--brand);text-decoration:underline;text-underline-offset:3px}
.m-tabs{justify-content:flex-start;gap:4px;margin-top:16px}
.m-tab{border:1px solid transparent;background:none;padding:5px 12px;border-radius:2px;font-size:12.5px;color:var(--text2);box-shadow:none}
.m-tab:hover{transform:none;border-color:var(--line);color:var(--text)}
.m-tab.on{background:transparent;border-color:var(--line);color:var(--brand);box-shadow:none;font-weight:600}
.m-wrap{max-width:1000px;padding:24px 24px 70px}
.masonry{columns:2;column-gap:14px}
.masonry.count-1{columns:1;max-width:560px}
@media(max-width:680px){.masonry{columns:1}}
.masonry .m-card{break-inside:avoid;margin:0 0 14px}
.m-card{background:var(--card);border:1px solid var(--line);border-radius:5px;padding:18px 20px;box-shadow:none;margin-bottom:14px}
.m-card:hover{box-shadow:none;border-color:#D6D0C2}
html[data-theme="dark"] .m-card:hover{border-color:#46443D}
.mh img{width:28px;height:28px;border:0;box-shadow:none}
.mh .nm{font-size:13.5px;font-weight:600;font-family:var(--serif)}
.mh .tm{font-size:11.5px;color:var(--text3)}
.badge{background:transparent;border:1px solid var(--line);color:var(--text3);font-size:10.5px;border-radius:2px;padding:0 6px}
.badge.art,.badge.anniv{background:transparent;color:var(--brand);border-color:var(--line)}
.mtxt{font-size:14.5px;line-height:1.95;margin:10px 0 12px;color:var(--text)}
.mnote{font-size:12px;color:var(--text3);border-left:2px solid var(--line);padding-left:10px;margin:0 0 10px}
.grid{gap:4px;border-radius:3px;max-width:100%}
.grid img,.grid video{border-radius:2px}
.grid.n1{max-width:100%}
.grid.n1 img{max-height:380px;object-fit:cover}
.art-title{font-family:var(--serif);font-size:17px;margin:8px 0 6px}
.art-brief{font-size:13.5px;color:var(--text2)}
.m-foot{border-top:1px solid var(--line);background:var(--bg);margin-top:40px}
.m-foot-nav a:hover{color:var(--brand)}
.empty{border:1px solid var(--line);border-radius:5px;padding:46px 20px;color:var(--text3);background:var(--card)}
.compose{background:var(--card);border:1px solid var(--line);border-radius:5px}

/* 文章卡片在瀑布流里横跨整行，像时间线上的节点 */
@media(min-width:681px){
  .masonry .m-card.art{column-span:all}
}
.m-card.art .art-title{font-size:19px;margin:4px 0 6px}
.m-card.art .art-brief{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin-bottom:8px}
.mtxt.fold{display:-webkit-box;-webkit-line-clamp:8;-webkit-box-orient:vertical;overflow:hidden}
.m-fold{display:inline-block;margin:-4px 0 8px;padding:0;border:0;background:none;color:var(--brand);font:inherit;font-size:12.5px;cursor:pointer}
.m-fold:hover{text-decoration:underline}

/* ---- 风格 B：科技玻璃 ---- */
html[data-style="tech"][data-theme="light"]{--bg:#f4f6f9;--card:#fff;--line:#e6e9ef;--text:#1d2430;--text2:#5a6577;--text3:#667085;--brand:#2f6fed;--serif:var(--sans)}
html[data-style="tech"][data-theme="dark"]{--bg:#0e1319;--card:#161e29;--line:#222c3a;--text:#e9eef6;--text2:#a6b2c4;--text3:#7c8a9e;--brand:#5b94ff;--serif:var(--sans)}
html[data-style="tech"] .m-card{border-radius:16px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 30px rgba(16,24,40,.05)}
html[data-style="tech"][data-theme="dark"] .m-card{box-shadow:0 1px 2px rgba(0,0,0,.3),0 8px 30px rgba(0,0,0,.24)}
html[data-style="tech"] .m-card:hover{box-shadow:0 10px 34px rgba(16,24,40,.1)}
html[data-style="tech"] .tab,html[data-style="tech"] .m-tab{border-radius:999px}
html[data-style="tech"] .m-head h1{font-weight:700;letter-spacing:0}

/* ---- 风格切换：顶栏按钮 ---- */
.style-btn{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;flex:none;
  border:1px solid var(--rule);border-radius:50%;background:transparent;color:var(--ink-2);cursor:pointer}
.style-btn span{display:inline-flex;align-items:center;justify-content:center}
.style-btn .i-tech{display:none}
html[data-style="tech"] .style-btn .i-paper{display:none}
html[data-style="tech"] .style-btn .i-tech{display:inline-flex}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="/"><span class="brand-mark">跃小叮当</span><span class="brand-tail">· 的博客</span></a>
    <nav class="nav" id="nav" aria-label="主导航">
      <a href="/index.php/archives.html">文章</a>
      <a href="<?php echo m_h($base); ?>/" class="<?php echo $isGallery ? '' : 'active'; ?>">朋友圈</a>
      <a href="/drive/">云盘</a>
      <a href="/index.php/links.html">友链</a>
      <a href="/index.php/about.html">关于</a>
      <a href="/index.php/guestbook.html">留言</a>
    </nav>
    <button class="style-btn" id="styleBtn" type="button" aria-label="切换界面风格：纸质 / 全屏封面" title="切换界面风格"><span class="i-paper"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3.5h9.2L19 8.3V20.5H5z"/><path d="M14 3.7V8.4h4.7"/><path d="M8 12.5h8M8 16h5.5"/></svg></span><span class="i-tech"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2v17.6M3.2 12h17.6"/><path d="M6.4 6.4l11.2 11.2M17.6 6.4L6.4 17.6" opacity=".55"/></svg></span></button>
    <button class="theme-btn" id="themeBtn" type="button" aria-label="切换深浅色"><span class="i-moon">☾</span><span class="i-sun">☀</span></button>
    <button class="nav-toggle" id="navToggle" aria-label="展开菜单" aria-expanded="false" aria-controls="nav">☰</button>
  </div>
</header>

<div class="m-head">
  <h1><?php echo $isGallery ? '图库' : ($isPosts ? '文章' : '朋友圈'); ?></h1>
  <p>
    <?php if ($isGallery): ?>
      共 <?php echo count($media); ?> 张照片 / 视频 · 点开看大图与配文
    <?php elseif ($isPosts): ?>
      <?php echo $postCount; ?> 篇文章
      <span class="m-hint">长文按时间倒序；一句话和照片在<a href="<?php echo m_h($base); ?>/">朋友圈</a></span>
    <?php else: ?>
      <?php echo $momentCount; ?> 条碎片 · <?php echo $postCount; ?> 篇文章
      <span class="m-hint">发布台写一次，两边同步；照片和视频只在这里</span>
    <?php endif; ?>
  </p>
  <div class="m-tabs">
    <a class="m-tab <?php echo (!$isGallery && !$isPosts) ? 'on' : ''; ?>" href="<?php echo m_h($base); ?>/">全部</a>
    <a class="m-tab <?php echo $isPosts ? 'on' : ''; ?>" href="<?php echo m_h($base); ?>/?view=posts">文章</a>
    <a class="m-tab <?php echo $isGallery ? 'on' : ''; ?>" href="<?php echo m_h($base); ?>/?view=gallery">图库</a>
    <a class="m-tab" href="/timeline/">时间轴</a>
  </div>
</div>

<main class="m-wrap <?php echo $isGallery ? 'wide' : 'masonry count-' . min(3, max(1, count($items))); ?>">

<?php if ($isGallery): ?>
  <?php if (!$media): ?>
    <div class="empty"><div class="ico">🖼</div>还没有照片，去「管理」发一条带图的动态吧</div>
  <?php else: ?>
    <div class="gal<?php echo count($media) === 1 ? ' one' : (count($media) === 2 ? ' two' : ''); ?>">
      <?php foreach ($media as $m): ?>
        <div class="cell" data-src="<?php echo m_h($m['src']); ?>" data-type="<?php echo $m['type']; ?>">
          <?php if ($m['type'] === 'img'): ?>
            <img src="<?php echo m_h($m['src']); ?>" alt="" loading="lazy" decoding="async">
          <?php else: ?>
            <video src="<?php echo m_h($m['src']); ?>" preload="metadata" muted playsinline></video>
            <span class="vtag">▶ 视频</span>
          <?php endif; ?>
          <?php if ($m['text'] !== ''): ?><span class="cap"><?php echo m_h(mb_substr($m['text'], 0, 40, 'UTF-8')); ?></span><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>
  <?php if (!$items): ?>
    <div class="empty"><div class="ico">✦</div>
      <?php if ($isPosts): ?>还没有文章，去<a href="/publish/" style="color:var(--brand)">发布台</a>写一篇<?php else: ?>这里还空着，去<a href="/publish/" style="color:var(--brand)">发布台</a>写第一条<?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($items as $it): ?>
      <?php
        $isArt     = m_is_article($it);
        $isPostRow = ($it['kind'] ?? '') === 'post';
        $artUrl    = $isPostRow ? (string)($it['url'] ?? '') : (string)($it['post_url'] ?? '');
        $artTitle  = $isPostRow ? (string)($it['title'] ?? '') : (string)($it['title'] ?? '');
        $artBrief  = $isPostRow ? (string)($it['brief'] ?? '') : m_trim((string)($it['text'] ?? ''), 140);
        if ($artTitle === '') $artTitle = '无题';
        $artTime   = (int)($it['time'] ?? 0);
      ?>
      <?php if ($isArt): ?>
        <article class="m-card art">
          <div class="mh">
            <img src="<?php echo M_AVATAR; ?>" alt="">
            <div><div class="nm">MC.跃小叮当<span class="badge art">文章</span></div>
            <div class="tm"><?php echo m_h(date('Y-m-d H:i', $artTime)); ?></div></div>
          </div>
          <h3 class="art-title">
            <?php if ($artUrl !== ''): ?><a href="<?php echo m_h($artUrl); ?>"><?php echo m_h($artTitle); ?></a><?php else: ?><?php echo m_h($artTitle); ?><?php endif; ?>
          </h3>
          <?php if ($artBrief !== ''): ?><p class="art-brief"><?php echo m_h($artBrief); ?></p><?php endif; ?>
          <?php if ($artUrl !== ''): ?><a class="art-more" href="<?php echo m_h($artUrl); ?>">阅读全文 →</a><?php endif; ?>
        </article>
      <?php else: ?>
        <article class="m-card">
          <div class="mh">
            <img src="<?php echo M_AVATAR; ?>" alt="">
            <div>
              <div class="nm">MC.跃小叮当
                <?php if (!empty($it['anniv'])): ?>
                  <?php $days = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$it['anniv'])) / 86400) + 1; ?>
                  <span class="badge anniv">纪念 <?php echo m_h((string)$it['anniv']); ?><?php echo $days > 0 ? ' · 第 ' . $days . ' 天' : ''; ?></span>
                <?php endif; ?>
              </div>
              <div class="tm"><?php echo m_h(m_ago((int)$it['time'])); ?></div>
            </div>
          </div>

          <?php
            $fold = !empty($it['post_cid']) && mb_strlen((string)$it['text'], 'UTF-8') > 300;
          ?>
          <?php if (!empty($it['text'])): ?>
            <p class="mtxt<?php echo $fold ? ' fold' : ''; ?>"><?php echo m_h((string)$it['text']); ?></p>
            <?php if ($fold): ?><button type="button" class="m-fold">展开全文</button><?php endif; ?>
          <?php endif; ?>
          <?php if (!empty($it['note'])): ?><p class="mnote"><?php echo m_h((string)$it['note']); ?></p><?php endif; ?>

          <?php foreach ((array)($it['videos'] ?? []) as $v): ?>
            <div class="vid">
              <video src="<?php echo m_h((string)$v); ?>" controls preload="metadata" playsinline></video>
            </div>
          <?php endforeach; ?>

          <?php $imgs = (array)($it['images'] ?? []); if ($imgs): ?>
            <div class="grid <?php echo count($imgs) === 1 ? 'n1' : (count($imgs) === 2 ? 'n2' : ''); ?>">
              <?php foreach ($imgs as $img): ?>
                <img src="<?php echo m_h((string)$img); ?>" alt="" loading="lazy" decoding="async"
                     onclick="openLb('<?php echo m_h((string)$img); ?>','img')">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <div class="m-ops">
            <span><?php echo m_h(date('Y-m-d H:i', (int)$it['time'])); ?></span>
            <?php if (!empty($it['post_cid']) && !empty($it['post_url'])): ?>
              <a href="<?php echo m_h((string)$it['post_url']); ?>">也有文章版 →</a>
            <?php endif; ?>
            <?php if ($logged): ?>
              <form method="post" action="<?php echo m_h($base); ?>/?act=delete" class="delForm" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo m_h(m_csrf()); ?>">
                <input type="hidden" name="id" value="<?php echo m_h((string)$it['id']); ?>">
                <button type="submit">删除</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endif; ?>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</main>

<div class="lightbox" id="lb" onclick="closeLb()">
  <div id="lbBox"></div>
</div>

<script>
function openLb(src, type){
  var lb = document.getElementById('lb'), box = document.getElementById('lbBox');
  box.innerHTML = type === 'vid'
    ? '<video src="'+src+'" controls autoplay playsinline></video>'
    : '<img src="'+src+'" alt="">';
  lb.classList.add('show');
}
function closeLb(){
  var lb = document.getElementById('lb');
  lb.classList.remove('show');
  document.getElementById('lbBox').innerHTML = '';
}
document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeLb(); });

(function(){
  var sb=document.getElementById('styleBtn');
  if(sb) sb.addEventListener('click',function(){
    var d=document.documentElement,n=d.getAttribute('data-style')==='tech'?'paper':'tech';
    d.setAttribute('data-style',n);try{localStorage.setItem('wb-style',n);}catch(e){}
  });
  var tb=document.getElementById('themeBtn');
  if(tb) tb.addEventListener('click',function(){
    var d=document.documentElement, x=d.getAttribute('data-theme')==='dark'?'light':'dark';
    d.setAttribute('data-theme',x); try{localStorage.setItem('wb-theme',x);}catch(e){}
  });
  var t=document.getElementById('navToggle'),n=document.getElementById('nav');
  if(t&&n){ t.addEventListener('click', function(){ n.classList.toggle('open'); }); }

  document.querySelectorAll('.delForm').forEach(function(f){
    f.addEventListener('submit', function(e){
      e.preventDefault();
      if (!confirm('删除这条动态？图片和视频会一起删掉')) return;
      var btn = f.querySelector('button');
      if (btn) { btn.disabled = true; btn.textContent = '删除中…'; }
      fetch(f.action, { method: 'POST', body: new FormData(f), credentials: 'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
          if (d && d.ok) location.reload();
          else { alert((d && d.msg) || '删除失败'); if (btn) { btn.disabled = false; btn.textContent = '删除'; } }
        })
        .catch(function(){ location.reload(); });
    });
  });

  document.querySelectorAll('.m-fold').forEach(function(b){
    b.addEventListener('click', function(){
      var p = b.previousElementSibling;
      if (!p) return;
      var folded = p.classList.toggle('fold');
      b.textContent = folded ? '展开全文' : '收起';
    });
  });

  document.querySelectorAll('.gal .cell').forEach(function(c){
    c.addEventListener('click', function(){ openLb(c.dataset.src, c.dataset.type); });
  });

  var f = document.getElementById('postForm');
  if (f) {
    var btn = document.getElementById('subBtn');
    var tip = document.getElementById('tip');
    var bar = document.getElementById('progBar');
    var box = document.getElementById('progBox');
    var LIMIT = <?php echo (int)m_video_limit(); ?>;

    f.addEventListener('submit', function(e){
      e.preventDefault();
      var vid = f.querySelector('input[name="videos[]"]');
      if (vid && vid.files) {
        for (var i = 0; i < vid.files.length; i++) {
          if (vid.files[i].size > LIMIT) {
            tip.textContent = '有视频超过服务器上限（约 <?php echo $limitMb; ?> MB），请压缩或剪短后再传';
            tip.style.color = '#dc2626';
            return;
          }
        }
      }
      var fd = new FormData(f);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', f.action, true);
      btn.disabled = true; btn.textContent = '发布中…';
      tip.style.color = ''; tip.textContent = '准备上传…';
      box.style.display = 'block'; bar.style.width = '0%';

      xhr.upload.onprogress = function(ev){
        if (ev.lengthComputable) {
          var p = Math.round(ev.loaded / ev.total * 100);
          bar.style.width = p + '%';
          tip.textContent = '上传中 ' + p + '%';
        }
      };
      xhr.onload = function(){
        var d = null;
        try { d = JSON.parse(xhr.responseText); } catch (err) {}
        if (d && d.ok) {
          tip.textContent = '发布成功，正在刷新…';
          bar.style.width = '100%';
          setTimeout(function(){ location.href = '<?php echo m_h($base); ?>/'; }, 400);
        } else {
          tip.textContent = (d && d.msg) || ('上传失败（HTTP ' + xhr.status + '）');
          tip.style.color = '#dc2626';
          btn.disabled = false; btn.textContent = '发布';
          box.style.display = 'none';
        }
      };
      xhr.onerror = function(){
        tip.textContent = '网络错误，请重试';
        tip.style.color = '#dc2626';
        btn.disabled = false; btn.textContent = '发布';
        box.style.display = 'none';
      };
      xhr.send(fd);
    });
  }
})();
</script>

<footer class="m-foot">
  <div class="m-foot-in">
    <span class="m-foot-c">&copy; <?php echo date('Y'); ?> MC.跃小叮当的博客</span>
    <nav class="m-foot-nav" aria-label="页脚导航">
      <a href="/index.php/about.html">关于</a>
      <a href="/index.php/feed/">RSS</a>
      <a href="/sitemap.xml">站点地图</a>
      <a class="m-foot-admin" href="/publish/"><?php echo $logged ? '写一条' : '管理入口'; ?></a>
    </nav>
  </div>
</footer>

<!-- 全站音乐播放器 -->
<link rel="stylesheet" href="/music/player.css?v=6">
<script src="/music/player.js?v=6" defer></script>
</body>
</html>
<?php
}
