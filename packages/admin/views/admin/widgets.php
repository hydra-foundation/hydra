<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /* The grid, and nothing in it. Every card is a placeholder that fetches
   its own body, so what the visitor waits for here is the layout rather than
   the slowest query on it. */ ?>
<?php $cards = $vm->cards() ?>

<?php if ($vm->summary !== null): ?>
    <?= $this->partial('admin/partials/summary', [
        'widget' => $vm->summary,
        'url' => $vm->url($vm->summary),
        'data' => null,
    ]) ?>
<?php endif ?>

<?php if ($vm->hasPeriod()): ?>
    <?= $this->partial('admin/partials/period', ['vm' => $vm]) ?>
<?php endif ?>

<?php if ($cards === []): ?>
    <p class="text-body-secondary">This dashboard declares no widgets yet.</p>
<?php else: ?>
    <div class="row g-3 admin-widgets" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
        <?php foreach ($cards as $widget): ?>
            <div class="col-12 col-lg-<?= $this->e((string) $widget->width()) ?>">
                <?= $this->partial('admin/partials/widget', [
                    'widget' => $widget,
                    'url' => $vm->url($widget),
                    'data' => null,
                ]) ?>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
