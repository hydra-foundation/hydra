<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /* The stretch of time the cards below are answering about.
   It asks the dashboard for itself again rather than talking to each card,
   because the address is where the period lives: the swap brings back the grid
   with every periodic card pointed at the new one, and hx-push-url leaves the
   visitor somewhere they can reload or send to somebody else.
   The summary strip is marked hx-preserve and so survives that swap with the
   totals it already fetched — which is what keeps this from re-asking a
   question whose answer cannot have changed. */ ?>
<div class="admin-period" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <label class="form-label admin-period-label" for="admin-period">Period</label>
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
