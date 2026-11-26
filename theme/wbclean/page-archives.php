<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 「文章」页：左边分类 / 标签，右边按年月归档。
 * 分类与标签不再各占一个空页面，都并到这里。
 */
$base = rtrim((string)$this->options->siteUrl, '/');

$rows = [];
$cats = [];
$tags = [];
try {
    $db   = Typecho_Db::get();
    $rows = $db->fetchAll($db->select('cid', 'title', 'created', 'text')
        ->from('table.contents')
        ->where('type = ?', 'post')->where('status = ?', 'publish')
        ->order('created', Typecho_Db::SORT_DESC));

    $rels = $db->fetchAll($db->select('cid', 'mid')->from('table.relationships'));
    $cats = $db->fetchAll($db->select('mid', 'name', 'slug')->from('table.metas')
        ->where('type = ?', 'category')->order('order', Typecho_Db::SORT_ASC));
    $tags = $db->fetchAll($db->select('mid', 'name', 'slug')->from('table.metas')
        ->where('type = ?', 'tag')->order('mid', Typecho_Db::SORT_ASC));
} catch (\Throwable $e) {
}

/* 已发布文章的 cid 集合，用来过滤「挂了但没文章」的元数据 */
$live = [];
foreach ($rows as $r) $live[(int)$r['cid']] = 1;

/* cid → [mid...] */
$byCid = [];
$countMid = [];
foreach (($rels ?? []) as $r) {
    $cid = (int)$r['cid']; $mid = (int)$r['mid'];
    if (!isset($live[$cid])) continue;
    $byCid[$cid][] = $mid;
    $countMid[$mid] = ($countMid[$mid] ?? 0) + 1;
}

/* mid → 名字 */
$nameOf = [];
$tagNameOf = [];
foreach ($cats as $c) $nameOf[(int)$c['mid']] = (string)$c['name'];
foreach ($tags as $t) { $nameOf[(int)$t['mid']] = (string)$t['name']; $tagNameOf[(int)$t['mid']] = (string)$t['name']; }

/* 按年 → 月分组 */
$years = [];
foreach ($rows as $r) {
    $ts = (int)$r['created'];
    $y  = date('Y', $ts);
    $m  = (int)date('n', $ts);
    $title = trim((string)($r['title'] ?? ''));
    $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($r['text'] ?? ''))));
    $tgs = [];
    foreach ($byCid[(int)$r['cid']] ?? [] as $mid) {
        if (isset($tagNameOf[$mid])) $tgs[] = $tagNameOf[$mid];
    }
    $years[$y][$m][] = [
        'title' => $title !== '' ? $title : '无标题',
        'time'  => $ts,
        'brief' => mb_substr($plain, 0, 76, 'UTF-8'),
        'tags'  => array_slice($tgs, 0, 3),
        'url'   => $base . '/index.php/archives/' . (int)$r['cid'] . '/',
    ];
}
$total = count($rows);

/* 标签云字号：按文章数在 12–18px 之间插值 */
$tagMax = 1;
foreach ($tags as $t) $tagMax = max($tagMax, (int)($countMid[(int)$t['mid']] ?? 0));
$liveTags = array_values(array_filter($tags, function ($t) use ($countMid) {
    return (int)($countMid[(int)$t['mid']] ?? 0) > 0;
}));

/* 有文章的分类 */
$liveCats = array_values(array_filter($cats, function ($c) use ($countMid) {
    return (int)($countMid[(int)$c['mid']] ?? 0) > 0;
}));

$this->need('header.php');
?>

<div class="page-head">
    <span class="kicker">Archive</span>
    <h1>文章</h1>
    <div class="sub">
        <?php if ($total > 0): ?>
            共 <?php echo $total; ?> 篇 · 按时间倒序 · 看过往，也看现在
        <?php else: ?>
            还没有文章，第一篇正在路上
        <?php endif; ?>
    </div>
</div>

<div class="filter-bar" aria-label="筛选">
    <span class="fl">视图</span>
    <a class="on" href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/index.php/archives.html">归档</a>
    <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/timeline/">时间轴</a>
    <span class="fl" style="margin-left:12px;">订阅</span>
    <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/index.php/feed/" target="_blank" rel="noopener">RSS ↗</a>
</div>

<?php if ($total === 0): ?>
<div class="empty-state">
    <p class="empty-t">这里还很安静</p>
    <p class="empty-d">第一篇写好后，它会出现在这里。</p>
</div>
<?php else: ?>

<div class="arch-wrap">
    <aside class="arch-side">
        <div class="arch-block">
            <p class="arch-h">分类</p>
            <?php if ($liveCats): ?>
            <ul class="cat-tree">
                <?php foreach ($liveCats as $c): ?>
                <li>
                    <a href="<?php echo htmlspecialchars($base . '/index.php/category/' . rawurlencode((string)$c['slug']) . '/', ENT_QUOTES); ?>">
                        <span><?php echo htmlspecialchars((string)$c['name'], ENT_QUOTES); ?></span>
                        <span class="n"><?php echo (int)($countMid[(int)$c['mid']] ?? 0); ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="about-tip" style="margin:0;">还没分过类。写的时候顺手选一个，这里就会长出来。</p>
            <?php endif; ?>
        </div>

        <div class="arch-block">
            <p class="arch-h">标签</p>
            <?php if ($liveTags): ?>
            <div class="tag-cloud">
                <?php foreach ($liveTags as $t):
                    $n = (int)($countMid[(int)$t['mid']] ?? 0);
                    $sz = $tagMax > 1 ? 12 + round(($n / $tagMax) * 6, 1) : 13;
                ?>
                <a href="<?php echo htmlspecialchars($base . '/index.php/tag/' . rawurlencode((string)$t['slug']) . '/', ENT_QUOTES); ?>"
                   style="font-size:<?php echo $sz; ?>px"><?php echo htmlspecialchars((string)$t['name'], ENT_QUOTES); ?><span class="c"><?php echo $n; ?></span></a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="about-tip" style="margin:0;">还没有标签。</p>
            <?php endif; ?>
        </div>
    </aside>

    <div class="archive-list">
        <?php foreach ($years as $year => $months): ?>
        <section class="ar-year">
            <h2 class="ar-year-t"><?php echo $year; ?><span class="ar-year-n"><?php
                $c = 0; foreach ($months as $list) { $c += count($list); } echo $c;
            ?> 篇</span></h2>

            <?php foreach ($months as $mon => $list): ?>
            <div class="ar-month">
                <div class="ar-month-t"><?php echo $mon; ?> 月</div>
                <ul class="ar-items">
                    <?php foreach ($list as $p): ?>
                    <li class="ar-item">
                        <a class="ar-link" href="<?php echo htmlspecialchars($p['url'], ENT_QUOTES); ?>">
                            <span class="ar-title"><?php echo htmlspecialchars($p['title'], ENT_QUOTES); ?></span>
                            <time class="ar-date" datetime="<?php echo date('c', $p['time']); ?>"><?php echo date('m-d', $p['time']); ?></time>
                        </a>
                        <?php if ($p['brief'] !== ''): ?>
                        <p class="ar-brief"><?php echo htmlspecialchars($p['brief'], ENT_QUOTES); ?></p>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endforeach; ?>
    </div>
</div>

<div class="archive-foot">
    <a class="btn ghost" href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/timeline/">看时间轴</a>
    <a class="btn ghost" href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/">回首页</a>
</div>

<?php endif; ?>

<?php $this->need('footer.php'); ?>
