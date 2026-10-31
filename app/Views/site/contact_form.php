<?php
/**
 * The contact form. It reuses Fluent Forms' class names, which zkm-wp-theme already styles.
 *
 * Errors and old input are read only on ?error=1, which has a query string and so never
 * reaches the page cache: one visitor's message must not be cached for the next.
 */
$request = service('request');
$failed  = $request->getGet('error') === '1';
$errors  = $failed ? visitor_session('contact_errors') ?? [] : [];
$input   = $failed ? visitor_session('_ci_old_input')['post'] ?? [] : [];
$old     = static fn (string $field) => $input[$field] ?? '';
$sent    = $request->getGet('sent') === '1';
?>
<div class="fluentform" id="contact-form">
<?php if ($sent): ?>
    <div class="ff-message-success" role="status"><p>Thanks! Your message is on its way. I'll get back to you soon.</p></div>
<?php else: ?>
<?php if ($errors !== []): ?>
    <div class="ff-errors-in-stack" role="alert">
<?php foreach ($errors as $error): ?>
        <div class="error text-danger"><?= esc($error) ?></div>
<?php endforeach ?>
    </div>
<?php endif ?>
    <form class="frm-fluent-form" method="post" action="/contact-form">
        <input type="hidden" name="return" value="<?= esc($returnUrl, 'attr') ?>">
        <input type="hidden" name="form_token" value="" data-contact-token>
        <noscript><div class="ff-errors-in-stack"><div class="error text-danger">The contact form needs JavaScript turned on.</div></div></noscript>
        <div class="ff-el-group<?= isset($errors['name']) ? ' ff-el-is-error' : '' ?>">
            <div class="ff-el-input--label ff-el-is-required asterisk-right"><label for="contact-name">Name</label></div>
            <div class="ff-el-input--content"><input class="ff-el-form-control" id="contact-name" name="name" type="text" autocomplete="name" required maxlength="200" value="<?= esc($old('name'), 'attr') ?>"></div>
        </div>
        <div class="ff-el-group<?= isset($errors['email']) ? ' ff-el-is-error' : '' ?>">
            <div class="ff-el-input--label ff-el-is-required asterisk-right"><label for="contact-email">Email</label></div>
            <div class="ff-el-input--content"><input class="ff-el-form-control" id="contact-email" name="email" type="email" autocomplete="email" required maxlength="191" value="<?= esc($old('email'), 'attr') ?>"></div>
        </div>
        <div class="ff-el-group<?= isset($errors['message']) ? ' ff-el-is-error' : '' ?>">
            <div class="ff-el-input--label ff-el-is-required asterisk-right"><label for="contact-message">Message</label></div>
            <div class="ff-el-input--content"><textarea class="ff-el-form-control" id="contact-message" name="message" rows="6" required maxlength="10000"><?= esc($old('message')) ?></textarea></div>
        </div>
        <div class="ff-el-group" style="position:absolute;left:-10000px" aria-hidden="true">
            <label for="contact-website">Website</label>
            <input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off">
        </div>
        <div class="ff-el-group ff_submit_btn_wrapper">
            <button type="submit" class="ff-btn ff-btn-submit">Send</button>
        </div>
    </form>
<?php endif ?>
</div>
