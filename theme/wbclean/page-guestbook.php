<?php
/**
 * 留言板：不注册、不显示邮箱、审核后才出现
 *
 * @package custom
 */
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
$this->need('header.php');
?>

<div class="gb-page">
<div class="page-head">
    <span class="kicker">Guestbook</span>
    <h1><?php $this->title(); ?></h1>
    <div class="sub">不用注册，留个名字就能说话</div>
</div>

<p class="gb-lead">
    这里不即时回复，慢慢看。想说什么都行——问一句、纠个错、说声“路过”，都算。
    邮箱只用来认人，不会显示出来；留言会先看一眼再放出来。
</p>

<?php if (trim(strip_tags((string)$this->text)) !== ''): ?>
<section class="about-sec">
    <div class="post-content"><?php $this->content(); ?></div>
</section>
<?php endif; ?>

<?php $this->need('comments.php'); ?>
</div>

<?php $this->need('footer.php'); ?>
