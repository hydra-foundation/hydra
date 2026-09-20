<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /* The stretch of time the cards below are answering about.
   It asks the dashboard for itself again rather than talking to each card,
   because the address is where the period lives: the swap brings back the grid
   with every periodic card pointed at the new one, and hx-push-url leaves the
   visitor somewhere they can reload or send to somebody else.
   The select is not in what it swaps, so it keeps focus and the visitor can
   arrow through the options; what does go stale comes back out of band.
   The label is for a screen reader only: the select's own value reads "Today",
   and a visible PERIOD beside it was a ninth small capital on a page that
   already had eight. */ ?>
<div class="admin-period-control" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <label class="visually-hidden" for="admin-period">Period</label>
    <select class="form-select form-select-sm" id="admin-period" name="period"
            hx-nonce="<?= $this->e($this->cspNonce()) ?>"
            hx-get="<?= $this->e($vm->dashboardUrl()) ?>"
            hx-trigger="change"
            hx-target="#admin-body"
            hx-push-url="true">
        <?php foreach ($vm->periods() as $period): ?>
            <option value="<?= $this->e($period->value) ?>"<?= $period === $vm->period ? ' selected' : '' ?>>
                <?= $this->e($period->label()) ?>
            </option>
        <?php endforeach ?>
    </select>
</div>
