<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Widget $widget */ ?>
<?php /** @var string|null $url */ ?>
<?php /* The grand totals above the grid, and the one element on a dashboard
   that a change of period must not disturb: it answers for all of time, so
   narrowing the cards below to today cannot change it.

   hx-preserve goes on this wrapper and never on the strip inside it. Preserving
   means "keep the node that is already here and drop the one that arrived", and
   an element that also replaces itself asks htmx to do both to one node — which
   it attempts, and fails, by removing a child of a parent it no longer has. So
   the marker sits on something that never swaps, and the thing that swaps sits
   inside it. */ ?>
<div class="admin-summary" id="admin-summary" hx-preserve="true"
     hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <?= $this->partial('admin/partials/summary-body', [
        'widget' => $widget,
        'url' => $url,
        'data' => null,
    ]) ?>
</div>
