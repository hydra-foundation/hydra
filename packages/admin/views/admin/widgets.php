<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /* The grid, and nothing in it. Every card is a placeholder that fetches
   its own body, so what the visitor waits for here is the layout rather than
   the slowest query on it.

   The summary strip and the period control are not here: they render in the
   toolbar, above the region this one swaps. See dashboard-toolbar. */ ?>
<?php $cards = $vm->cards() ?>

<?php if ($cards === []): ?>
    <?= $this->partial('admin/partials/widget-empty', ['message' => 'This dashboard declares no widgets yet.']) ?>
<?php else: ?>
    <?php /* The nonce and no hx- attributes: this is a root of the fragment the
       period swaps in, and htmx gates every root whether or not it asks for
       anything. */ ?>
    <div class="admin-widgets" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
        <?php foreach ($cards as $widget): ?>
            <div class="admin-widget-cell" data-span="<?= $this->e((string) $widget->width()) ?>">
                <?= $this->partial('admin/partials/widget', [
                    'widget' => $widget,
                    'url' => $vm->url($widget),
                    'data' => null,
                ]) ?>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
