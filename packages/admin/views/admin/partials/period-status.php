<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /** @var bool $oob */ ?>
<?php /* What the cards below are currently answering for, said in words.

   It is the one part of the toolbar a body swap makes stale, so a body swap
   sends a fresh copy out of band, the way the export link is kept current on a
   list. Being a live region is the other half of its job: the select it sits
   beside replaces the entire grid silently, and a screen reader is told nothing
   by five cards changing underneath it. This element is outside the swap, so it
   is still here when its own text changes, which is the only way a live region
   announces anything.

   Nothing at all on a dashboard that offers no period: the out-of-band copy is
   sent on every body swap, and one addressed to an element the page does not
   have is a swap htmx cannot land. */ ?>
<?php if ($vm->hasPeriod()): ?>
<p class="admin-period-showing" id="admin-period-showing"<?= ($oob ?? false) ? ' hx-swap-oob="true"' : '' ?>
   hx-nonce="<?= $this->e($this->cspNonce()) ?>" role="status" aria-live="polite">
    Showing <?= $this->e(strtolower($vm->period->label())) ?>
</p>
<?php endif ?>
