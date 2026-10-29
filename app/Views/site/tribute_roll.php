<?php
/**
 * The memorial roll, shown on the home page (Config\Tribute).
 *
 * @var \Config\Tribute $tribute
 */
?>
<section class="trek-roll" aria-labelledby="trek-roll-title">
    <header class="trek-roll-header">
        <span class="trek-roll-elbow" aria-hidden="true"></span>
        <h2 id="trek-roll-title" class="trek-roll-title">In memoriam</h2>
        <span class="trek-roll-code" aria-hidden="true">Crew manifest · <?= count($tribute->crew) ?></span>
    </header>

    <p class="trek-roll-intro">For the crew who took us where no one had gone before, and who have now gone on ahead. Thank you for the voyage.</p>

    <ul class="trek-roll-list">
<?php foreach ($tribute->crew as $i => $member): ?>
        <li class="trek-plate trek-plate--<?= $i % 5 ?>">
            <span class="trek-plate-year"><?= (int) $member['year'] ?></span>
            <span class="trek-plate-body">
                <strong class="trek-plate-name"><?= esc($member['name']) ?></strong>
                <span class="trek-plate-role"><?= esc($member['role']) ?></span>
                <span class="trek-plate-credits"><?= esc($member['credits']) ?></span>
            </span>
        </li>
<?php endforeach ?>
    </ul>

<?php if ($tribute->alsoRemembered !== []): ?>
    <p class="trek-roll-also">
        <span class="trek-roll-also-label">Also remembered</span>
<?php foreach ($tribute->alsoRemembered as $i => $guest): ?>
        <span class="trek-roll-guest"><?= esc($guest['name']) ?> <small>(<?= esc($guest['role']) ?>, <?= (int) $guest['year'] ?>)</small></span><?= $i < count($tribute->alsoRemembered) - 1 ? ' · ' : '' ?>
<?php endforeach ?>
    </p>
<?php endif ?>
</section>
