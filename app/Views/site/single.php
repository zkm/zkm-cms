<?php
/**
 * A single post or page (zkm-wp-theme index.php, singular branch).
 */
?>
        <article id="post-<?= (int) $post['id'] ?>" class="<?= esc($classes, 'attr') ?>">
            <header class="entry-header">
                <h1 class="entry-title"><?= esc($post['title']) ?></h1>
            </header>

<?php if ($media): ?>
            <div class="entry-thumbnail">
                <a class="entry-thumbnail-link" href="<?= esc($url, 'attr') ?>" aria-hidden="true" tabindex="-1">
                    <?= featured_image($media, 'attachment-large size-large wp-post-image', true) ?>
                </a>
            </div>
<?php endif ?>

            <div id="entry-content-<?= (int) $post['id'] ?>" class="entry-content">
<?= $html ?>

<?php if ($post['template'] === 'contact'): ?>
<?= view('site/contact_form', ['returnUrl' => $url]) ?>
<?php endif ?>
            </div>
        </article>
