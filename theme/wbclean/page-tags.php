<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->need('header.php'); ?>
<div class="page-head"><h1><?php $this->title(); ?></h1><div class="sub"><?php _e('按标签浏览'); ?></div></div>
<div class="card">
    <div class="terms">
        <?php \Widget\Metas\Tag\Cloud::alloc()->parse('<a class="tag" style="font-size:14px;padding:8px 16px;" href="{permalink}"># {name} <span class="count">({count})</span></a>'); ?>
    </div>
</div>
<?php $this->need('footer.php'); ?>
