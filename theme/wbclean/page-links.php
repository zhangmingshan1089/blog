<?php
/**
 * 友链页面（站点清单 + 本站信息 + 申请说明）
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

$base   = rtrim((string)$this->options->siteUrl, '/');
$site   = trim((string)$this->options->title);
$desc   = trim((string)$this->options->description);
$avatar = wbclean_avatar();

/* 友链数据：增删只改这个数组即可 */
$links = [
    [
        'no'     => '01',
        'name'   => 'Floyd の博客',
        'host'   => 'floyd-blog.xt91tv.shop',
        'url'    => 'https://floyd-blog.xt91tv.shop/',
        'shot'   => wbclean_asset('link-floyd.webp'),
        'letter' => 'F',
        'tone'   => 'pink',
        'role'   => '本站技术员',
        'sub'    => '梦到啥写啥',
        'desc'   => '一个把服务自己跑起来的博客，目前 11 篇，写全是具体问题：ClipCascade 四端剪贴板同步的自建部署、Magpie 网关的完整配置、RAG 从零搭到能用、服务器重启后 Docker 起不来（UFW 与容器 IP 打架）的排查、Claude Code 启动失败的复盘。习惯是先摆现象、再给原因、最后留可复现的步骤，不太写“原理概览”这类听完还是不会动手的内容。本站遇到的多数技术问题，都是先在他这儿找到答案。',
        'tags'   => ['自建部署', 'Docker', '网络排障', 'RAG'],
    ],
    [
        'no'     => '02',
        'name'   => '星穹旅驿',
        'host'   => 'xqly.xt91tv.shop',
        'url'    => 'https://xqly.xt91tv.shop/',
        'shot'   => wbclean_asset('link-xqly.webp'),
        'letter' => '星',
        'tone'   => 'green',
        'role'   => '本站服务器商',
        'sub'    => '生存服务器官网',
        'desc'   => '以阵营对抗为核心的 Minecraft 乱世生存服务器，官网本身就是入口：在线人数、服务器公告、社区、资源中心都在同一页里，注册之后能发帖、进社区。技术上用 Geyser + Velocity + Paper 1.21.11 打通 Java 与基岩，手机端和电脑端进同一个世界；服内分成「黎明誓约」与「暮夜同盟」两方，选了阵营就基本回不了头。站内还有个「星驿助手」，进服、指令、规则这类问题直接问它就行。本站所在的这台机器，就来自他们。',
        'tags'   => ['生存服', '阵营对抗', '多端互通', '玩家社区'],
    ],
];

/* 站点自身信息，方便对方回链 */
/* 注意：feedUrl() 是 echo 型方法，直接调用会把地址打到 DOCTYPE 之前，这里手工拼 */
$feedUrl = $base . '/index.php/feed/';
$snippet = '<a href="' . $base . '/" target="_blank" rel="noopener">' . ($site !== '' ? $site : 'MC.跃小叮当的博客') . '</a>';

/* 后台正文（申请说明等），留空则不渲染这一块 */
ob_start();
$this->content();
$bodyHtml = trim((string)ob_get_clean());

$this->need('header.php');
?>

<div class="page-head">
    <h1><?php $this->title(); ?></h1>
    <div class="sub">几个我愿意推荐的站点</div>
</div>

<div class="links-wrap">

    <p class="lf-lead">
        这里不做流量交换，只放我确实会点进去看的站。下面两个一个管技术、一个管机器——本站能跑起来，跟它们都有关系。
    </p>

    <?php foreach ($links as $i => $l): ?>
    <article class="lf-card<?php echo $i % 2 ? ' lf-flip' : ''; ?>">
        <a class="lf-shot" href="<?php echo htmlspecialchars($l['url'], ENT_QUOTES); ?>" target="_blank" rel="noopener"
           aria-label="在新窗口打开 <?php echo htmlspecialchars($l['name'], ENT_QUOTES); ?>">
            <span class="lf-bar" aria-hidden="true"><i></i><i></i><i></i><em><?php echo htmlspecialchars($l['host'], ENT_QUOTES); ?></em></span>
            <img src="<?php echo htmlspecialchars($l['shot'], ENT_QUOTES); ?>"
                 alt="<?php echo htmlspecialchars($l['name'], ENT_QUOTES); ?> 首页截图"
                 width="1200" height="750" loading="lazy" decoding="async">
            <span class="lf-shot-hint" aria-hidden="true">打开首页 ↗</span>
        </a>

        <div class="lf-body">
            <span class="lf-no" aria-hidden="true"><?php echo $l['no']; ?></span>
            <div class="lf-top">
                <span class="lf-ava lf-ava-<?php echo $l['tone']; ?>" aria-hidden="true"><?php echo htmlspecialchars($l['letter'], ENT_QUOTES); ?></span>
                <div class="lf-id">
                    <h2 class="lf-name"><?php echo htmlspecialchars($l['name'], ENT_QUOTES); ?></h2>
                    <p class="lf-host"><?php echo htmlspecialchars($l['sub'], ENT_QUOTES); ?></p>
                </div>
            </div>
            <p class="lf-desc"><?php echo htmlspecialchars($l['desc'], ENT_QUOTES); ?></p>
            <ul class="lf-tags">
                <?php foreach ($l['tags'] as $t): ?><li><?php echo htmlspecialchars($t, ENT_QUOTES); ?></li><?php endforeach; ?>
            </ul>
            <div class="lf-foot">
                <span class="lf-role"><?php echo htmlspecialchars($l['role'], ENT_QUOTES); ?></span>
                <a class="lf-visit" href="<?php echo htmlspecialchars($l['url'], ENT_QUOTES); ?>" target="_blank" rel="noopener">
                    访问站点
                    <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M9 7h8v8"/></svg>
                </a>
            </div>
        </div>
    </article>
    <?php endforeach; ?>

    <section class="card lf-self">
        <h2 class="about-h2">本站信息</h2>
        <p class="lf-self-tip">想把我加进你的友链，直接抄下面这段；或者点右侧按钮复制。</p>

        <div class="lf-idcard">
            <img class="lf-idcard-ava" src="<?php echo htmlspecialchars($avatar, ENT_QUOTES); ?>" alt="" width="48" height="48" loading="lazy" decoding="async">
            <div class="lf-idcard-main">
                <p class="lf-idcard-name"><?php echo htmlspecialchars($site, ENT_QUOTES); ?></p>
                <p class="lf-idcard-desc"><?php echo htmlspecialchars($desc !== '' ? $desc : '记录、存档与分享', ENT_QUOTES); ?></p>
            </div>
            <a class="lf-visit" href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/" target="_blank" rel="noopener">
                本站首页
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7"/><path d="M9 7h8v8"/></svg>
            </a>
        </div>

        <dl class="lf-facts">
            <div><dt>站点地址</dt><dd><a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/"><?php echo htmlspecialchars($base, ENT_QUOTES); ?>/</a></dd></div>
            <div><dt>头像</dt><dd><a href="<?php echo htmlspecialchars($avatar, ENT_QUOTES); ?>" target="_blank" rel="noopener">avatar.jpg</a></dd></div>
            <div><dt>订阅</dt><dd><a href="<?php echo htmlspecialchars($feedUrl, ENT_QUOTES); ?>" target="_blank" rel="noopener">RSS</a></dd></div>
        </dl>

        <div class="lf-copybox">
            <code id="lfSnippet"><?php echo htmlspecialchars($snippet, ENT_QUOTES); ?></code>
            <button class="lf-copy" type="button" data-copy="<?php echo htmlspecialchars($snippet, ENT_QUOTES); ?>">复制</button>
        </div>
    </section>

    <?php if ($bodyHtml !== ''): ?>
    <section class="card lf-apply">
        <div class="post-content">
            <?php $this->content(); ?>
        </div>
    </section>
    <?php endif; ?>

    <p class="lf-check">友链最近核对：2026-11-24 · 都能正常打开</p>
</div>

<script>
(function () {
  var btn = document.querySelector('.lf-copy');
  if (!btn) return;
  btn.addEventListener('click', function () {
    var text = btn.getAttribute('data-copy') || '';
    var done = function () {
      var old = btn.textContent;
      btn.textContent = '已复制';
      btn.classList.add('is-done');
      window.setTimeout(function () { btn.textContent = old; btn.classList.remove('is-done'); }, 1600);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;left:-9999px';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); done(); } catch (e) {}
      document.body.removeChild(ta);
    }
  });
})();
</script>

<?php $this->need('footer.php'); ?>
