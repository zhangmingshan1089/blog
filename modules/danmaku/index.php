<?php
/**
 * 弹幕 API · 单文件
 * GET  /danmaku/            → 拉取最近弹幕
 * POST /danmaku/ act=send   → 发送弹幕（游客可用，限流）
 * POST /danmaku/ act=del    → 删除弹幕（需管理员密码）
 * 数据：data.json（最多保留 400 条）
 */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Shanghai');

const D_DATA   = __DIR__ . '/data.json';
const D_KEEP   = 400;
const D_MAXLEN = 40;
const D_GAP    = 25;   // 同 IP 发送间隔（秒）
const D_PWD    = '20100809aaa';

function d_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function d_json(array $r, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function d_load(): array {
    if (!is_file(D_DATA)) return [];
    $d = json_decode((string)@file_get_contents(D_DATA), true);
    return is_array($d) ? $d : [];
}
function d_save(array $d): bool {
    $tmp = D_DATA . '.tmp';
    if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    return @rename($tmp, D_DATA);
}
function d_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '';
}
function d_ipkey(): string {
    return substr(hash('sha256', d_ip() . 'wb-danmaku-2026'), 0, 12);
}
/** 管理员校验：优先云盘 bcrypt */
function d_admin_ok(string $pwd): bool {
    foreach ([__DIR__ . '/.passwd', __DIR__ . '/../drive/.passwd'] as $f) {
        if (is_file($f)) {
            $v = trim((string)@file_get_contents($f));
            if ($v === '') continue;
            return str_starts_with($v, '$2') ? password_verify($pwd, $v) : hash_equals($v, $pwd);
        }
    }
    return hash_equals(D_PWD, $pwd);
}
/** 简单屏蔽词（避免明显垃圾/脏话） */
function d_blocked(string $s): bool {
    $bad = ['http://', 'https://', 'www.', '<script', 'javascript:'];
    foreach ($bad as $b) { if (stripos($s, $b) !== false) return true; }
    return false;
}

$act = (string)($_REQUEST['act'] ?? 'list');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- 拉取 ---------- */
if ($act === 'list') {
    $all = d_load();
    $since = (int)($_GET['since'] ?? 0);
    $out = [];
    foreach ($all as $row) {
        if ($since > 0 && (int)($row['t'] ?? 0) <= $since) continue;
        $out[] = [
            'id'    => $row['id'] ?? '',
            'text'  => $row['text'] ?? '',
            't'     => (int)($row['t'] ?? 0),
            'c'     => (int)($row['c'] ?? 0),
        ];
    }
    d_json(['ok' => true, 'list' => array_slice($out, -60), 'total' => count($all), 'server' => time()]);
}

/* ---------- 发送 ---------- */
if ($act === 'send') {
    if ($method !== 'POST') d_json(['ok' => false, 'msg' => '方法不允许'], 405);
    $text = trim((string)($_POST['text'] ?? ''));
    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';
    $text = mb_substr($text, 0, D_MAXLEN, 'UTF-8');
    if ($text === '') d_json(['ok' => false, 'msg' => '说点什么吧']);
    if (d_blocked($text)) d_json(['ok' => false, 'msg' => '内容包含不允许的链接或标签']);
    if (!empty($_POST['hp'])) d_json(['ok' => false, 'msg' => '提交失败'], 400); // 蜜罐

    $all = d_load();
    $key = d_ipkey();
    $now = time();
    // 同 IP 限流
    for ($i = count($all) - 1; $i >= 0 && $i > count($all) - 60; $i--) {
        if (($all[$i]['k'] ?? '') === $key && $now - (int)($all[$i]['t'] ?? 0) < D_GAP) {
            $wait = D_GAP - ($now - (int)$all[$i]['t']);
            d_json(['ok' => false, 'msg' => "太快啦，{$wait} 秒后再发"]);
        }
    }
    // 颜色：0 白 / 1 浅蓝 / 2 浅黄 / 3 浅粉
    $c = random_int(0, 3);
    $row = ['id' => bin2hex(random_bytes(6)), 'text' => $text, 't' => $now, 'c' => $c, 'k' => $key];
    $all[] = $row;
    if (count($all) > D_KEEP) $all = array_slice($all, -D_KEEP);
    if (!d_save($all)) d_json(['ok' => false, 'msg' => '保存失败，请检查 danmaku 目录权限']);
    d_json(['ok' => true, 'msg' => '已发送', 'item' => ['id' => $row['id'], 'text' => $text, 't' => $now, 'c' => $c]]);
}

/* ---------- 删除 ---------- */
if ($act === 'del') {
    if ($method !== 'POST') d_json(['ok' => false, 'msg' => '方法不允许'], 405);
    if (!d_admin_ok((string)($_POST['pwd'] ?? ''))) d_json(['ok' => false, 'msg' => '密码错误'], 403);
    $id = (string)($_POST['id'] ?? '');
    $all = d_load();
    $before = count($all);
    $all = array_values(array_filter($all, fn($r) => ($r['id'] ?? '') !== $id));
    if (count($all) === $before) d_json(['ok' => false, 'msg' => '没找到这条弹幕']);
    d_save($all);
    d_json(['ok' => true, 'msg' => '已删除']);
}

d_json(['ok' => false, 'msg' => '未知操作'], 400);
