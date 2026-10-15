<?php

/** The 7 / 14 / 30 / 90-day switch of a dashboard tab. Uses $tab and $chart_days from index.php. */
?>
<div class="btn-group btn-group-sm" role="group" aria-label="Days shown">
    <?php foreach (Dashboard::CHART_DAY_OPTIONS as $days): ?>
        <a class="btn <?= $days === $chart_days ? 'btn-chimbo' : 'btn-outline-secondary' ?>"
           href="<?= e(url("admin/index.php?tab={$tab}&days={$days}")) ?>" <?= $days === $chart_days ? 'aria-current="true"' : '' ?>><?= e($days) ?> days</a>
    <?php endforeach; ?>
</div>
