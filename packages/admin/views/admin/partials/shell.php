<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /** @var string $content */ ?>
<?php /* The two ids the admin swaps against are declared here and in frame.php,
   beside the hx-targets that name them. Renaming one means renaming its targets
   and Renderer's constant with it — ShippedViewsTest holds them together. */ ?>
<div class="admin">
    <?= $this->partial('admin/partials/topbar') ?>

    <div class="admin-layout">
        <?= $this->partial('admin/partials/sidebar', ['screen' => $screen]) ?>
        <div id="admin-frame" class="admin-frame"><?= $content ?></div>
    </div>
</div>
