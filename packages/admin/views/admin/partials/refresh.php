<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $url */ ?>
<?php /** @var string $target a CSS id selector for the element to replace */ ?>
<?php /** @var string $label what is being refreshed, for a visitor who cannot see the icon */ ?>
<?php /* Ask this one card again, now. The card already knows how to replace
   itself with the answer, so this is the same request the card makes on load
   pointed at the same place; nothing here knows what the card contains. */ ?>
<button class="admin-widget-refresh" type="button"
        hx-nonce="<?= $this->e($this->cspNonce()) ?>"
        hx-get="<?= $this->e($url) ?>"
        hx-target="<?= $this->e($target) ?>"
        hx-swap="outerHTML"
        aria-label="Refresh <?= $this->e($label) ?>">
    <i class="bi bi-arrow-clockwise" aria-hidden="true"></i>
</button>
