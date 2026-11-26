<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 首页：首屏（header.php 渲染）→ 四个入口 → 最近写的 → 一行数据
 * 计数全部实时算，不写死；空站也不尴尬。
 *
 * v21：加第三套「蝴蝶卡片」风格，首页内容包进 .bf-cols，
 * 左侧是原来的内容，右侧多一个 .bf-side 侧栏（只在 butterfly 下显示）。
 */
$isHome = $this->is('index');
$baseUrl = rtrim((string)$this->options->siteUrl, '/');

$st = wbclean_stats();

/* ---------- 最近写的：取 6 篇（tech / paper 只显示前 3） ---------- */
$latest = [];
$tagCloud = [];
try {
    $db   = Typecho_Db::get();
    $rows = $db->fetchAll($db->select('cid', 'title', 'created', 'modified', 'text')
        ->from('table.contents')
        ->where('type = ?', 'post')->where('status = ?', 'publish')
        ->order('created', Typecho_Db::SORT_DESC)->limit(6));

    /* 标签与分类：一次取出，避免逐篇查库 */
    $labelMap = [];
    $metaName = [];
    $metaSlug = [];
    $tagCount = [];
    try {
        foreach ($db->fetchAll($db->select('mid', 'name', 'slug', 'type')->from('table.metas')) as $m) {
            $mid = (int)$m['mid'];
            $metaName[$mid] = (string)$m['name'];
            $metaSlug[$mid] = (string)$m['slug'];
            if ((string)$m['type'] !== 'tag') $metaSlug[$mid] = '';  // 标签云只要真标签
        }
        foreach ($db->fetchAll($db->select('cid', 'mid')->from('table.relationships')) as $r) {
            $mid = (int)$r['mid'];
            if (isset($metaName[$mid])) $labelMap[(int)$r['cid']][] = $metaName[$mid];
            if (isset($metaSlug[$mid]) && $metaSlug[$mid] !== '') $tagCount[$mid] = ($tagCount[$mid] ?? 0) + 1;
        }
        /* 标签云：按文章数降序取 12 个 */
        arsort($tagCount);
        $i = 0;
        foreach ($tagCount as $mid => $n) {
            if ($i++ >= 12) break;
            if ($metaSlug[$mid] === '') continue;
            $tagCloud[] = [
                'name' => $metaName[$mid],
                'n'    => (int)$n,
                'url'  => $baseUrl . '/index.php/tag/' . rawurlencode($metaSlug[$mid]) . '/',
            ];
        }
    } catch (\Throwable $e) {}

    foreach ($rows as $r) {
        $cid   = (int)$r['cid'];
        $text  = (string)($r['text'] ?? '');
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
        $title = trim((string)($r['title'] ?? ''));
        $title = $title !== '' ? $title : '无标题';

        /* 封面：正文里第一张图；没有就留空，CSS 用渐变 + 首字兜底 */
        $cover = wbclean_first_image($text);

        $latest[] = [
            'title' => $title,
            'time'  => (int)$r['created'],
            'brief' => mb_substr($plain, 0, 96, 'UTF-8'),
            'tags'  => array_slice(array_values(array_unique($labelMap[$cid] ?? [])), 0, 2),
            'mins'  => wbclean_reading_time($text),
            'url'   => $baseUrl . '/index.php/archives/' . $cid . '/',
            'cover' => $cover,
            'ch'    => mb_substr($title, 0, 1, 'UTF-8'),
            'hue'   => 204 + (int)(crc32((string)$cid) % 22),
        ];
    }
} catch (\Throwable $e) {
    // 静默降级
}

/* ---------- 四个入口：数字来自实时统计 ---------- */
$hub = [
    [
        'no' => '01', 't' => '时间轴', 'n' => $st['posts'] > 0 ? $st['posts'] . ' 篇' : '空',
        'd' => '按年月排下来，看它长成什么样',
        'u' => $baseUrl . '/timeline/',
    ],
    [
        'no' => '02', 't' => '朋友圈', 'n' => $st['moments'] > 0 ? $st['moments'] . ' 条' : '暂无',
        'd' => '一句话、一张图，随手记',
        'u' => $baseUrl . '/moments/',
    ],
    [
        'no' => '03', 't' => '云盘', 'n' => $st['files'] > 0 ? $st['files'] . ' 个文件' : '暂无文件',
        'd' => '公开的文件，能预览也能下载',
        'u' => $baseUrl . '/drive/',
    ],
    [
        'no' => '04', 't' => '归档', 'n' => $st['cats'] > 0 ? $st['cats'] . ' 类' : '暂无分类',
        'd' => '分类与标签，按主题找',
        'u' => $baseUrl . '/index.php/archives.html',
    ],
];

/* 侧栏用的站点信息 */
$bfSiteName = trim((string)$this->options->title);
if ($bfSiteName === '') $bfSiteName = '未命名站点';
$bfDesc = trim((string)$this->options->description);
if ($bfDesc === '') $bfDesc = '记录、存档与分享。';
$bfAvatar = wbclean_avatar();
$__bfSideAv = __DIR__ . '/assets/avatar-176.webp';
if (strpos($bfAvatar, 'assets/avatar') !== false && is_readable($__bfSideAv)) {
    $bfAvatar = 'data:image/webp;base64,' . base64_encode((string)file_get_contents($__bfSideAv));
}

$this->need('header.php');
?>

<?php if ($isHome): ?>
<div class="bf-cols">
<div class="bf-main">

<!-- ============ 四个入口 ============ -->
<section class="hub" id="hub" aria-label="入口">
    <div class="hub-grid">
        <?php foreach ($hub as $h): ?>
        <a class="hub-card" href="<?php echo htmlspecialchars($h['u'], ENT_QUOTES); ?>">
            <span class="hub-no"><?php echo $h['no']; ?></span>
            <div class="hub-row">
                <h2 class="hub-t"><?php echo htmlspecialchars($h['t'], ENT_QUOTES); ?></h2>
                <span class="hub-n"><?php echo htmlspecialchars($h['n'], ENT_QUOTES); ?></span>
            </div>
            <p class="hub-d"><?php echo htmlspecialchars($h['d'], ENT_QUOTES); ?></p>
            <span class="hub-go" aria-hidden="true">→</span>
        </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- ============ 最近写的：零点击读得到 ============ -->
<section class="recent" id="recent">
    <div class="sec-head">
        <h2 class="sec-title">最近写的<span class="en">Recent</span></h2>
        <?php if ($st['posts'] > 3): ?>
        <a class="sec-more" href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>/index.php/archives.html">看全部 <?php echo $st['posts']; ?> 篇 →</a>
        <?php endif; ?>
    </div>
    <div class="sec-rule"></div>

    <?php if (!empty($latest)): ?>
    <ul class="recent-list">
        <?php foreach ($latest as $idx => $p): ?>
        <li class="recent-row<?php echo $idx >= 3 ? ' r-extra' : ''; ?>">
            <a href="<?php echo htmlspecialchars($p['url'], ENT_QUOTES); ?>">
                <?php if ($p['cover'] !== ''): ?>
                <span class="r-cover">
                    <img src="<?php echo htmlspecialchars($p['cover'], ENT_QUOTES); ?>" alt="" loading="lazy" decoding="async">
                    <?php if ($p['tags']): ?><span class="r-cover-tag"><?php echo htmlspecialchars($p['tags'][0], ENT_QUOTES); ?></span><?php endif; ?>
                </span>
                <?php else: ?>
                <span class="r-cover gen" style="background-image:linear-gradient(135deg,hsl(<?php echo $p['hue']; ?>,66%,58%),hsl(<?php echo $p['hue'] + 12; ?>,72%,46%))">
                    <span class="r-cover-ch"><?php echo htmlspecialchars($p['ch'], ENT_QUOTES); ?></span>
                    <?php if ($p['tags']): ?><span class="r-cover-tag"><?php echo htmlspecialchars($p['tags'][0], ENT_QUOTES); ?></span><?php endif; ?>
                </span>
                <?php endif; ?>
                <span class="r-date"><time datetime="<?php echo date('Y-m-d', $p['time']); ?>"><?php echo date('Y-m-d', $p['time']); ?></time></span>
                <span class="r-body">
                    <span class="r-title"><?php echo htmlspecialchars($p['title'], ENT_QUOTES); ?></span>
                    <?php if ($p['brief'] !== ''): ?>
                    <span class="r-brief"><?php echo htmlspecialchars($p['brief'], ENT_QUOTES); ?></span>
                    <?php endif; ?>
                    <span class="r-meta">
                        <?php foreach ($p['tags'] as $tg): ?>
                        <span class="r-tag"><?php echo htmlspecialchars($tg, ENT_QUOTES); ?></span>
                        <?php endforeach; ?>
                        <?php if ($p['tags']): ?><i class="r-dot" aria-hidden="true"></i><?php endif; ?>
                        <span><?php echo $p['mins']; ?> 分钟读完</span>
                    </span>
                </span>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="empty-state">
        <p class="empty-t">这里还很安静</p>
        <p class="empty-d">第一篇写好后，它会出现在这里。也可以先去<a href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>/moments/">朋友圈</a>看看碎碎念。</p>
    </div>
    <?php endif; ?>
</section>

<!-- ============ 一行数据：页脚式小字 ============ -->
<div class="facts">
    <span>共 <b><?php echo $st['posts']; ?></b> 篇</span>
    <i aria-hidden="true"></i>
    <span>最近更新 <b><?php echo $st['last'] ? date('Y-m-d', $st['last']) : '—'; ?></b></span>
    <i aria-hidden="true"></i>
    <span><?php echo $st['posts'] > 0 ? '持续记录中' : '等待第一篇'; ?></span>
    <?php if ($st['moments'] > 0): ?>
    <i aria-hidden="true"></i>
    <span>碎片 <b><?php echo $st['moments']; ?></b> 条</span>
    <?php endif; ?>
</div>

</div><!-- /.bf-main -->

<!-- ============ 右侧栏（仅蝴蝶风格显示） ============ -->
<aside class="bf-side" aria-label="侧栏">

    <div class="bf-card bf-author">
        <img class="bf-author-av" src="<?php echo htmlspecialchars($bfAvatar, ENT_QUOTES); ?>"
             alt="<?php echo htmlspecialchars($bfSiteName, ENT_QUOTES); ?>" width="74" height="74" loading="lazy" decoding="async">
        <p class="bf-author-name"><?php echo htmlspecialchars($bfSiteName, ENT_QUOTES); ?></p>
        <p class="bf-author-bio"><?php echo htmlspecialchars(mb_substr($bfDesc, 0, 46, 'UTF-8'), ENT_QUOTES); ?></p>
        <div class="bf-stat">
            <div><b><?php echo $st['posts']; ?></b><span>文章</span></div>
            <div><b><?php echo $st['cats']; ?></b><span>分类</span></div>
            <div><b><?php echo $st['tags']; ?></b><span>标签</span></div>
        </div>
    </div>

    <div class="bf-card bf-music">
        <span class="bf-music-ico" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 3v10.6a3.4 3.4 0 1 0 2 3.1V7.2l6-1.5v3.4l-4 1v7.5a3.4 3.4 0 1 1-2-3.1V3z"/></svg>
        </span>
        <span class="bf-music-txt">
            <b>我喜欢的歌</b>
            <span>点开听一会儿</span>
        </span>
        <a href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>/music/" aria-label="打开音乐库" style="margin-left:auto;color:inherit"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></a>
    </div>

    <?php if (!empty($tagCloud)): ?>
    <div class="bf-card">
        <p class="bf-card-t">标签</p>
        <div class="bf-tags">
            <?php foreach ($tagCloud as $tg): ?>
            <a href="<?php echo htmlspecialchars($tg['url'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($tg['name'], ENT_QUOTES); ?><span class="bf-tag-n"><?php echo $tg['n']; ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($latest)): ?>
    <div class="bf-card">
        <p class="bf-card-t">最新文章</p>
        <ul class="bf-posts">
            <?php foreach (array_slice($latest, 0, 4) as $p): ?>
            <li>
                <a href="<?php echo htmlspecialchars($p['url'], ENT_QUOTES); ?>" tabindex="-1" aria-hidden="true">
                    <?php if ($p['cover'] !== ''): ?>
                    <img class="bf-post-thumb" src="<?php echo htmlspecialchars($p['cover'], ENT_QUOTES); ?>" alt="" loading="lazy" decoding="async">
                    <?php else: ?>
                    <span class="bf-post-thumb gen" style="background:linear-gradient(135deg,hsl(<?php echo $p['hue']; ?>,66%,58%),hsl(<?php echo $p['hue'] + 12; ?>,72%,46%))"><?php echo htmlspecialchars($p['ch'], ENT_QUOTES); ?></span>
                    <?php endif; ?>
                </a>
                <div class="bf-post-info">
                    <a href="<?php echo htmlspecialchars($p['url'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($p['title'], ENT_QUOTES); ?></a>
                    <time datetime="<?php echo date('Y-m-d', $p['time']); ?>"><?php echo date('m-d', $p['time']); ?></time>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="bf-card">
        <p class="bf-card-t">关于这里</p>
        <div class="bf-notice">
            <p><?php echo htmlspecialchars($bfDesc, ENT_QUOTES); ?></p>
            <p>文章、朋友圈、云盘与音乐都在顶部导航里，<a href="<?php echo htmlspecialchars($baseUrl, ENT_QUOTES); ?>/index.php/about.html">看看关于页</a>也行。</p>
        </div>
    </div>

</aside>
</div><!-- /.bf-cols -->

<?php endif; ?>

<?php $this->need('footer.php'); ?>
