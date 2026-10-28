<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-header" content="<?= esc(config('Security')->headerName, 'attr') ?>">
    <meta name="csrf-token" content="<?= esc(csrf_hash(), 'attr') ?>">
    <title>Admin – zkm-cms</title>
<?php if ($built): ?>
    <link rel="stylesheet" href="/admin-app/app.css?v=<?= esc($cssVer, 'attr') ?>">
<?php endif ?>
</head>
<body>
<div id="app" data-user="<?= esc((string) $userName, 'attr') ?>"></div>
<?php if ($built): ?>
<script type="module" src="/admin-app/app.js?v=<?= esc($jsVer, 'attr') ?>"></script>
<?php else: ?>
<p style="font-family:sans-serif;padding:2rem">The admin app isn't built yet. Run <code>npm install &amp;&amp; npm run build</code> in <code>admin-ui/</code>.</p>
<?php endif ?>
</body>
</html>
