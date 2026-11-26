<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('header.php'); ?>

<div class="page-head">
    <h1><?php $this->archiveTitle([
        'category' => _t('分类：%s'),
        'search'   => _t('搜索：%s'),
        'tag'      => _t('标签：%s'),
        'author'   => _t('作者：%s'),
        'date'     => _t('归档：%s')
    ], '', ''); ?></h1>
    <div class="sub"><?php _e('按时间浏览，最新的在前面'); ?></div>
</div>

<?php if ($this->is('search')): ?>
<form class="search-form" method="post" action="<?php $this->options->siteUrl(); ?>" role="search">
    <input type="text" name="s" placeholder="<?php _e('换一个关键词…'); ?>">
    <button class="btn" type="submit"><?php _e('搜索'); ?></button>
</form>
<?php endif; ?>

<?php if ($this->have()): ?>
    <div class="post-grid">
    <?php while ($this->next()): ?>
        <article class="card post-item">
            <div class="post-top">
                <h2 class="post-title"><a href="<?php $this->permalink(); ?>"><?php $this->title(); ?></a></h2>
            </div>
            <div class="post-meta"><time datetime="<?php $this->date('c'); ?>"><?php $this->date(); ?></time></div>
            <p class="post-excerpt"><?php echo wbclean_excerpt($this->text, 140); ?></p>
            <a class="read-more" href="<?php $this->permalink(); ?>"><?php _e('阅读全文'); ?> →</a>
        </article>
    <?php endwhile; ?>
    </div>
<?php else: ?>
    <div class="empty-state">
        <p class="empty-t"><?php _e('没有找到匹配的内容'); ?></p>
        <p class="empty-d"><?php _e('换个词试试，或者直接去'); ?><a href="<?php $this->options->siteUrl(); ?>"><?php _e('首页'); ?></a><?php _e('看看。'); ?></p>
    </div>
<?php endif; ?>

<?php if ($this->getTotal() > $this->parameter->pageSize): ?>
    <div class="pagination"><?php $this->pageNav('&laquo; 上一页', '下一页 &raquo;', 5, '...'); ?></div>
<?php endif; ?>

<?php $this->need('footer.php'); ?>
