<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ListViewModel $vm */ ?>
<?php /** @var bool $oob */ ?>
<?php /* The link carries the current view in its href, and the toolbar it sits
   in renders outside the swappable body so the search box can keep focus. That
   makes this the one control in the toolbar that goes stale: filtering swaps
   the table and would leave a button here still pointing at the unfiltered
   list. So it is its own element with its own id, and a body swap sends a fresh
   copy of it out of band, the way the sidebar is kept current. */ ?>
<div id="admin-export"<?= ($oob ?? false) ? ' hx-swap-oob="true"' : '' ?> hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <?php if ($vm->exportUrl() !== null): ?>
        <?php /* No htmx on the link itself, deliberately: the response is a file
           and not a fragment, so a swap would put a CSV where the table is. */ ?>
        <a class="btn btn-outline-secondary text-nowrap"
           href="<?= $this->e($vm->exportUrl()) ?>"
           download><?= $this->e($vm->exportLabel()) ?></a>
    <?php endif ?>
</div>
