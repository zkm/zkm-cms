<?php
/**
 * The blog and category/tag archives (zkm-wp-theme index.php, list branch).
 */
?>
<?php if ($term): ?>
        <header class="page-header">
            <h1 class="entry-title"><?= esc($term['name']) ?></h1>
        </header>
<?php endif ?>

<?php if ($items === []): ?>
        <article class="post">
            <header class="entry-header">
                <h1 class="entry-title">Nothing found</h1>
            </header>
            <div class="entry-content">
                <p>It seems we can’t find what you’re looking for.</p>
            </div>
        </article>
<?php endif ?>

<?php foreach ($items as $item): ?>
        <article id="post-<?= (int) $item['post']['id'] ?>" class="<?= esc($item['classes'], 'attr') ?>">
            <header class="entry-header">
                <h2 class="entry-title"><a href="<?= esc($item['url'], 'attr') ?>"><?= esc($item['post']['title']) ?></a></h2>
            </header>

<?php if ($item['image']): ?>
            <div class="entry-thumbnail">
                <a class="entry-thumbnail-link" href="<?= esc($item['url'], 'attr') ?>" aria-hidden="true" tabindex="-1">
                    <?= $item['image'] ?>
                </a>
            </div>
<?php endif ?>

            <div id="entry-content-<?= (int) $item['post']['id'] ?>" class="entry-content">
<?php if ($item['excerpt'] !== ''): ?>
                <p><?= $item['excerpt'] ?></p>
<?php endif ?>
            </div>
        </article>
<?php endforeach ?>

<?php if ($newer || $older): ?>
        <nav class="navigation posts-navigation" aria-label="Posts">
            <h2 class="screen-reader-text">Posts navigation</h2>
            <div class="nav-links">
<?php if ($older): ?>
                <div class="nav-previous"><a href="<?= esc($older, 'attr') ?>">Older posts</a></div>
<?php endif ?>
<?php if ($newer): ?>
                <div class="nav-next"><a href="<?= esc($newer, 'attr') ?>">Newer posts</a></div>
<?php endif ?>
            </div>
        </nav>
<?php endif ?>
