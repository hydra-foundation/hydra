<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Widget $widget */ ?>
<?php /** @var string|null $url */ ?>
<?php /** @var array<string, mixed>|null $data null until the strip has fetched itself */ ?>
<?php /* The strip itself: a card in every respect except its chrome. It fetches
   and replaces itself the way the cards below do; what it does not do is
   reload when the period changes, which the wrapper around it sees to. */ ?>
<?php $data ??= null ?>
<?php $loading = $data === null && $url !== null ?>
<?php $polling = $data !== null && $url !== null && $widget->refresh() > 0 ?>

<div class="admin-summary-card" id="admin-summary-card"
     hx-nonce="<?= $this->e($this->cspNonce()) ?>"
     <?php if ($loading || $polling): ?>
     hx-get="<?= $this->e((string) $url) ?>"
     hx-trigger="<?= $loading ? 'load' : '' ?><?= $loading && $polling ? ', ' : '' ?><?= $polling ? 'every ' . $this->e((string) $widget->refresh()) . 's' : '' ?>"
     hx-swap="outerHTML"
     <?php endif ?>>
    <?php if ($data === null): ?>
        <div class="admin-summary-loading placeholder-glow" aria-hidden="true">
            <span class="placeholder col-2"></span>
            <span class="placeholder col-2"></span>
            <span class="placeholder col-2"></span>
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
