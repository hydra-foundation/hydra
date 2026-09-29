<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var list<array{label: string, url: string, active: bool}> $tabs */ ?>
<?php /** @var string $label */ ?>
<?php /* Each tab is a URL, so these are links and the browser keeps the
   history. They swap the frame like every other admin link. The frame draws
   this for a family of modules; a module whose screens are categories, as
   Settings is, hands it its own list. */ ?>
<nav class="admin-tabs" hx-nonce="<?= $this->e($this->cspNonce()) ?>" aria-label="<?= $this->e($label) ?>">
    <?php foreach ($tabs as $tab): ?>
        <a class="admin-tab<?= $tab['active'] ? ' active' : '' ?>"
           href="<?= $this->e($tab['url']) ?>"
           hx-nonce="<?= $this->e($this->cspNonce()) ?>"
           hx-get="<?= $this->e($tab['url']) ?>"
           hx-target="#admin-frame"
           hx-push-url="true"
           <?= $tab['active'] ? 'aria-current="page"' : '' ?>><?= $this->e($tab['label']) ?></a>
    <?php endforeach ?>
</nav>
