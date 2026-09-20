<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ListViewModel $vm */ ?>
<?php /** @var array<string, int>|null $counts */ ?>
<?php /* The module's named views of its own table. The links render with the
   list and are clickable at once; the tally beside each one is a query of its
   own, so the bar asks for them after the fact and replaces itself with the
   answer. The replacement carries no hx-get, which is what ends the exchange
   after a single round trip. */ ?>
<?php $links = $vm->links() ?>
<?php $counts ??= null ?>

<?php if ($links !== []): ?>
    <?php /* The nonce is unconditional: it vouches for the element, not for the
       request on it, and htmx gates every root it swaps in whether or not that
       root asks for anything. Tying it to the hx-get left the filled bar
       arriving unvouched and refused, with an error for each one. */ ?>
    <nav id="admin-links" class="admin-links nav nav-pills gap-1 mb-3" aria-label="Filter"
         hx-nonce="<?= $this->e($this->cspNonce()) ?>"
         <?php if ($counts === null && $vm->countsUrl() !== null): ?>
         hx-get="<?= $this->e($vm->countsUrl()) ?>"
         hx-trigger="load"
         hx-swap="outerHTML"
         <?php endif ?>>
        <?php foreach ($links as $link): ?>
            <?php $active = $vm->isActiveLink($link) ?>
            <a class="nav-link d-flex align-items-center gap-2<?= $active ? ' active' : '' ?>"
               href="<?= $this->e($vm->linkUrl($link)) ?>"
               <?= $active ? 'aria-current="page"' : '' ?>
               hx-nonce="<?= $this->e($this->cspNonce()) ?>"
               hx-get="<?= $this->e($vm->linkUrl($link)) ?>"
               hx-target="#admin-body"
               hx-push-url="true">
                <?= $this->e($link->label()) ?>
                <?php if ($counts === null): ?>
                    <?php /* A space, not nothing, and deliberately not a
                       Bootstrap placeholder. The placeholder paints itself in
                       currentColor, which on the link being clicked is the
                       strongest ink on the page, so a pending tally arrived as
                       a solid dark block. Empty is no better: a box with no
                       content has no line box either, so the pill collapsed to
                       a sliver and the whole bar grew when the numbers landed.
                       A space is invisible, and gives the same line box a digit
                       would, so the pill is already its final size. */ ?>
                    <span class="badge rounded-pill admin-link-count admin-link-count-pending" aria-hidden="true">&nbsp;</span>
                <?php else: ?>
                    <span class="badge rounded-pill admin-link-count"><?= $this->e((string) ($counts[$link->key()] ?? 0)) ?></span>
                <?php endif ?>
            </a>
        <?php endforeach ?>
    </nav>
<?php endif ?>
