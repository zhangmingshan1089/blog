<?php if (!defined('__TYPECHO_ROOT_DIR__')) exit; ?>
<?php $this->comments()->to($comments); ?>
<section id="comments" class="comments">
    <div class="about-h2" style="margin-top:34px;">
        <?php $this->commentsNum(_t('还没有留言'), _t('1 条留言'), _t('%d 条留言')); ?>
    </div>

    <?php if ($comments->have()): ?>
    <ol class="comment-list">
        <?php
        while ($comments->next()):
            $initial = mb_substr(trim((string)$comments->author), 0, 1, 'UTF-8');
        ?>
        <li class="comment-item" id="<?php $comments->theId(); ?>">
            <div class="comment-head">
                <span class="comment-avatar" aria-hidden="true"><?php echo htmlspecialchars($initial !== '' ? $initial : '·'); ?></span>
                <span class="comment-author"><?php $comments->author(); ?></span>
                <?php if ($comments->authorId == $this->author->uid): ?>
                <span class="comment-badge"><?php _e('博主'); ?></span>
                <?php endif; ?>
                <time class="comment-date" datetime="<?php $comments->date('c'); ?>"><?php $comments->date('Y-m-d H:i'); ?></time>
                <?php $comments->reply(_t('回复')); ?>
            </div>
            <div class="comment-body"><?php $comments->content(); ?></div>
        </li>
        <?php endwhile; ?>
    </ol>
    <div class="pagination"><?php $comments->pageNav('&laquo; 上一页', '下一页 &raquo;'); ?></div>
    <?php endif; ?>

    <?php if ($this->allow('comment')): ?>
    <div id="<?php $this->respondId(); ?>" class="respond" style="margin-top:26px;">
        <div class="cancel-comment-reply"><?php $comments->cancelReply(); ?></div>
        <h3><?php _e('说两句'); ?></h3>
        <form method="post" action="<?php $this->commentUrl(); ?>" class="comment-form gb-form" id="comment-form" role="form">
            <input type="text" name="hp_url" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
            <input type="hidden" name="wb_t" id="wbT" value="">
            <?php if ($this->user->hasLogin()): ?>
            <div class="msg"><?php _e('以'); ?> <?php $this->user->screenName(); ?> <?php _e('的身份留言'); ?> · <a href="<?php $this->options->logoutUrl(); ?>"><?php _e('退出'); ?></a></div>
            <?php else: ?>
            <div class="row">
                <input type="text" name="author" required maxlength="40" placeholder="<?php _e('称呼 *'); ?>" value="<?php $this->remember('author'); ?>">
                <input type="email" name="mail" <?php if ($this->options->commentsRequireMail): ?>required<?php endif; ?> maxlength="80" placeholder="<?php _e('邮箱（不会公开）'); ?>" value="<?php $this->remember('mail'); ?>">
            </div>
            <?php endif; ?>
            <textarea name="text" required placeholder="<?php _e('想说什么就写，不用注册。'); ?>"><?php $this->remember('text'); ?></textarea>
            <p class="hint"><?php _e('邮箱只用来认人，不会显示；留言会先看一眼再放出来，所以不会立刻出现。'); ?></p>
            <button type="submit" class="btn"><?php _e('提交'); ?></button>
        </form>
    </div>
    <?php else: ?>
    <div class="notice"><?php _e('这里暂时关闭了留言。'); ?></div>
    <?php endif; ?>
</section>
<script>
(function(){
  var f = document.getElementById('comment-form');
  if (!f) return;
  var t = document.getElementById('wbT');
  if (t) t.value = String(Date.now());
  f.addEventListener('submit', function(e){
    var hp = f.querySelector('input[name="hp_url"]');
    if (hp && hp.value !== '') { e.preventDefault(); return; }
    if (t && Date.now() - parseInt(t.value || '0', 10) < 2500) {
      e.preventDefault();
      alert('写得也太快了，再确认一下内容吧。');
    }
  });
})();
</script>
