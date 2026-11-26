<?php
/**
 * 关于页面（博主 / Now / 订阅与联系 / 这个站）
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$base   = rtrim((string)$this->options->siteUrl, '/');
$avatar = wbclean_avatar();
$site   = trim((string)$this->options->title);
$desc   = trim((string)$this->options->description);
$brand  = wbclean_brand($site);

$st = wbclean_stats();
$postN  = (int)$st['posts'];
$firstY = $st['first'] ? date('Y', $st['first']) : date('Y');
$rssUrl = $base . '/index.php/feed/';

$brandName = $brand['main'] !== '' ? $brand['main'] : $site;

$this->need('header.php');
?>

<div class="page-head">
    <span class="kicker">About</span>
    <h1><?php $this->title(); ?></h1>
    <div class="sub">关于这个站，和写字的人</div>
</div>

<div class="about-wrap">
    <section class="about-hero">
        <img class="about-avatar" src="<?php echo htmlspecialchars($avatar, ENT_QUOTES); ?>" alt="" width="64" height="64" loading="lazy" decoding="async">
        <div class="about-id">
            <p class="about-name"><?php echo htmlspecialchars($brandName, ENT_QUOTES); ?></p>
            <p class="about-desc"><?php echo htmlspecialchars($desc !== '' ? $desc : '记录、存档与分享', ENT_QUOTES); ?></p>
        </div>
        <dl class="about-facts">
            <div><dt>开始写</dt><dd><?php echo $firstY; ?></dd></div>
            <div><dt>文章</dt><dd><?php echo $postN; ?> 篇</dd></div>
            <div><dt>碎片</dt><dd><?php echo (int)$st['moments']; ?> 条</dd></div>
        </dl>
    </section>

    <section class="about-sec about-body">
        <div class="post-content">
            <?php $this->content(); ?>
        </div>
    </section>

    <section class="about-sec">
        <h2 class="about-h2">订阅与联系</h2>
        <ul class="about-list">
            <li>
                <span class="about-k">RSS</span>
                <a href="<?php echo htmlspecialchars($rssUrl, ENT_QUOTES); ?>" target="_blank" rel="noopener">打开订阅源 ↗</a>
            </li>
            <li>
                <span class="about-k">留言</span>
                <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/index.php/guestbook.html">不用注册，直接说</a>
            </li>
            <li>
                <span class="about-k">友链</span>
                <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/index.php/links.html">几个我常看的站</a>
            </li>
            <li>
                <span class="about-k">站点地图</span>
                <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/sitemap.xml">sitemap.xml</a>
            </li>
        </ul>
        <div class="lf-copybox" style="max-width:520px;">
            <code id="rssText"><?php echo htmlspecialchars($rssUrl, ENT_QUOTES); ?></code>
            <button class="lf-copy" type="button" id="rssCopy">复制</button>
        </div>
        <p class="about-tip">粘进任意阅读器都行：Feedly、Inoreader、NetNewsWire、Reeder。有新文章它会自己找上门。</p>
    </section>

    <section class="about-sec">
        <h2 class="about-h2">这个站</h2>
        <ul class="about-list about-list-dot">
            <li><b>程序</b>Typecho 1.2，数据存在 SQLite 里，一个文件就是全部。</li>
            <li><b>主题</b>自己改的 wbclean：纸白底、宋体标题、只留一个墨绿做强调色。</li>
            <li><b>图片</b>上传时自动压到 1600px 以内，首页封面给了 WebP 多尺寸。</li>
            <li><b>统计</b>没有装第三方统计，不追踪、不画像；谁来过，我不知道。</li>
        </ul>
        <p class="about-ai">整理的时候用过 AI 帮忙核对和改错字，字是自己写的。</p>
    </section>
</div>

<script>
(function(){
  var btn = document.getElementById('rssCopy'), txt = document.getElementById('rssText');
  if (!btn || !txt) return;
  btn.addEventListener('click', function(){
    var v = txt.textContent.trim();
    var done = function(){ btn.textContent = '已复制'; btn.classList.add('is-done');
      setTimeout(function(){ btn.textContent = '复制'; btn.classList.remove('is-done'); }, 1600); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(v).then(done, function(){});
    } else {
      var r = document.createRange(); r.selectNodeContents(txt);
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      try { document.execCommand('copy'); done(); } catch(e) {}
    }
  });
})();
</script>

<?php $this->need('footer.php'); ?>
