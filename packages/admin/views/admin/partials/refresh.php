<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $url */ ?>
<?php /** @var string $target a CSS id selector for the element to replace */ ?>
<?php /** @var string $label what is being refreshed, for a visitor who cannot see the icon */ ?>
<?php /** @var string $swap how the answer lands; a card replaces itself, a region is filled */ ?>
<?php /** @var string $class the rest of the class list; a card's button is quieter than the page's */ ?>
<?php /* Ask this again, now. Whatever asked for this button already knows how
   to replace itself with the answer, so this is the same request pointed at the
   same place; nothing here knows what comes back. */ ?>
<button class="admin-refresh<?= isset($class) ? ' ' . $this->e($class) : '' ?>" type="button"
        hx-nonce="<?= $this->e($this->cspNonce()) ?>"
        hx-get="<?= $this->e($url) ?>"
        hx-target="<?= $this->e($target) ?>"
        hx-swap="<?= $this->e($swap ?? 'outerHTML') ?>"
        aria-label="Refresh <?= $this->e($label) ?>">
    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
</button>
