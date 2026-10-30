<?php
/**
 * Memorial banner under the header (Config\Tribute).
 *
 * @var \Config\Tribute $tribute
 */
$f = $tribute->featured;
?>
<aside class="trek-banner" aria-label="In memoriam">
    <p class="trek-banner-text">
        In memory of <strong class="trek-banner-name"><?= esc($f['name']) ?></strong>,
        <?= esc($f['role']) ?>, <?= esc($f['years']) ?>.
<?php if ($f['line'] !== ''): ?>
        <em class="trek-banner-line"><?= esc($f['line']) ?></em>
<?php endif ?>
    </p>
</aside>
