<?php
/**
 * Site layout, ported from zkm-wp-theme's header.php and footer.php.
 *
 * @var array<string, string> $site
 */
$themeVersion = static fn (string $file) => esc('/theme/' . $file . '?v=' . (@filemtime(FCPATH . 'theme/' . $file) ?: '1'), 'attr');
$meta = [
    ['name', 'description', $description],
    ['property', 'og:type', $ogType],
    ['property', 'og:site_name', $site['site_name']],
    ['property', 'og:locale', 'en_US'],
    ['property', 'og:title', $title ?? $site['site_name']],
    ['property', 'og:description', $description],
    ['property', 'og:url', $canonicalUrl],
    ['property', 'og:image', $ogImage['url']],
    ['property', 'og:image:width', $ogImage['width']],
    ['property', 'og:image:height', $ogImage['height']],
    ['property', 'og:image:alt', $ogImage['alt']],
    ['name', 'twitter:card', 'summary_large_image'],
    ['name', 'twitter:site', $site['twitter']],
    ['name', 'twitter:creator', $site['twitter']],
    ['name', 'twitter:title', $title ?? $site['site_name']],
    ['name', 'twitter:description', $description],
    ['name', 'twitter:image', $ogImage['url']],
    ['name', 'twitter:image:alt', $ogImage['alt']],
    ['property', 'article:published_time', $published ? date(DATE_W3C, strtotime($published)) : null],
    ['property', 'article:modified_time', $modified ? date(DATE_W3C, strtotime($modified)) : null],
];
?><!doctype html>
<html lang="en-US">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>(function(){var t;try{t=localStorage.getItem('zkm-color-mode')}catch(e){}if(t!=='light'&&t!=='dark'){t=window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'}document.documentElement.setAttribute('data-theme',t)})();</script>
    <title><?= esc($documentTitle) ?></title>
<?php if ($noindex): ?>
    <meta name="robots" content="noindex">
<?php else: ?>
    <meta name="robots" content="max-image-preview:large">
<?php endif ?>
<?php if ($site['fediverse'] !== ''): ?>
    <meta name="fediverse:creator" content="<?= esc($site['fediverse'], 'attr') ?>">
<?php endif ?>
<?php if ($canonicalUrl): ?>
    <link rel="canonical" href="<?= esc($canonicalUrl, 'attr') ?>">
<?php endif ?>
<?php foreach ($meta as [$attr, $key, $value]): ?>
<?php if ((string) $value !== ''): ?>
    <meta <?= $attr ?>="<?= esc($key, 'attr') ?>" content="<?= esc(html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'), 'attr') ?>">
<?php endif ?>
<?php endforeach ?>
    <link rel="alternate" type="application/rss+xml" title="<?= esc($site['site_name'], 'attr') ?> &raquo; Feed" href="/feed/">
<?php if ($icons !== []): ?>
    <link rel="icon" href="<?= esc($icons['32'], 'attr') ?>" sizes="32x32">
    <link rel="icon" href="<?= esc($icons['192'], 'attr') ?>" sizes="192x192">
    <link rel="apple-touch-icon" href="<?= esc($icons['180'], 'attr') ?>">
    <meta name="msapplication-TileImage" content="<?= esc($icons['270'], 'attr') ?>">
<?php endif ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800&amp;family=Rajdhani:wght@400;500;600;700&amp;display=swap">
    <link rel="stylesheet" href="<?= $themeVersion('blocks.css') ?>">
    <link rel="stylesheet" href="<?= $themeVersion('style.css') ?>">
<?php if ($site['google_tag_id'] !== '' && ENVIRONMENT === 'production'): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= esc(rawurlencode($site['google_tag_id']), 'attr') ?>"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?= esc($site['google_tag_id'], 'js') ?>');</script>
<?php endif ?>
<?php if ($site['gtm_id'] !== '' && ENVIRONMENT === 'production'): ?>
    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?= esc($site['gtm_id'], 'js') ?>');</script>
<?php endif ?>
</head>
<body class="<?= esc(trim($bodyClass . ' wp-theme-zkm-wp-theme'), 'attr') ?>">
<a class="skip-link screen-reader-text" href="#primary">Skip to content</a>

<div class="site-header-wrap">
    <header class="site-header" role="banner">
        <div class="site-branding">
            <a class="custom-logo-link zkm-logo-mark" href="/" rel="home" tabindex="-1" aria-hidden="true"></a>

<?php if ($isHome): ?>
            <h1 class="site-title"><a href="/" rel="home"><?= esc($site['site_name']) ?></a></h1>
<?php else: ?>
            <p class="site-title"><a href="/" rel="home"><?= esc($site['site_name']) ?></a></p>
<?php endif ?>

<?php if ($site['tagline'] !== ''): ?>
            <p class="site-description"><?= esc($site['tagline']) ?></p>
<?php endif ?>
        </div>

        <div class="header-controls">
            <button id="zkm-menu-toggle" class="menu-toggle" type="button" aria-controls="zkm-primary-menu" aria-expanded="false" aria-label="Open menu">
                <span class="menu-toggle-bars" aria-hidden="true"><span></span><span></span><span></span></span>
                <span class="screen-reader-text">Menu</span>
            </button>

            <nav id="zkm-primary-menu" class="main-navigation" aria-label="Primary menu">
                <ul id="menu-header" class="menu">
<?php foreach ($primaryMenu as $link): ?>
<?php $current = $currentPath !== null && $link['url'] === $currentPath ?>
                    <li class="menu-item<?= $current ? ' current-menu-item' : '' ?>"><a href="<?= esc($link['url'], 'attr') ?>"<?= $current ? ' aria-current="page"' : '' ?>><?= esc($link['label']) ?></a></li>
<?php endforeach ?>
                </ul>
            </nav>

            <button id="zkm-theme-toggle" class="theme-toggle" type="button" aria-live="polite" aria-label="Toggle color mode">
                <span class="theme-toggle-icon" aria-hidden="true">◐</span>
                <span class="theme-toggle-label">Theme</span>
            </button>
        </div>
    </header>
</div>

<main id="primary" class="site-main">
    <div class="content-area">
<?= $content ?>
    </div>
</main>

<div class="site-footer-wrap">
    <footer class="site-footer" role="contentinfo">
        <div class="footer-bottom">
            <nav class="social-navigation" aria-label="Social menu">
                <ul id="menu-social" class="menu">
<?php foreach ($socialMenu as $link): ?>
                    <li class="menu-item"><a rel="me" href="<?= esc($link['url'], 'attr') ?>"><span class="zkm-social-icon" aria-hidden="true"><?= social_icon($link['url'], $link['label']) ?></span><span class="screen-reader-text"><?= esc($link['label'] !== '' ? $link['label'] : parse_url($link['url'], PHP_URL_HOST)) ?></span></a></li>
<?php endforeach ?>
                </ul>
            </nav>

            <div class="site-info">
                <span>&copy; <?= date('Y') ?> <?= esc($site['site_name']) ?></span>
<?php if ($site['footer_note'] !== ''): ?>
                <span><?= esc($site['footer_note']) ?></span>
<?php endif ?>
            </div>
        </div>
    </footer>
</div>

<button id="zkm-back-to-top" class="back-to-top" type="button" aria-label="Back to top">
    ↑
</button>

<script src="<?= $themeVersion('theme.js') ?>"></script>
</body>
</html>
