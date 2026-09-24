<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Widget $widget */ ?>
<?php /** @var string|null $url */ ?>
<?php /** @var array<string, mixed>|null $data null until the strip has fetched itself */ ?>
<?php /** @var bool $oob */ ?>
<?php /* The page's masthead: the totals, in figures a size larger than anything
   in the grid, on a band rather than in a box.

   It fetches and replaces itself the way the cards below do. It renders in the
   toolbar, outside the region a period change swaps, and so has to be sent back
   out of band when one happens — see summary-oob. That is new: the strip used
   to answer for all of time and survive a period change by sitting still, which
   put three all-time figures directly above three period figures wearing the
   same clothes, and 4 above 3 reads as a discrepancy rather than as two
   questions. The period is the page's now, and all time is the caption under
   each figure. */ ?>
<?php $data ??= null ?>
<?php $loading = $data === null && $url !== null ?>
<?php $polling = $data !== null && $url !== null && $widget->refresh() > 0 ?>

<div class="admin-summary-card" id="admin-summary-card"<?= ($oob ?? false) ? ' hx-swap-oob="true"' : '' ?>
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
        <?php if ($url !== null): ?>
            <?= $this->partial('admin/partials/widget-failed', ['url' => $url, 'target' => '#admin-summary-card', 'label' => $widget->title()]) ?>
        <?php endif ?>
    <?php else: ?>
        <?= $this->partial($widget->template(), $data) ?>
    <?php endif ?>
</div>
