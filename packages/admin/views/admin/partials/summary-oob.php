<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /* The strip, addressed to where it already is.

   It lives in the toolbar, outside the swap, for the reason written on the
   toolbar itself. Out of band is how it can sit there and still follow the
   period.

   Only when it follows it, though: a strip answering for all time is the same
   strip after the change, and sending it anyway would replace three settled
   figures with a placeholder that fetches the identical numbers again.

   It goes back empty rather than filled: the dashboard request still runs no
   query, and the placeholder that lands fetches itself at the new period the
   same way it did on first paint. */ ?>
<?php if ($vm->summary?->isPeriodic()): ?>
    <?= $this->partial('admin/partials/summary-body', [
        'widget' => $vm->summary,
        'url' => $vm->url($vm->summary),
        'data' => null,
        'oob' => true,
    ]) ?>
<?php endif ?>
