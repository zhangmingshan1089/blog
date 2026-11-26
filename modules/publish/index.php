<?php
/**
 * 发布台 · 一个入口，两处分发
 * ---------------------------------------------------------------
 * 写一次文案：
 *   · 短句 / 带照片 / 带视频  → 发到朋友圈（媒体只留在这里）
 *   · 长篇纯文字            → 同时生成一篇文章，朋友圈留一张卡片
 * 认证：Typecho 后台登录态，或朋友圈管理密码
 * 数据：../moments/data.json  +  Typecho contents 表
 */
declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    exit;
}

session_name('ZMS_MOMENTS_SESS');
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');

$P_ROOT = dirname(__DIR__);
$P_MOM  = $P_ROOT . '/moments';
$P_DATA = $P_MOM . '/data.json';
$P_UP   = $P_MOM . '/uploads';
$P_CFG  = $P_ROOT . '/config.inc.php';
$P_CAP  = 96 * 1024 * 1024;      // 视频硬上限
$P_MAXIMG = 30;
$P_MAXVID = 3;
$P_IMG_B  = 12 * 1024 * 1024;
$P_LONG   = 260;                  // 超过这个字数自动成文

/* ------------------------------------------------------------------ 基础 */

function p_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function p_json(array $r, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function p_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '') return 0;
    $u = strtolower(substr($v, -1));
    $n = (int)$v;
    switch ($u) {
        case 'g': $n *= 1024;
        case 'm': $n *= 1024;
        case 'k': $n *= 1024;
    }
    return $n;
}

/** 服务器真正能收下的单文件上限 */
function p_upload_limit(int $cap): int
{
    static $cache = [];
    if (isset($cache[$cap])) return $cache[$cap];
    $vals = [];
    $up = p_ini_bytes((string)ini_get('upload_max_filesize'));
    $po = p_ini_bytes((string)ini_get('post_max_size'));
    if ($up > 0) $vals[] = $up;
    if ($po > 0) $vals[] = $po;
    $v = $vals ? min($vals) : $cap;
    if ($v > $cap) $v = $cap;
    if ($v < 2 * 1024 * 1024) $v = 2 * 1024 * 1024;
    return $cache[$cap] = $v;
}

/* ------------------------------------------------------------------ 身份 */

function p_boot(): bool
{
    global $P_CFG;
    if (defined('__TYPECHO_ROOT_DIR__')) return true;
    if (!is_file($P_CFG)) return false;
    try {
        require_once $P_CFG;
        return defined('__TYPECHO_ROOT_DIR__');
    } catch (\Throwable $e) {
        return false;
    }
}

/** Typecho 后台登录态 */
function p_ty_logged(): bool
{
    static $v = null;
    if ($v !== null) return $v;
    $v = false;
    try {
        /* Typecho 的 cookie 名带 md5(rootUrl) 前缀，独立脚本里拿不到，
           所以直接按后缀找，避免依赖 Cookie::setPrefix 的时机 */
        $uid = 0;
        $code = '';
        foreach ($_COOKIE as $k => $val) {
            if (substr((string)$k, -13) === '__typecho_uid') {
                $uid  = (int)$val;
                $pre  = substr((string)$k, 0, -13);
                $code = (string)($_COOKIE[$pre . '__typecho_authCode'] ?? '');
                break;
            }
        }
        if ($uid <= 0 || $code === '') return $v = false;
        if (!p_boot()) return $v = false;
        $db  = \Typecho\Db::get();
        $row = $db->fetchRow($db->select('uid', 'authCode')->from('table.users')->where('uid = ?', $uid));
        if (!$row || empty($row['authCode'])) return $v = false;
        $v = \Typecho\Common::hashValidate((string)$row['authCode'], $code);
    } catch (\Throwable $e) {
        $v = false;
    }
    return $v;
}

function p_pwd_ok(string $p): bool
{
    global $P_MOM;
    foreach ([$P_MOM . '/.passwd', dirname($P_MOM) . '/drive/.passwd'] as $f) {
        if (is_file($f)) {
            $v = trim((string)@file_get_contents($f));
            if ($v === '') continue;
            return str_starts_with($v, '$2') ? password_verify($p, $v) : hash_equals($v, $p);
        }
    }
    return hash_equals('20100809aaa', $p);
}

function p_logged(): bool
{
    if (!empty($_SESSION['m_logged'])) return true;
    if (p_ty_logged()) {
        $_SESSION['m_logged'] = true;
        return true;
    }
    return false;
}

function p_csrf(): string
{
    if (empty($_SESSION['p_csrf'])) $_SESSION['p_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['p_csrf'];
}

function p_csrf_ok(): bool
{
    return hash_equals((string)($_SESSION['p_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''));
}

function p_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}

/* ------------------------------------------------------------------ 数据 */

function p_load(): array
{
    global $P_DATA;
    if (!is_file($P_DATA)) return [];
    $d = json_decode((string)@file_get_contents($P_DATA), true);
    return is_array($d) ? $d : [];
}

function p_save(array $d): bool
{
    global $P_DATA;
    $tmp = $P_DATA . '.tmp';
    $js  = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($js === false) return false;
    if (@file_put_contents($tmp, $js, LOCK_EX) === false) return false;
    return @rename($tmp, $P_DATA);
}

/** 纯文本 → 段落 HTML（不做 markdown，写什么显示什么） */
function p_html(string $t): string
{
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $out = '';
    foreach (preg_split('/\n{2,}/', trim($t)) ?: [] as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out .= '<p>' . nl2br(p_h($p), false) . '</p>' . "\n";
    }
    return $out;
}

/** 从正文里取一个标题 */
function p_title(string $t): string
{
    $one = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    $one = preg_replace('/^[#>\-\*\s]+/u', '', $one) ?? $one;
    if ($one === '') return '无题';
    if (preg_match('/^(.{4,32}?)[。！？!?…\n]/u', $one, $m)) return $m[1];
    $cut = mb_substr($one, 0, 24, 'UTF-8');
    return mb_strlen($one, 'UTF-8') > 24 ? $cut . '…' : $cut;
}

function p_brief(string $t, int $n = 120): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    return mb_strlen($s, 'UTF-8') > $n ? mb_substr($s, 0, $n, 'UTF-8') . '…' : $s;
}

function p_author_id(): int
{
    try {
        if (!p_boot()) return 1;
        $db  = \Typecho\Db::get();
        $row = $db->fetchRow($db->select('uid')->from('table.users')->order('uid', \Typecho\Db::SORT_ASC)->limit(1));
        return $row ? (int)$row['uid'] : 1;
    } catch (\Throwable $e) {
        return 1;
    }
}

/** 写入一篇文章，返回 [cid, url] */
function p_write_post(string $title, string $plain): array
{
    if (!p_boot()) throw new \RuntimeException('Typecho 未就绪');
    $db  = \Typecho\Db::get();
    $now = time();
    $cid = (int)$db->query($db->insert('table.contents')->rows([
        'title'        => $title,
        'slug'         => 'p' . $now . bin2hex(random_bytes(2)),
        'created'      => $now,
        'modified'     => $now,
        'text'         => p_html($plain),
        'order'        => 0,
        'authorId'     => p_author_id(),
        'template'     => '',
        'type'         => 'post',
        'status'       => 'publish',
        'password'     => null,
        'commentsNum'  => 0,
        'allowComment' => '1',
        'allowPing'    => '1',
        'allowFeed'    => '1',
        'parent'       => 0,
    ]));
    if ($cid <= 0) throw new \RuntimeException('写入失败');

    // 默认分类，保证后台列表与分类页能取到
    try {
        $mid = $db->fetchRow($db->select('mid')->from('table.metas')
            ->where('type = ?', 'category')->order('mid', \Typecho\Db::SORT_ASC)->limit(1));
        $db->query($db->insert('table.relationships')->rows([
            'cid' => $cid,
            'mid' => $mid ? (int)$mid['mid'] : 1,
        ]));
    } catch (\Throwable $e) {
    }

    return [$cid, '/index.php/archives/' . $cid . '/'];
}

/** 把新文章补进静态 sitemap（失败就算了） */
function p_sitemap(string $url, string $date): void
{
    global $P_ROOT;
    $f = $P_ROOT . '/sitemap.xml';
    if (!is_file($f) || !is_writable($f)) return;
    $xml = (string)@file_get_contents($f);
    if ($xml === '' || strpos($xml, $url) !== false) return;
    $entry = "  <url>\n    <loc>" . p_h($url) . "</loc>\n    <lastmod>" . $date
        . "</lastmod>\n    <changefreq>monthly</changefreq>\n    <priority>0.7</priority>\n  </url>\n";
    $pos = strpos($xml, '</urlset>');
    if ($pos === false) return;
    @file_put_contents($f, substr($xml, 0, $pos) . $entry . substr($xml, $pos), LOCK_EX);
}

/* ------------------------------------------------------------------ 上传 */

function p_img_ext(string $tmp): string
{
    $info = @getimagesize($tmp);
    if (!$info) return '';
    return [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$info[2]] ?? '';
}

function p_vid_ext(string $tmp, string $name): string
{
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)@finfo_file($fi, $tmp);
            @finfo_close($fi);
        }
    }
    $map = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-m4v' => 'm4v'];
    if (isset($map[$mime])) return $map[$mime];
    $g = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($g, ['mp4', 'webm', 'mov', 'm4v'], true) ? $g : '';
}

function p_take_images(int $max, int $maxBytes): array
{
    global $P_UP;
    if (empty($_FILES['images']['name'][0])) return [];
    if (!is_dir($P_UP)) @mkdir($P_UP, 0755, true);
    $f   = $_FILES['images'];
    $out = [];
    $n   = min(count($f['name']), $max);
    for ($i = 0; $i < $n; $i++) {
        if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp = (string)$f['tmp_name'][$i];
        $sz  = (int)$f['size'][$i];
        if ($sz <= 0 || $sz > $maxBytes || !is_uploaded_file($tmp)) continue;
        $ext = p_img_ext($tmp);
        if ($ext === '') continue;
        $name = 'i' . bin2hex(random_bytes(10)) . '.' . $ext;
        if (@move_uploaded_file($tmp, $P_UP . '/' . $name)) $out[] = 'uploads/' . $name;
    }
    return $out;
}

function p_take_videos(int $max, int $maxBytes): array
{
    global $P_UP;
    if (empty($_FILES['videos']['name'][0])) return [];
    if (!is_dir($P_UP)) @mkdir($P_UP, 0755, true);
    $f   = $_FILES['videos'];
    $out = [];
    $n   = min(count($f['name']), $max);
    for ($i = 0; $i < $n; $i++) {
        if ((int)$f['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp = (string)$f['tmp_name'][$i];
        $sz  = (int)$f['size'][$i];
        if ($sz <= 0 || $sz > $maxBytes || !is_uploaded_file($tmp)) continue;
        $ext = p_vid_ext($tmp, (string)$f['name'][$i]);
        if ($ext === '') continue;
        $name = 'v' . bin2hex(random_bytes(10)) . '.' . $ext;
        if (@move_uploaded_file($tmp, $P_UP . '/' . $name)) $out[] = 'uploads/' . $name;
    }
    return $out;
}

/* ------------------------------------------------------------------ 动作 */

$act = (string)($_REQUEST['act'] ?? '');
$err = '';

/* 请求体超过 post_max_size 时，PHP 会把 $_POST / $_FILES 清空，先给一句人话 */
if (($act === 'publish') && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $po = p_ini_bytes((string)ini_get('post_max_size'));
    p_json(['ok' => false, 'msg' => '这次上传太大，超过了服务器一次能接收的 ' . round($po / 1048576) . ' MB，请压缩或分两次发']);
}

if ($act === 'login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $_SESSION['p_fail'] = (int)($_SESSION['p_fail'] ?? 0);
    if ($_SESSION['p_fail'] >= 8) {
        $err = '尝试次数过多，请稍后再试';
    } elseif (p_pwd_ok((string)($_POST['pwd'] ?? ''))) {
        $_SESSION['m_logged'] = true;
        $_SESSION['p_fail']   = 0;
        header('Location: ./');
        exit;
    } else {
        $_SESSION['p_fail']++;
        $err = '密码不对';
    }
}

if ($act === 'logout') {
    $_SESSION['m_logged'] = false;
    header('Location: ./');
    exit;
}

if ($act === 'publish' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!p_logged()) p_json(['ok' => false, 'msg' => '登录已过期，请刷新页面重新登录'], 403);
    if (!p_csrf_ok()) p_json(['ok' => false, 'msg' => '表单已过期，刷新后再试']);

    $text  = trim((string)($_POST['text'] ?? ''));
    $text  = mb_substr($text, 0, 20000, 'UTF-8');
    $note  = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200, 'UTF-8');
    $anniv = trim((string)($_POST['anniv'] ?? ''));
    $mode  = (string)($_POST['mode'] ?? 'auto');        // auto | post | note
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $anniv)) $anniv = '';
    if (!in_array($mode, ['auto', 'post', 'note'], true)) $mode = 'auto';

    $limit = p_upload_limit($P_CAP);
    $imgs  = p_take_images($P_MAXIMG, $P_IMG_B);
    $vids  = p_take_videos($P_MAXVID, $limit);
    $hasImg = !empty($_FILES['images']['name'][0]);
    $hasVid = !empty($_FILES['videos']['name'][0]);

    if ($text === '' && !$imgs && !$vids) {
        $why = ($hasImg || $hasVid)
            ? '文件没上传成功：可能超过服务器上限（约 ' . round($limit / 1048576) . ' MB）或格式不支持'
            : '写点什么，或选几张照片 / 一段视频';
        p_json(['ok' => false, 'msg' => $why]);
    }

    $len  = mb_strlen($text, 'UTF-8');
    $makePost = $mode === 'post' || ($mode === 'auto' && !$imgs && !$vids && $len >= $P_LONG);
    if ($text === '') $makePost = false;   // 只有媒体时不硬造一篇空文章

    $postCid = 0;
    $postUrl = '';
    $postTitle = '';
    if ($makePost) {
        $postTitle = trim((string)($_POST['title'] ?? ''));
        if ($postTitle === '') $postTitle = p_title($text);
        $postTitle = mb_substr($postTitle, 0, 120, 'UTF-8');
        try {
            [$postCid, $postUrl] = p_write_post($postTitle, $text);
            p_sitemap('https://zhangmingshan.wwh.us.ci' . $postUrl, date('Y-m-d'));
        } catch (\Throwable $e) {
            p_json(['ok' => false, 'msg' => '文章写入失败：' . $e->getMessage()]);
        }
    }

    $type = $vids ? 'video' : ($imgs ? 'photo' : ($postCid ? 'article' : 'note'));
    $d    = p_load();
    array_unshift($d, [
        'id'         => bin2hex(random_bytes(8)),
        'type'       => $type,
        'text'       => mb_substr($text, 0, 3000, 'UTF-8'),
        'title'      => $postTitle,
        'post_cid'   => $postCid ?: null,
        'post_url'   => $postUrl ?: null,
        'note'       => $note,
        'anniv'      => $anniv,
        'images'     => $imgs,
        'videos'     => $vids,
        'time'       => time(),
        'ip'         => p_ip(),
    ]);
    if (count($d) > 800) $d = array_slice($d, 0, 800);
    if (!p_save($d)) {
        p_json(['ok' => false, 'msg' => '朋友圈保存失败：moments/data.json 不可写']);
    }

    p_json([
        'ok'         => true,
        'type'       => $type,
        'images'     => count($imgs),
        'videos'     => count($vids),
        'post'       => $postCid ? ['cid' => $postCid, 'url' => $postUrl, 'title' => $postTitle] : null,
        'msg'        => $postCid ? '已发布：文章 + 朋友圈' : '已发布到朋友圈',
    ]);
}

/* ------------------------------------------------------------------ 渲染 */

$logged = p_logged();
$limit  = p_upload_limit($P_CAP);
$limitMb = max(2, (int)round($limit / 1048576));
$owner  = $logged;
?><!DOCTYPE html>
<html lang="zh-CN" data-style="tech" data-theme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>写一条 · MC.跃小叮当的博客</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" href="/usr/themes/wbclean/assets/favicon-64.png" type="image/png">
<script>(function(){try{var t=localStorage.getItem('wb-theme');if(t!=='light'&&t!=='dark'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}var d=document.documentElement,d2=localStorage.getItem('wb-style');if(d2!=='paper'&&d2!=='tech')d2='tech';d.setAttribute('data-theme',t);d.setAttribute('data-style',d2);}catch(e){}})();</script>
<style>
:root{
  --bg:#FAF9F6;--card:#FFFFFF;--line:#E5E1D8;--line-2:#D6D0C2;
  --ink:#2B2B2B;--ink-2:#56534C;--ink-3:#8A867D;
  --accent:#3F6B5B;--accent-soft:#EDF3F0;--danger:#B4453B;
  --radius:6px;--shadow:none;
  --serif:"Noto Serif SC","Noto Serif CJK SC","Songti SC","STSong","SimSun",Georgia,serif;
  --sans:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
}
html[data-theme="dark"]{
  --bg:#1B1B19;--card:#232321;--line:#33322E;--line-2:#403E38;
  --ink:#E8E5DE;--ink-2:#BDB8AC;--ink-3:#8A867D;
  --accent:#8FB6A3;--accent-soft:#243029;--danger:#E08A80;
}
html[data-style="tech"]{
  --radius:16px;--serif:var(--sans);
  --shadow:0 1px 2px rgba(16,24,40,.04),0 10px 34px rgba(16,24,40,.06);
}
html[data-style="tech"][data-theme="light"]{
  --bg:#f4f6f9;--card:#fff;--line:#e6e9ef;--line-2:#d8dee9;
  --ink:#1d2430;--ink-2:#5a6577;--ink-3:#7c8a9e;
  --accent:#2f6fed;--accent-soft:#eef4ff;
}
html[data-style="tech"][data-theme="dark"]{
  --bg:#0e1319;--card:#161e29;--line:#222c3a;--line-2:#2c3849;
  --ink:#e9eef6;--ink-2:#a6b2c4;--ink-3:#7c8a9e;
  --accent:#5b94ff;--accent-soft:#182436;
  --shadow:0 1px 2px rgba(0,0,0,.3),0 10px 34px rgba(0,0,0,.26);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.8;
  -webkit-font-smoothing:antialiased;min-height:100svh}
a{color:inherit;text-decoration:none}
button{font-family:inherit}
::selection{background:var(--accent-soft);color:var(--accent)}

.p-bar{position:sticky;top:0;z-index:20;background:var(--bg);border-bottom:1px solid var(--line)}
.p-bar-in{max-width:920px;margin:0 auto;height:56px;padding:0 20px;display:flex;align-items:center;gap:14px}
.p-back{display:inline-flex;align-items:center;gap:6px;color:var(--ink-2);font-size:13.5px}
.p-back:hover{color:var(--accent)}
.p-back svg{width:14px;height:14px}
.p-bar-r{margin-left:auto;display:flex;align-items:center;gap:8px}
.p-mini{font-size:12.5px;color:var(--ink-3);padding:5px 10px;border-radius:999px;border:1px solid transparent;transition:.2s}
.p-mini:hover{color:var(--accent);border-color:var(--line)}
.p-ico{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;flex:none;
  border:1px solid var(--line);border-radius:50%;background:transparent;color:var(--ink-2);cursor:pointer;transition:.2s}
.p-ico:hover{color:var(--accent);border-color:var(--line-2)}
.p-ico .i-tech{display:none}
html[data-style="tech"] .p-ico .i-paper{display:none}
html[data-style="tech"] .p-ico .i-tech{display:inline-flex}

.p-main{max-width:920px;margin:0 auto;padding:34px 20px 90px}
.p-head{margin:0 0 22px}
.p-head h1{margin:0 0 8px;font-family:var(--serif);font-size:clamp(26px,3.4vw,34px);font-weight:600;letter-spacing:.01em}
.p-head p{margin:0;color:var(--ink-3);font-size:13.5px;line-height:1.9}
.p-head p b{color:var(--ink-2);font-weight:600}

.p-card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);
  padding:6px 22px 16px;margin-bottom:16px;transition:border-color .25s}
.p-card:focus-within{border-color:var(--line-2)}
.p-who{display:flex;align-items:center;gap:9px;padding:14px 0 4px;font-size:12.5px;color:var(--ink-3)}
.p-who img{width:26px;height:26px;border-radius:50%;object-fit:cover}
.p-who b{color:var(--ink-2);font-weight:600;font-size:13px}

#text{width:100%;min-height:150px;border:0;outline:none;resize:none;background:transparent;color:var(--ink);
  font-family:inherit;font-size:16.5px;line-height:1.95;padding:12px 0 6px}
#text::placeholder{color:var(--ink-3)}

.p-tools{display:flex;align-items:center;gap:4px;padding-top:6px;border-top:1px dashed var(--line);margin-top:4px}
.p-tool{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 11px;border:0;border-radius:8px;
  background:transparent;color:var(--ink-2);font-size:13px;cursor:pointer;transition:.18s}
.p-tool:hover{background:var(--accent-soft);color:var(--accent)}
.p-tool svg{width:16px;height:16px;flex:none}
.p-count{margin-left:auto;font-size:12px;color:var(--ink-3);font-variant-numeric:tabular-nums}

.p-att{display:grid;grid-template-columns:repeat(auto-fill,minmax(96px,1fr));gap:8px;margin:10px 0 4px}
.p-att:empty{display:none}
.p-thumb{position:relative;aspect-ratio:1;border-radius:var(--radius);overflow:hidden;background:#000;border:1px solid var(--line)}
html[data-style="paper"] .p-thumb{border-radius:3px}
.p-thumb img,.p-thumb video{width:100%;height:100%;object-fit:cover;display:block}
.p-thumb .x{position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;border:0;
  background:rgba(10,12,16,.62);color:#fff;font-size:12px;line-height:20px;cursor:pointer;padding:0;backdrop-filter:blur(4px)}
.p-thumb .vv{position:absolute;left:5px;bottom:5px;background:rgba(10,12,16,.6);color:#fff;font-size:10.5px;
  padding:1px 6px;border-radius:999px}

.p-opt{margin-top:16px;padding:16px 22px}
.p-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.p-switch{display:inline-flex;align-items:center;gap:10px;cursor:pointer;user-select:none;font-size:14px;color:var(--ink-2)}
.p-switch input{position:absolute;opacity:0;pointer-events:none}
.p-switch .track{width:38px;height:22px;border-radius:999px;background:var(--line-2);position:relative;transition:.22s;flex:none}
.p-switch .track::after{content:"";position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;
  background:#fff;transition:.22s cubic-bezier(.2,.9,.25,1);box-shadow:0 1px 3px rgba(0,0,0,.2)}
.p-switch input:checked + .track{background:var(--accent)}
.p-switch input:checked + .track::after{transform:translateX(16px)}
.p-switch input:focus-visible + .track{outline:2px solid var(--accent);outline-offset:2px}

.p-field{margin-top:12px}
.p-field input{width:100%;padding:10px 13px;border:1px solid var(--line);border-radius:calc(var(--radius) - 1px);
  background:transparent;color:var(--ink);font:inherit;font-size:14px;outline:none;transition:.2s}
.p-field input:focus{border-color:var(--accent);background:var(--accent-soft)}
.p-field input::placeholder{color:var(--ink-3)}
.p-fields{display:none}
.p-fields.on{display:block}

.p-more{margin-top:14px;border-top:1px dashed var(--line);padding-top:12px}
.p-more summary{cursor:pointer;font-size:12.5px;color:var(--ink-3);list-style:none;display:inline-flex;align-items:center;gap:6px}
.p-more summary::-webkit-details-marker{display:none}
.p-more summary::before{content:"+";font-size:14px;color:var(--ink-3)}
.p-more[open] summary::before{content:"−"}
.p-more .p-2col{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.p-more .p-2col input{flex:1 1 180px;padding:9px 12px;border:1px solid var(--line);border-radius:calc(var(--radius) - 1px);
  background:transparent;color:var(--ink);font:inherit;font-size:13.5px;outline:none}
.p-more .p-2col input:focus{border-color:var(--accent)}
.p-more .hint{font-size:11.5px;color:var(--ink-3);margin-top:8px}

.p-send{display:flex;align-items:center;gap:14px;margin-top:20px;flex-wrap:wrap}
.p-send .state{font-size:12.5px;color:var(--ink-3);line-height:1.7;flex:1 1 240px}
.p-send .state b{color:var(--accent);font-weight:600}
.p-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:42px;padding:0 26px;border:0;
  border-radius:999px;background:var(--accent);color:#fff;font-size:14.5px;font-weight:600;cursor:pointer;
  transition:transform .2s cubic-bezier(.2,.9,.25,1),filter .2s,box-shadow .2s;box-shadow:0 6px 18px rgba(0,0,0,.08)}
html[data-style="paper"] .p-btn{border-radius:4px;box-shadow:none}
.p-btn:hover{filter:brightness(1.06);transform:translateY(-1px)}
.p-btn:active{transform:scale(.97)}
.p-btn[disabled]{opacity:.55;cursor:default;transform:none;filter:none}
.p-btn.ghost{background:transparent;border:1px solid var(--line-2);color:var(--ink-2)}
.p-btn.ghost:hover{border-color:var(--accent);color:var(--accent);filter:none}

.p-prog{display:none;margin-top:14px}
.p-prog.on{display:block}
.p-prog .tr{height:3px;background:var(--line);border-radius:999px;overflow:hidden}
.p-prog .br{height:100%;width:0;background:var(--accent);border-radius:999px;transition:width .18s}
.p-prog .tx{font-size:11.5px;color:var(--ink-3);margin-top:6px}

.p-done{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);
  padding:26px 24px;margin-bottom:16px;display:none}
.p-done.on{display:block}
.p-done h2{margin:0 0 6px;font-family:var(--serif);font-size:20px;font-weight:600;display:flex;align-items:center;gap:9px}
.p-done h2 .dot{width:8px;height:8px;border-radius:50%;background:var(--accent);flex:none}
.p-done .sub{color:var(--ink-3);font-size:13px;margin:0 0 16px}
.p-links{display:flex;gap:10px;flex-wrap:wrap}

.p-login{max-width:360px;margin:44px auto 0;background:var(--card);border:1px solid var(--line);
  border-radius:var(--radius);box-shadow:var(--shadow);padding:26px 24px}
.p-login h2{margin:0 0 6px;font-family:var(--serif);font-size:19px;font-weight:600}
.p-login p{margin:0 0 16px;color:var(--ink-3);font-size:12.5px;line-height:1.8}
.p-login input{width:100%;padding:11px 13px;border:1px solid var(--line);border-radius:calc(var(--radius) - 1px);
  background:transparent;color:var(--ink);font:inherit;font-size:14px;outline:none;margin-bottom:12px}
.p-login input:focus{border-color:var(--accent)}
.p-errtx{color:var(--danger);font-size:12.5px;margin:-4px 0 10px}

.p-toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,14px);background:var(--ink);color:var(--bg);
  font-size:13px;padding:9px 16px;border-radius:999px;opacity:0;pointer-events:none;transition:.24s;z-index:80;max-width:86vw}
.p-toast.on{opacity:1;transform:translate(-50%,0)}

@media(max-width:640px){
  .p-bar-in{height:52px;padding:0 14px;gap:10px}
  .p-mini{display:none}
  .p-main{padding:24px 14px 80px}
  .p-card{padding:4px 16px 14px}
  .p-opt{padding:14px 16px}
  .p-head h1{font-size:26px}
  #text{font-size:16px;min-height:120px}
  .p-send .p-btn{width:100%}
  .p-att{grid-template-columns:repeat(auto-fill,minmax(76px,1fr))}
}
</style>
</head>
<body>

<div class="p-bar">
  <div class="p-bar-in">
    <a class="p-back" href="/moments/">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg>
      朋友圈
    </a>
    <div class="p-bar-r">
      <a class="p-mini" href="/index.php/archives.html">文章</a>
      <a class="p-mini" href="/admin/">后台</a>
      <button class="p-ico" id="styleBtn" type="button" aria-label="切换界面风格" title="切换界面风格">
        <span class="i-paper"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M5 3.5h9.2L19 8.3V20.5H5z"/><path d="M14 3.7V8.4h4.7"/><path d="M8 12.5h8M8 16h5.5"/></svg></span>
        <span class="i-tech"><svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.2v17.6M3.2 12h17.6"/><path d="M6.4 6.4l11.2 11.2M17.6 6.4L6.4 17.6" opacity=".55"/></svg></span>
      </button>
      <button class="p-ico" id="themeBtn" type="button" aria-label="切换深浅色" title="切换深浅色"><span id="themeIco">☾</span></button>
    </div>
  </div>
</div>

<main class="p-main">

<?php if (!$owner): ?>
  <div class="p-login">
    <h2>写一条</h2>
    <p>这是站主的发布台。已登录 <a href="/admin/" style="color:var(--accent)">后台</a> 会自动识别；也可以直接输入发布密码。</p>
    <?php if ($err !== ''): ?><div class="p-errtx"><?php echo p_h($err); ?></div><?php endif; ?>
    <form method="post" action="./?act=login">
      <input type="password" name="pwd" placeholder="发布密码" autofocus required>
      <button class="p-btn" type="submit" style="width:100%">进入</button>
    </form>
  </div>
<?php else: ?>

  <div class="p-head">
    <h1>写一条</h1>
    <p>一次写完，<b>文章与朋友圈自动同步</b>。照片和视频只留在朋友圈，不会塞进文章正文。</p>
  </div>

  <div class="p-done" id="done">
    <h2><span class="dot"></span><span id="doneTitle">已发布</span></h2>
    <p class="sub" id="doneSub"></p>
    <div class="p-links">
      <a class="p-btn" id="doneMoment" href="/moments/">看朋友圈</a>
      <a class="p-btn ghost" id="donePost" href="#" style="display:none">读文章</a>
      <a class="p-btn ghost" id="doneAgain" href="./">再写一条</a>
    </div>
  </div>

  <form id="pubForm" method="post" action="./?act=publish" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="csrf" value="<?php echo p_h(p_csrf()); ?>">
    <input type="hidden" name="mode" id="modeField" value="auto">

    <div class="p-card">
      <div class="p-who">
        <img src="/usr/themes/wbclean/assets/avatar.jpg" alt="">
        <b>MC.跃小叮当</b><span>· 现在</span>
      </div>
      <textarea id="text" name="text" placeholder="此刻的想法…&#10;&#10;短句会发到朋友圈；写长了自动成为一篇文章，朋友圈里留一张卡片。" autofocus></textarea>
      <div class="p-att" id="att"></div>
      <div class="p-tools">
        <button class="p-tool" type="button" id="pickImg">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><circle cx="8.6" cy="9.6" r="1.6"/><path d="m4.6 17.4 4.6-4.6 3.2 3.2 2.6-2.6 4.4 4.4"/></svg>
          照片
        </button>
        <button class="p-tool" type="button" id="pickVid">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5.5" width="13.5" height="13" rx="2.5"/><path d="m16.5 12 5-3.2v6.4z"/></svg>
          视频
        </button>
        <span class="p-count" id="count">0 字</span>
      </div>
      <input type="file" id="imgInput" name="images[]" accept="image/*" multiple hidden>
      <input type="file" id="vidInput" name="videos[]" accept="video/*" multiple hidden>
    </div>

    <div class="p-opt p-card">
      <div class="p-row">
        <label class="p-switch">
          <input type="checkbox" id="asPost">
          <span class="track"></span>
          <span>同时写成文章</span>
        </label>
        <span id="modeTip" style="font-size:12px;color:var(--ink-3)">写够 <?php echo $P_LONG; ?> 字会自动打开</span>
      </div>
      <div class="p-fields" id="postFields">
        <div class="p-field">
          <input type="text" name="title" id="title" maxlength="120" placeholder="标题（留空就取第一句话）">
        </div>
      </div>

      <details class="p-more">
        <summary>备注、纪念日</summary>
        <div class="p-2col">
          <input type="text" name="note" maxlength="200" placeholder="备注：地点 / 心情 / 一句补充">
          <input type="date" name="anniv" title="纪念日">
        </div>
        <div class="hint">填了日期，卡片会显示「第 N 天」。备注只出现在朋友圈。</div>
      </details>
    </div>

    <div class="p-send">
      <span class="state" id="state"></span>
      <button class="p-btn" type="submit" id="sendBtn">发布</button>
    </div>

    <div class="p-prog" id="prog">
      <div class="tr"><div class="br" id="progBar"></div></div>
      <div class="tx" id="progTx">准备中…</div>
    </div>
  </form>

  <p style="font-size:11.5px;color:var(--ink-3);margin:18px 0 0">
    单张照片 ≤ 12 MB，最多 <?php echo $P_MAXIMG; ?> 张；视频最多 <?php echo $P_MAXVID; ?> 段，单段 ≤ <?php echo $limitMb; ?> MB。
    <a href="./?act=logout" style="color:var(--ink-3);text-decoration:underline;text-underline-offset:3px">退出</a>
  </p>

<?php endif; ?>
</main>

<div class="p-toast" id="toast"></div>

<script>
(function(){
  var d = document.documentElement;
  var sb = document.getElementById('styleBtn');
  if (sb) sb.addEventListener('click', function(){
    var n = d.getAttribute('data-style') === 'tech' ? 'paper' : 'tech';
    d.setAttribute('data-style', n);
    try { localStorage.setItem('wb-style', n); } catch(e){}
  });
  var tb = document.getElementById('themeBtn');
  if (tb) tb.addEventListener('click', function(){
    var n = d.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    d.setAttribute('data-theme', n);
    var i = document.getElementById('themeIco'); if (i) i.textContent = n === 'dark' ? '☀' : '☾';
    try { localStorage.setItem('wb-theme', n); } catch(e){}
  });
  var ti = document.getElementById('themeIco');
  if (ti) ti.textContent = d.getAttribute('data-theme') === 'dark' ? '☀' : '☾';

  var form = document.getElementById('pubForm');
  if (!form) return;

  var text   = document.getElementById('text');
  var count  = document.getElementById('count');
  var state  = document.getElementById('state');
  var modeTip= document.getElementById('modeTip');
  var asPost = document.getElementById('asPost');
  var fields = document.getElementById('postFields');
  var title  = document.getElementById('title');
  var modeF  = document.getElementById('modeField');
  var att    = document.getElementById('att');
  var imgIn  = document.getElementById('imgInput');
  var vidIn  = document.getElementById('vidInput');
  var sendBtn= document.getElementById('sendBtn');
  var prog   = document.getElementById('prog');
  var progBar= document.getElementById('progBar');
  var progTx = document.getElementById('progTx');
  var toast  = document.getElementById('toast');
  var done   = document.getElementById('done');

  var LONG = <?php echo (int)$P_LONG; ?>;
  var MAXIMG = <?php echo (int)$P_MAXIMG; ?>;
  var MAXVID = <?php echo (int)$P_MAXVID; ?>;
  var LIMIT  = <?php echo (int)$limit; ?>;

  var imgFiles = [];
  var vidFiles = [];
  var manual   = false;   // 用户手动动过开关

  function chars(s){ return Array.from(s.replace(/\r/g, '')).length; }

  function toastMsg(m){
    toast.textContent = m;
    toast.classList.add('on');
    clearTimeout(toastMsg._t);
    toastMsg._t = setTimeout(function(){ toast.classList.remove('on'); }, 2600);
  }

  function autoGrow(){
    text.style.height = 'auto';
    text.style.height = Math.min(560, Math.max(120, text.scrollHeight)) + 'px';
  }

  function firstSentence(s){
    var one = s.replace(/\s+/g, ' ').trim();
    var m = one.match(/^(.{4,32}?)[。！？!?…]/);
    if (m) return m[1];
    return one.length > 24 ? one.slice(0, 24) + '…' : one;
  }

  function refresh(){
    var n = chars(text.value);
    var media = imgFiles.length + vidFiles.length;
    count.textContent = n + ' 字';

    if (!manual) {
      var auto = (media === 0 && n >= LONG);
      asPost.checked = auto;
    }
    fields.classList.toggle('on', asPost.checked);
    modeF.value = asPost.checked ? 'post' : 'note';

    if (title && !title.dataset.touched && asPost.checked && n > 0) {
      title.placeholder = '标题：' + firstSentence(text.value);
    }

    if (media) {
      state.innerHTML = imgFiles.length + ' 张照片' + (vidFiles.length ? ' · ' + vidFiles.length + ' 段视频' : '')
        + ' 只留在朋友圈' + (asPost.checked ? '，文章里只有文字' : '');
      modeTip.textContent = '照片和视频不会进文章';
    } else if (asPost.checked) {
      state.innerHTML = '将作为<b>文章</b>发布，朋友圈留一张卡片';
      modeTip.textContent = '文章只放文字';
    } else if (n >= LONG) {
      state.innerHTML = '将只发到<b>朋友圈</b>（' + n + ' 字，可以打开上面的开关写成文章）';
      modeTip.textContent = '超过 ' + LONG + ' 字，建议写成文章';
    } else {
      state.innerHTML = '将发到<b>朋友圈</b>';
      modeTip.textContent = '写够 ' + LONG + ' 字会自动打开';
    }
    autoGrow();
  }

  function renderAtt(){
    att.innerHTML = '';
    imgFiles.forEach(function(f, i){
      var d1 = document.createElement('div');
      d1.className = 'p-thumb';
      var img = document.createElement('img');
      img.src = URL.createObjectURL(f);
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'x'; x.textContent = '×';
      x.setAttribute('aria-label', '移除这张照片');
      x.onclick = function(){ imgFiles.splice(i, 1); renderAtt(); refresh(); };
      d1.appendChild(img); d1.appendChild(x);
      att.appendChild(d1);
    });
    vidFiles.forEach(function(f, i){
      var d2 = document.createElement('div');
      d2.className = 'p-thumb';
      var v = document.createElement('video');
      v.src = URL.createObjectURL(f); v.muted = true; v.playsInline = true;
      v.onloadeddata = function(){ try { v.currentTime = 0.1; } catch(e){} };
      var tag = document.createElement('span');
      tag.className = 'vv'; tag.textContent = '视频';
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'x'; x.textContent = '×';
      x.setAttribute('aria-label', '移除这段视频');
      x.onclick = function(){ vidFiles.splice(i, 1); renderAtt(); refresh(); };
      d2.appendChild(v); d2.appendChild(tag); d2.appendChild(x);
      att.appendChild(d2);
    });
  }

  function addFiles(list, kind){
    var arr = Array.prototype.slice.call(list || []);
    if (kind === 'img') {
      arr.forEach(function(f){
        if (imgFiles.length >= MAXIMG) return;
        if (!/^image\//.test(f.type)) return;
        if (f.size > 12 * 1024 * 1024) { toastMsg('照片超过 12 MB：' + f.name); return; }
        imgFiles.push(f);
      });
    } else {
      arr.forEach(function(f){
        if (vidFiles.length >= MAXVID) return;
        if (!/^video\//.test(f.type)) return;
        if (f.size > LIMIT) { toastMsg('视频超过服务器上限：' + f.name); return; }
        vidFiles.push(f);
      });
    }
    renderAtt(); refresh();
  }

  document.getElementById('pickImg').onclick = function(){ imgIn.click(); };
  document.getElementById('pickVid').onclick = function(){ vidIn.click(); };
  imgIn.onchange = function(){ addFiles(imgIn.files, 'img'); imgIn.value = ''; };
  vidIn.onchange = function(){ addFiles(vidIn.files, 'vid'); vidIn.value = ''; };

  text.addEventListener('input', refresh);
  asPost.addEventListener('change', function(){ manual = true; refresh(); });
  if (title) title.addEventListener('input', function(){ title.dataset.touched = '1'; });

  // 拖拽到页面里直接添加
  ['dragover','drop'].forEach(function(ev){
    document.addEventListener(ev, function(e){ e.preventDefault(); });
  });
  document.addEventListener('drop', function(e){
    var f = e.dataTransfer && e.dataTransfer.files;
    if (!f || !f.length) return;
    var kinds = Array.prototype.map.call(f, function(x){ return x.type; });
    if (kinds.every(function(t){ return /^image\//.test(t); })) addFiles(f, 'img');
    else if (kinds.every(function(t){ return /^video\//.test(t); })) addFiles(f, 'vid');
    else toastMsg('一次拖一种：要么全是照片，要么全是视频');
  });

  document.addEventListener('keydown', function(e){
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.submit(); }
  });

  form.addEventListener('submit', function(e){
    e.preventDefault();
    if (!text.value.trim() && !imgFiles.length && !vidFiles.length) { toastMsg('写点什么，或选几张照片'); text.focus(); return; }

    var fd = new FormData();
    fd.append('csrf', form.querySelector('input[name="csrf"]').value);
    fd.append('text', text.value);
    fd.append('mode', modeF.value);
    fd.append('title', asPost.checked ? (title.value || '') : '');
    fd.append('note', form.querySelector('input[name="note"]').value || '');
    fd.append('anniv', form.querySelector('input[name="anniv"]').value || '');
    imgFiles.forEach(function(f){ fd.append('images[]', f, f.name); });
    vidFiles.forEach(function(f){ fd.append('videos[]', f, f.name); });

    sendBtn.disabled = true;
    sendBtn.textContent = '发布中…';
    prog.classList.add('on');
    progBar.style.width = '0%';
    progTx.textContent = '准备上传…';

    var xhr = new XMLHttpRequest();
    xhr.open('POST', form.action, true);
    xhr.upload.onprogress = function(ev){
      if (ev.lengthComputable) {
        var p = Math.round(ev.loaded / ev.total * 100);
        progBar.style.width = p + '%';
        progTx.textContent = '上传中 ' + p + '%';
      }
    };
    xhr.onload = function(){
      var r = null;
      try { r = JSON.parse(xhr.responseText); } catch(err){}
      if (!r || !r.ok) {
        progTx.textContent = (r && r.msg) || ('失败（HTTP ' + xhr.status + '）');
        progTx.style.color = 'var(--danger)';
        sendBtn.disabled = false; sendBtn.textContent = '发布';
        return;
      }
      progBar.style.width = '100%';
      progTx.textContent = r.msg || '完成';

      document.getElementById('doneTitle').textContent = r.post ? '已发布：文章 + 朋友圈' : '已发布到朋友圈';
      document.getElementById('doneSub').textContent = r.post
        ? '文章《' + r.post.title + '》已生成，朋友圈里会显示一张卡片指向它。' + (r.images ? ' ' + r.images + ' 张照片只留在朋友圈。' : '')
        : (r.images || r.videos ? '照片 / 视频都留在朋友圈，文章列表没有新增。' : '只有一条碎片，没有生成文章。');
      var dp = document.getElementById('donePost');
      if (r.post) { dp.href = r.post.url; dp.style.display = 'inline-flex'; }
      else { dp.style.display = 'none'; }

      form.style.display = 'none';
      done.classList.add('on');
      done.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };
    xhr.onerror = function(){
      progTx.textContent = '网络中断，检查一下再试';
      progTx.style.color = 'var(--danger)';
      sendBtn.disabled = false; sendBtn.textContent = '发布';
    };
    xhr.send(fd);
  });

  refresh();
})();
</script>
</body>
</html>
