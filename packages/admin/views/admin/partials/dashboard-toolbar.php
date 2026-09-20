<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\DashboardViewModel $vm */ ?>
<?php /* Everything above the grid that the grid's own swap must not disturb.

   The toolbar renders outside #admin-body, which is the whole reason it
   exists: the period select targets the body, and a control that sits inside
   what it replaces is a control destroyed by its own answer — focus lost mid
   keystroke, and in a browser that fires change on arrow keys, destroyed once
   per key. The strip is here for the same reason and not for the old one: it
   answers for the chosen period now, so it goes stale with the grid and comes
   back out of band rather than staying put.

   One masthead row, totals left and the controls that govern the whole page
   right. They were two stacked bands, which left three figures clustered at one
   end of a row whose only other occupant was a 28px button.

   The dates sit in that group rather than on a line of their own under the
   masthead. Under it they landed directly beneath the caption of the leftmost
   figure, in the same size and the same muted ink, and read as a fourth line of
   that one stat rather than as the scope of the page. Beside the select they
   are what they are: the choice on the control, spelled out. */ ?>
<div class="admin-dashboard-toolbar" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <div class="admin-masthead">
        <?php if ($vm->summary !== null): ?>
            <?= $this->partial('admin/partials/summary-body', [
                'widget' => $vm->summary,
                'url' => $vm->url($vm->summary),
                'data' => null,
            ]) ?>
        <?php endif ?>

        <?php if ($vm->hasPeriod()): ?>
            <div class="admin-masthead-controls">
                <?= $this->partial('admin/partials/period-status', ['vm' => $vm]) ?>
                <?= $this->partial('admin/partials/period', ['vm' => $vm]) ?>
                <?php /* The page's refresh, and the only one on it that means
                   the page: it asks the dashboard again at the period showing,
                   so every card re-fetches and the strip comes back with them
                   out of band. It sat on the strip, at the top right of the top
                   band, which is exactly where a reader expects this and it
                   refreshed three numbers. */ ?>
                <?= $this->partial('admin/partials/refresh', [
                    'url' => $vm->refreshUrl(),
                    'target' => '#' . \Hydra\Admin\Renderer::BODY,
                    'swap' => 'innerHTML',
                    'label' => 'dashboard',
                ]) ?>
            </div>
        <?php endif ?>
    </div>
</div>
