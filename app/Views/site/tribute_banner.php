<?php
/**
 * Memorial banner under the header (Config\Tribute).
 *
 * @var \Config\Tribute $tribute
 */
$f = $tribute->featured;
?>
<aside class="trek-banner" aria-label="In memoriam">
    <span class="trek-bar trek-bar--left" aria-hidden="true"></span>
    <p class="trek-banner-text">
        <span class="trek-banner-kicker">In memory of</span>
        <strong class="trek-banner-name"><?= esc($f['name']) ?></strong>
        <span class="trek-banner-role"><?= esc($f['role']) ?> · <?= esc($f['years']) ?></span>
<?php if ($f['line'] !== ''): ?>
        <em class="trek-banner-line"><?= esc($f['line']) ?></em>
<?php endif ?>
    </p>
    <span class="trek-bar trek-bar--right" aria-hidden="true"></span>
</aside>
