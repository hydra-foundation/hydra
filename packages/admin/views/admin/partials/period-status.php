<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /** @var bool $oob */ ?>
<?php /* The stretch the cards below are actually covering, in dates.

   It used to read "Showing today" beside a select displaying "Today", which is
   one idea said twice and the smaller half of the duplication the strip had
   with the cards. The name of the choice is on the control; this says what the
   choice came out as, which is the only way to tell last 7 days from last 30
   without counting.

   It renders beside the select and not on a line under the masthead, where it
   landed directly below the caption of the leftmost figure in the same size and
   the same muted ink, and so read as a fourth line of that one stat.

   It is the one part of the toolbar whose text a body swap makes stale, so a
   body swap sends a fresh copy out of band, the way the export link is kept
   current on a list. Being a live region is the other half of its job: the
   select it belongs to replaces the entire grid silently, and a screen reader
   is told nothing by five cards changing underneath it. This element is outside
   the swap, so it is still here when its own text changes, which is the only
   way a live region announces anything — and the announcement names the period,
   because "12 Sep – 19 Sep 2026" read aloud on its own says nothing about what
   was asked for.

   Nothing at all on a dashboard that offers no period: the out-of-band copy is
   sent on every body swap, and one addressed to an element the page does not
   have is a swap htmx cannot land. */ ?>
<?php if ($vm->hasPeriod()): ?>
<p class="admin-period-showing" id="admin-period-showing"<?= ($oob ?? false) ? ' hx-swap-oob="true"' : '' ?>
   hx-nonce="<?= $this->e($this->cspNonce()) ?>" role="status" aria-live="polite">
    <span class="visually-hidden">Showing <?= $this->e(strtolower($vm->period->label())) ?>. </span>
    <?= $this->e($vm->window?->range() ?? 'Everything recorded') ?>
</p>
<?php endif ?>
