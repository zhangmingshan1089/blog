<?php
/**
 * wbclean 主题函数（v2 全屏背景版）
 * 职责：主题设置（背景图/头像/大标题）、图片上传压缩、评论 IP 脱敏。
 * 断网可用：不引用任何 CDN / 远程字体 / 远程 JS。
 */

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/* =========================================================
   v18 · 页面缓存（匿名访客 GET）
   ---------------------------------------------------------
   美国机房到国内一个来回就要 0.4~0.5s，PHP 再快也得等这一趟。
   这里把渲染好的整页落成静态 HTML：
     命中 → 读文件直接吐，跳过 Typecho 引导、模板、SQLite
     未命中 → 正常渲染，footer 结尾落盘
   写操作（评论 / 发文 / 登录）清空；登录用户永不命中。
   ========================================================= */

function wb_cache_dir(): string
{
    return __TYPECHO_ROOT_DIR__ . '/usr/cache/wbpage';
}

/** 只缓存干净的路径；分页放行，其余查询串一律不缓存（搜索、预览等） */
function wb_cache_key(): string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $q    = (string)($_SERVER['QUERY_STRING'] ?? '');
    if ($q !== '' && !preg_match('/^page=\d+$/', $q)) return '';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    return md5($host . '|' . $path . '|' . $q);
}

function wb_cache_skip(): bool
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    if (preg_match('#^/(admin|action|install|publish|drive|moments|timeline|music|danmaku|usr|api|feed)#i', $path)) return true;
    foreach ($_COOKIE as $k => $v) {
        $k = (string)$k;
        if (substr($k, -13) === '__typecho_uid') return true;       // 站主登录态
        if (strpos($k, '__typecho_remember') !== false) return true; // 记住的评论者
    }
    return false;
}

function wb_cache_purge(): void
{
    $dir = wb_cache_dir();
    if (!is_dir($dir)) return;
    foreach ((array)@glob($dir . '/*.html') as $f) @unlink($f);
}

function wb_cache_write(string $html): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (wb_cache_skip()) return;   // 登录态 / 后台页面绝不落盘
    if (strlen($html) < 1500) return;
    if (function_exists('http_response_code') && http_response_code() && http_response_code() !== 200) return;
    $key = wb_cache_key();
    if ($key === '') return;
    $dir = wb_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return;
    $tmp = $dir . '/' . $key . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $html, LOCK_EX) !== false) {
        @rename($tmp, $dir . '/' . $key . '.html');
        // 顺手存一份 gzip：命中时直接发压缩体，nginx 不用再压
        if (function_exists('gzencode')) {
            $gz = gzencode($html, 6);
            if ($gz !== false) @file_put_contents($dir . '/' . $key . '.html.gz', $gz, LOCK_EX);
        }
    } else {
        @unlink($tmp);
    }
}

/** 命中就输出并结束；未命中返回，让 Typecho 正常渲染 */
function wb_cache_boot(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET' && $method !== 'HEAD') { wb_cache_purge(); return; }
    if (wb_cache_skip()) return;

    $key = wb_cache_key();
    if ($key === '') return;

    $dir  = wb_cache_dir();
    $file = $dir . '/' . $key . '.html';
    $gz   = $dir . '/' . $key . '.html.gz';
    if (!is_file($file)) return;

    $mtime = (int)@filemtime($file);
    if (time() - $mtime > 300) { @unlink($file); @unlink($gz); return; }

    $etag = '"' . substr(md5($key . '|' . $mtime), 0, 20) . '"';

    // 清掉 Typecho 已开的输出缓冲，避免它的 callback 覆盖我们的头
    while (ob_get_level() > 0) { @ob_end_clean(); }

    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''));
    header('ETag: ' . $etag);
    header('X-WB-Cache: HIT');
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: public, max-age=120, stale-while-revalidate=600');
    header('Vary: Accept-Encoding');

    if (strpos($accept, 'gzip') !== false && is_file($gz) && ($size = @filesize($gz)) !== false) {
        header('Content-Encoding: gzip');
        header('Content-Length: ' . $size);
        @readfile($gz);
        exit;
    }
    @readfile($file);
    exit;
}

/** footer 末尾调用：交出整页内容并结束缓冲 */
function wb_cache_end(): void
{
    if (ob_get_level() < 1) return;
    $html = (string)ob_get_contents();
    ob_end_flush();          // 内容照常交给上层（Typecho / nginx）
    if (($GLOBALS['__wb_cache_ok'] ?? true) === true) wb_cache_write($html);
}

wb_cache_boot();

/** 主题启用时的可配置项（后台 外观 → 设置主题 里即可修改） */
function themeConfig($form)
{
    $bg = new \Typecho\Widget\Helper\Form\Element\Text(
        'bgUrl', null, '',
        _t('首页背景图地址'),
        _t('留空则使用主题内置背景。可填本站图片地址（如 /usr/uploads/2026/11/xx.jpg）或任意图片直链。在后台写文章时上传图片后复制地址到这里即可更换。')
    );
    $form->addInput($bg);

    $avatar = new \Typecho\Widget\Helper\Form\Element\Text(
        'avatarUrl', null, '',
        _t('头像地址'),
        _t('留空使用默认圆形头像。可填图片直链。')
    );
    $form->addInput($avatar);

    $heroTitle = new \Typecho\Widget\Helper\Form\Element\Text(
        'heroTitle', null, '',
        _t('首页大标题'),
        _t('留空则显示站点标题。')
    );
    $form->addInput($heroTitle);

    $heroSub = new \Typecho\Widget\Helper\Form\Element\Text(
        'heroSub', null, '',
        _t('首页副标题'),
        _t('一句话介绍，留空则不显示。')
    );
    $form->addInput($heroSub);

    $notice = new \Typecho\Widget\Helper\Form\Element\Text(
        'siteNotice', null, '',
        _t('站点公告'),
        _t('显示在内容区顶部的一句话，留空则不显示')
    );
    $form->addInput($notice);

    $icp = new \Typecho\Widget\Helper\Form\Element\Text(
        'icp', null, '',
        _t('备案号'),
        _t('显示在页脚，留空不显示')
    );
    $form->addInput($icp);

    $compress = new \Typecho\Widget\Helper\Form\Element\Select(
        'imgCompress', ['1' => _t('开启'), '0' => _t('关闭')], '1',
        _t('图片上传压缩'),
        _t('超过阈值的 JPEG/PNG 压到 1600px 内（需 GD，缺失自动跳过）')
    );
    $form->addInput($compress);

    $threshold = new \Typecho\Widget\Helper\Form\Element\Text(
        'imgThreshold', null, '300',
        _t('压缩阈值(KB)'),
        _t('超过该体积才触发压缩，默认 300KB')
    );
    $form->addInput($threshold);
}

/** 解析首页背景图地址：优先主题设置，否则内置 */
function wbclean_bg(): string
{
    $opts = \Widget\Options::alloc();
    $url = trim((string)($opts->bgUrl ?? ''));
    if ($url !== '') {
        // 相对路径补全站点地址；绝对地址原样使用
        if ($url[0] === '/') {
            return rtrim((string)$opts->siteUrl, '/') . $url;
        }
        return $url;
    }
    return $opts->themeUrl('assets/hero-bg.jpg', 'wbclean');
}

/**
 * 首页背景图数据：内置图给出 WebP 多尺寸 srcset，自定义 URL 则原样返回单张。
 * 内置图同时保留 JPEG，给不支持 WebP 的老浏览器兜底（<picture> 的 <img> 回退）。
 */
function wbclean_bg_data(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $opts = \Widget\Options::alloc();
    $url  = trim((string)($opts->bgUrl ?? ''));

    if ($url !== '') {
        if ($url[0] === '/') $url = rtrim((string)$opts->siteUrl, '/') . $url;
        return $cache = ['custom' => true, 'src' => $url, 'webp' => '', 'srcset' => ''];
    }

    $t = (string)$opts->themeUrl('assets/', 'wbclean');
    return $cache = [
        'custom' => false,
        'src'    => $t . 'hero-bg.jpg',
        'webp'   => $t . 'hero-bg-1024.webp',
        'srcset' => $t . 'hero-bg-768.webp 768w, '
                  . $t . 'hero-bg-1024.webp 1024w',
    ];
}

/** 分享预览图（1200×630）：固定用内置封面裁切图，微信/Telegram/Twitter 都能正常显示 */
function wbclean_og_image(): string
{
    return (string)\Widget\Options::alloc()->themeUrl('assets/og-cover.jpg', 'wbclean');
}

/** 主题 resources 内的资源地址（返回字符串，别直接 echo） */
function wbclean_asset(string $path): string
{
    return (string)\Widget\Options::alloc()->themeUrl('assets/' . ltrim($path, '/'), 'wbclean');
}

/** 头像地址：优先主题设置，否则用内置头像 */
function wbclean_avatar(): string
{
    $opts = \Widget\Options::alloc();
    $url = trim((string)($opts->avatarUrl ?? ''));
    if ($url !== '') {
        if ($url[0] === '/') return rtrim((string)$opts->siteUrl, '/') . $url;
        return $url;
    }
    return $opts->themeUrl('assets/avatar.jpg', 'wbclean');
}

/** IP 脱敏：IPv4 保留首尾段，IPv6 保留前两段 */
function wbclean_mask_ip(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') return '';
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $p = explode('.', $ip);
        return $p[0] . '.**.*.' . $p[3];
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $p = explode(':', $ip);
        return $p[0] . ':' . $p[1] . ':****';
    }
    return '***';
}

/** 图片上传后压缩：挂 beforeUpload，不替换默认上传逻辑 */
function wbclean_compress_uploaded_image(array $result): array
{
    $options = \Widget\Options::alloc();
    $enabled = isset($options->imgCompress) ? ((string)$options->imgCompress === '1') : true;
    $thresholdKb = (isset($options->imgThreshold) && is_numeric($options->imgThreshold))
        ? max(50, (int)$options->imgThreshold) : 300;
    if (!$enabled || !function_exists('imagejpeg') || empty($result['path'])) {
        return $result;
    }
    if ((int)($result['size'] ?? 0) < $thresholdKb * 1024) {
        return $result;
    }

    $path = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/' . ltrim((string)$result['path'], '/');
    if (!is_file($path)) {
        return $result;
    }

    $info = @getimagesize($path);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return $result;
    }
    $max = 1600;
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w <= $max && $h <= $max) {
        return $result;
    }

    $ratio = min($max / $w, $max / $h);
    $nw = max(1, (int)($w * $ratio));
    $nh = max(1, (int)($h * $ratio));

    try {
        if ($info[2] === IMAGETYPE_JPEG) {
            $src = @imagecreatefromjpeg($path);
        } elseif ($info[2] === IMAGETYPE_PNG) {
            $src = @imagecreatefrompng($path);
        } else {
            return $result;
        }
        if (!$src) return $result;

        $dst = imagecreatetruecolor($nw, $nh);
        if ($info[2] === IMAGETYPE_PNG) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        if ($info[2] === IMAGETYPE_JPEG) {
            @imagejpeg($dst, $path, 82);
        } else {
            @imagepng($dst, $path, 6);
        }
        imagedestroy($src);
        imagedestroy($dst);

        clearstatcache(true, $path);
        $result['size'] = filesize($path);
    } catch (\Throwable $e) {
    }
    return $result;
}

\Typecho\Plugin::factory(\Widget\Upload::class)->beforeUpload = 'wbclean_compress_uploaded_image';

/** 摘要截取（UTF-8 安全） */
function wbclean_excerpt($text, $len = 120)
{
    $text = trim(strip_tags($text));
    $text = preg_replace('/\s+/u', ' ', $text);
    if (mb_strlen($text, 'UTF-8') <= $len) return $text;
    return mb_substr($text, 0, $len, 'UTF-8') . '…';
}

/**
 * 站名拆成 wordmark + 尾巴。
 * 「MC.跃小叮当的博客」→「跃小叮当」+「· 的博客」
 * 移动端只显示 wordmark，桌面上尾巴是小字。
 */
function wbclean_brand(string $name): array
{
    $s = trim($name);
    $s = preg_replace('/^MC[.。·・\s]*/iu', '', $s);
    $tail = '';
    if (preg_match('/^(.+?)的博客$/u', $s, $m)) {
        $s    = trim($m[1]);
        $tail = '· 的博客';
    } elseif (preg_match('/^(.+?)\s*[·|｜]\s*(.+)$/u', $s, $m)) {
        $s    = trim($m[1]);
        $tail = '· ' . trim($m[2]);
    }
    if ($s === '') $s = trim($name);
    return ['main' => $s, 'tail' => $tail];
}

/** 阅读时长：中文按 400 字/分钟，最少 1 分钟 */
function wbclean_reading_time(string $text): int
{
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    $len   = mb_strlen($plain, 'UTF-8');
    return max(1, (int)ceil($len / 400));
}

/** 文章总数（带缓存，模板里多次调用只查一次） */
function wbclean_post_count(): int
{
    $s = wbclean_stats();
    return (int)$s['posts'];
}

/**
 * 取正文里的第一张图当封面（蝴蝶风格用）。
 * 兼容已渲染的 <img src> 和还没解析的 markdown ![]()；取不到返回空串。
 */
function wbclean_first_image(string $text): string
{
    if ($text === '') return '';

    if (preg_match('/<img[^>]+src\s*=\s*["\']([^"\']+)["\']/i', $text, $m)) {
        $src = trim($m[1]);
    } elseif (preg_match('/!\[[^\]]*\]\(\s*([^)\s]+)/', $text, $m)) {
        $src = trim($m[1]);
    } else {
        return '';
    }

    if ($src === '') return '';
    /* 排除 data:、表情包这类不适合当封面的图 */
    if (stripos($src, 'data:') === 0) return '';
    if (preg_match('#/(emoji|smilies|avatar|emotion)/#i', $src)) return '';
    return $src;
}

/**
 * 全站实时计数：文章 / 碎片 / 文件 / 分类。
 * 任何一处读不到都只影响自己的那个数字，不影响页面。
 */
function wbclean_stats(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $s = [
        'posts' => 0, 'moments' => 0, 'files' => 0,
        'cats' => 0, 'tags' => 0, 'first' => 0, 'last' => 0,
    ];

    /* ---- Typecho：文章 / 分类 / 标签 ---- */
    try {
        $db = \Typecho\Db::get();
        $row = $db->fetchRow($db->select('COUNT(*) AS num')->from('table.contents')
            ->where('type = ?', 'post')->where('status = ?', 'publish'));
        if (is_array($row)) $s['posts'] = (int)$row['num'];

        $row = $db->fetchRow($db->select('created')->from('table.contents')
            ->where('type = ?', 'post')->where('status = ?', 'publish')
            ->order('created', \Typecho\Db::SORT_ASC)->limit(1));
        if (is_array($row) && !empty($row['created'])) $s['first'] = (int)$row['created'];

        $row = $db->fetchRow($db->select('created')->from('table.contents')
            ->where('type = ?', 'post')->where('status = ?', 'publish')
            ->order('created', \Typecho\Db::SORT_DESC)->limit(1));
        if (is_array($row) && !empty($row['created'])) $s['last'] = (int)$row['created'];

        /* 只统计「真的挂着文章」的分类 / 标签 */
        $used = [];
        foreach ($db->fetchAll($db->select('mid')->from('table.relationships')) as $r) {
            $used[(int)$r['mid']] = 1;
        }
        $cats = $db->fetchAll($db->select('mid')->from('table.metas')->where('type = ?', 'category'));
        $tags = $db->fetchAll($db->select('mid')->from('table.metas')->where('type = ?', 'tag'));
        foreach ($cats as $c) if (isset($used[(int)$c['mid']])) $s['cats']++;
        foreach ($tags as $t) if (isset($used[(int)$t['mid']])) $s['tags']++;
    } catch (\Throwable $e) {
        // 静默
    }

    /* ---- 朋友圈：碎片条数 ---- */
    try {
        $p = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/moments/data.json';
        if (is_file($p)) {
            $d = json_decode((string)@file_get_contents($p), true);
            if (is_array($d)) {
                foreach ($d as $it) {
                    if (($it['kind'] ?? 'moment') !== 'post') $s['moments']++;
                }
            }
        }
    } catch (\Throwable $e) {
    }

    /* ---- 云盘：非目录条目数 ---- */
    try {
        $p = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/drive/files.json';
        if (is_file($p)) {
            $d = json_decode((string)@file_get_contents($p), true);
            if (is_array($d)) {
                foreach ($d as $it) {
                    if (empty($it['is_dir'])) $s['files']++;
                }
            }
        }
    } catch (\Throwable $e) {
    }

    return $cache = $s;
}

/** 中文数字：把 3 说成「三类」，没有内容时说「暂无」 */
function wbclean_cn_count(int $n, string $unit): string
{
    return $n > 0 ? ($n . ' ' . $unit) : '暂无';
}

/**
 * 站主身份：带 Typecho 登录 cookie 才去查库，访客不产生任何查询。
 * 用于顶栏「写一条」入口——只让站主看到。
 */
function wb_is_owner(): bool
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = false;
    try {
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
        if ($uid <= 0 || $code === '') {
            return $v = false;
        }
        $db  = \Typecho\Db::get();
        $row = $db->fetchRow($db->select('authCode')->from('table.users')->where('uid = ?', $uid));
        if (!$row || empty($row['authCode'])) {
            return $v = false;
        }
        $v = \Typecho\Common::hashValidate((string)$row['authCode'], $code);
    } catch (\Throwable $e) {
        $v = false;
    }
    return $v;
}
