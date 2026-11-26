<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php
$slug = $this->slug;
if (in_array($slug, ['archives', 'categories', 'tags', 'guestbook'], true) && file_exists(__DIR__ . '/page-' . $slug . '.php')) {
    $this->need('page-' . $slug . '.php');
    return;
}
?>
<?php $this->need('header.php'); ?>
<article class="card" itemscope itemtype="https://schema.org/Article">
    <header class="post-header page-head">
        <h1><?php $this->title(); ?></h1>
        <div class="post-meta"><time datetime="<?php $this->date('c'); ?>"><?php $this->date(); ?></time></div>
    </header>
    <div class="post-content"><?php $this->content(); ?></div>
</article>
<?php if ($this->allowComment): ?>
    <?php $this->need('comments.php'); ?>
<?php endif; ?>
<?php $this->need('footer.php'); ?>
