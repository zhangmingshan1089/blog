<?php
/**
 * 音乐库 · 独立单文件版
 * ------------------------------------------------------------------
 * 站内播放器的后台：上传、备注、排序、删除、试听。
 * 目录契约： /music/index.php, /music/data.json, /music/uploads/
 * 播放器前端在主题里（footer.php 引入 /music/player.css 与 player.js）。
 *
 * 密码复用云盘那份（drive/.passwd，bcrypt），独立会话，互不影响。
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');

define('MUSIC_ROOT', __DIR__);
define('UP_DIR', MUSIC_ROOT . '/uploads');
define('DATA_FILE', MUSIC_ROOT . '/data.json');
define('PASSWD_FILE', dirname(__DIR__) . '/drive/.passwd');
define('SESSION_NAME', 'xdy_music_sid');
define('TMP_DIR', UP_DIR . '/.tmp');
define('COVER_DIR', UP_DIR . '/covers');
// 封面：浏览器端已经压到 900px 内，这里只兜个底
define('MAX_COVER_BYTES', 6 * 1024 * 1024);
// 成品音频上限：512 MB（无损单曲、整场录音都够）
define('MAX_TRACK_BYTES', 512 * 1024 * 1024);
// 视频源上限：2 GB（手机随手拍的 4K 短片也在里面）
define('MAX_SOURCE_BYTES', 2048 * 1024 * 1024);
// 分片上传的片大小（服务端按 POST 上限再收一次）
define('CHUNK_BYTES', 1024 * 1024);   // 1 MB 一片，绕开 nginx 的 client_max_body_size
define('ALLOW_EXT', ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'wav', 'flac', 'opus', 'weba']);
define('VIDEO_EXT', ['mp4', 'm4v', 'mov']);

// MP4 音轨提取器（纯 PHP，不依赖 ffmpeg）
$__mp4lib = __DIR__ . '/mp4audio.php';
if (is_file($__mp4lib)) require_once $__mp4lib;
unset($__mp4lib);

if (!is_dir(UP_DIR)) {
    @mkdir(UP_DIR, 0755, true);
}
if (!is_dir(TMP_DIR)) {
    @mkdir(TMP_DIR, 0755, true);
}
if (!is_dir(COVER_DIR)) {
    @mkdir(COVER_DIR, 0755, true);
}
// 上传目录保护：nginx 不看 .htaccess，但文件名是随机且无扩展名，猜不到也执行不了
$ht = UP_DIR . '/.htaccess';
if (!file_exists($ht)) {
    @file_put_contents($ht, "php_flag engine off\nOptions -Indexes\n<FilesMatch \".+\">\nForceType application/octet-stream\n</FilesMatch>\n");
}
if (!file_exists(UP_DIR . '/index.html')) {
    @file_put_contents(UP_DIR . '/index.html', '');
}

// ============ 会话 ============
/* 公开接口（列表、音频流）不启会话：PHP 的 session 文件锁会让并发的 Range 请求排队，
   一首歌拖进度条会连带把列表请求卡住。这里只在需要鉴权的请求上开会话。 */
$wbNeedSession = true;
if (isset($_GET['get']) || (($_GET['api'] ?? '') === 'list')) {
    $wbNeedSession = false;
}
/* 公开页（歌单 / 单曲）不启会话：
   一来不给爬虫发 Set-Cookie，二来 PHP 的 session 文件锁会拖慢并发抓取。
   带会话 cookie 的站主仍会进后台；?admin=1 也会强制启会话。 */
$wbRawAct = (string)($_REQUEST['act'] ?? '');
if ($wbRawAct === '' && !isset($_GET['admin']) && !isset($_GET['preview']) && !isset($_COOKIE[SESSION_NAME])) {
    $wbNeedSession = false;
}
if ($wbNeedSession && session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    @session_start();
}

// ============ 小工具 ============
/** 按 id 生成稳定的渐变色块，管理列表用 */
function art_grad(string $id): string
{
    /* 与前端 player.js 的 coverGrad 同一套算法：色相锁在 196°–292° 的青蓝紫区间 */
    $h = 0;
    foreach (str_split($id) as $ch) {
        $h = ($h * 31 + ord($ch)) & 0x7fffffff;
    }
    $hue = 196 + ($h % 96);
    return 'linear-gradient(135deg,hsl(' . $hue . ',76%,63%),hsl(' . ($hue + 32) . ',66%,45%))';
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $data, int $code = 200): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rid(int $len = 12): string
{
    $s = '0123456789abcdefghijklmnopqrstuvwxyz';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $s[random_int(0, strlen($s) - 1)];
    }
    return $out;
}

function data_load(): array
{
    if (!is_file(DATA_FILE)) {
        return ['v' => 1, 'tracks' => []];
    }
    $raw = @file_get_contents(DATA_FILE);
    $d = json_decode((string)$raw, true);
    if (!is_array($d) || !isset($d['tracks']) || !is_array($d['tracks'])) {
        return ['v' => 1, 'tracks' => []];
    }
    return $d;
}

function data_save(array $d): bool
{
    $d['v'] = 1;
    $tmp = DATA_FILE . '.tmp';
    $ok = @file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    if ($ok) {
        $ok = @rename($tmp, DATA_FILE);
        @chmod(DATA_FILE, 0644);
    }
    return $ok;
}

/** 读写锁：防并发上传互相覆盖 */
function data_tx(callable $fn)
{
    $fp = @fopen(DATA_FILE . '.lock', 'c');
    if ($fp) {
        @flock($fp, LOCK_EX);
    }
    $d = data_load();
    $res = $fn($d);
    data_save($d);
    if ($fp) {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
    return $res;
}

function human_size(int $b): string
{
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
    if ($b >= 1024) return round($b / 1024) . ' KB';
    return $b . ' B';
}

function fmt_dur(float $s): string
{
    $s = (int)round($s);
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/** 老曲目没存编码信息时，从 mime 猜个短名 */
function mime_short(string $mime): string
{
    $m = strtolower($mime);
    if (strpos($m, 'mpeg') !== false) return 'MP3';
    if (strpos($m, 'mp4') !== false || strpos($m, 'm4a') !== false) return 'M4A';
    if (strpos($m, 'ogg') !== false) return 'OGG';
    if (strpos($m, 'flac') !== false) return 'FLAC';
    if (strpos($m, 'wav') !== false) return 'WAV';
    if (strpos($m, 'aac') !== false) return 'AAC';
    return '';
}

/** 一句话描述这条音轨的技术参数：AAC-LC · 44.1 kHz · 立体声 · 256k */
function quality_label(array $t): string
{
    $p = [];
    $codec = (string)($t['codec'] ?? '');
    if ($codec === '') $codec = mime_short((string)($t['mime'] ?? ''));
    $sr = (int)($t['sr'] ?? 0);
    $ch = (int)($t['ch'] ?? 0);
    $kbps = (int)($t['kbps'] ?? 0);
    if ($codec !== '') $p[] = $codec;
    if ($sr > 0) $p[] = rtrim(rtrim(number_format($sr / 1000, 2, '.', ''), '0'), '.') . ' kHz';
    if ($ch === 1) $p[] = '单声道';
    elseif ($ch >= 2) $p[] = '立体声';
    if ($kbps > 0) $p[] = $kbps . 'k';
    return implode(' · ', $p);
}

/** 歌单设置：标题、简介、SEO 三件套。老数据没有这一块，给一套默认值 */
function wb_settings(array $d): array
{
    $def = [
        'title'    => '我喜欢的歌',
        'intro'    => '听了很多年，最后留在这个列表里的这些。没有按风格分类，也没有按年份排，就是随手一首一首加进来的顺序。每首后面写了一句当时的理由。',
        'seo_title'    => '',
        'seo_desc'     => '',
        'seo_keywords' => '',
    ];
    $s = (isset($d['settings']) && is_array($d['settings'])) ? $d['settings'] : [];
    foreach ($def as $k => $v) {
        if (isset($s[$k]) && is_string($s[$k])) {
            $def[$k] = trim($s[$k]);
        }
    }
    if ($def['title'] === '') $def['title'] = '我喜欢的歌';
    return $def;
}

/** 站点名与副标题：直接读 Typecho 的 options，读不到就用兜底 */
function wb_site_info(): array
{
    static $info = null;
    if ($info !== null) return $info;
    $info = ['title' => 'MC.跃小叮当的博客', 'desc' => '写在自己的地方'];
    $db = dirname(__DIR__) . '/usr/xdy_blog.db';
    if (class_exists('PDO') && is_file($db)) {
        try {
            $pdo = new PDO('sqlite:' . $db, null, null, [
                PDO::ATTR_TIMEOUT => 2,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT,
            ]);
            $st = $pdo->query("SELECT name, value FROM xdy_options WHERE name IN ('title','description')");
            if ($st) {
                foreach ($st as $row) {
                    $name = (string)($row['name'] ?? '');
                    $val  = trim((string)($row['value'] ?? ''));
                    if ($name === 'title' && $val !== '') $info['title'] = $val;
                    if ($name === 'description' && $val !== '') $info['desc'] = $val;
                }
            }
        } catch (Throwable $e) {
            /* 数据库读不到不影响页面 */
        }
    }
    return $info;
}

/** 绝对地址，给 canonical / OG / JSON-LD 用 */
function wb_abs_url(string $path): string
{
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    if ($path === '') $path = '/';
    if ($path[0] !== '/') $path = '/' . $path;
    return ($https ? 'https' : 'http') . '://' . $host . $path;
}

/** 曲目封面文件路径（没有就返回空串） */
function cover_file(array $t): string
{
    $f = basename((string)($t['cover'] ?? ''));
    if ($f === '') return '';
    $p = COVER_DIR . '/' . $f;
    return is_file($p) ? $p : '';
}

/** 曲目封面 URL（没有封面返回空串，前端会退回渐变） */
function cover_url(array $t): string
{
    if (cover_file($t) === '') return '';
    $f = basename((string)($t['cover'] ?? ''));
    // 文件名每次替换都变，拿它算版本号，避免同秒替换被缓存住
    $v = substr((string)@md5($f), 0, 8);
    return base_path() . '/?cover=' . rawurlencode((string)($t['id'] ?? '')) . '&v=' . $v;
}

function base_path(): string
{
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/music/index.php')));
    return rtrim($dir, '/');
}

function site_root(): string
{
    return rtrim(str_replace('\\', '/', dirname(base_path())), '/');
}

function is_installed(): bool
{
    return is_file(PASSWD_FILE);
}

function is_logged(): bool
{
    return !empty($_SESSION['logged']);
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = rid(20);
    }
    return (string)$_SESSION['csrf'];
}

function csrf_ok(): bool
{
    $t = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '');
    return $t !== '' && hash_equals(csrf(), $t);
}

function require_login(): void
{
    if (!is_logged()) {
        json_out(['ok' => false, 'msg' => '登录已失效，请刷新页面重新登录', 'relogin' => true], 401);
    }
    if (!csrf_ok()) {
        json_out(['ok' => false, 'msg' => '令牌校验失败，请刷新页面重试'], 419);
    }
}

/** 按文件头判断真实音频类型，不信任扩展名与浏览器上报的 mime */
function sniff_mime(string $file): string
{
    $fh = @fopen($file, 'rb');
    if (!$fh) return '';
    $head = (string)fread($fh, 16);
    fclose($fh);
    if ($head === '') return '';
    if (substr($head, 0, 3) === 'ID3') return 'audio/mpeg';
    $b0 = ord($head[0]);
    $b1 = strlen($head) > 1 ? ord($head[1]) : 0;
    if ($b0 === 0xFF && ($b1 & 0xE0) === 0xE0) return 'audio/mpeg';
    if (substr($head, 4, 4) === 'ftyp') return 'audio/mp4';
    if (substr($head, 0, 4) === 'OggS') return 'audio/ogg';
    if (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WAVE') return 'audio/wav';
    if (substr($head, 0, 4) === 'fLaC') return 'audio/flac';
    if (substr($head, 0, 4) === 'FORM') return 'audio/aiff';
    return '';
}

function upload_limit(): int
{
    $to = static function (string $v): int {
        $v = trim($v);
        if ($v === '') return 0;
        $u = strtoupper(substr($v, -1));
        $n = (int)$v;
        if ($u === 'G') return $n * 1073741824;
        if ($u === 'M') return $n * 1048576;
        if ($u === 'K') return $n * 1024;
        return $n;
    };
    $a = $to((string)ini_get('upload_max_filesize'));
    $b = $to((string)ini_get('post_max_size'));
    $lim = MAX_TRACK_BYTES;
    if ($a > 0) $lim = min($lim, $a);
    if ($b > 0) $lim = min($lim, $b);
    return $lim;
}

/** 标题兜底：文件名去扩展名、把下划线与多余空格理顺 */
function title_from_filename(string $name): string
{
    $t = preg_replace('/\.[a-z0-9]{1,5}$/i', '', $name) ?? $name;
    $t = str_replace(['_', '+'], ' ', $t);
    $t = preg_replace('/\s{2,}/', ' ', $t) ?? $t;
    $t = trim($t);
    return $t !== '' ? mb_substr($t, 0, 80) : '未命名';
}

/** 找 ffmpeg（有就用，没有也不影响主流程） */
function ffmpeg_bin(): string
{
    static $bin = null;
    if ($bin !== null) return $bin;
    $bin = '';
    if (!function_exists('exec')) return $bin;
    $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $dis, true)) return $bin;
    foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/bin/ffmpeg'] as $p) {
        if (@is_executable($p)) { $bin = $p; break; }
    }
    if ($bin === '' && function_exists('shell_exec') && !in_array('shell_exec', $dis, true)) {
        $o = @shell_exec('command -v ffmpeg 2>/dev/null');
        if (is_string($o)) {
            $o = trim($o);
            if ($o !== '' && strpos($o, '/') === 0) $bin = $o;
        }
    }
    return $bin;
}

/** 用 ffmpeg 把浏览器放不了的音轨转成无损 FLAC */
function ffmpeg_to_flac(string $src, string $dst): bool
{
    $ff = ffmpeg_bin();
    if ($ff === '') return false;
    $cmd = $ff . ' -hide_banner -loglevel error -y -i ' . escapeshellarg($src)
         . ' -vn -sn -dn -map 0:a:0 -c:a flac -compression_level 5 ' . escapeshellarg($dst) . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    return $code === 0 && is_file($dst) && filesize($dst) > 1024;
}

/**
 * 把一个已经落到服务器上的文件变成曲目：
 *   - 视频（mp4/mov/m4v）→ 无损抽出音轨，源文件丢弃
 *   - 音频 → 直接入库
 * @return array{ok:bool,msg?:string,track?:array}
 */
function process_media(string $src, string $origName): array
{
    $id = rid(12);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $title = title_from_filename($origName);
    $isIso = class_exists('Mp4Audio') && Mp4Audio::isMp4($src);
    $isVideoExt = in_array($ext, VIDEO_EXT, true);
    $isAudioExt = in_array($ext, ALLOW_EXT, true);

    if (!$isIso && !$isVideoExt && !$isAudioExt) {
        return ['ok' => false, 'msg' => '不支持的格式 .' . $ext];
    }
    $mime = $isIso ? '' : sniff_mime($src);
    if (!$isIso && $mime === '') {
        return ['ok' => false, 'msg' => '这个文件不像音频/视频，已拒绝'];
    }

    $meta = ['codec' => '', 'sr' => 0, 'ch' => 0, 'kbps' => 0, 'lossless' => false, 'dur' => 0.0];
    $fromVideo = false;
    $srcSize = (int)@filesize($src);

    if ($isIso || $isVideoExt) {
        if (!class_exists('Mp4Audio')) {
            return ['ok' => false, 'msg' => '服务器缺少音轨提取组件（mp4audio.php）'];
        }
        $dst = UP_DIR . '/' . $id . '.m4a';
        $r = Mp4Audio::extract($src, $dst);
        if (!$r['ok']) {
            @unlink($dst);
            $msg = (string)($r['msg'] ?? '提取失败');
            if (($r['code'] ?? '') === 'noaudio') {
                return ['ok' => false, 'msg' => '这个视频没有声音轨，没什么可提取的'];
            }
            if (($r['code'] ?? '') === 'fragmented') {
                return ['ok' => false, 'msg' => '分片 MP4（OBS / 录屏工具直出）暂时拆不出音轨，先用剪辑软件导出一个普通 MP4'];
            }
            return ['ok' => false, 'msg' => $msg];
        }
        if (empty($r['web_ok'])) {
            // PCM / AC-3 这类浏览器解不了的，交给 ffmpeg 转无损 FLAC
            $flac = UP_DIR . '/' . $id . '.flac';
            if (($r['codec'] ?? '') === 'PCM' && ffmpeg_to_flac($src, $flac)) {
                @unlink($dst);
                $store = $id . '.flac';
                $mimeOut = 'audio/flac';
                $sz = (int)filesize($flac);
                $meta = ['codec' => 'FLAC', 'sr' => (int)$r['sr'], 'ch' => (int)$r['ch'],
                         'kbps' => (int)$r['kbps'], 'lossless' => true, 'dur' => (float)$r['dur']];
            } else {
                @unlink($dst);
                return ['ok' => false, 'msg' => '这条音轨是 ' . $r['codec'] . '，浏览器解不了；装个 ffmpeg 就能自动转成无损 FLAC'];
            }
        } else {
            $store = $id . '.m4a';
            $mimeOut = 'audio/mp4';
            $sz = (int)filesize($dst);
            $meta = ['codec' => (string)$r['codec'], 'sr' => (int)$r['sr'], 'ch' => (int)$r['ch'],
                     'kbps' => (int)$r['kbps'], 'lossless' => (bool)$r['lossless'], 'dur' => (float)$r['dur']];
        }
        $fromVideo = !empty($r['from_video']) || $isVideoExt;
    } else {
        $store = $id . '.bin';
        $mimeOut = $mime;
        $sz = $srcSize;
        if (!@rename($src, UP_DIR . '/' . $store)) {
            if (!@copy($src, UP_DIR . '/' . $store)) {
                return ['ok' => false, 'msg' => '写入失败，请检查 uploads 权限'];
            }
            @unlink($src);
        }
    }

    $dest = UP_DIR . '/' . $store;
    if (!is_file($dest) || filesize($dest) < 512) {
        @unlink($dest);
        return ['ok' => false, 'msg' => '成品文件异常，已丢弃'];
    }
    @chmod($dest, 0644);
    if ($sz <= 0) $sz = (int)filesize($dest);

    $track = [
        'id'     => $id,
        'file'   => $store,
        'title'  => $title,
        'artist' => '',
        'note'   => '',
        'tags'   => [],
        'dur'    => round((float)$meta['dur'], 1),
        'size'   => $sz,
        'mime'   => $mimeOut,
        'added'  => time(),
        'codec'  => $meta['codec'],
        'sr'     => $meta['sr'],
        'ch'     => $meta['ch'],
        'kbps'   => $meta['kbps'],
        'lossless' => $meta['lossless'],
    ];
    if ($fromVideo) {
        $track['src'] = mb_substr($origName, 0, 120);
        $track['src_size'] = $srcSize;
    }

    data_tx(function (array &$d) use ($track) {
        $d['tracks'][] = $track;
        return true;
    });

    return ['ok' => true, 'track' => $track];
}

/** 清掉 24 小时前没合并完的分片 */
function gc_tmp(): void
{
    $now = time();
    foreach ((array)@glob(TMP_DIR . '/*', GLOB_ONLYDIR) as $dir) {
        $name = basename((string)$dir);
        if (!preg_match('/^[a-f0-9]{16,32}$/', $name)) continue;
        $m = @filemtime($dir);
        if ($m && $now - $m > 86400) {
            foreach ((array)@glob($dir . '/*') as $p) @unlink($p);
            @rmdir($dir);
        }
    }
}

// ============ 路由 ============
$act = (string)($_REQUEST['act'] ?? '');
if ($act === '') {
    $act = isset($_GET['get']) ? 'get' : (isset($_GET['api']) ? 'api' : '');
}
$api = (string)($_GET['api'] ?? '');

// ---- 公开：封面图 ----
if (isset($_GET['cover'])) {
    $cid = (string)$_GET['cover'];
    $hit = null;
    foreach (data_load()['tracks'] as $t) {
        if ((string)($t['id'] ?? '') === $cid) { $hit = $t; break; }
    }
    $path = $hit ? cover_file($hit) : '';
    if ($path === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '没有封面';
        exit;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';
    $mtime = (int)@filemtime($path);
    $etag = '"' . substr((string)@md5_file($path), 0, 16) . '"';
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=2592000, immutable');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');
    if ((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . (int)filesize($path));
    readfile($path);
    exit;
}

// ---- 公开：导出歌单（M3U，任何播放器都能读） ----
if ((string)($_GET['playlist'] ?? '') === 'm3u') {
    $d = data_load();
    $s = wb_settings($d);
    $clean = static function (string $v): string {
        return trim(preg_replace('/[\r\n]+/u', ' ', $v) ?? $v);
    };
    header('Content-Type: audio/x-mpegurl; charset=utf-8');
    header('Content-Disposition: attachment; filename="playlist.m3u"; filename*=UTF-8\'\'' . rawurlencode($s['title']) . '.m3u');
    header('Cache-Control: public, max-age=300');
    echo "#EXTM3U\n#PLAYLIST:" . $clean($s['title']) . "\n";
    foreach ($d['tracks'] as $t) {
        $id = (string)($t['id'] ?? '');
        if ($id === '') continue;
        $title = $clean((string)($t['title'] ?? '未命名'));
        $artist = $clean((string)($t['artist'] ?? ''));
        echo '#EXTINF:' . (int)round((float)($t['dur'] ?? 0)) . ',' . ($artist !== '' ? $artist . ' - ' : '') . $title . "\n";
        echo wb_abs_url(base_path() . '/?get=' . rawurlencode($id)) . "\n";
    }
    exit;
}

// ---- 公开：曲目列表 ----
if ($api === 'list' || $act === 'list') {
    $d = data_load();
    $out = [];
    foreach ($d['tracks'] as $t) {
        if (!isset($t['id'])) continue;
        $out[] = [
            'id'     => (string)$t['id'],
            'title'  => (string)($t['title'] ?? ''),
            'artist' => (string)($t['artist'] ?? ''),
            'note'   => (string)($t['note'] ?? ''),
            'tags'   => array_values(array_filter((array)($t['tags'] ?? []), 'is_string')),
            'dur'    => (float)($t['dur'] ?? 0),
            'size'   => (int)($t['size'] ?? 0),
            'added'  => (int)($t['added'] ?? 0),
            'codec'  => (string)($t['codec'] ?? ''),
            'sr'     => (int)($t['sr'] ?? 0),
            'ch'     => (int)($t['ch'] ?? 0),
            'kbps'   => (int)($t['kbps'] ?? 0),
            'lossless' => !empty($t['lossless']),
            'src'    => (string)($t['src'] ?? ''),
            'mime'   => (string)($t['mime'] ?? ''),
            'cover'  => cover_url($t),
            'url'    => base_path() . '/?get=' . rawurlencode((string)$t['id']),
        ];
    }
    $bytes = 0;
    foreach ($d['tracks'] as $t) $bytes += (int)($t['size'] ?? 0);
    $s = wb_settings($d);
    json_out([
        'ok'    => true,
        'total' => count($out),
        'bytes' => $bytes,
        'playlist' => [
            'title' => $s['title'],
            'intro' => $s['intro'],
        ],
        'tracks' => $out,
    ]);
}

// ---- 公开：输出音频（支持 Range，拖动进度条才会正常） ----
if ($act === 'get') {
    $id = (string)($_GET['get'] ?? '');
    $d = data_load();
    $hit = null;
    foreach ($d['tracks'] as $t) {
        if ((string)($t['id'] ?? '') === $id) {
            $hit = $t;
            break;
        }
    }
    if (!$hit) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '曲目不存在';
        exit;
    }
    $path = UP_DIR . '/' . basename((string)($hit['file'] ?? ''));
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '音频文件丢失';
        exit;
    }
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $size = (int)filesize($path);
    $mime = (string)($hit['mime'] ?? '') ?: 'audio/mpeg';
    $start = 0;
    $end = $size - 1;
    $partial = false;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\s*(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') $end = min($end, (int)$m[2]);
        }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $partial = true;
    }

    $len = $end - $start + 1;
    http_response_code($partial ? 206 : 200);
    header('Content-Type: ' . $mime);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $len);
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    if ($partial) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    $title = (string)($hit['title'] ?? 'track');
    header('Content-Disposition: inline; filename="track.mp3"; filename*=UTF-8\'\'' . rawurlencode($title) . '.mp3');

    $fp = @fopen($path, 'rb');
    if (!$fp) exit;
    @fseek($fp, $start);
    $left = $len;
    while ($left > 0 && !feof($fp)) {
        $buf = fread($fp, (int)min(65536, $left));
        if ($buf === false || $buf === '') break;
        echo $buf;
        $left -= strlen($buf);
        @flush();
    }
    fclose($fp);
    exit;
}

// ---- 登录 ----
if ($act === 'login') {
    if (!is_installed()) {
        json_out(['ok' => false, 'msg' => '还没有设置管理员密码，请先打开云盘设置一次'], 400);
    }
    if (!csrf_ok()) {
        json_out(['ok' => false, 'msg' => '令牌失效，请刷新页面'], 419);
    }
    $wait = (int)($_SESSION['fail_at'] ?? 0);
    if ($wait > 0 && time() - $wait < 2) {
        usleep(600000);
    }
    $pwd = (string)($_POST['password'] ?? '');
    $hash = trim((string)@file_get_contents(PASSWD_FILE));
    if ($pwd !== '' && $hash !== '' && password_verify($pwd, $hash)) {
        $_SESSION['logged'] = true;
        session_regenerate_id(true);
        unset($_SESSION['fail'], $_SESSION['fail_at']);
        json_out(['ok' => true]);
    }
    $_SESSION['fail'] = (int)($_SESSION['fail'] ?? 0) + 1;
    $_SESSION['fail_at'] = time();
    usleep(400000);
    json_out(['ok' => false, 'msg' => '密码不正确'], 401);
}

if ($act === 'logout') {
    $_SESSION = [];
    @session_destroy();
    header('Location: ' . base_path() . '/');
    exit;
}

// ---- 以下都需要登录 ----
if (in_array($act, ['upload', 'chunk', 'merge', 'cstatus', 'save', 'move', 'del', 'dur', 'reorder', 'cover', 'coverdel', 'settings'], true)) {
    require_login();
}

// ---- 上传（小文件直传） ----
if ($act === 'upload') {
    if (!isset($_FILES['file'])) {
        json_out(['ok' => false, 'msg' => '没有收到文件'], 400);
    }
    $f = $_FILES['file'];
    if (!empty($f['error'])) {
        $map = [
            UPLOAD_ERR_INI_SIZE   => '文件超过服务器直传上限（' . human_size(upload_limit()) . '），大文件会自动分片，刷新页面再试',
            UPLOAD_ERR_FORM_SIZE  => '文件超过表单上限',
            UPLOAD_ERR_PARTIAL    => '上传中断，请重试',
            UPLOAD_ERR_NO_FILE    => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
            UPLOAD_ERR_EXTENSION  => '被 PHP 扩展拦截',
        ];
        json_out(['ok' => false, 'msg' => $map[(int)$f['error']] ?? ('上传失败（错误码 ' . (int)$f['error'] . '）')], 400);
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) {
        json_out(['ok' => false, 'msg' => '空文件'], 400);
    }
    if ($size > upload_limit()) {
        json_out(['ok' => false, 'msg' => '这个文件 ' . human_size($size) . '，超过了直传上限 ' . human_size(upload_limit()) . '，请走分片上传'], 413);
    }
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOW_EXT, true) && !in_array($ext, VIDEO_EXT, true)) {
        json_out(['ok' => false, 'msg' => '不支持的格式 .' . $ext . '（音频 mp3/m4a/flac/wav/ogg，视频 mp4/mov/m4v）'], 400);
    }
    $tmp = (string)$f['tmp_name'];
    $mime = sniff_mime($tmp);
    $isIso = class_exists('Mp4Audio') && Mp4Audio::isMp4($tmp);
    if ($mime === '' && !$isIso) {
        json_out(['ok' => false, 'msg' => '这个文件既不像音频也不像视频，已拒绝'], 400);
    }

    $r = process_media($tmp, (string)$f['name']);
    if (!$r['ok']) {
        json_out(['ok' => false, 'msg' => (string)($r['msg'] ?? '处理失败')], 400);
    }
    gc_tmp();
    json_out(['ok' => true, 'track' => $r['track'], 'msg' => '已添加']);
}

// ---- 分片上传：收一片 ----
if ($act === 'chunk') {
    $uid = (string)($_POST['uid'] ?? '');
    if (!preg_match('/^[a-f0-9]{16,32}$/', $uid)) {
        json_out(['ok' => false, 'msg' => '上传会话无效，刷新页面重试'], 400);
    }
    $idx = (int)($_POST['idx'] ?? -1);
    $total = (int)($_POST['total'] ?? 0);
    if ($idx < 0 || $total <= 0 || $total > 8192 || $idx >= $total) {
        json_out(['ok' => false, 'msg' => '分片参数不对'], 400);
    }
    if (!isset($_FILES['chunk']) || !empty($_FILES['chunk']['error'])) {
        json_out(['ok' => false, 'msg' => '分片没收到'], 400);
    }
    $dir = TMP_DIR . '/' . $uid;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $part = $dir . '/' . $idx . '.part';
    $ok = @move_uploaded_file((string)$_FILES['chunk']['tmp_name'], $part);
    if (!$ok) $ok = @rename((string)$_FILES['chunk']['tmp_name'], $part);
    if (!$ok) {
        json_out(['ok' => false, 'msg' => '分片写入失败'], 500);
    }
    @touch($dir);
    json_out(['ok' => true, 'idx' => $idx, 'got' => (int)filesize($part)]);
}

// ---- 分片上传：查已经传了哪些（断点续传） ----
if ($act === 'cstatus') {
    $uid = (string)($_POST['uid'] ?? '');
    if (!preg_match('/^[a-f0-9]{16,32}$/', $uid)) json_out(['ok' => false, 'msg' => '会话无效'], 400);
    $total = (int)($_POST['total'] ?? 0);
    $have = [];
    $dir = TMP_DIR . '/' . $uid;
    for ($i = 0; $i < $total && $i < 8192; $i++) {
        $p = $dir . '/' . $i . '.part';
        if (is_file($p) && filesize($p) > 0) $have[] = $i;
    }
    json_out(['ok' => true, 'have' => $have]);
}

// ---- 分片上传：合并 + 提取 ----
if ($act === 'merge') {
    @set_time_limit(0);
    $uid = (string)($_POST['uid'] ?? '');
    if (!preg_match('/^[a-f0-9]{16,32}$/', $uid)) {
        json_out(['ok' => false, 'msg' => '上传会话无效，刷新页面重试'], 400);
    }
    $total = (int)($_POST['total'] ?? 0);
    $expect = (int)($_POST['size'] ?? 0);
    $name = (string)($_POST['name'] ?? '');
    if ($total <= 0 || $total > 8192 || $name === '') {
        json_out(['ok' => false, 'msg' => '合并参数不对'], 400);
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOW_EXT, true) && !in_array($ext, VIDEO_EXT, true)) {
        json_out(['ok' => false, 'msg' => '不支持的格式 .' . $ext], 400);
    }
    $dir = TMP_DIR . '/' . $uid;
    $src = $dir . '/src.bin';
    $out = @fopen($src, 'wb');
    if (!$out) json_out(['ok' => false, 'msg' => '临时文件写不了'], 500);
    $written = 0;
    for ($i = 0; $i < $total; $i++) {
        $p = $dir . '/' . $i . '.part';
        if (!is_file($p)) {
            fclose($out);
            @unlink($src);
            json_out(['ok' => false, 'msg' => '第 ' . ($i + 1) . ' 片缺失，请重传'], 409);
        }
        $in = @fopen($p, 'rb');
        if (!$in) continue;
        $n = stream_copy_to_stream($in, $out);
        fclose($in);
        $written += (int)$n;
    }
    fclose($out);
    if ($expect > 0 && $written !== $expect) {
        @unlink($src);
        json_out(['ok' => false, 'msg' => '分片总长对不上（收到 ' . human_size($written) . '，应为 ' . human_size($expect) . '），请重传'], 409);
    }
    if ($written > MAX_SOURCE_BYTES) {
        @unlink($src);
        foreach ((array)@glob($dir . '/*') as $p2) @unlink($p2);
        @rmdir($dir);
        json_out(['ok' => false, 'msg' => '源文件 ' . human_size($written) . '，超过 ' . human_size(MAX_SOURCE_BYTES) . ' 上限'], 400);
    }

    $r = process_media($src, $name);

    // 无论成败都清掉临时目录
    foreach ((array)@glob($dir . '/*') as $p) @unlink($p);
    @rmdir($dir);
    gc_tmp();

    if (!$r['ok']) {
        json_out(['ok' => false, 'msg' => (string)($r['msg'] ?? '处理失败')], 400);
    }
    json_out(['ok' => true, 'track' => $r['track'], 'msg' => '已添加']);
}

// ---- 编辑 ----
if ($act === 'save') {
    $id = (string)($_POST['id'] ?? '');
    $title = trim((string)($_POST['title'] ?? ''));
    $artist = trim((string)($_POST['artist'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    $tagsRaw = trim((string)($_POST['tags'] ?? ''));
    if ($id === '') json_out(['ok' => false, 'msg' => '缺少 id'], 400);
    if (mb_strlen($title) > 80) $title = mb_substr($title, 0, 80);
    if (mb_strlen($artist) > 60) $artist = mb_substr($artist, 0, 60);
    if (mb_strlen($note) > 400) $note = mb_substr($note, 0, 400);
    $tags = [];
    foreach (preg_split('/[,，\s]+/u', $tagsRaw) ?: [] as $tg) {
        $tg = trim($tg);
        if ($tg !== '' && mb_strlen($tg) <= 12) $tags[] = $tg;
        if (count($tags) >= 6) break;
    }
    $found = false;
    data_tx(function (array &$d) use ($id, $title, $artist, $note, $tags, &$found) {
        foreach ($d['tracks'] as &$t) {
            if ((string)$t['id'] === $id) {
                $t['title'] = $title !== '' ? $title : (string)($t['title'] ?? '未命名');
                $t['artist'] = $artist;
                $t['note'] = $note;
                $t['tags'] = $tags;
                $found = true;
                break;
            }
        }
        unset($t);
        return true;
    });
    if (!$found) json_out(['ok' => false, 'msg' => '曲目不存在'], 404);
    json_out(['ok' => true, 'msg' => '已保存']);
}

// ---- 封面：上传 / 替换 ----
if ($act === 'cover') {
    $id = (string)($_POST['id'] ?? '');
    if ($id === '') json_out(['ok' => false, 'msg' => '缺少 id'], 400);

    $f = $_FILES['cover'] ?? null;
    if (!is_array($f)) json_out(['ok' => false, 'msg' => '没有收到图片'], 400);
    if (!empty($f['error'])) {
        $map = [
            UPLOAD_ERR_INI_SIZE  => '图片超过服务器上传上限',
            UPLOAD_ERR_FORM_SIZE => '图片超过表单上限',
            UPLOAD_ERR_PARTIAL   => '上传中断，请重试',
            UPLOAD_ERR_NO_FILE   => '没有选择图片',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
            UPLOAD_ERR_EXTENSION => '被 PHP 扩展拦截',
        ];
        json_out(['ok' => false, 'msg' => $map[(int)$f['error']] ?? ('上传失败（' . (int)$f['error'] . '）')], 400);
    }
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) json_out(['ok' => false, 'msg' => '空文件'], 400);
    if ($size > MAX_COVER_BYTES) {
        json_out(['ok' => false, 'msg' => '封面 ' . human_size($size) . '，超过 ' . human_size(MAX_COVER_BYTES) . '，请换一张小点的'], 400);
    }
    $info = @getimagesize((string)$f['tmp_name']);
    if (!$info || empty($info['mime'])) {
        json_out(['ok' => false, 'msg' => '这个文件不是有效图片'], 400);
    }
    $mime = strtolower((string)$info['mime']);
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extMap[$mime])) {
        json_out(['ok' => false, 'msg' => '只支持 JPG / PNG / WebP / GIF'], 400);
    }
    $w = (int)($info[0] ?? 0);
    $h = (int)($info[1] ?? 0);
    if ($w < 60 || $h < 60) json_out(['ok' => false, 'msg' => '图片太小，至少 60×60'], 400);
    if ($w > 8000 || $h > 8000) json_out(['ok' => false, 'msg' => '图片尺寸过大'], 400);

    if (!is_dir(COVER_DIR)) @mkdir(COVER_DIR, 0755, true);
    $name = preg_replace('/[^a-z0-9]/i', '', $id) . '-' . rid(6) . '.' . $extMap[$mime];
    $dest = COVER_DIR . '/' . $name;
    $ok = @move_uploaded_file((string)$f['tmp_name'], $dest) || @rename((string)$f['tmp_name'], $dest);
    if (!$ok) json_out(['ok' => false, 'msg' => '封面写入失败，请检查 uploads 目录权限'], 500);
    @chmod($dest, 0644);

    $found = false;
    $newUrl = '';
    $oldFile = '';
    data_tx(function (array &$d) use ($id, $name, &$found, &$newUrl, &$oldFile) {
        foreach ($d['tracks'] as &$t) {
            if ((string)$t['id'] === $id) {
                $oldFile = (string)($t['cover'] ?? '');
                $t['cover'] = $name;
                $t['cover_at'] = time();
                $found = true;
                $newUrl = cover_url($t);
                break;
            }
        }
        unset($t);
        return true;
    });
    if (!$found) {
        @unlink($dest);
        json_out(['ok' => false, 'msg' => '曲目不存在'], 404);
    }
    if ($oldFile !== '' && $oldFile !== $name) {
        @unlink(COVER_DIR . '/' . basename($oldFile));
    }
    json_out(['ok' => true, 'cover' => $newUrl, 'msg' => '封面已更新']);
}

// ---- 封面：移除 ----
if ($act === 'coverdel') {
    $id = (string)($_POST['id'] ?? '');
    if ($id === '') json_out(['ok' => false, 'msg' => '缺少 id'], 400);
    $oldFile = '';
    $found = false;
    data_tx(function (array &$d) use ($id, &$oldFile, &$found) {
        foreach ($d['tracks'] as &$t) {
            if ((string)$t['id'] === $id) {
                $oldFile = (string)($t['cover'] ?? '');
                unset($t['cover'], $t['cover_at']);
                $found = true;
                break;
            }
        }
        unset($t);
        return true;
    });
    if (!$found) json_out(['ok' => false, 'msg' => '曲目不存在'], 404);
    if ($oldFile !== '') @unlink(COVER_DIR . '/' . basename($oldFile));
    json_out(['ok' => true, 'msg' => '封面已移除']);
}

// ---- 歌单设置（标题 / 简介 / SEO） ----
if ($act === 'settings') {
    $clip = static function (string $v, int $max): string {
        $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? $v);
        return mb_substr($v, 0, $max);
    };
    $title = $clip((string)($_POST['title'] ?? ''), 40);
    $intro = $clip((string)($_POST['intro'] ?? ''), 300);
    $seoTitle = $clip((string)($_POST['seo_title'] ?? ''), 80);
    $seoDesc = $clip((string)($_POST['seo_desc'] ?? ''), 200);
    $seoKw = $clip((string)($_POST['seo_keywords'] ?? ''), 200);
    if ($title === '') $title = '我喜欢的歌';
    data_tx(function (array &$d) use ($title, $intro, $seoTitle, $seoDesc, $seoKw) {
        $d['settings'] = [
            'title' => $title,
            'intro' => $intro,
            'seo_title' => $seoTitle,
            'seo_desc' => $seoDesc,
            'seo_keywords' => $seoKw,
        ];
        return true;
    });
    json_out(['ok' => true, 'msg' => '设置已保存', 'settings' => wb_settings(data_load())]);
}

// ---- 写回时长（由浏览器读元数据后回填） ----
if ($act === 'dur') {
    $id = (string)($_POST['id'] ?? '');
    $dur = (float)($_POST['dur'] ?? 0);
    if ($id === '' || $dur <= 0 || $dur > 36000) json_out(['ok' => false], 400);
    data_tx(function (array &$d) use ($id, $dur) {
        foreach ($d['tracks'] as &$t) {
            if ((string)$t['id'] === $id) {
                $t['dur'] = round($dur, 1);
                break;
            }
        }
        unset($t);
        return true;
    });
    json_out(['ok' => true, 'dur' => $dur]);
}

// ---- 上移 / 下移 ----
if ($act === 'move') {
    $id = (string)($_POST['id'] ?? '');
    $dir = (string)($_POST['dir'] ?? 'up') === 'down' ? 1 : -1;
    $order = data_tx(function (array &$d) use ($id, $dir) {
        $idx = -1;
        foreach ($d['tracks'] as $i => $t) {
            if ((string)$t['id'] === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx < 0) return null;
        $to = $idx + $dir;
        if ($to < 0 || $to >= count($d['tracks'])) return null;
        $tmp = $d['tracks'][$idx];
        $d['tracks'][$idx] = $d['tracks'][$to];
        $d['tracks'][$to] = $tmp;
        return array_column($d['tracks'], 'id');
    });
    if ($order === null) json_out(['ok' => false, 'msg' => '已经到头了'], 400);
    json_out(['ok' => true, 'order' => $order]);
}

// ---- 删除 ----
if ($act === 'del') {
    $id = (string)($_POST['id'] ?? '');
    $gone = data_tx(function (array &$d) use ($id) {
        $gone = ['file' => '', 'cover' => ''];
        foreach ($d['tracks'] as $i => $t) {
            if ((string)$t['id'] === $id) {
                $gone['file'] = (string)($t['file'] ?? '');
                $gone['cover'] = (string)($t['cover'] ?? '');
                array_splice($d['tracks'], $i, 1);
                break;
            }
        }
        return $gone;
    });
    if (!empty($gone['file'])) {
        @unlink(UP_DIR . '/' . basename((string)$gone['file']));
    }
    if (!empty($gone['cover'])) {
        @unlink(COVER_DIR . '/' . basename((string)$gone['cover']));
    }
    json_out(['ok' => true, 'msg' => '已删除']);
}

// ============ 页面 ============
$logged = is_logged();
$forceAdmin = isset($_GET['admin']);
$forcePublic = isset($_GET['preview']);
$songId = trim((string)($_GET['song'] ?? ''));
/* 站主默认落到后台；带 ?preview=1 或 ?song= 可看访客视角。
   访客只有带 ?admin=1 才进登录页 */
$adminMode = $logged ? (!$forcePublic && $songId === '') : $forceAdmin;

$d = data_load();
$settings = wb_settings($d);
$site = wb_site_info();
$home = site_root();
$homeUrl = $home === '' ? '/' : $home . '/';
$pubTracks = [];
foreach ($d['tracks'] as $t) {
    if (isset($t['id'])) $pubTracks[] = $t;
}

if (!$adminMode) {
    require_once __DIR__ . '/public.php';
    $mode = 'playlist';
    $track = null;
    $idx = -1;
    if ($songId !== '') {
        foreach ($pubTracks as $i => $t) {
            if ((string)$t['id'] === $songId) { $track = $t; $idx = $i; break; }
        }
        if ($track !== null) {
            $mode = 'song';
        } else {
            header('Location: ' . base_path() . '/', true, 302);
            exit;
        }
    }
    wb_public_render([
        'mode'       => $mode,
        'base'       => base_path(),
        'home'       => $home,
        'site'       => $site,
        'settings'   => $settings,
        'tracks'     => $pubTracks,
        'track'      => $track,
        'index'      => $idx,
        'logged'     => $logged,
        'default_og' => wb_abs_url(($home === '' ? '' : $home) . '/usr/themes/wbclean/assets/og-cover.jpg'),
        'jsonld'     => [],
    ]);
    exit;
}

$trackCount = count($d['tracks']);
$totalBytes = 0;
foreach ($d['tracks'] as $t) $totalBytes += (int)($t['size'] ?? 0);
$limit = upload_limit();
$msgFail = !is_installed();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>音乐库 · 后台 · <?php echo h($site['title']); ?></title>
<link rel="icon" href="<?php echo h($homeUrl); ?>usr/themes/wbclean/assets/favicon-64.png">
<style>
:root{
  --bg:#f2f6fc; --card:#fff; --line:#e5eaf3; --line-2:#eef2f8;
  --text:#0f172a; --text2:#465468; --text3:#7b8799;
  --brand:#2f6fed; --brand-dark:#2159c9; --brand-weak:#eaf1ff;
  --ok:#0fa968; --warn:#d97706; --danger:#e0453f;
  --radius:16px;
  --shadow:0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.06);
}
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  background:var(--bg); color:var(--text);
  font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;
  -webkit-font-smoothing:antialiased;
}
a{color:var(--brand);text-decoration:none}
button{font:inherit;cursor:pointer}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}

/* ---------- 顶栏 ---------- */
.top{
  background:linear-gradient(118deg,#0e1e3c 0%,#173463 52%,#1e4279 100%);
  color:#fff; position:sticky; top:0; z-index:30;
  box-shadow:0 8px 26px rgba(14,30,60,.18);
}
.top-in{
  max-width:1000px;margin:0 auto;padding:0 22px;height:60px;
  display:flex;align-items:center;gap:14px;
}
.brand{display:flex;align-items:center;gap:11px;font-weight:600;letter-spacing:.3px;color:#fff}
.brand-i{
  width:32px;height:32px;border-radius:9px;display:grid;place-items:center;
  background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.16);
}
.brand small{display:block;font-size:11px;font-weight:400;opacity:.62;letter-spacing:.5px;margin-top:1px}
.top-nav{margin-left:auto;display:flex;align-items:center;gap:6px}
.top-link{
  color:rgba(255,255,255,.82);font-size:13.5px;padding:7px 12px;border-radius:9px;
  transition:background .18s,color .18s;display:inline-flex;align-items:center;gap:6px;
}
.top-link:hover{background:rgba(255,255,255,.12);color:#fff}

/* ---------- 容器 ---------- */
.wrap{max-width:1000px;margin:0 auto;padding:26px 22px 60px}
.page-h{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin:6px 0 20px}
.page-h h1{margin:0;font-size:23px;letter-spacing:-.3px}
.page-h .sub{color:var(--text3);font-size:13.5px;margin:0 0 3px}
.chips{display:flex;gap:8px;margin-left:auto;flex-wrap:wrap}
.chip{
  font-size:12.5px;color:var(--text2);background:#fff;border:1px solid var(--line);
  border-radius:99px;padding:5px 12px;display:inline-flex;align-items:center;gap:6px;
}
.chip b{color:var(--text);font-variant-numeric:tabular-nums}

.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow)}

/* ---------- 登录 ---------- */
.login-box{max-width:380px;margin:12vh auto 0;padding:30px 28px 26px}
.login-box h1{font-size:20px;margin:0 0 6px;letter-spacing:-.2px}
.login-box p{color:var(--text3);font-size:13.5px;margin:0 0 20px;line-height:1.7}
.field{margin-bottom:14px}
.field label{display:block;font-size:12.5px;color:var(--text2);margin-bottom:6px;font-weight:500}
.input{
  width:100%;height:44px;border:1px solid var(--line);border-radius:11px;padding:0 14px;
  font-size:15px;background:#fbfcfe;color:var(--text);transition:border-color .18s,box-shadow .18s,background .18s;
}
.input:focus{outline:0;border-color:#9dbcff;background:#fff;box-shadow:0 0 0 4px rgba(47,111,237,.12)}
.btn{
  display:inline-flex;align-items:center;justify-content:center;gap:7px;
  height:42px;padding:0 18px;border-radius:11px;border:1px solid var(--line);
  background:#fff;color:var(--text);font-size:14px;font-weight:500;
  transition:transform .18s cubic-bezier(.2,.9,.25,1),background .18s,box-shadow .18s,border-color .18s;
}
.btn:hover{background:#f8fafd;transform:translateY(-1px);box-shadow:0 4px 12px rgba(16,24,40,.07)}
.btn:active{transform:scale(.985)}
.btn:focus-visible{outline:0;box-shadow:0 0 0 4px rgba(47,111,237,.18)}
.btn-primary{background:linear-gradient(180deg,#3b7bf2,#2a63d9);border-color:#2a63d9;color:#fff;font-weight:600}
.btn-primary:hover{background:linear-gradient(180deg,#4485f7,#2f6bed);border-color:#2f6bed;box-shadow:0 6px 18px rgba(47,111,237,.28)}
.btn-block{width:100%;height:44px}
.btn-sm{height:34px;padding:0 13px;font-size:13px;border-radius:9px}
.btn-danger{color:var(--danger);border-color:#f6d3d1;background:#fff}
.btn-danger:hover{background:#fdf2f1;border-color:#f0b5b1}
.tip{font-size:12.5px;color:var(--text3);margin:14px 0 0;line-height:1.7}
.err{background:#fdf2f1;border:1px solid #f6d3d1;color:#b02520;font-size:13px;border-radius:10px;padding:9px 12px;margin-bottom:14px}

/* ---------- 上传区 ---------- */
.up{
  border:1.5px dashed #cfdaea;border-radius:var(--radius);background:
    radial-gradient(120% 100% at 50% 0%, #f7faff 0%, #fff 60%);
  padding:28px 22px;text-align:center;transition:border-color .2s,background .2s,transform .2s;
  margin-bottom:16px;
}
.up.hot{border-color:#8ab0f7;background:radial-gradient(120% 100% at 50% 0%,#eef4ff 0%,#fff 70%);transform:scale(1.004)}
.up-ico{
  width:52px;height:52px;border-radius:15px;margin:0 auto 12px;display:grid;place-items:center;
  background:var(--brand-weak);color:var(--brand);border:1px solid #d9e5ff;
}
.up h2{margin:0 0 5px;font-size:16px;font-weight:600}
.up p{margin:0 0 15px;font-size:13px;color:var(--text3)}
.up-actions{display:flex;gap:9px;justify-content:center;flex-wrap:wrap}
.up-note{margin:14px 0 0;font-size:12px;color:var(--text3);line-height:1.8}

/* 上传队列 */
.queue{margin-top:16px;display:none;flex-direction:column;gap:8px}
.queue.on{display:flex}
.q-row{
  display:flex;align-items:center;gap:11px;padding:9px 12px;border:1px solid var(--line);
  border-radius:11px;background:#fbfcfe;font-size:13px;
}
.q-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text2)}
.q-bar{width:120px;height:5px;border-radius:99px;background:#e8eef8;overflow:hidden;flex:none}
.q-bar i{display:block;height:100%;width:0;background:linear-gradient(90deg,#5b93f7,#2f6fed);border-radius:99px;transition:width .2s}
.q-st{font-size:12px;color:var(--text3);flex:none;min-width:56px;text-align:right}
.q-st.ok{color:var(--ok)}
.q-st.bad{color:var(--danger)}

/* ---------- 列表 ---------- */
.list-card{padding:6px 6px 10px}
.list-h{
  display:flex;align-items:center;gap:10px;padding:12px 14px 10px;border-bottom:1px solid var(--line-2);
}
.list-h h2{margin:0;font-size:14.5px;font-weight:600}
.list-h .cnt{font-size:12.5px;color:var(--text3)}
.list-h .right{margin-left:auto;font-size:12px;color:var(--text3)}
.rows{list-style:none;margin:0;padding:6px}
.row{
  display:grid;grid-template-columns:30px 46px minmax(0,1fr) auto;
  align-items:center;gap:12px;padding:10px 10px;border-radius:12px;
  transition:background .16s;
}
.row + .row{margin-top:2px}
.row:hover{background:#f7fafe}
.row.playing{background:var(--brand-weak)}
.row-n{font-size:12px;color:var(--text3);text-align:center;font-variant-numeric:tabular-nums}
.row-art{
  width:46px;height:46px;border-radius:11px;cursor:pointer;display:grid;place-items:center;color:#fff;
  font-size:16px;font-weight:700;letter-spacing:.5px;position:relative;flex:none;overflow:hidden;
}
.row-art::after{
  content:'';position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.18),rgba(255,255,255,0));
}
.row-main{min-width:0}
.row-title{
  font-size:14.5px;font-weight:600;color:var(--text);margin:0 0 2px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
}
.row-meta{
  font-size:12.5px;color:var(--text3);display:flex;align-items:center;gap:7px;flex-wrap:wrap;
}
.row-meta .dot{width:3px;height:3px;border-radius:50%;background:#c6cedb;flex:none}
.row-note{
  font-size:12.5px;color:var(--text2);margin:5px 0 0;line-height:1.6;
  display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;
}
.row-tags{display:inline-flex;gap:5px;flex-wrap:wrap}
.row-tags span{background:#f1f5fb;color:var(--text3);border-radius:5px;padding:1px 6px;font-size:11px}
.row-q{
  font-size:11.5px;color:#5b6b82;font-variant-numeric:tabular-nums;
  background:#f4f7fc;border:1px solid #e8eef7;border-radius:5px;padding:1px 7px;
}
.row-from{
  font-size:11.5px;color:#2b6cb0;background:#eaf3ff;border:1px solid #d8e7fb;
  border-radius:5px;padding:1px 7px;
}
.row-act{display:flex;gap:3px;align-items:center}
.ico-btn{
  width:32px;height:32px;border-radius:9px;border:1px solid transparent;background:transparent;
  color:var(--text3);display:grid;place-items:center;
  transition:background .16s,color .16s,transform .16s,border-color .16s;
}
.ico-btn:hover{background:#fff;border-color:var(--line);color:var(--brand);box-shadow:0 2px 8px rgba(16,24,40,.06)}
.ico-btn:active{transform:scale(.94)}
.ico-btn:focus-visible{outline:0;box-shadow:0 0 0 3px rgba(47,111,237,.2)}
.ico-btn.del:hover{color:var(--danger);border-color:#f6d3d1}
.ico-btn[disabled]{opacity:.35;pointer-events:none}

.empty{padding:44px 20px;text-align:center;color:var(--text3)}
.empty svg{opacity:.45;margin-bottom:10px}
.empty p{margin:0 0 4px;font-size:14px;color:var(--text2)}
.empty small{font-size:12.5px}

/* ---------- 编辑弹窗 ---------- */
dialog{
  border:0;padding:0;border-radius:18px;width:min(520px,calc(100vw - 32px));
  box-shadow:0 30px 80px rgba(15,23,42,.28);background:#fff;color:var(--text);
}
dialog::backdrop{background:rgba(12,22,40,.42);backdrop-filter:blur(2px)}
.dlg-h{display:flex;align-items:center;gap:10px;padding:18px 20px 0}
.dlg-h h3{margin:0;font-size:16.5px}
.dlg-h .ico-btn{margin-left:auto}
.dlg-b{padding:14px 20px 4px}
.dlg-f{padding:8px 20px 18px;display:flex;gap:9px;justify-content:flex-end}
textarea.input{
  height:auto;min-height:92px;padding:10px 13px;line-height:1.7;resize:vertical;font-family:inherit;
}
.hint-row{display:flex;justify-content:space-between;font-size:11.5px;color:var(--text3);margin-top:5px}
.counter.out{color:var(--danger)}

.toast{
  position:fixed;left:50%;bottom:26px;transform:translate(-50%,14px);
  background:#0f1f3d;color:#fff;font-size:13.5px;padding:10px 18px;border-radius:99px;
  box-shadow:0 12px 36px rgba(15,31,61,.3);opacity:0;pointer-events:none;
  transition:opacity .22s,transform .22s;z-index:60;
}
.toast.on{opacity:1;transform:translate(-50%,0)}
.toast.bad{background:#b02520}

.foot-note{
  margin:22px 0 0;font-size:12px;color:var(--text3);line-height:1.9;text-align:center;
}

@media(max-width:720px){
  .top-in{height:56px;padding:0 14px}
  .brand small{display:none}
  .top-link span{display:none}
  .top-link{padding:8px 10px}
  .wrap{padding:18px 14px 50px}
  .page-h h1{font-size:20px}
  .chips{width:100%;margin-left:0}
  .row{grid-template-columns:24px 40px minmax(0,1fr);gap:10px;padding:10px 8px}
  .row-art{width:40px;height:40px;border-radius:10px;font-size:14px}
  .row-act{grid-column:2 / -1;justify-content:flex-end;margin-top:-4px}
  .row-note{-webkit-line-clamp:2}
  .q-bar{width:70px}
  .up{padding:22px 14px}
}
@media(prefers-reduced-motion:reduce){
  *{transition:none!important;animation:none!important}
}

/* ---------- 歌单设置 ---------- */
.set{margin:0 0 16px;overflow:hidden}
.set-head{
  width:100%;display:flex;align-items:center;gap:12px;padding:14px 16px;
  background:none;border:0;text-align:left;color:inherit;
}
.set-head:hover{background:#f8fafd}
.set-ico{
  width:34px;height:34px;border-radius:10px;flex:none;display:grid;place-items:center;
  background:var(--brand-weak);color:var(--brand);border:1px solid #d9e5ff;
}
.set-title{display:flex;flex-direction:column;line-height:1.4;min-width:0}
.set-title b{font-size:14px}
.set-title small{font-size:11.5px;color:var(--text3)}
.set-state{margin-left:auto;font-size:11.5px;color:var(--ok);background:#eafaf2;border:1px solid #c9f0dd;border-radius:99px;padding:2px 9px;white-space:nowrap}
.set-chev{margin-left:4px;transition:transform .28s cubic-bezier(.2,.9,.25,1);color:var(--text3)}
.set.open .set-chev{transform:rotate(180deg)}
.set-body{display:none;padding:2px 16px 16px;border-top:1px solid var(--line-2)}
.set.open .set-body{display:block}
.set-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px 14px;padding-top:14px}
.set-grid .span2{grid-column:1 / -1}
.set-grid .field{margin:0}
.set-grid .input{height:40px;font-size:14px}
.set-grid textarea.input{height:auto;min-height:70px}
.serp{
  margin-top:14px;padding:13px 15px;border:1px solid var(--line);border-radius:12px;background:#fbfcfe;
}
.serp-k{margin:0 0 7px;font-size:10.5px;letter-spacing:.12em;color:var(--text3);text-transform:uppercase}
.serp-t{margin:0;font-size:16px;color:#1a0dab;line-height:1.4;word-break:break-word}
.serp-u{margin:2px 0 3px;font-size:12px;color:#0d652d;word-break:break-all}
.serp-d{margin:0;font-size:12.8px;color:var(--text2);line-height:1.6}
.set-foot{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-top:14px}
.set-tip{font-size:11.5px;color:var(--text3)}
.seo-links{margin:10px 0 0;font-size:11.8px;line-height:1.8;color:var(--text3)}
.seo-links a{color:var(--brand)}

/* ---------- 封面 ---------- */
.cover-field{display:flex;align-items:center;gap:14px;padding:12px;border:1px solid var(--line);border-radius:12px;background:#fbfcfe;margin-bottom:14px}
.cover-prev{
  width:76px;height:76px;border-radius:12px;flex:none;overflow:hidden;
  background:linear-gradient(135deg,#c7d2e4,#8fa3bf);display:grid;place-items:center;
  color:#fff;font-weight:700;font-size:24px;background-size:cover;background-position:center;
  box-shadow:0 2px 10px rgba(16,24,40,.14);
}
.cover-meta{min-width:0;flex:1}
.cover-meta b{display:block;font-size:13px;margin-bottom:2px}
.cover-meta small{display:block;font-size:11.5px;color:var(--text3);line-height:1.5}
.cover-acts{display:flex;gap:6px;margin-top:7px;flex-wrap:wrap}
.row-art.has-cover{background-size:cover;background-position:center;color:transparent}
.row-art.has-cover::after{display:none}
@media(max-width:720px){
  .set-grid{grid-template-columns:1fr}
  .set-state{display:none}
}
</style>
</head>
<body>

<header class="top">
  <div class="top-in">
    <div class="brand">
      <span class="brand-i">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/>
        </svg>
      </span>
      <span>音乐库<small>MUSIC LIBRARY</small></span>
    </div>
    <nav class="top-nav">
      <a class="top-link" href="<?php echo h($homeUrl); ?>">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 10.6 12 3.6l8.5 7"/><path d="M5.6 9.6V20h12.8V9.6"/><path d="M9.8 20v-5.4h4.4V20"/></svg>
        <span>回网站</span>
      </a>
      <?php if ($logged): ?>
      <a class="top-link" href="?preview=1" target="_blank" rel="noopener">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.4 12s3.6-6.4 9.6-6.4S21.6 12 21.6 12 18 18.4 12 18.4 2.4 12 2.4 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
        <span>看歌单</span>
      </a>
      <a class="top-link" href="?act=logout">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 17l5-5-5-5"/><path d="M20 12H9"/><path d="M12 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h6"/></svg>
        <span>退出</span>
      </a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<?php if (!$logged): ?>
<main class="wrap">
  <div class="card login-box">
    <h1>音乐库</h1>
    <p>登录后可以上传曲子、写备注、调整顺序。密码与云盘、管理台共用一份。</p>
    <?php if ($msgFail): ?>
      <div class="err">还没有管理员密码。请先打开<a href="<?php echo h($homeUrl); ?>drive/">云盘</a>设置一次，这里会自动沿用。</div>
    <?php else: ?>
      <div class="err" id="loginErr" style="display:none"></div>
      <form id="loginForm" autocomplete="off">
        <div class="field">
          <label for="pwd">管理员密码</label>
          <input class="input" type="password" id="pwd" name="password" placeholder="••••••••" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit" id="loginBtn">进入</button>
      </form>
    <?php endif; ?>
    <p class="tip">这个页面不会被搜索引擎收录。</p>
  </div>
</main>
<script>
(function(){
  var f = document.getElementById('loginForm');
  if (!f) return;
  var btn = document.getElementById('loginBtn');
  var err = document.getElementById('loginErr');
  f.addEventListener('submit', function(e){
    e.preventDefault();
    btn.disabled = true; btn.textContent = '验证中…';
    err.style.display = 'none';
    var fd = new FormData();
    fd.append('password', document.getElementById('pwd').value);
    fd.append('csrf', <?php echo json_encode(csrf()); ?>);
    fetch('?act=login', { method: 'POST', body: fd, headers: { 'X-CSRF': <?php echo json_encode(csrf()); ?> } })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j.ok) { location.reload(); return; }
        err.textContent = j.msg || '登录失败';
        err.style.display = 'block';
        btn.disabled = false; btn.textContent = '进入';
      })
      .catch(function(){
        err.textContent = '网络异常，请重试';
        err.style.display = 'block';
        btn.disabled = false; btn.textContent = '进入';
      });
  });
})();
</script>
<?php else: ?>
<main class="wrap">
  <div class="page-h">
    <div>
      <h1>音乐库</h1>
      <p class="sub">上传后全站播放器与公开歌单会同时更新</p>
    </div>
    <div class="chips">
      <span class="chip">曲目 <b id="cntTracks"><?php echo $trackCount; ?></b></span>
      <span class="chip">占用 <b id="cntSize"><?php echo h(human_size($totalBytes)); ?></b></span>
      <span class="chip">成品上限 <b><?php echo h(human_size(MAX_TRACK_BYTES)); ?></b></span>
    </div>
  </div>

  <section class="card set" id="setCard">
    <button class="set-head" id="setToggle" type="button" aria-expanded="false" aria-controls="setBody">
      <span class="set-ico" aria-hidden="true">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.4"/><path d="m20 20-4.2-4.2"/></svg>
      </span>
      <span class="set-title">
        <b>歌单与搜索</b>
        <small>标题、简介、搜索引擎关键词</small>
      </span>
      <span class="set-state" id="setState">公开可搜</span>
      <svg class="set-chev" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
    <div class="set-body" id="setBody">
      <div class="set-grid">
        <div class="field">
          <label for="sTitle">歌单名</label>
          <input class="input" id="sTitle" maxlength="40" value="<?php echo h($settings['title']); ?>" placeholder="我喜欢的歌">
        </div>
        <div class="field">
          <label for="sKeywords">搜索关键词</label>
          <input class="input" id="sKeywords" maxlength="200" value="<?php echo h($settings['seo_keywords']); ?>" placeholder="留空则自动从标签和曲名生成">
        </div>
        <div class="field span2">
          <label for="sIntro">歌单简介</label>
          <textarea class="input" id="sIntro" maxlength="300" placeholder="写几句这份歌单的由来"><?php echo h($settings['intro']); ?></textarea>
          <div class="hint-row"><span>显示在歌单页首屏，也会进搜索摘要</span><span id="introCnt">0 / 300</span></div>
        </div>
        <div class="field span2">
          <label for="sSeoTitle">搜索结果标题</label>
          <input class="input" id="sSeoTitle" maxlength="80" value="<?php echo h($settings['seo_title']); ?>" placeholder="留空自动：歌单名 · 歌单 · 站点名">
        </div>
        <div class="field span2">
          <label for="sSeoDesc">搜索结果描述</label>
          <textarea class="input" id="sSeoDesc" maxlength="200" placeholder="留空自动按曲目和备注生成"><?php echo h($settings['seo_desc']); ?></textarea>
        </div>
      </div>
      <div class="serp" aria-live="polite">
        <p class="serp-k">搜索结果预览</p>
        <p class="serp-t" id="serpT"></p>
        <p class="serp-u" id="serpU"></p>
        <p class="serp-d" id="serpD"></p>
      </div>
      <p class="seo-links">
        动态站点地图 <a href="sitemap.php" target="_blank" rel="noopener">/music/sitemap.php</a>（曲目变化自动更新）· 可提交到
        <a href="https://ziyuan.baidu.com/linksubmit/url" target="_blank" rel="noopener nofollow">百度搜索资源平台</a> /
        <a href="https://search.google.com/search-console" target="_blank" rel="noopener nofollow">Google Search Console</a> /
        <a href="https://www.bing.com/webmasters" target="_blank" rel="noopener nofollow">Bing Webmaster</a>
      </p>
      <div class="set-foot">
        <button class="btn btn-primary btn-sm" id="setSave" type="button">保存设置</button>
        <a class="btn btn-sm" href="?preview=1" target="_blank" rel="noopener">打开歌单页</a>
        <span class="set-tip">保存后公开页、结构化数据、播放器同步更新</span>
      </div>
    </div>
  </section>

  <section class="card up" id="drop">
    <div class="up-ico">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5"/><path d="M4 15v3.5A2.5 2.5 0 0 0 6.5 21h11a2.5 2.5 0 0 0 2.5-2.5V15"/>
      </svg>
    </div>
    <h2>把歌或视频拖进来</h2>
    <p>MP4 / MOV 会自动抽出音轨，只留下声音</p>
    <div class="up-actions">
      <button class="btn btn-primary" id="pickBtn" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
        选择文件
      </button>
      <button class="btn" id="hintBtn" type="button">支持哪些格式</button>
    </div>
    <p class="up-note" id="upHint" style="display:none">
      音频：mp3 / m4a / aac / ogg / wav / flac / opus。<br>
      视频：mp4 / mov / m4v —— 只把音轨原样搬出来，不重编码、不降码率，画质那部分直接丢掉。<br>
      大文件自动分片上传，断了刷新重选会接着传；成品单曲上限 <?php echo h(human_size(MAX_TRACK_BYTES)); ?>。
    </p>
    <input type="file" id="fileInput" accept="audio/*,video/mp4,video/quicktime,.mp3,.m4a,.aac,.flac,.wav,.ogg,.opus,.mp4,.mov,.m4v" multiple hidden>
    <div class="queue" id="queue"></div>
  </section>

  <section class="card list-card">
    <div class="list-h">
      <h2>播放列表</h2>
      <span class="cnt" id="listCnt"><?php echo $trackCount; ?> 首</span>
      <span class="right">从上到下的顺序，就是播放器里的顺序</span>
    </div>
    <ul class="rows" id="rows">
      <?php if (!$trackCount): ?>
      <li class="empty">
        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M9 18V6l10-2v12"/><circle cx="6.5" cy="18" r="2.6"/><circle cx="16.5" cy="16" r="2.6"/>
        </svg>
        <p>还没有曲子</p>
        <small>上传第一首，右下角就会出现播放器</small>
      </li>
      <?php else: ?>
        <?php foreach ($d['tracks'] as $i => $t):
            $title = (string)($t['title'] ?? '未命名');
            $artist = (string)($t['artist'] ?? '');
            $note = (string)($t['note'] ?? '');
            $tags = array_values(array_filter((array)($t['tags'] ?? []), 'is_string'));
            $dur = (float)($t['dur'] ?? 0);
            $size = (int)($t['size'] ?? 0);
        ?>
        <li class="row" data-id="<?php echo h((string)$t['id']); ?>"
            data-title="<?php echo h($title); ?>" data-artist="<?php echo h($artist); ?>"
            data-note="<?php echo h($note); ?>" data-tags="<?php echo h(implode(', ', $tags)); ?>"
            data-url="<?php echo h(base_path() . '/?get=' . rawurlencode((string)$t['id'])); ?>"
            data-cover="<?php echo h(cover_url($t)); ?>">
          <span class="row-n"><?php echo $i + 1; ?></span>
          <?php $__cv = cover_url($t); ?>
          <span class="row-art<?php echo $__cv !== '' ? ' has-cover' : ''; ?>" title="点这里换封面" style="<?php echo $__cv !== '' ? 'background-image:url(' . h($__cv) . ')' : 'background:' . h(art_grad((string)$t['id'])); ?>"><?php echo h(mb_substr($title, 0, 1)); ?></span>
          <div class="row-main">
            <p class="row-title"><?php echo h($title); ?></p>
            <div class="row-meta">
              <?php if ($artist !== ''): ?><span><?php echo h($artist); ?></span><span class="dot"></span><?php endif; ?>
              <span class="js-dur"><?php echo $dur > 0 ? h(fmt_dur($dur)) : '--:--'; ?></span>
              <span class="dot"></span>
              <span><?php echo h(human_size($size)); ?></span>
              <?php $ql = quality_label($t); if ($ql !== ''): ?>
                <span class="dot"></span><span class="row-q"><?php echo h($ql); ?></span>
              <?php endif; ?>
              <?php if (!empty($t['src'])): ?>
                <span class="dot"></span><span class="row-from" title="<?php echo h((string)$t['src']); ?>">视频提取</span>
              <?php endif; ?>
              <?php if ($tags): ?><span class="dot"></span><span class="row-tags"><?php foreach ($tags as $tg): ?><span><?php echo h($tg); ?></span><?php endforeach; ?></span><?php endif; ?>
            </div>
            <?php if ($note !== ''): ?><p class="row-note"><?php echo h($note); ?></p><?php endif; ?>
          </div>
          <div class="row-act">
            <button class="ico-btn js-play" type="button" title="试听" aria-label="试听 <?php echo h($title); ?>">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>
            </button>
            <button class="ico-btn js-up" type="button" title="上移" aria-label="上移"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>
            <button class="ico-btn js-down" type="button" title="下移" aria-label="下移"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12l7 7 7-7"/></svg></button>
            <button class="ico-btn js-edit" type="button" title="编辑信息" aria-label="编辑信息"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 6.5 17.5 9.5"/></svg></button>
            <button class="ico-btn del js-del" type="button" title="删除" aria-label="删除"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4.8A.8.8 0 0 1 9.8 4h4.4a.8.8 0 0 1 .8.8V7"/><path d="M6 7l.9 12.2a1 1 0 0 0 1 .8h8.2a1 1 0 0 0 1-.8L18 7"/></svg></button>
          </div>
        </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ul>
  </section>

  <p class="foot-note">
    播放器在网站右下角，所有页面都能用；访客看不到这个后台。<br>
    备注会显示在播放器的「关于这首」里，支持 400 字。
  </p>
</main>

<dialog id="editDlg">
  <form method="dialog" id="editForm">
    <div class="dlg-h">
      <h3>编辑曲目</h3>
      <button class="ico-btn" type="button" id="dlgClose" aria-label="关闭">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
      </button>
    </div>
    <div class="dlg-b">
      <div class="cover-field">
        <div class="cover-prev" id="fCoverPrev" aria-hidden="true"></div>
        <div class="cover-meta">
          <b>封面</b>
          <small id="fCoverHint">正方形最好。选图后浏览器先压到 900px 内再传，原图不会被上传。</small>
          <div class="cover-acts">
            <button class="btn btn-sm" type="button" id="fCoverPick">上传封面</button>
            <button class="btn btn-sm btn-danger" type="button" id="fCoverDel">移除</button>
          </div>
        </div>
        <input type="file" id="fCoverInput" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
      </div>
      <div class="field">
        <label for="fTitle">标题</label>
        <input class="input" id="fTitle" maxlength="80" placeholder="例如：夜航">
      </div>
      <div class="field">
        <label for="fArtist">歌手 / 来源</label>
        <input class="input" id="fArtist" maxlength="60" placeholder="可以留空">
      </div>
      <div class="field">
        <label for="fTags">标签</label>
        <input class="input" id="fTags" maxlength="60" placeholder="用逗号分隔，最多 6 个，例如：深夜, 循环">
      </div>
      <div class="field" style="margin-bottom:0">
        <label for="fNote">备注</label>
        <textarea class="input" id="fNote" maxlength="400" placeholder="写点关于这首歌的话：什么时候听的、为什么留在这里、想起什么。"></textarea>
        <div class="hint-row"><span>会显示在播放器里，访客也能看到</span><span id="noteCnt">0 / 400</span></div>
      </div>
    </div>
    <div class="dlg-f">
      <button class="btn" type="button" id="dlgCancel">取消</button>
      <button class="btn btn-primary" type="button" id="dlgSave">保存</button>
    </div>
  </form>
</dialog>

<div class="toast" id="toast" role="status" aria-live="polite"></div>
<audio id="preview" preload="none"></audio>

<script>
(function(){
  var CSRF = <?php echo json_encode(csrf()); ?>;
  var API = location.pathname;
  var $ = function(id){ return document.getElementById(id); };
  var toastT = null;

  function toast(msg, bad){
    var el = $('toast');
    el.textContent = msg;
    el.className = 'toast on' + (bad ? ' bad' : '');
    clearTimeout(toastT);
    toastT = setTimeout(function(){ el.className = 'toast'; }, 2400);
  }

  function post(act, data){
    var fd = new FormData();
    fd.append('csrf', CSRF);
    Object.keys(data || {}).forEach(function(k){ fd.append(k, data[k]); });
    return fetch('?act=' + act, { method: 'POST', body: fd, headers: { 'X-CSRF': CSRF }, credentials: 'same-origin' })
      .then(function(r){ return r.json().catch(function(){ return { ok: false, msg: '返回异常（HTTP ' + r.status + '）' }; }); });
  }

  /* ---------- 试听 ---------- */
  var preview = $('preview');
  var playingRow = null;
  document.addEventListener('click', function(e){
    var b = e.target.closest ? e.target.closest('.js-play') : null;
    if (!b) return;
    var row = b.closest('.row');
    var url = row.getAttribute('data-url');
    if (playingRow === row && !preview.paused) {
      preview.pause();
      b.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>';
      row.classList.remove('playing');
      return;
    }
    document.querySelectorAll('.row.playing').forEach(function(r){ r.classList.remove('playing'); });
    document.querySelectorAll('.js-play').forEach(function(x){
      x.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>';
    });
    preview.src = url;
    preview.play().catch(function(){ toast('浏览器拦了自动播放，再点一次', true); });
    playingRow = row;
    row.classList.add('playing');
    b.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="7" y="5" width="3.6" height="14" rx="1"/><rect x="13.4" y="5" width="3.6" height="14" rx="1"/></svg>';
  });
  preview.addEventListener('ended', function(){
    if (playingRow) {
      playingRow.classList.remove('playing');
      var b = playingRow.querySelector('.js-play');
      if (b) b.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg>';
      playingRow = null;
    }
  });

  /* ---------- 上传 ---------- */
  var input = $('fileInput'), drop = $('drop'), queue = $('queue');
  $('pickBtn').addEventListener('click', function(){ input.click(); });
  $('hintBtn').addEventListener('click', function(){
    var el = $('upHint');
    el.style.display = el.style.display === 'none' ? '' : 'none';
  });
  input.addEventListener('change', function(){
    if (input.files && input.files.length) uploadAll(input.files);
    input.value = '';
  });
  ['dragenter', 'dragover'].forEach(function(ev){
    drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.add('hot'); });
  });
  ['dragleave', 'drop'].forEach(function(ev){
    drop.addEventListener(ev, function(e){ e.preventDefault(); drop.classList.remove('hot'); });
  });
  drop.addEventListener('drop', function(e){
    if (e.dataTransfer && e.dataTransfer.files.length) uploadAll(e.dataTransfer.files);
  });

  var MAX_DIRECT = <?php echo (int)$limit; ?>;
  var MAX_TRACK = <?php echo (int)MAX_TRACK_BYTES; ?>;
  var MAX_SOURCE = <?php echo (int)MAX_SOURCE_BYTES; ?>;
  var CHUNK = <?php echo (int)CHUNK_BYTES; ?>;
  var CONC = 3;

  function uploadAll(files){
    queue.classList.add('on');
    var list = Array.prototype.slice.call(files).filter(function(f){
      if (!/\.(mp3|m4a|aac|ogg|oga|wav|flac|opus|weba|mp4|m4v|mov)$/i.test(f.name)) {
        toast('跳过不支持的格式：' + f.name, true);
        return false;
      }
      var isVid = /\.(mp4|m4v|mov)$/i.test(f.name);
      var cap = isVid ? MAX_SOURCE : MAX_TRACK;
      if (f.size > cap) {
        toast('「' + f.name + '」超出 ' + bytesText(cap) + ' 上限', true);
        return false;
      }
      return true;
    });
    if (!list.length) return;
    var i = 0;
    (function next(){
      if (i >= list.length) { toast('全部处理完成'); return; }
      uploadOne(list[i++], next);
    })();
  }

  /** 文件名+大小+修改时间 → 稳定的 24 位十六进制，刷新后还能找回已传分片 */
  function uidFor(f){
    var s = f.name + '|' + f.size + '|' + (f.lastModified || 0);
    function hex(seed){
      var h = seed >>> 0;
      for (var i = 0; i < s.length; i++) {
        h ^= s.charCodeAt(i);
        h = Math.imul(h, 16777619) >>> 0;
      }
      return ('00000000' + h.toString(16)).slice(-8);
    }
    return hex(2166136261) + hex(7919) + hex(104729);
  }

  function uploadOne(file, done){
    var row = document.createElement('div');
    row.className = 'q-row';
    row.innerHTML = '<span class="q-name"></span><span class="q-bar"><i></i></span><span class="q-st">0%</span>';
    row.querySelector('.q-name').textContent = file.name;
    queue.appendChild(row);
    var bar = row.querySelector('.q-bar i');
    var st = row.querySelector('.q-st');
    var nameEl = row.querySelector('.q-name');

    function setP(p, text){
      bar.style.width = Math.max(0, Math.min(100, p)) + '%';
      if (text != null) st.textContent = text;
    }
    function fail(msg){
      st.textContent = '失败';
      st.className = 'q-st bad';
      nameEl.textContent = file.name + ' — ' + msg;
      done();
    }
    function ok(j){
      setP(100, '完成');
      st.className = 'q-st ok';
      insertRow(j.track);
      if (!(j.track.dur > 0)) probeDur(j.track);
      bumpCounts(1, j.track.size);
      var suffix = j.track.src ? ' → 已提取音轨' : '';
      var q = j.track.codec ? ' · ' + j.track.codec : '';
      nameEl.textContent = file.name + q + suffix;
      nameEl.title = file.name;
      done();
    }

    if (file.size <= MAX_DIRECT) {
      var fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('file', file, file.name);
      var xhr = new XMLHttpRequest();
      xhr.open('POST', '?act=upload');
      xhr.setRequestHeader('X-CSRF', CSRF);
      xhr.upload.onprogress = function(e){
        if (!e.lengthComputable) return;
        var p = Math.round(e.loaded / e.total * 100);
        setP(p, p + '%');
      };
      xhr.onload = function(){
        var j = null;
        try { j = JSON.parse(xhr.responseText); } catch (err) {}
        if (j && j.ok) { setP(100, '提取中…'); ok(j); }
        else if (j && j.msg && /直传上限/.test(j.msg)) { chunked(); }
        else if (j && j.relogin) { fail(j.msg); setTimeout(function(){ location.reload(); }, 1200); }
        else fail((j && j.msg) || ('HTTP ' + xhr.status));
      };
      xhr.onerror = function(){ fail('网络中断'); };
      xhr.send(fd);
    } else {
      chunked();
    }

    /* ---------------- 分片 ---------------- */
    function chunked(){
      var total = Math.ceil(file.size / CHUNK);
      var uid = uidFor(file);
      setP(0, '准备…');
      post('cstatus', { uid: uid, total: total }).then(function(j){
        var have = (j && j.ok && j.have) ? j.have : [];
        var base = 0;
        have.forEach(function(i){
          base += Math.min(CHUNK, file.size - i * CHUNK);
        });
        if (base > 0) setP(base / file.size * 100, '续传 ' + Math.round(base / file.size * 100) + '%');
        var pending = [];
        for (var k = 0; k < total; k++) if (have.indexOf(k) < 0) pending.push(k);
        if (!pending.length) return doMerge();
        var ptr = 0, running = 0, sent = 0, dead = false;
        function pump(){
          if (dead) return;
          while (running < CONC && ptr < pending.length) {
            sendChunk(pending[ptr++], function(good){
              running--;
              if (dead) return;
              if (!good) { dead = true; fail('分片传输失败，重试一下'); return; }
              if (ptr >= pending.length && running === 0) doMerge();
              else pump();
            });
            running++;
          }
        }
        function sendChunk(idx, cb){
          var start = idx * CHUNK;
          var blob = file.slice(start, Math.min(file.size, start + CHUNK));
          var tries = 0;
          (function attempt(){
            var fdx = new FormData();
            fdx.append('csrf', CSRF);
            fdx.append('uid', uid);
            fdx.append('idx', String(idx));
            fdx.append('total', String(total));
            fdx.append('chunk', blob, 'c');
            var x = new XMLHttpRequest();
            x.open('POST', '?act=chunk');
            x.setRequestHeader('X-CSRF', CSRF);
            x.onload = function(){
              var jj = null;
              try { jj = JSON.parse(x.responseText); } catch (e) {}
              if (jj && jj.ok) {
                sent += blob.size;
                setP((base + sent) / file.size * 100, Math.round((base + sent) / file.size * 100) + '%');
                cb(true);
              } else if (tries++ < 3) {
                setTimeout(attempt, 400 * tries);
              } else cb(false);
            };
            x.onerror = function(){
              if (tries++ < 3) setTimeout(attempt, 400 * tries); else cb(false);
            };
            x.send(fdx);
          })();
        }
        function doMerge(){
          setP(100, '提取音轨…');
          post('merge', { uid: uid, total: total, size: file.size, name: file.name }).then(function(j){
            if (j && j.ok) ok(j);
            else if (j && j.relogin) { fail(j.msg); setTimeout(function(){ location.reload(); }, 1200); }
            else fail((j && j.msg) || '合并失败');
          });
        }
        pump();
      }).catch(function(){ fail('连接失败'); });
    }
  }

  function bumpCounts(delta, bytes){
    var n = $('cntTracks'), s = $('cntSize');
    if (n) n.textContent = (parseInt(n.textContent, 10) || 0) + delta;
    if (s) s.textContent = bytesText((parseBytes(s.textContent) || 0) + (bytes || 0));
    var lc = $('listCnt');
    if (lc) lc.textContent = ((parseInt(lc.textContent, 10) || 0) + delta) + ' 首';
  }
  function parseBytes(t){
    var m = /([\d.]+)\s*(B|KB|MB|GB)/i.exec(t || '');
    if (!m) return 0;
    var v = parseFloat(m[1]), u = m[2].toUpperCase();
    return u === 'GB' ? v * 1073741824 : u === 'MB' ? v * 1048576 : u === 'KB' ? v * 1024 : v;
  }
  function bytesText(b){
    if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return Math.round(b) + ' B';
  }

  function artGrad(id){
    var h = 196 + (Math.abs(hash(id)) % 96);
    return 'linear-gradient(135deg,hsl(' + h + ',76%,63%),hsl(' + (h + 32) + ',66%,45%))';
  }

  function insertRow(t){
    var rows = $('rows');
    var empty = rows.querySelector('.empty');
    if (empty) empty.remove();
    var li = document.createElement('li');
    li.className = 'row';
    li.setAttribute('data-id', t.id);
    li.setAttribute('data-title', t.title || '');
    li.setAttribute('data-artist', '');
    li.setAttribute('data-note', '');
    li.setAttribute('data-tags', '');
    li.setAttribute('data-cover', t.cover || '');
    li.setAttribute('data-url', <?php echo json_encode(base_path()); ?> + '/?get=' + encodeURIComponent(t.id));
    var grad = artGrad(t.id);
    var q = qualityLabel(t);
    var extra = '';
    if (q) extra += '<span class="dot"></span><span class="row-q"></span>';
    if (t.src) extra += '<span class="dot"></span><span class="row-from" title=""></span>';
    li.innerHTML =
      '<span class="row-n"></span>' +
      '<span class="row-art' + (t.cover ? ' has-cover' : '') + '" title="点这里换封面" style="' + (t.cover ? 'background-image:url(' + t.cover + ')' : 'background:' + grad) + '"></span>' +
      '<div class="row-main"><p class="row-title"></p><div class="row-meta">' +
        '<span class="js-dur">' + (t.dur > 0 ? fmtTime(t.dur) : '--:--') + '</span>' +
        '<span class="dot"></span><span>' + bytesText(t.size) + '</span>' + extra + '</div></div>' +
      '<div class="row-act">' + playIcon() + moveIcons() + editIcon() + delIcon() + '</div>';
    if (q) li.querySelector('.row-q').textContent = q;
    if (t.src) {
      var from = li.querySelector('.row-from');
      from.textContent = '视频提取';
      from.title = t.src;
    }
    if (!t.cover) li.querySelector('.row-art').textContent = (t.title || '?').slice(0, 1);
    li.querySelector('.row-title').textContent = t.title || '未命名';
    rows.appendChild(li);
    renumber();
  }

  function qualityLabel(t){
    var p = [];
    var sr = t.sr | 0, ch = t.ch | 0, kbps = t.kbps | 0;
    if (t.codec) p.push(t.codec);
    if (sr > 0) {
      var k = sr / 1000;
      p.push((k % 1 === 0 ? k : parseFloat(k.toFixed(2))) + ' kHz');
    }
    if (ch === 1) p.push('单声道');
    else if (ch >= 2) p.push('立体声');
    if (kbps > 0) p.push(kbps + 'k');
    return p.join(' · ');
  }

  function fmtTime(s){
    s = Math.round(s || 0);
    return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  }
  function hash(s){ var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0; return h; }
  function playIcon(){ return '<button class="ico-btn js-play" type="button" title="试听" aria-label="试听"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5z"/></svg></button>'; }
  function moveIcons(){
    return '<button class="ico-btn js-up" type="button" title="上移" aria-label="上移"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>' +
           '<button class="ico-btn js-down" type="button" title="下移" aria-label="下移"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12l7 7 7-7"/></svg></button>';
  }
  function editIcon(){ return '<button class="ico-btn js-edit" type="button" title="编辑信息" aria-label="编辑信息"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9a2.1 2.1 0 0 0-3-3L5 17z"/><path d="M14.5 6.5 17.5 9.5"/></svg></button>'; }
  function delIcon(){ return '<button class="ico-btn del js-del" type="button" title="删除" aria-label="删除"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4.8A.8.8 0 0 1 9.8 4h4.4a.8.8 0 0 1 .8.8V7"/><path d="M6 7l.9 12.2a1 1 0 0 0 1 .8h8.2a1 1 0 0 0 1-.8L18 7"/></svg></button>'; }

  function renumber(){
    document.querySelectorAll('#rows .row').forEach(function(r, i){
      r.querySelector('.row-n').textContent = i + 1;
      r.querySelector('.js-up').disabled = i === 0;
      r.querySelector('.js-down').disabled = i === document.querySelectorAll('#rows .row').length - 1;
    });
  }
  renumber();

  /* ---------- 时长探测 ---------- */
  var probeQueue = [];
  var probing = false;
  function probeDur(t){
    probeQueue.push(t);
    if (!probing) runProbe();
  }
  function runProbe(){
    var t = probeQueue.shift();
    if (!t) { probing = false; return; }
    probing = true;
    var a = new Audio();
    a.preload = 'metadata';
    var done = false;
    var fin = function(){
      if (done) return;
      done = true;
      a.src = '';
      setTimeout(runProbe, 120);
    };
    a.addEventListener('loadedmetadata', function(){
      var d = Math.round(a.duration * 10) / 10;
      if (isFinite(d) && d > 0) {
        var row = document.querySelector('#rows .row[data-id="' + t.id + '"]');
        if (row) row.querySelector('.js-dur').textContent = fmt(d);
        post('dur', { id: t.id, dur: d });
      }
      fin();
    });
    a.addEventListener('error', fin);
    setTimeout(fin, 8000);
    a.src = <?php echo json_encode(base_path()); ?> + '/?get=' + encodeURIComponent(t.id);
  }
  function fmt(s){ s = Math.round(s); return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2); }

  /* ---------- 编辑 ---------- */
  var dlg = $('editDlg'), curId = null, curRow = null;
  var coverBusy = false;

  function paintCover(url, letter){
    var p = $('fCoverPrev');
    if (!p) return;
    if (url) {
      p.style.background = '';
      p.style.backgroundImage = 'url("' + url + '")';
      p.textContent = '';
    } else {
      p.style.backgroundImage = '';
      p.style.background = artGrad(curId || 'cover');
      p.textContent = (letter || '?').slice(0, 1);
    }
  }

  /* 浏览器端压图：长边 900px，优先 WebP，不支持就退 JPEG */
  function shrinkImage(file){
    return new Promise(function(resolve, reject){
      if (!file || !/^image\//.test(file.type)) { reject(new Error('不是图片')); return; }
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function(){
        try {
          var max = 900;
          var w = img.naturalWidth || img.width;
          var h = img.naturalHeight || img.height;
          var sc = Math.min(1, max / Math.max(w, h));
          w = Math.max(1, Math.round(w * sc));
          h = Math.max(1, Math.round(h * sc));
          var cv = document.createElement('canvas');
          cv.width = w; cv.height = h;
          var c2 = cv.getContext('2d');
          c2.fillStyle = '#0b1018';
          c2.fillRect(0, 0, w, h);
          c2.drawImage(img, 0, 0, w, h);
          var type = 'image/webp';
          var out = cv.toDataURL(type, 0.9);
          if (out.indexOf('data:image/webp') !== 0) {
            type = 'image/jpeg';
            out = cv.toDataURL(type, 0.88);
          }
          var bin = atob(out.split(',')[1]);
          var arr = new Uint8Array(bin.length);
          for (var i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
          var ext = type === 'image/webp' ? 'webp' : 'jpg';
          resolve(new File([arr], 'cover.' + ext, { type: type }));
        } catch (err) {
          reject(err);
        } finally {
          URL.revokeObjectURL(url);
        }
      };
      img.onerror = function(){ URL.revokeObjectURL(url); reject(new Error('图片读不出来')); };
      img.src = url;
    });
  }

  function uploadCover(file){
    if (coverBusy || !curId) return;
    coverBusy = true;
    var hint = $('fCoverHint');
    var pick = $('fCoverPick');
    hint.textContent = '正在压缩…';
    pick.disabled = true;
    shrinkImage(file).then(function(f){
      hint.textContent = '上传中…';
      var fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('id', curId);
      fd.append('cover', f, f.name);
      return fetch('?act=cover', {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF': CSRF }
      }).then(function(r){ return r.json(); });
    }).then(function(j){
      if (!j || !j.ok) throw new Error((j && j.msg) || '上传失败');
      paintCover(j.cover, '');
      if (curRow) {
        curRow.setAttribute('data-cover', j.cover);
        var art = curRow.querySelector('.row-art');
        if (art) {
          art.classList.add('has-cover');
          art.style.background = '';
          art.style.backgroundImage = 'url("' + j.cover + '")';
          art.textContent = '';
        }
      }
      hint.textContent = '已更新。公开歌单和播放器的封面也一起换了。';
      toast('封面已更新');
    }).catch(function(err){
      hint.textContent = (err && err.message) || '上传失败，换一张试试';
      toast((err && err.message) || '封面上传失败', true);
    }).then(function(){
      coverBusy = false;
      pick.disabled = false;
      $('fCoverInput').value = '';
    });
  }

  $('fCoverPick').addEventListener('click', function(){ $('fCoverInput').click(); });
  $('fCoverInput').addEventListener('change', function(){
    var f = this.files && this.files[0];
    if (f) uploadCover(f);
  });
  $('fCoverDel').addEventListener('click', function(){
    if (!curId || coverBusy) return;
    var has = curRow && curRow.getAttribute('data-cover');
    if (!has) { toast('这首还没有封面'); return; }
    if (!confirm('移除这首的封面？会回到自动渐变色块。')) return;
    coverBusy = true;
    post('coverdel', { id: curId }).then(function(j){
      if (!j.ok) { toast(j.msg || '移除失败', true); return; }
      paintCover('', $('fTitle').value.trim().charAt(0) || '?');
      if (curRow) {
        curRow.setAttribute('data-cover', '');
        var art = curRow.querySelector('.row-art');
        if (art) {
          art.classList.remove('has-cover');
          art.style.backgroundImage = '';
          art.style.background = artGrad(curId);
          art.textContent = ($('fTitle').value.trim() || '?').slice(0, 1);
        }
      }
      $('fCoverHint').textContent = '已移除，公开页会回到渐变色块。';
      toast('封面已移除');
    }).catch(function(){ toast('网络异常', true); }).then(function(){ coverBusy = false; });
  });

  document.addEventListener('click', function(e){
    var artHit = e.target.closest ? e.target.closest('.row-art') : null;
    if (artHit) {
      var er = artHit.closest('.row');
      var eb = er && er.querySelector('.js-edit');
      if (eb) { eb.click(); return; }
    }
    var ed = e.target.closest ? e.target.closest('.js-edit') : null;
    if (ed) {
      var row = ed.closest('.row');
      curRow = row; curId = row.getAttribute('data-id');
      $('fTitle').value = row.getAttribute('data-title') || '';
      $('fArtist').value = row.getAttribute('data-artist') || '';
      $('fTags').value = row.getAttribute('data-tags') || '';
      $('fNote').value = row.getAttribute('data-note') || '';
      coverBusy = false;
      paintCover(row.getAttribute('data-cover') || '', row.getAttribute('data-title') || '?');
      $('fCoverHint').textContent = '正方形最好。选图后浏览器先压到 900px 内再传，原图不会被上传。';
      updateCnt();
      dlg.showModal();
      setTimeout(function(){ $('fTitle').focus(); }, 60);
      return;
    }
    var up = e.target.closest ? e.target.closest('.js-up') : null;
    if (up) { move(up.closest('.row'), 'up'); return; }
    var dn = e.target.closest ? e.target.closest('.js-down') : null;
    if (dn) { move(dn.closest('.row'), 'down'); return; }
    var dl = e.target.closest ? e.target.closest('.js-del') : null;
    if (dl) { del(dl.closest('.row')); return; }
  });

  function move(row, dir){
    var id = row.getAttribute('data-id');
    post('move', { id: id, dir: dir }).then(function(j){
      if (!j.ok) { toast(j.msg || '不能移动', true); return; }
      var rows = $('rows');
      row.parentNode.removeChild(row);
      var els = Array.prototype.slice.call(rows.querySelectorAll('.row'));
      if (dir === 'up') {
        // 放到前一个之前
        var prev = els[Array.prototype.indexOf(els, els[els.length - 1])];
        rows.insertBefore(row, prev && prev !== row ? prev : null);
      }
      // 用返回的顺序重排更可靠
      if (j.order) {
        var map = {};
        rows.querySelectorAll('.row').forEach(function(r){ map[r.getAttribute('data-id')] = r; });
        // 先把 row 补回 DOM
        rows.appendChild(row);
        j.order.forEach(function(oid){
          if (map[oid]) rows.appendChild(map[oid]);
          else if (oid === id) rows.appendChild(row);
        });
      }
      renumber();
    });
  }

  function del(row){
    var title = row.getAttribute('data-title') || '这首';
    if (!confirm('删除「' + title + '」？音频文件会一起删掉，不能恢复。')) return;
    var id = row.getAttribute('data-id');
    post('del', { id: id }).then(function(j){
      if (!j.ok) { toast(j.msg || '删除失败', true); return; }
      row.style.opacity = '0';
      row.style.transform = 'translateX(8px)';
      setTimeout(function(){
        row.remove();
        renumber();
        var n = document.querySelectorAll('#rows .row').length;
        $('listCnt').textContent = n + ' 首';
        $('cntTracks').textContent = n;
        if (!n) location.reload();
      }, 180);
      toast('已删除');
    });
  }

  function updateCnt(){
    var v = $('fNote').value.length;
    var el = $('noteCnt');
    el.textContent = v + ' / 400';
    el.className = v > 380 ? 'counter out' : '';
  }
  $('fNote').addEventListener('input', updateCnt);

  $('dlgClose').addEventListener('click', function(){ dlg.close(); });
  $('dlgCancel').addEventListener('click', function(){ dlg.close(); });
  $('dlgSave').addEventListener('click', function(){
    if (!curId) return;
    var payload = {
      id: curId,
      title: $('fTitle').value.trim(),
      artist: $('fArtist').value.trim(),
      tags: $('fTags').value.trim(),
      note: $('fNote').value.trim()
    };
    post('save', payload).then(function(j){
      if (!j.ok) { toast(j.msg || '保存失败', true); return; }
      dlg.close();
      if (curRow) {
        curRow.setAttribute('data-title', payload.title);
        curRow.setAttribute('data-artist', payload.artist);
        curRow.setAttribute('data-tags', payload.tags);
        curRow.setAttribute('data-note', payload.note);
        curRow.querySelector('.row-title').textContent = payload.title || '未命名';
        curRow.querySelector('.row-art').textContent = (payload.title || '?').slice(0, 1);
        var meta = curRow.querySelector('.row-meta');
        var artistEl = meta.querySelector('.js-artist');
        if (payload.artist) {
          if (!artistEl) {
            var s = document.createElement('span');
            s.className = 'js-artist';
            s.textContent = payload.artist;
            meta.insertBefore(s, meta.firstChild);
            var d = document.createElement('span');
            d.className = 'dot';
            meta.insertBefore(d, meta.children[1]);
          } else { artistEl.textContent = payload.artist; }
        } else if (artistEl) {
          var nxt = artistEl.nextElementSibling;
          artistEl.remove();
          if (nxt && nxt.classList.contains('dot')) nxt.remove();
        }
        var noteEl = curRow.querySelector('.row-note');
        if (payload.note) {
          if (!noteEl) {
            noteEl = document.createElement('p');
            noteEl.className = 'row-note';
            curRow.querySelector('.row-main').appendChild(noteEl);
          }
          noteEl.textContent = payload.note;
        } else if (noteEl) { noteEl.remove(); }
      }
      toast('已保存');
    });
  });
  /* ---------- 歌单与搜索 ---------- */
  (function(){
    var card = $('setCard');
    if (!card) return;
    var SITE = <?php echo json_encode($site['title']); ?>;
    var PATH = <?php echo json_encode(base_path() . '/'); ?>;

    function introCnt(){
      var v = $('sIntro').value.length;
      $('introCnt').textContent = v + ' / 300';
      $('introCnt').className = v > 285 ? 'counter out' : '';
    }

    function serpPreview(){
      var title = $('sTitle').value.trim() || '我喜欢的歌';
      var seoTitle = $('sSeoTitle').value.trim();
      var seoDesc = $('sSeoDesc').value.trim();
      var seoKw = $('sKeywords').value.trim();
      if (!seoTitle) seoTitle = title + ' · 歌单 · ' + SITE;
      if (!seoDesc) {
        var rows = document.querySelectorAll('#rows .row');
        var names = Array.prototype.slice.call(document.querySelectorAll('#rows .row-title'), 0, 4)
          .map(function(el){ return '《' + el.textContent.trim() + '》'; });
        seoDesc = '《' + title + '》是' + SITE + '的私人歌单，共 ' + rows.length + ' 首' +
          (names.length ? '：' + names.join('、') : '') + '。每首都有手写备注，按上传顺序排列。';
      }
      $('serpT').textContent = seoTitle;
      $('serpU').textContent = location.origin + PATH;
      $('serpD').textContent = seoDesc.length > 150 ? seoDesc.slice(0, 148) + '…' : seoDesc;
    }

    $('setToggle').addEventListener('click', function(){
      var on = card.classList.toggle('open');
      this.setAttribute('aria-expanded', on ? 'true' : 'false');
      if (on) serpPreview();
    });
    ['sTitle', 'sSeoTitle', 'sSeoDesc', 'sKeywords'].forEach(function(id){
      $(id).addEventListener('input', serpPreview);
    });
    $('sIntro').addEventListener('input', function(){ introCnt(); serpPreview(); });

    $('setSave').addEventListener('click', function(){
      var btn = this;
      btn.disabled = true;
      post('settings', {
        title: $('sTitle').value.trim(),
        intro: $('sIntro').value.trim(),
        seo_title: $('sSeoTitle').value.trim(),
        seo_desc: $('sSeoDesc').value.trim(),
        seo_keywords: $('sKeywords').value.trim()
      }).then(function(j){
        if (!j.ok) { toast(j.msg || '保存失败', true); return; }
        $('setState').textContent = '已保存';
        toast('设置已保存，公开页已同步');
        serpPreview();
      }).catch(function(){ toast('网络异常', true); }).then(function(){ btn.disabled = false; });
    });

    introCnt();
    serpPreview();
  })();

  dlg.addEventListener('click', function(e){
    if (e.target === dlg) dlg.close();
  });
})();
</script>
<?php endif; ?>

</body>
</html>
