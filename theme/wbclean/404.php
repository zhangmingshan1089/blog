<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
$base = rtrim((string)$this->options->siteUrl, '/');
$this->need('header.php');
?>

<div class="nf">
    <p class="nf-code">404</p>
    <h1 class="nf-t">这页不在了</h1>
    <p class="nf-d">
        可能是地址打错了，也可能是我把它删了、改名了。下面几个地方更可能有你要找的东西——
        文章都在归档里，碎念在朋友圈，文件在云盘。
    </p>

    <div class="nf-grid">
        <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/">回首页 <span>↗</span></a>
        <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/index.php/archives.html">翻全部文章 <span>→</span></a>
        <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/moments/">随便逛逛 <span>→</span></a>
        <a href="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>/index.php/guestbook.html">留句话 <span>→</span></a>
    </div>
</div>

<?php $this->need('footer.php'); ?>
