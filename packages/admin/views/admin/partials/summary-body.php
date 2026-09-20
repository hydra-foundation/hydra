<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Widget $widget */ ?>
<?php /** @var string|null $url */ ?>
<?php /** @var array<string, mixed>|null $data null until the strip has fetched itself */ ?>
<?php /* The page's masthead: the running totals, in figures a size larger than
   anything in the grid, on a band rather than in a box.

   It fetches and replaces itself the way the cards below do. What it does not
   do is reload when the period changes, and that is placement rather than
   instruction: it renders in the toolbar, outside the region the period
   swaps. */ ?>
<?php $data ??= null ?>
<?php $loading = $data === null && $url !== null ?>
<?php $polling = $data !== null && $url !== null && $widget->refresh() > 0 ?>

<div class="admin-summary-card" id="admin-summary-card"
     role="region" aria-labelledby="admin-summary-title"
     aria-busy="<?= $data === null ? 'true' : 'false' ?>"
     hx-nonce="<?= $this->e($this->cspNonce()) ?>"
     <?php if ($loading || $polling): ?>
     hx-get="<?= $this->e((string) $url) ?>"
     hx-trigger="<?= $loading ? 'load' : '' ?><?= $loading && $polling ? ', ' : '' ?><?= $polling ? 'every ' . $this->e((string) $widget->refresh()) . 's' : '' ?>"
     hx-swap="outerHTML"
     <?php endif ?>>
    <?php /* The strip shows figures and no heading, so its name exists only
       here — without it the refresh button's "Refresh Totals" names a word
       that appears nowhere on the page. */ ?>
    <h2 class="visually-hidden" id="admin-summary-title"><?= $this->e($widget->title()) ?></h2>

    <?php if ($data === null): ?>
        <div class="admin-summary-loading" aria-hidden="true">
            <span class="admin-bar"></span>
            <span class="admin-bar"></span>
            <span class="admin-bar"></span>
        </div>
        <span class="visually-hidden">Loading <?= $this->e($widget->title()) ?></span>
    <?php else: ?>
        <?= $this->partial($widget->template(), $data) ?>
        <?php if ($url !== null): ?>
            <?= $this->partial('admin/partials/refresh', [
                'url' => $url,
                'target' => '#admin-summary-card',
                'label' => $widget->title(),
            ]) ?>
        <?php endif ?>
    <?php endif ?>
</div>
