<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
/**
 * 文章页：正文 + 目录 + 阅读进度 + 代码复制 + 图片放大 + 上下篇 + 同标签
 */
$base = rtrim((string)$this->options->siteUrl, '/');
$mins = wbclean_reading_time((string)$this->text);

/* 正文先取出来：要给 h2/h3 加锚点、生成目录、给图片加懒加载 */
ob_start();
$this->content();
$body = (string)ob_get_clean();

$toc = [];
$body = preg_replace_callback('#<h([23])([^>]*)>(.*?)</h\1>#is', function ($m) use (&$toc) {
    $lv    = (int)$m[1];
    $attrs = (string)$m[2];
    $inner = (string)$m[3];
    $text  = trim(strip_tags($inner));
    if ($text === '') return $m[0];
    if (preg_match('/\sid="([^"]+)"/i', $attrs, $mm)) {
        $id = $mm[1];
    } else {
        $id = 'sec-' . (count($toc) + 1);
        $attrs .= ' id="' . $id . '"';
    }
    $toc[] = ['lv' => $lv, 'id' => $id, 't' => $text];
    return '<h' . $lv . $attrs . '>' . $inner . '</h' . $lv . '>';
}, $body);

/* 图片：第一张保留 eager（可能是 LCP），其余懒加载 */
$imgSeen = 0;
$body = preg_replace_callback('#<img\b([^>]*)>#i', function ($m) use (&$imgSeen) {
    $attrs = $m[1];
    $imgSeen++;
    if (stripos($attrs, 'loading=') !== false) return $m[0];
    $attrs .= $imgSeen === 1 ? ' loading="eager"' : ' loading="lazy" decoding="async"';
    return '<img' . $attrs . '>';
}, $body);

/* 相邻文章（按发布时间，不依赖 Widget 内部游标） */
$prevPost = $nextPost = null;
try {
    $db = Typecho_Db::get();
    $prevPost = $db->fetchRow($db->select('cid', 'title', 'created')->from('table.contents')
        ->where('type = ?', 'post')->where('status = ?', 'publish')
        ->where('created < ?', (int)$this->created)
        ->order('created', Typecho_Db::SORT_DESC)->limit(1));
    $nextPost = $db->fetchRow($db->select('cid', 'title', 'created')->from('table.contents')
        ->where('type = ?', 'post')->where('status = ?', 'publish')
        ->where('created > ?', (int)$this->created)
        ->order('created', Typecho_Db::SORT_ASC)->limit(1));
} catch (\Throwable $e) {}

/* 同标签推荐（最多 3 篇） */
$related = [];
try {
    if ($this->tags) {
        $db = Typecho_Db::get();
        $first = null;
        foreach ($this->tags as $tg) { $first = $tg; break; }
        if ($first) {
            $rows = $db->fetchAll($db->select('c.cid', 'c.title', 'c.created')->from('table.contents c')
                ->join('table.relationships r', 'c.cid = r.cid')
                ->where('r.mid = ?', (int)$first->mid)
                ->where('c.type = ?', 'post')->where('c.status = ?', 'publish')
                ->where('c.cid <> ?', (int)$this->cid)
                ->order('c.created', Typecho_Db::SORT_DESC)->limit(3));
            foreach ($rows as $r) {
                $related[] = [
                    't' => (string)$r['title'],
                    'd' => date('Y-m-d', (int)$r['created']),
                    'u' => $base . '/index.php/archives/' . (int)$r['cid'] . '/',
                ];
            }
        }
    }
} catch (\Throwable $e) {}

$hasToc  = count($toc) >= 3;
$modified = (int)($this->modified ?: 0);
$showMod  = $modified > 0 && date('Y-m-d', $modified) !== date('Y-m-d', (int)$this->created);

$this->need('header.php');
?>

<article class="article">
    <header class="article-head">
        <h1><?php $this->title(); ?></h1>
        <div class="article-meta">
            <time datetime="<?php $this->date('c'); ?>"><?php $this->date('Y-m-d'); ?></time>
            <span class="sep" aria-hidden="true"></span>
            <span><?php echo $mins; ?> 分钟读完</span>
            <?php if ($this->categories): ?>
            <span class="sep" aria-hidden="true"></span>
            <span><?php $this->category(' / ', false); ?></span>
            <?php endif; ?>
            <span class="sep" aria-hidden="true"></span>
            <span><?php $this->author(); ?></span>
        </div>
    </header>

    <?php if ($hasToc): ?>
    <details class="toc-inline">
        <summary>目录 · <?php echo count($toc); ?> 节</summary>
        <ol>
            <?php foreach ($toc as $it): ?>
            <li><a class="<?php echo $it['lv'] === 3 ? 'lv3' : ''; ?>" href="#<?php echo htmlspecialchars($it['id'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($it['t'], ENT_QUOTES); ?></a></li>
            <?php endforeach; ?>
        </ol>
    </details>
    <?php endif; ?>

    <div class="article-body<?php echo $hasToc ? '' : ' no-toc'; ?>">
        <div class="post-content" id="articleBody">
            <?php echo $body; ?>
        </div>

        <?php if ($hasToc): ?>
        <aside class="toc" aria-label="目录">
            <p class="toc-t">Contents</p>
            <ol>
                <?php foreach ($toc as $it): ?>
                <li><a class="<?php echo $it['lv'] === 3 ? 'lv3' : ''; ?>" href="#<?php echo htmlspecialchars($it['id'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($it['t'], ENT_QUOTES); ?></a></li>
                <?php endforeach; ?>
            </ol>
        </aside>
        <?php endif; ?>
    </div>

    <footer class="article-foot">
        <?php if ($this->tags): ?>
        <div class="terms">
            <?php foreach ($this->tags as $tg): ?>
            <a class="tag" href="<?php echo htmlspecialchars((string)$tg->url, ENT_QUOTES); ?>"><?php echo htmlspecialchars((string)$tg->name, ENT_QUOTES); ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($showMod): ?>
        <p class="article-updated">写于 <?php $this->date('Y-m-d'); ?>，最后改于 <?php echo date('Y-m-d', $modified); ?>。</p>
        <?php endif; ?>

        <?php if ($prevPost || $nextPost): ?>
        <nav class="post-nav" aria-label="上下篇">
            <?php if ($prevPost): ?>
            <a href="<?php echo htmlspecialchars($base . '/index.php/archives/' . (int)$prevPost['cid'] . '/', ENT_QUOTES); ?>">
                <span class="k">← 上一篇</span>
                <span class="t"><?php echo htmlspecialchars((string)$prevPost['title'], ENT_QUOTES); ?></span>
            </a>
            <?php else: ?><div class="pn-empty">这是最早的一篇</div><?php endif; ?>
            <?php if ($nextPost): ?>
            <a class="next" href="<?php echo htmlspecialchars($base . '/index.php/archives/' . (int)$nextPost['cid'] . '/', ENT_QUOTES); ?>">
                <span class="k">下一篇 →</span>
                <span class="t"><?php echo htmlspecialchars((string)$nextPost['title'], ENT_QUOTES); ?></span>
            </a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>

        <?php if ($related): ?>
        <section>
            <h2 class="related-t">同一个标签下</h2>
            <ul class="related">
                <?php foreach ($related as $r): ?>
                <li><a href="<?php echo htmlspecialchars($r['u'], ENT_QUOTES); ?>"><span><?php echo htmlspecialchars($r['t'], ENT_QUOTES); ?></span><span class="d"><?php echo $r['d']; ?></span></a></li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>
    </footer>
</article>

<?php $this->need('comments.php'); ?>

<?php $this->need('footer.php'); ?>
