<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('header.php'); ?>

<div class="page-head">
    <h1><?php _e('站内搜索'); ?></h1>
</div>
<div class="card">
    <form class="search-form" method="post" action="<?php $this->options->siteUrl(); ?>" role="search" style="margin:0;">
        <input type="text" name="s" placeholder="<?php _e('输入关键词…'); ?>" value="<?php echo htmlspecialchars($this->request->get('s', '')); ?>">
        <button class="btn" type="submit"><?php _e('搜索'); ?></button>
    </form>
</div>

<?php if ($this->have()): ?>
    <div class="post-grid">
    <?php while ($this->next()): ?>
        <article class="card post-item">
            <div class="post-top"><h2 class="post-title"><a href="<?php $this->permalink(); ?>"><?php $this->title(); ?></a></h2></div>
            <div class="post-meta"><time datetime="<?php $this->date('c'); ?>"><?php $this->date(); ?></time></div>
            <p class="post-excerpt"><?php echo wbclean_excerpt($this->text, 140); ?></p>
        </article>
    <?php endwhile; ?>
    </div>
    <?php if ($this->getTotal() > $this->parameter->pageSize): ?>
        <div class="pagination"><?php $this->pageNav('&laquo; 上一页', '下一页 &raquo;', 5, '...'); ?></div>
    <?php endif; ?>
<?php elseif ($this->request->get('s')): ?>
    <div class="card empty">
        <div class="ico">🔍</div>
        <div class="t"><?php _e('没有匹配「'); ?><?php echo htmlspecialchars($this->request->get('s')); ?><?php _e('」的文章'); ?></div>
    </div>
<?php endif; ?>

<?php $this->need('footer.php'); ?>
