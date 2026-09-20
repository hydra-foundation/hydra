<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /* Everything above the grid that the grid's own swap must not disturb.

   The toolbar renders outside #admin-body, which is the whole reason it
   exists: the period select targets the body, and a control that sits inside
   what it replaces is a control destroyed by its own answer — focus lost mid
   keystroke, and in a browser that fires change on arrow keys, destroyed once
   per key. The strip is here for the other half of the same reason: it answers
   for all of time, so a change of period has nothing to say to it, and keeping
   it out of the swap is how it survives one.

   Wrapped, and the wrapper carries the spacing: the strip and the control are
   one masthead block under the screen's title, and each drawing its own rule
   left three horizontal lines stacked in five rems. */ ?>
<div class="admin-dashboard-toolbar" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
<?php if ($vm->summary !== null): ?>
    <?= $this->partial('admin/partials/summary-body', [
        'widget' => $vm->summary,
        'url' => $vm->url($vm->summary),
        'data' => null,
    ]) ?>
<?php endif ?>

<?php if ($vm->hasPeriod()): ?>
    <?= $this->partial('admin/partials/period', ['vm' => $vm]) ?>
<?php endif ?>
</div>
