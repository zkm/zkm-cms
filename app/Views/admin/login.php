<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sign in – zkm-cms</title>
    <link rel="stylesheet" href="/admin-app/login.css?v=<?= (int) @filemtime(FCPATH . 'admin-app/login.css') ?>">
</head>
<body>
<main class="login">
    <h1>zkm-cms</h1>
    <?php if ($error): ?><p class="login-error" role="alert"><?= esc($error) ?></p><?php endif ?>
    <form method="post" action="/admin/login">
        <?= csrf_field() ?>
        <label>Email <input name="email" type="email" autocomplete="username" required value="<?= esc($email ?? '', 'attr') ?>" autofocus></label>
        <label>Password <input name="password" type="password" autocomplete="current-password" required></label>
        <label>Authenticator code <span class="hint">(if two-factor sign-in is on)</span>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9 ]*" maxlength="7"></label>
        <button type="submit">Sign in</button>
    </form>
    <p class="back"><a href="/">← Back to the site</a></p>
</main>
</body>
</html>
