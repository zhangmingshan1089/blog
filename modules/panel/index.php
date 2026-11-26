<?php
/**
 * MC.跃小叮当的博客 · 管理台
 *
 * 单文件后台面板：服务器状态（负载 / 内存 / 磁盘 / 网络）+ 内容统计 + 快捷入口
 * 密码与云盘共用 drive/.passwd（bcrypt）
 */
declare(strict_types=1);

$ROOT = dirname(__DIR__);
date_default_timezone_set('Asia/Shanghai');

/* ---------------- 工具 ---------------- */

function wb_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return 0;
    $n = (float)$v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') $n *= 1024;
    if ($u === 'g' || $u === 'm') $n *= 1024;
    if ($u === 'g' || $u === 'm' || $u === 'k') $n *= 1024;
    return (int)$n;
}

function wb_size(float $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return ($i === 0 ? (string)(int)round($b) : number_format($b, $b < 10 ? 1 : 0)) . ' ' . $u[$i];
}

function wb_pw_ok(string $pass, string $root): bool
{
    $f = $root . '/drive/.passwd';
    if (!is_file($f)) return false;
    $hash = trim((string)@file_get_contents($f));
    return $hash !== '' && password_verify($pass, $hash);
}

/** 站点体积（缓存 120s） */
function wb_site_size(string $root): array
{
    $bytes = 0; $files = 0;
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $t0 = microtime(true);
        foreach ($it as $f) {
            if (microtime(true) - $t0 > 5) break;
            if ($f->isFile()) { $files++; $bytes += $f->getSize(); }
        }
    } catch (Throwable $e) {}
    return ['files' => $files, 'bytes' => $bytes];
}

/** 内容统计（SQLite，前缀自动探测） */
function wb_content(string $root): array
{
    $out = [
        'ok' => false, 'posts' => 0, 'pages' => 0, 'drafts' => 0, 'comments' => 0,
        'metas' => 0, 'chars' => 0, 'recent' => [], 'db_size' => 0,
    ];
    $dbFile = '';
    foreach (glob($root . '/usr/*.db') ?: [] as $f) { $dbFile = $f; break; }
    if ($dbFile === '') return $out;
    $out['db_size'] = (int)@filesize($dbFile);

    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        $t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%_contents'")->fetchColumn();
        if (!$t) return $out;
        $p = substr((string)$t, 0, -strlen('contents'));

        $one = function (string $sql) use ($pdo, $p): int {
            try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { return 0; }
        };
        $out['posts']    = $one("SELECT COUNT(*) FROM {$p}contents WHERE type='post' AND status='publish'");
        $out['pages']    = $one("SELECT COUNT(*) FROM {$p}contents WHERE type='page' AND status='publish'");
        $out['drafts']   = $one("SELECT COUNT(*) FROM {$p}contents WHERE status<>'publish' AND type IN ('post','page')");
        $out['comments'] = $one("SELECT COUNT(*) FROM {$p}comments");
        $out['metas']    = $one("SELECT COUNT(*) FROM {$p}metas");

        try {
            $nm = $pdo->query("SELECT value FROM {$p}options WHERE name='title'")->fetchColumn();
            if ($nm) $out['site_name'] = (string)$nm;
        } catch (Throwable $e) {}

        try {
            $rows = $pdo->query("SELECT text FROM {$p}contents WHERE type='post' AND status='publish'")->fetchAll(PDO::FETCH_COLUMN);
            $chars = 0;
            foreach ($rows as $txt) { $chars += mb_strlen(strip_tags((string)$txt), 'UTF-8'); }
            $out['chars'] = $chars;
        } catch (Throwable $e) {}

        try {
            $rows = $pdo->query("SELECT cid,title,created,status,type FROM {$p}contents
                                 WHERE type IN ('post','page') ORDER BY created DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $out['recent'][] = [
                    'cid'    => (int)$r['cid'],
                    'title'  => (string)$r['title'],
                    'date'   => date('m-d H:i', (int)$r['created']),
                    'type'   => (string)$r['type'],
                    'status' => (string)$r['status'],
                ];
            }
        } catch (Throwable $e) {}

        $out['ok'] = true;
    } catch (Throwable $e) {
        return $out;
    }
    return $out;
}

/** JSON 文件条数 */
function wb_json_count(string $file): int
{
    if (!is_file($file)) return 0;
    $raw = (string)@file_get_contents($file);
    $arr = json_decode($raw, true);
    return is_array($arr) ? count($arr) : 0;
}

/** 汇总 */
function wb_stats(string $root): array
{
    $load = function_exists('sys_getloadavg') ? (array)@sys_getloadavg() : [0, 0, 0];

    /* 内存：PHP 进程视角（共享主机读不到 /proc/meminfo） */
    $ru = function_exists('getrusage') ? @getrusage() : [];
    $rss = isset($ru['ru_maxrss']) ? (int)$ru['ru_maxrss'] * 1024 : 0;   // Linux: KB
    $limit = wb_bytes((string)ini_get('memory_limit'));

    /* 磁盘 */
    $dt = (float)@disk_total_space($root);
    $df = (float)@disk_free_space($root);

    /* 缓存：体积 120s / 内容 30s */
    $cacheFile = sys_get_temp_dir() . '/wbpanel-cache.json';
    $cache = [];
    if (is_file($cacheFile)) {
        $cache = json_decode((string)@file_get_contents($cacheFile), true) ?: [];
    }
    $now = time();
    if (empty($cache['site_at']) || $now - (int)$cache['site_at'] > 120) {
        $cache['site'] = wb_site_size($root);
        $cache['site_at'] = $now;
    }
    if (empty($cache['cont_at']) || $now - (int)$cache['cont_at'] > 30) {
        $cache['content'] = wb_content($root);
        $cache['cont_at'] = $now;
    }
    @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);

    /* 网卡（拿得到地址，拿不到流量：主机未开放 /proc/net/dev） */
    $iface = ['name' => '', 'ip' => ''];
    if (function_exists('net_get_interfaces')) {
        foreach ((array)@net_get_interfaces() as $name => $info) {
            if ($name === 'lo' || empty($info['up'])) continue;
            foreach ((array)($info['unicast'] ?? []) as $u) {
                if (isset($u['address']) && filter_var($u['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $iface = ['name' => (string)$name, 'ip' => (string)$u['address']];
                    break 2;
                }
            }
        }
    }

    $content = $cache['content'] ?? wb_content($root);

    return [
        'ts'   => $now,
        'time' => date('H:i:s'),
        'load' => array_map(fn($v) => round((float)$v, 2), array_pad($load, 3, 0)),
        'mem'  => [
            'rss'   => $rss,
            'usage' => memory_get_usage(true),
            'peak'  => memory_get_peak_usage(true),
            'limit' => $limit,
        ],
        'disk' => [
            'total' => (int)$dt,
            'free'  => (int)$df,
            'used'  => max(0, (int)$dt - (int)$df),
        ],
        'site' => $cache['site'] ?? ['files' => 0, 'bytes' => 0],
        'net'  => $iface,
        'sys'  => [
            'host'   => (string)@gethostname(),
            'os'     => function_exists('php_uname') ? php_uname('s') . ' ' . php_uname('r') : PHP_OS,
            'php'    => PHP_VERSION,
            'sapi'   => PHP_SAPI,
            'server' => (string)($_SERVER['SERVER_SOFTWARE'] ?? ''),
            'upload' => (string)ini_get('upload_max_filesize'),
            'post'   => (string)ini_get('post_max_size'),
            'time'   => (string)ini_get('max_execution_time'),
            'memlim' => (string)ini_get('memory_limit'),
        ],
        'site_name' => (string)($content['site_name'] ?? ''),
        'content' => [
            'ok'       => (bool)($content['ok'] ?? false),
            'posts'    => (int)($content['posts'] ?? 0),
            'pages'    => (int)($content['pages'] ?? 0),
            'drafts'   => (int)($content['drafts'] ?? 0),
            'comments' => (int)($content['comments'] ?? 0),
            'metas'    => (int)($content['metas'] ?? 0),
            'chars'    => (int)($content['chars'] ?? 0),
            'db'       => (int)($content['db_size'] ?? 0),
            'moments'  => wb_json_count($root . '/moments/data.json'),
            'drive'    => wb_json_count($root . '/drive/files.json'),
            'recent'   => $content['recent'] ?? [],
        ],
    ];
}

/* ---------------- 会话 ---------------- */

ini_set('session.gc_maxlifetime', '2592000');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
session_name('wbp');
session_start();

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (isset($_GET['logout'])) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'));
    exit;
}

$authed = !empty($_SESSION['wb_t']) && (time() - (int)$_SESSION['wb_t'] < 2592000);
$loginErr = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['p'])) {
    if (!empty($_SESSION['wb_try']) && time() - (int)$_SESSION['wb_try'] < 3) {
        $loginErr = '慢一点，等两三秒再试';
    } elseif (wb_pw_ok((string)$_POST['p'], $ROOT)) {
        $_SESSION['wb_t'] = time();
        $_SESSION['wb_try'] = time();
        @session_regenerate_id(true);
        header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?'));
        exit;
    } else {
        $_SESSION['wb_try'] = time();
        $loginErr = '密码不对';
    }
}

/* ---------------- 接口 ---------------- */

$api = (string)($_GET['api'] ?? '');
if ($api !== '') {
    header('Cache-Control: no-store, max-age=0');
    if (!$authed) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'err' => 'auth']);
        exit;
    }
    if ($api === 'stats') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true] + wb_stats($ROOT), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($api === 'ping') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 't' => microtime(true)]);
        exit;
    }
    if ($api === 'speed') {
        $n = (int)($_GET['n'] ?? 262144);
        $n = max(65536, min(2097152, $n));
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . $n);
        $chunk = random_bytes(8192);
        $sent = 0;
        while ($sent < $n) {
            $take = min(8192, $n - $sent);
            echo $take === 8192 ? $chunk : substr($chunk, 0, $take);
            $sent += $take;
        }
        exit;
    }
    http_response_code(404);
    exit;
}

$boot = $authed ? wb_stats($ROOT) : null;

/* ---------------- 登录页 ---------------- */
if (!$authed):
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>管理台 · 登录</title>
<style>
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;background:#080d15;color:#e8eef7;font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
  display:flex;align-items:center;justify-content:center;padding:24px;position:relative;overflow:hidden}
body::before,body::after{content:'';position:absolute;border-radius:50%;filter:blur(90px);opacity:.5;pointer-events:none}
body::before{width:520px;height:520px;left:-120px;top:-160px;background:radial-gradient(circle,#1d4ed8,transparent 70%)}
body::after{width:460px;height:460px;right:-140px;bottom:-180px;background:radial-gradient(circle,#0e7490,transparent 70%)}
.login{position:relative;z-index:2;width:100%;max-width:352px;padding:30px 28px 24px;border-radius:20px;
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);
  box-shadow:0 24px 60px rgba(0,0,0,.55);backdrop-filter:blur(18px);-webkit-backdrop-filter:blur(18px)}
.logo{width:46px;height:46px;border-radius:14px;display:grid;place-items:center;font-weight:800;font-size:19px;
  background:linear-gradient(140deg,#4c8dff,#22d3ee);color:#04101f;margin-bottom:16px;letter-spacing:-.5px}
h1{margin:0;font-size:20px;letter-spacing:.5px}
.tip{margin:6px 0 20px;font-size:13px;color:#8598b4;line-height:1.7}
input[type=password]{width:100%;height:44px;padding:0 14px;border-radius:12px;font-size:15px;letter-spacing:2px;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#e8eef7;outline:none;
  transition:border-color .18s,box-shadow .18s,background .18s}
input[type=password]:focus{border-color:#4c8dff;background:rgba(76,141,255,.08);box-shadow:0 0 0 3px rgba(76,141,255,.18)}
button{width:100%;height:44px;margin-top:12px;border:0;border-radius:12px;cursor:pointer;font-size:15px;font-weight:600;
  color:#04101f;background:linear-gradient(140deg,#5b9bff,#28d8ee);transition:transform .16s,box-shadow .2s,filter .2s}
button:hover{filter:brightness(1.06);box-shadow:0 10px 24px rgba(60,140,255,.35)}
button:active{transform:scale(.98)}
.err{margin:12px 0 0;font-size:13px;color:#fca5a5}
.foot{margin:18px 0 0;font-size:12px;color:#5d6f8a;text-align:center}
</style>
</head>
<body>
<form class="login" method="post" autocomplete="off">
  <div class="logo">跃</div>
  <h1>管理台</h1>
  <p class="tip">和云盘共用同一个密码。登录后可以看服务器状态，也能直接跳去写东西。</p>
  <input type="password" name="p" placeholder="密码" autofocus required>
  <button type="submit">进入</button>
  <?php if ($loginErr !== ''): ?><p class="err"><?php echo htmlspecialchars($loginErr, ENT_QUOTES); ?></p><?php endif; ?>
  <p class="foot">仅站主可见 · 不会被搜索引擎收录</p>
</form>
</body>
</html>
<?php
exit;
endif;

/* ---------------- 面板 ---------------- */
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#080d15">
<title>管理台 · <?php
    $wbName = trim((string)($boot['site_name'] ?? ''));
    echo htmlspecialchars($wbName !== '' ? $wbName : trim((string)($_SERVER['HTTP_HOST'] ?? '')), ENT_QUOTES);
?></title>
<style>
*{box-sizing:border-box}
:root{
  --bg:#080d15;--panel:rgba(255,255,255,.045);--line:rgba(255,255,255,.085);
  --text:#e8eef7;--muted:#8598b4;--dim:#5d6f8a;
  --brand:#4c8dff;--cyan:#22d3ee;--green:#34d399;--amber:#fbbf24;--red:#f87171;--pink:#f472b6;
  --r:16px;
}
html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;
  min-height:100vh;position:relative;overflow-x:hidden;-webkit-font-smoothing:antialiased}
body::before,body::after{content:'';position:fixed;border-radius:50%;filter:blur(100px);opacity:.42;pointer-events:none;z-index:0}
body::before{width:620px;height:620px;left:-180px;top:-200px;background:radial-gradient(circle,#1d4ed8,transparent 70%)}
body::after{width:520px;height:520px;right:-200px;top:220px;background:radial-gradient(circle,#0e7490,transparent 70%)}
a{color:inherit;text-decoration:none}
.wrap{position:relative;z-index:1;max-width:1180px;margin:0 auto;padding:0 22px 48px}

/* 顶栏 */
.top{position:sticky;top:0;z-index:20;display:flex;align-items:center;gap:14px;
  padding:14px 22px;margin:0 -22px 22px;background:rgba(8,13,21,.72);backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  border-bottom:1px solid rgba(255,255,255,.06)}
.brand{display:flex;align-items:center;gap:11px;min-width:0}
.logo{width:38px;height:38px;border-radius:12px;flex:none;display:grid;place-items:center;font-weight:800;font-size:16px;
  background:linear-gradient(140deg,#4c8dff,#22d3ee);color:#04101f;letter-spacing:-.5px;
  box-shadow:0 6px 18px rgba(60,140,255,.3)}
.brand b{display:block;font-size:15px;letter-spacing:.3px;line-height:1.35}
.brand small{display:block;font-size:11.5px;color:var(--dim);line-height:1.4}
.top-right{margin-left:auto;display:flex;align-items:center;gap:8px}
.clock{font-variant-numeric:tabular-nums;font-size:13px;color:var(--muted);padding:0 6px;letter-spacing:.5px}
.btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 13px;border-radius:10px;font-size:13px;
  border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--muted);
  transition:background .18s,color .18s,border-color .18s,transform .16s}
.btn:hover{background:rgba(255,255,255,.09);color:var(--text);border-color:rgba(255,255,255,.16)}
.btn:active{transform:scale(.97)}
.btn.solid{background:linear-gradient(140deg,#4c8dff,#22d3ee);color:#04101f;border-color:transparent;font-weight:600}
.btn.solid:hover{filter:brightness(1.06);color:#04101f}

/* 卡片 */
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--r);padding:18px 20px;
  backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);
  transition:border-color .25s,transform .25s,box-shadow .25s}
.card:hover{border-color:rgba(255,255,255,.14)}
.card h2{margin:0 0 14px;font-size:13px;font-weight:600;letter-spacing:1.2px;color:var(--muted);text-transform:uppercase}

.grid{display:grid;gap:14px;margin-bottom:14px}
.metrics{grid-template-columns:repeat(4,minmax(0,1fr))}
.two{grid-template-columns:minmax(0,1.25fr) minmax(0,1fr)}

/* 指标卡 */
.metric{padding:16px 18px 14px;position:relative;overflow:hidden}
.metric:hover{transform:translateY(-2px);box-shadow:0 14px 34px rgba(0,0,0,.28)}
.m-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:10px}
.m-label{font-size:13px;color:var(--muted);letter-spacing:.4px}
.m-badge{font-size:10.5px;padding:2px 7px;border-radius:6px;color:var(--dim);background:rgba(255,255,255,.05);letter-spacing:.3px}
.m-num{font-size:30px;font-weight:700;line-height:1.1;letter-spacing:-.5px;font-variant-numeric:tabular-nums}
.m-unit{font-size:12.5px;color:var(--dim);margin-left:5px;font-weight:400;letter-spacing:0}
.m-sub{margin-top:6px;font-size:11.5px;color:var(--dim);line-height:1.6}
.m-sub b{color:var(--muted);font-weight:600}

.bars{display:grid;gap:6px;margin-top:10px}
.bar-row{display:grid;grid-template-columns:26px 1fr 34px;align-items:center;gap:8px;font-size:11px;color:var(--dim)}
.bar{height:5px;border-radius:99px;background:rgba(255,255,255,.07);overflow:hidden}
.bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#4c8dff,#22d3ee);
  transition:width .5s cubic-bezier(.2,.9,.25,1)}
.bar-val{text-align:right;font-variant-numeric:tabular-nums;color:var(--muted)}

.donut{position:relative;width:92px;height:92px;flex:none}
.donut svg{transform:rotate(-90deg);display:block}
.donut circle{fill:none;stroke-linecap:round}
.donut .track{stroke:rgba(255,255,255,.07)}
.donut .val{transition:stroke-dashoffset .6s cubic-bezier(.2,.9,.25,1),stroke .3s}
.donut .ctr{position:absolute;inset:0;display:grid;place-items:center;font-size:17px;font-weight:700;font-variant-numeric:tabular-nums}
.metric.with-donut .m-body{display:flex;align-items:center;gap:14px}
.metric.with-donut .m-info{min-width:0}
.metric.with-donut .m-num{font-size:22px}

.spark{width:100%;height:40px;display:block;margin-top:8px;overflow:visible}
.spark polyline{fill:none;stroke:#22d3ee;stroke-width:1.6;stroke-linejoin:round;stroke-linecap:round}
.spark .area{fill:url(#gArea);stroke:none;opacity:.5}

/* 系统信息 */
.kv{display:grid;grid-template-columns:auto 1fr;gap:9px 16px;margin:0;font-size:13px}
.kv dt{color:var(--dim);white-space:nowrap}
.kv dd{margin:0;color:var(--text);text-align:right;font-variant-numeric:tabular-nums;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.kv dd small{color:var(--dim);font-size:11.5px;margin-left:4px}

/* 内容统计 */
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px}
.stat{background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.06);border-radius:11px;padding:11px 12px;
  transition:background .2s,border-color .2s,transform .2s}
.stat:hover{background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.12);transform:translateY(-1px)}
.stat b{display:block;font-size:20px;font-weight:700;line-height:1.2;font-variant-numeric:tabular-nums}
.stat span{font-size:11.5px;color:var(--dim)}

/* 快捷入口 */
.quick{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.q{display:flex;align-items:center;gap:11px;padding:12px 13px;border-radius:12px;
  background:rgba(255,255,255,.035);border:1px solid rgba(255,255,255,.06);
  transition:background .2s,border-color .2s,transform .2s}
.q:hover{background:rgba(76,141,255,.1);border-color:rgba(76,141,255,.34);transform:translateY(-2px)}
.q i{width:32px;height:32px;border-radius:9px;flex:none;display:grid;place-items:center;
  background:rgba(76,141,255,.14);color:#8fb8ff}
.q i svg{width:16px;height:16px}
.q div{min-width:0}
.q b{display:block;font-size:13px;font-weight:600;line-height:1.4}
.q small{display:block;font-size:11px;color:var(--dim);line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* 最近内容 */
.recent{list-style:none;margin:0;padding:0;display:grid;gap:2px}
.recent li{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:9px;font-size:13.5px;
  transition:background .18s}
.recent li:hover{background:rgba(255,255,255,.045)}
.recent .date{font-size:11.5px;color:var(--dim);font-variant-numeric:tabular-nums;flex:none;width:66px}
.recent .t{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.recent .tag{font-size:10.5px;padding:1px 6px;border-radius:5px;flex:none;
  background:rgba(255,255,255,.06);color:var(--dim)}
.recent .tag.draft{background:rgba(251,191,36,.12);color:#fcd34d}

.foot{margin:22px 0 0;text-align:center;font-size:11.5px;color:var(--dim);line-height:1.8}
.foot a{color:var(--muted);border-bottom:1px solid rgba(255,255,255,.14)}

@media(max-width:980px){
  .metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .two{grid-template-columns:1fr}
  .quick{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:560px){
  .wrap{padding:0 14px 40px}
  .top{padding:12px 14px;margin:0 -14px 16px}
  .brand small{display:none}
  .clock{display:none}
  .metrics{grid-template-columns:1fr}
  .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
  .quick{grid-template-columns:1fr 1fr}
  .q small{display:none}
  .m-num{font-size:26px}
}
</style>
</head>
<body>
<div class="wrap">

  <header class="top">
    <div class="brand">
      <div class="logo">跃</div>
      <div>
        <b>管理台</b>
        <small id="hostName"><?php echo htmlspecialchars(trim((string)($boot['site_name'] ?? '')) !== '' ? (string)$boot['site_name'] : trim((string)($_SERVER['HTTP_HOST'] ?? '')), ENT_QUOTES); ?></small>
      </div>
    </div>
    <div class="top-right">
      <span class="clock" id="clock">--:--:--</span>
      <a class="btn" href="/" target="_blank" rel="noopener">站点</a>
      <a class="btn" href="/admin/">后台</a>
      <a class="btn" href="?logout=1">退出</a>
    </div>
  </header>

  <section class="grid metrics">
    <article class="card metric">
      <div class="m-top"><span class="m-label">CPU 负载</span><span class="m-badge">load avg</span></div>
      <div class="m-num"><span id="load1">--</span><span class="m-unit">1 分钟</span></div>
      <div class="m-sub" id="loadSub">5 分钟 -- · 15 分钟 --</div>
      <svg class="spark" id="loadSpark" viewBox="0 0 200 40" preserveAspectRatio="none">
        <polyline id="loadLine" points="" style="stroke:#4c8dff"></polyline>
      </svg>
    </article>

    <article class="card metric with-donut">
      <div class="m-top"><span class="m-label">内存</span><span class="m-badge">PHP 进程</span></div>
      <div class="m-body">
        <div class="donut">
          <svg width="92" height="92" viewBox="0 0 92 92">
            <circle class="track" cx="46" cy="46" r="38" stroke-width="7"></circle>
            <circle class="val" id="memArc" cx="46" cy="46" r="38" stroke-width="7"
                    stroke="#34d399" stroke-dasharray="238.8" stroke-dashoffset="238.8"></circle>
          </svg>
          <div class="ctr" id="memPct">--</div>
        </div>
        <div class="m-info">
          <div class="m-num" id="memUsed">--</div>
          <div class="m-sub" id="memSub">上限 --</div>
          <div class="m-sub" id="memSub2" style="margin-top:2px"></div>
        </div>
      </div>
    </article>

    <article class="card metric with-donut">
      <div class="m-top"><span class="m-label">存储</span><span class="m-badge">磁盘</span></div>
      <div class="m-body">
        <div class="donut">
          <svg width="92" height="92" viewBox="0 0 92 92">
            <circle class="track" cx="46" cy="46" r="38" stroke-width="7"></circle>
            <circle class="val" id="diskArc" cx="46" cy="46" r="38" stroke-width="7"
                    stroke="#4c8dff" stroke-dasharray="238.8" stroke-dashoffset="238.8"></circle>
          </svg>
          <div class="ctr" id="diskPct">--</div>
        </div>
        <div class="m-info">
          <div class="m-num" id="diskUsed">--</div>
          <div class="m-sub" id="diskSub">总容量 --</div>
          <div class="m-sub" id="diskSub2"></div>
        </div>
      </div>
    </article>

    <article class="card metric">
      <div class="m-top"><span class="m-label">网络</span><span class="m-badge">实测延迟</span></div>
      <div class="m-num"><span id="rtt">--</span><span class="m-unit">ms</span></div>
      <div class="m-sub" id="netSub">正在采样…</div>
      <svg class="spark" id="spark" viewBox="0 0 200 40" preserveAspectRatio="none">
        <defs><linearGradient id="gArea" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="#22d3ee" stop-opacity=".45"></stop>
          <stop offset="100%" stop-color="#22d3ee" stop-opacity="0"></stop>
        </linearGradient></defs>
        <polygon class="area" id="sparkArea" points=""></polygon>
        <polyline id="sparkLine" points=""></polyline>
      </svg>
      <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
        <button class="btn" id="speedBtn" style="height:28px;font-size:12px;padding:0 10px">测一下速度</button>
        <span id="speedOut" style="font-size:11.5px;color:var(--dim)"></span>
      </div>
    </article>
  </section>

  <section class="grid two">
    <article class="card">
      <h2>运行环境</h2>
      <dl class="kv" id="sysList"></dl>
    </article>
    <article class="card">
      <h2>内容</h2>
      <div class="stats" id="contentStats"></div>
      <div class="m-sub" id="contentNote" style="margin-top:12px"></div>
    </article>
  </section>

  <section class="card" style="margin-bottom:14px">
    <h2>要做什么</h2>
    <div class="quick">
      <a class="q" href="/admin/write-post.php">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></i>
        <div><b>写文章</b><small>长文存档</small></div>
      </a>
      <a class="q" href="/moments/">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.5A8.4 8.4 0 0 1 12 3.5a8.4 8.4 0 0 1 9 8Z"/></svg></i>
        <div><b>发动态</b><small>碎片与图</small></div>
      </a>
      <a class="q" href="/drive/">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 15V3"/><path d="m7 8 5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg></i>
        <div><b>传文件</b><small>云盘</small></div>
      </a>
      <a class="q" href="/music/">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/></svg></i>
        <div><b>音乐库</b><small>上传、写备注</small></div>
      </a>
      <a class="q" href="/timeline/">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></i>
        <div><b>记时间轴</b><small>什么时候做了什么</small></div>
      </a>
      <a class="q" href="/admin/manage-posts.php">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h10"/></svg></i>
        <div><b>管理文章</b><small>改、删、置顶</small></div>
      </a>
      <a class="q" href="/admin/options-theme.php">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg></i>
        <div><b>换外观</b><small>主题与封面</small></div>
      </a>
      <a class="q" href="/admin/manage-comments.php">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.9 8.9 0 0 1-4-.9L3 21l1.9-4.5A8.4 8.4 0 0 1 12 3.5a8.4 8.4 0 0 1 9 8Z"/><path d="M8 10h8M8 14h5"/></svg></i>
        <div><b>看评论</b><small>回复与审核</small></div>
      </a>
      <a class="q" href="/admin/options-general.php">
        <i><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 13.7H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 10.3 3V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 2.9 1.2l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0 1.2 2.9H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg></i>
        <div><b>站点设置</b><small>标题、描述、地址</small></div>
      </a>
    </div>
  </section>

  <section class="card">
    <h2>最近内容</h2>
    <ul class="recent" id="recentList"><li><span class="t" style="color:var(--dim)">读取中…</span></li></ul>
  </section>

  <p class="foot">
    负载是系统的 1/5/15 分钟平均值，内存是 PHP 进程占用；共享主机没开放网卡计数，<br>
    所以「网络」一栏给的是到服务器的实测往返延迟与下载测速，不是流量带宽。<br>
    数据每 3 秒刷新 · 页面不可见时暂停 · 只有登录后才能看到<br>
    <a href="/admin/">Typecho 后台</a> · <a href="/" target="_blank" rel="noopener">打开站点</a>
  </p>
</div>

<script>
window.__BOOT__ = <?php echo json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

(function () {
  var $ = function (id) { return document.getElementById(id); };
  var D = window.__BOOT__ || null;
  var rttHist = [], loadHist = [], fail = 0, total = 0;

  function size(b) {
    if (!b || b < 0) return '--';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0, v = b;
    while (v >= 1024 && i < 4) { v /= 1024; i++; }
    return (i === 0 ? Math.round(v) : v.toFixed(v < 10 ? 1 : 0)) + ' ' + u[i];
  }
  function pct(v) { return Math.max(0, Math.min(100, v)); }
  function tone(p) { return p >= 88 ? '#f87171' : p >= 70 ? '#fbbf24' : null; }

  function donut(arcId, pctVal, color) {
    var c = 238.76; // 2πr, r=38
    var arc = $(arcId);
    if (!arc) return;
    arc.setAttribute('stroke-dashoffset', String(c - c * pctVal / 100));
    if (tone(pctVal)) arc.setAttribute('stroke', tone(pctVal));
    else if (color) arc.setAttribute('stroke', color);
  }

  function render(d) {
    if (!d) return;

    // CPU 负载
    var l = d.load || [0, 0, 0];
    $('load1').textContent = l[0].toFixed(2);
    $('loadSub').textContent = '5 分钟 ' + l[1].toFixed(2) + ' · 15 分钟 ' + l[2].toFixed(2);
    loadHist.push(l[0]);
    if (loadHist.length > 32) loadHist.shift();
    spark('loadLine', null, loadHist);

    // 内存
    var m = d.mem || {}, lim = m.limit || 0, rss = m.rss || 0;
    var mp = lim > 0 ? pct(rss / lim * 100) : 0;
    $('memPct').textContent = lim > 0 ? Math.round(mp) + '%' : '--';
    donut('memArc', mp, '#34d399');
    $('memUsed').textContent = size(rss);
    $('memSub').textContent = '进程上限 ' + (lim > 0 ? size(lim) : '不限');
    $('memSub2').innerHTML = '本次 ' + size(m.usage) + '<br>峰值 ' + size(m.peak);

    // 存储
    var k = d.disk || {}, used = k.used || 0, tot = k.total || 0;
    var dp = tot > 0 ? pct(used / tot * 100) : 0;
    $('diskPct').textContent = tot > 0 ? Math.round(dp) + '%' : '--';
    donut('diskArc', dp, '#4c8dff');
    $('diskUsed').textContent = size(used);
    $('diskSub').textContent = '总 ' + size(tot) + ' · 剩 ' + size(k.free);
    var site = d.site || {};
    $('diskSub2').textContent = '本站 ' + size(site.bytes) + ' / ' + (site.files || 0) + ' 文件';

    // 系统
    var s = d.sys || {};
    var rows = [
      ['主机', s.host || '--'],
      ['系统', s.os || '--'],
      ['Web', s.server || '--'],
      ['PHP', (s.php || '?') + ' · ' + (s.sapi || '?')],
      ['上传上限', s.upload + ' / POST ' + s.post],
      ['最大执行', s.time + ' 秒'],
      ['内存上限', s.memlim],
      ['网卡', d.net && d.net.ip ? (d.net.name + ' · ' + d.net.ip) : '--']
    ];
    $('sysList').innerHTML = rows.map(function (r) {
      return '<dt>' + r[0] + '</dt><dd>' + String(r[1]).replace(/</g, '&lt;') + '</dd>';
    }).join('');

    // 内容
    var c = d.content || {};
    var cells = [
      ['文章', c.posts], ['独立页', c.pages], ['草稿', c.drafts],
      ['评论', c.comments], ['动态', c.moments], ['云盘', c.drive]
    ];
    $('contentStats').innerHTML = cells.map(function (x) {
      return '<div class="stat"><b>' + (x[1] == null ? '--' : x[1]) + '</b><span>' + x[0] + '</span></div>';
    }).join('');
    $('contentNote').textContent = c.ok
      ? '正文 ' + (c.chars || 0).toLocaleString() + ' 字 · 数据库 ' + size(c.db)
      : '数据库暂时读不到（不影响使用）';

    // 最近内容
    if (c.recent && c.recent.length) {
      $('recentList').innerHTML = c.recent.map(function (r) {
        var href = r.type === 'page' ? '/admin/write-page.php?cid=' + r.cid : '/admin/write-post.php?cid=' + r.cid;
        var tag = r.status !== 'publish' ? '<span class="tag draft">草稿</span>' : (r.type === 'page' ? '<span class="tag">页面</span>' : '');
        return '<li><span class="date">' + r.date + '</span><a class="t" href="' + href + '">' +
          String(r.title).replace(/</g, '&lt;') + '</a>' + tag + '</li>';
      }).join('');
    } else {
      $('recentList').innerHTML = '<li><span class="t" style="color:var(--dim)">还没有内容，去写一篇？</span></li>';
    }
  }

  function spark(lineId, areaId, arr) {
    var line = $(lineId);
    if (!line || arr.length < 2) return;
    var max = Math.max.apply(null, arr) * 1.2 || 1;
    var w = 200, h = 40, n = arr.length, step = w / (n - 1);
    var pts = [], ptsA = ['0,40'];
    for (var i = 0; i < n; i++) {
      var x = (i * step).toFixed(1);
      var y = (h - (arr[i] / max) * (h - 6) - 3).toFixed(1);
      pts.push(x + ',' + y);
      ptsA.push(x + ',' + y);
    }
    ptsA.push(w.toFixed(1) + ',40');
    line.setAttribute('points', pts.join(' '));
    if (areaId) {
      var a = $(areaId);
      if (a) a.setAttribute('points', ptsA.join(' '));
    }
  }

  function pushRtt(ms) {
    rttHist.push(ms);
    if (rttHist.length > 32) rttHist.shift();
    $('rtt').textContent = Math.round(ms);
    var okRate = total ? Math.round((total - fail) / total * 100) : 100;
    $('netSub').textContent = '成功率 ' + okRate + '% · 已采样 ' + total + ' 次';
    spark('sparkLine', 'sparkArea', rttHist);
  }

  function tick() {
    var t0 = performance.now();
    total++;
    fetch('?api=stats', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error('http'); return r.json(); })
      .then(function (d) {
        var ms = performance.now() - t0;
        pushRtt(ms);
        render(d);
      })
      .catch(function () {
        fail++;
        $('netSub').textContent = '采样失败，稍后重试';
      });
  }

  // 时钟
  function clock() {
    var d = new Date();
    var p = function (n) { return n < 10 ? '0' + n : '' + n; };
    $('clock').textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }
  clock(); setInterval(clock, 1000);

  // 测速
  $('speedBtn').addEventListener('click', function () {
    var btn = this, out = $('speedOut');
    btn.disabled = true; btn.textContent = '测速中…'; out.textContent = '';
    var t0 = performance.now();
    fetch('?api=speed&n=524288', { cache: 'no-store' })
      .then(function (r) { return r.arrayBuffer(); })
      .then(function (buf) {
        var sec = (performance.now() - t0) / 1000;
        var mbps = (buf.byteLength * 8) / sec / 1e6;
        out.textContent = '≈ ' + mbps.toFixed(1) + ' Mbps（512 KB / ' + sec.toFixed(2) + 's）';
      })
      .catch(function () { out.textContent = '测速失败'; })
      .then(function () { btn.disabled = false; btn.textContent = '再测一次'; });
  });

  render(D);

  // 轮询：页面可见才跑
  var timer = null;
  function start() { if (timer) return; tick(); timer = setInterval(tick, 3000); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop(); else start();
  });
  start();
})();
</script>
</body>
</html>
