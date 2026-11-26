<?php
/**
 * 音乐歌单的动态 sitemap
 * ------------------------------------------------------------------
 * 曲目一有变动，这里立刻跟着变，不用手动维护静态 XML。
 * 地址：/music/sitemap.php（已写进 robots.txt）
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');

$dataFile = __DIR__ . '/data.json';
$tracks = [];
if (is_file($dataFile)) {
    $raw = @file_get_contents($dataFile);
    $d = json_decode((string)$raw, true);
    if (is_array($d) && isset($d['tracks']) && is_array($d['tracks'])) {
        $tracks = $d['tracks'];
    }
}

$https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$origin = ($https ? 'https' : 'http') . '://' . $host;
$base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/music/sitemap.php'))), '/');
$pageUpdated = is_file($dataFile) ? (int)@filemtime($dataFile) : time();

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=1800');
header('X-Content-Type-Options: nosniff');

$e = static function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
};

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo "  <url>\n";
echo '    <loc>' . $e($origin . $base . '/') . "</loc>\n";
echo '    <lastmod>' . date('Y-m-d', $pageUpdated) . "</lastmod>\n";
echo "    <changefreq>weekly</changefreq>\n";
echo "    <priority>0.8</priority>\n";
echo "  </url>\n";

foreach ($tracks as $t) {
    $id = (string)($t['id'] ?? '');
    if ($id === '') continue;
    $added = (int)($t['added'] ?? 0);
    echo "  <url>\n";
    echo '    <loc>' . $e($origin . $base . '/?song=' . rawurlencode($id)) . "</loc>\n";
    if ($added > 0) echo '    <lastmod>' . date('Y-m-d', $added) . "</lastmod>\n";
    echo "    <changefreq>monthly</changefreq>\n";
    echo "    <priority>0.6</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
