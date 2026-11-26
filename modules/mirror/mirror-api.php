<?php
/**
 * 镜像数据网关 · /mirror-api.php
 * ------------------------------------------------------------------
 * GitHub Pages 是纯静态托管，跑不了 PHP，但页面可以用 fetch 跨域读这里。
 * 本文件把主站的实时数据整理成 JSON：文章、弹幕、音乐库、云盘公开文件。
 *
 * 只读、无凭据、允许任意来源（Access-Control-Allow-Origin: *），
 * 因此不暴露任何后台信息、私有文件、密码。
 *
 * 接口：
 *   ?action=stats        站点统计
 *   ?action=posts&limit=10&offset=0
 *   ?action=post&cid=12  单篇正文（也支持 &slug=hello-world）
 *   ?action=music        歌单 + 曲目（音频地址已绝对化）
 *   ?action=danmaku&since=0
 *   ?action=drive        云盘里已公开分享的文件
 *   ?action=bootstrap    stats + posts + danmaku + music 一次取回
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');
@ini_set('display_errors', '0');

/* ---------------- 跨域 ---------------- */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const M_MAX_POSTS = 60;

function m_json(array $d, int $code = 200, int $maxAge = 120): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=' . $maxAge);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function m_fail(string $msg, int $code = 400): void
{
    m_json(['ok' => false, 'error' => $msg], $code, 0);
}

/* ---------------- 定位数据库与站点地址 ---------------- */
$ROOT = __DIR__;
$site = '';
$prefix = 'typecho_';
$dbfile = $ROOT . '/usr/typecho.db';

/* 1) 从 config.inc.php 里抠（格式随版本会变，抠不到就走下一步） */
$cfgPath = $ROOT . '/config.inc.php';
if (is_file($cfgPath)) {
    $cfg = (string)@file_get_contents($cfgPath);
    if (preg_match("/(?:__TYPECHO_ROOT_DIR__|dirname\(__FILE__\))\s*\.\s*'([^']*\.db)'/", $cfg, $m)) {
        $dbfile = $ROOT . $m[1];
    } elseif (preg_match("/'file'\s*=>\s*'([^']*\.db)'/", $cfg, $m)) {
        $dbfile = $m[1];
    }
    if (preg_match("/Db\s*\(\s*'[A-Za-z_]+'\s*,\s*'([^']*)'\s*\)/", $cfg, $m)) {
        $prefix = $m[1];
    }
}

/* 2) 还不行就直接在 usr/ 里找 .db */
if (!is_file($dbfile)) {
    foreach ([$ROOT . '/usr', $ROOT] as $dir) {
        $hit = glob($dir . '/*.db') ?: [];
        if (!$hit) continue;
        usort($hit, static fn($a, $b) => filesize($b) <=> filesize($a));
        $dbfile = $hit[0];
        break;
    }
}

$pdo = null;
try {
    if (is_file($dbfile)) {
        $pdo = new PDO('sqlite:' . $dbfile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->query('PRAGMA query_only = 1');

        /* 3) 前缀也从表名反推：找 xxxoptions 表 */
        $tabs = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%options'")->fetchAll();
        foreach ($tabs as $t) {
            $p = substr((string)$t['name'], 0, -7);
            if (preg_match('/^[A-Za-z0-9_]*$/', $p)) {
                try {
                    if ((int)$pdo->query("SELECT COUNT(*) FROM {$p}options")->fetchColumn() > 0) {
                        $prefix = $p;
                        break;
                    }
                } catch (Throwable $e) {
                }
            }
        }

        $r = $pdo->query("SELECT value FROM {$prefix}options WHERE name='siteUrl' LIMIT 1")->fetch();
        if ($r && !empty($r['value'])) {
            $site = rtrim((string)$r['value'], '/');
        }
    }
} catch (Throwable $e) {
    $pdo = null;
}
if ($site === '') {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $site = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $site = rtrim($site, '/');
}

/* ---------------- 小工具 ---------------- */
function m_plain(string $html): string
{
    $t = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html);
    $t = strip_tags((string)$t);
    /* 正文里混着 Markdown 图片/链接语法时，摘要里不该出现 ![](...) 这种东西 */
    $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', '', (string)$t);
    $t = preg_replace('/!\[[^\]]*\]\[[^\]]*\]/u', '', (string)$t);
    $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', (string)$t);
    $t = preg_replace('/\[([^\]]*)\]\[[^\]]*\]/u', '$1', (string)$t);
    $t = preg_replace('/^\s*\[[^\]]+\]:\s*\S+.*$/mu', '', (string)$t);
    return trim(preg_replace('/\s+/u', ' ', (string)$t));
}

function m_first_image(string $html): string
{
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
        return (string)$m[1];
    }
    return '';
}

function m_reading_time(string $html): int
{
    $len = mb_strlen(m_plain($html), 'UTF-8');
    return max(1, (int)ceil($len / 300));
}

function m_cjk_first(string $s): string
{
    return mb_substr($s, 0, 1, 'UTF-8');
}

/** 把站内相对地址补成主站绝对地址 */
function m_abs(string $url, string $site): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if (str_starts_with($url, '//')) return 'https:' . $url;
    return $site . '/' . ltrim($url, '/');
}

function m_load_json(string $path): array
{
    if (!is_file($path)) return [];
    $raw = (string)@file_get_contents($path);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

/* ---------------- 数据读取 ---------------- */
/** @return array<int,array> */
function m_posts(PDO $pdo, string $prefix, string $site, int $limit, int $offset): array
{
    $limit = max(1, min(M_MAX_POSTS, $limit));
    $offset = max(0, $offset);
    $sql = "SELECT cid,title,slug,created,modified,text FROM {$prefix}contents
            WHERE type='post' AND status='publish'
            ORDER BY created DESC LIMIT {$limit} OFFSET {$offset}";
    $rows = $pdo->query($sql)->fetchAll();

    $cids = array_map(static fn($r) => (int)$r['cid'], $rows);
    $tagMap = [];
    if ($cids) {
        $in = implode(',', $cids);
        $tsql = "SELECT r.cid, m.name FROM {$prefix}relationships r
                 JOIN {$prefix}metas m ON m.mid = r.mid
                 WHERE m.type='tag' AND r.cid IN ({$in})
                 ORDER BY r.cid, m.mid";
        foreach ($pdo->query($tsql)->fetchAll() as $t) {
            $tagMap[(int)$t['cid']][] = (string)$t['name'];
        }
    }

    $out = [];
    foreach ($rows as $r) {
        $cid = (int)$r['cid'];
        $text = (string)($r['text'] ?? '');
        $title = trim((string)$r['title']);
        if ($title === '') $title = '无标题';
        $plain = m_plain($text);
        $tags = array_slice(array_values(array_unique($tagMap[$cid] ?? [])), 0, 2);
        $out[] = [
            'cid'   => $cid,
            'title' => $title,
            'date'  => date('Y-m-d', (int)$r['created']),
            'ts'    => (int)$r['created'],
            'brief' => mb_substr($plain, 0, 96, 'UTF-8'),
            'tags'  => $tags,
            'mins'  => m_reading_time($text),
            'url'   => $site . '/index.php/archives/' . $cid . '/',
            'cover' => m_abs(m_first_image($text), $site),
            'ch'    => m_cjk_first($title),
            'hue'   => 204 + (int)(crc32((string)$cid) % 22),
        ];
    }
    return $out;
}

function m_stats(PDO $pdo, string $prefix): array
{
    $one = static function (string $sql) use ($pdo): int {
        try {
            $v = $pdo->query($sql)->fetchColumn();
            return (int)$v;
        } catch (Throwable $e) {
            return 0;
        }
    };
    return [
        'posts'    => $one("SELECT COUNT(*) FROM {$prefix}contents WHERE type='post' AND status='publish'"),
        'pages'    => $one("SELECT COUNT(*) FROM {$prefix}contents WHERE type='page' AND status='publish'"),
        'comments' => $one("SELECT COUNT(*) FROM {$prefix}comments WHERE status='approved'"),
        'last'     => $one("SELECT MAX(created) FROM {$prefix}contents WHERE type='post' AND status='publish'"),
    ];
}

function m_music(string $root, string $site): array
{
    $d = m_load_json($root . '/music/data.json');
    $tracks = [];
    foreach (($d['tracks'] ?? []) as $t) {
        $id = (string)($t['id'] ?? '');
        if ($id === '') continue;
        $cov = basename((string)($t['cover'] ?? ''));
        $tracks[] = [
            'id'     => $id,
            'title'  => (string)($t['title'] ?? ''),
            'artist' => (string)($t['artist'] ?? ''),
            'note'   => (string)($t['note'] ?? ''),
            'tags'   => $t['tags'] ?? [],
            'dur'    => (int)($t['dur'] ?? 0),
            'size'   => (int)($t['size'] ?? 0),
            'added'  => (int)($t['added'] ?? 0),
            'mime'   => (string)($t['mime'] ?? 'audio/mpeg'),
            'cover'  => $cov === '' ? '' : $site . '/music/?cover=' . rawurlencode($id) . '&v=' . substr(md5($cov), 0, 8),
            'url'    => $site . '/music/?get=' . rawurlencode($id),
        ];
    }
    usort($tracks, static fn($a, $b) => $b['added'] <=> $a['added']);
    $pl = $d['playlist'] ?? [];
    return [
        'playlist' => [
            'title' => (string)($pl['title'] ?? '歌单'),
            'intro' => (string)($pl['intro'] ?? ''),
        ],
        'total'  => count($tracks),
        'tracks' => $tracks,
    ];
}

function m_danmaku(string $root, int $since = 0): array
{
    $d = m_load_json($root . '/danmaku/data.json');
    $list = [];
    foreach ($d as $it) {
        if (!is_array($it)) continue;
        $t = (int)($it['t'] ?? 0);
        if ($since > 0 && $t <= $since) continue;
        $list[] = [
            'id'   => (string)($it['id'] ?? $t),
            'text' => (string)($it['text'] ?? ''),
            'c'    => (int)($it['c'] ?? 0),
            't'    => $t,
        ];
    }
    usort($list, static fn($a, $b) => $a['t'] <=> $b['t']);
    return $list;
}

/** 云盘：只返回「已经生成公开分享链接」的文件 */
function m_drive(string $root, string $site): array
{
    $shares = m_load_json($root . '/drive/.shares.json');
    $files  = m_load_json($root . '/drive/files.json');
    $byId = [];
    foreach ($files as $k => $f) {
        if (!is_array($f)) continue;
        $id = (string)($f['id'] ?? $k);
        $byId[$id] = $f;
    }
    $out = [];
    foreach ($shares as $token => $s) {
        if (!is_array($s)) continue;
        $fid = (string)($s['id'] ?? '');
        $f = $byId[$fid] ?? null;
        if (!$f || !empty($f['is_dir'])) continue;
        $url = $site . '/drive/?act=share&t=' . rawurlencode((string)$token);
        $out[] = [
            'name'  => (string)($f['name'] ?? '未命名'),
            'size'  => (int)($f['size'] ?? 0),
            'time'  => (int)($f['time'] ?? 0),
            'ext'   => strtolower(pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION)),
            'url'   => $url,
            'dir'   => (string)($f['dir'] ?? ''),
        ];
    }
    usort($out, static fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/* ---------------- 分发 ---------------- */
$action = strtolower(trim((string)($_GET['action'] ?? 'stats')));
$maxAge = 120;

switch ($action) {
    case 'stats':
        if (!$pdo) m_fail('数据库不可用', 503);
        try {
            $st = m_stats($pdo, $prefix);
        } catch (Throwable $e) {
            m_fail('查询失败', 503);
        }
        $st['music']   = count(m_load_json($ROOT . '/music/data.json')['tracks'] ?? []);
        $st['danmaku'] = count(m_load_json($ROOT . '/danmaku/data.json'));
        $st['drive']   = count(m_drive($ROOT, $site));            // 已公开分享
        $allFiles      = m_load_json($ROOT . '/drive/files.json');
        $st['driveFiles'] = count(array_filter($allFiles, static fn($f) => is_array($f) && empty($f['is_dir'])));
        $st['site']    = $site;
        $st['now']     = time();
        m_json(['ok' => true] + $st);

        // no break（已 exit）

    case 'posts':
        if (!$pdo) m_fail('数据库不可用', 503);
        $limit  = (int)($_GET['limit'] ?? 10);
        $offset = (int)($_GET['offset'] ?? 0);
        try {
            $rows = m_posts($pdo, $prefix, $site, $limit, $offset);
            $total = (int)$pdo->query("SELECT COUNT(*) FROM {$prefix}contents WHERE type='post' AND status='publish'")->fetchColumn();
        } catch (Throwable $e) {
            m_fail('查询失败', 503);
        }
        m_json(['ok' => true, 'total' => $total, 'limit' => $limit, 'offset' => $offset, 'list' => $rows]);

    case 'post':
        if (!$pdo) m_fail('数据库不可用', 503);
        $cid  = (int)($_GET['cid'] ?? 0);
        $slug = trim((string)($_GET['slug'] ?? ''));
        try {
            if ($cid > 0) {
                $st = $pdo->prepare("SELECT cid,title,slug,created,modified,text FROM {$prefix}contents WHERE cid=? AND status='publish' LIMIT 1");
                $st->execute([$cid]);
            } elseif ($slug !== '') {
                $st = $pdo->prepare("SELECT cid,title,slug,created,modified,text FROM {$prefix}contents WHERE slug=? AND status='publish' LIMIT 1");
                $st->execute([$slug]);
            } else {
                m_fail('需要 cid 或 slug');
            }
            $row = $st->fetch();
        } catch (Throwable $e) {
            m_fail('查询失败', 503);
        }
        if (!$row) m_fail('文章不存在', 404);
        $cid = (int)$row['cid'];
        $tags = [];
        try {
            $ts = $pdo->prepare("SELECT m.name FROM {$prefix}relationships r JOIN {$prefix}metas m ON m.mid=r.mid WHERE m.type='tag' AND r.cid=? ORDER BY m.mid");
            $ts->execute([$cid]);
            $tags = array_map(static fn($x) => (string)$x['name'], $ts->fetchAll());
        } catch (Throwable $e) {
        }
        m_json([
            'ok'      => true,
            'cid'     => $cid,
            'title'   => trim((string)$row['title']) !== '' ? (string)$row['title'] : '无标题',
            'date'    => date('Y-m-d', (int)$row['created']),
            'updated' => date('Y-m-d', (int)$row['modified']),
            'tags'    => $tags,
            'html'    => (string)$row['text'],
            'url'     => $site . '/index.php/archives/' . $cid . '/',
            'mins'    => m_reading_time((string)$row['text']),
        ], 200, 60);

    case 'music':
        m_json(['ok' => true] + m_music($ROOT, $site));

    case 'danmaku':
        $since = (int)($_GET['since'] ?? 0);
        $list  = m_danmaku($ROOT, $since);
        m_json(['ok' => true, 'total' => count($list), 'list' => $list], 200, 0);

    case 'drive':
        $files = m_drive($ROOT, $site);
        m_json(['ok' => true, 'total' => count($files), 'list' => $files], 200, 60);

    case 'bootstrap':
        if (!$pdo) m_fail('数据库不可用', 503);
        $limit = (int)($_GET['limit'] ?? 6);
        $limit = max(1, min(20, $limit));
        try {
            $st    = m_stats($pdo, $prefix);
            $posts = m_posts($pdo, $prefix, $site, $limit, 0);
        } catch (Throwable $e) {
            m_json(['ok' => false, 'error' => '查询失败'], 503, 0);
        }
        $music = m_music($ROOT, $site);
        $st['music']   = $music['total'];
        $st['danmaku'] = count(m_load_json($ROOT . '/danmaku/data.json'));
        $st['drive']   = count(m_drive($ROOT, $site));
        $st['site']    = $site;
        $st['now']     = time();
        m_json([
            'ok'       => true,
            'stats'    => $st,
            'posts'    => $posts,
            'danmaku'  => m_danmaku($ROOT, (int)($_GET['since'] ?? 0)),
            'music'    => ['total' => $music['total'], 'playlist' => $music['playlist'], 'tracks' => array_slice($music['tracks'], 0, 3)],
            'drive'    => array_slice(m_drive($ROOT, $site), 0, 6),
            'site'     => $site,
        ], 200, 60);

    default:
        m_json([
            'ok'      => true,
            'service' => 'mirror-api',
            'actions' => ['stats', 'posts', 'post', 'music', 'danmaku', 'drive', 'bootstrap'],
            'site'    => $site,
        ], 200, 600);
}
