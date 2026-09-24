<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $url */ ?>
<?php /** @var string $target a CSS id selector for the card to replace */ ?>
<?php /** @var string $label the card's title */ ?>
<?php /* Drawn with the placeholder and hidden, because the answer to a failed
   fetch is the error renderer's, which knows nothing about cards. admin.js
   shows it when the card's own request fails. Without it a card whose first
   load is refused keeps its bars for good: it only polls once it has data. */ ?>
<div class="admin-widget-failed" hidden>
    <p>Couldn&rsquo;t load <?= $this->e($label) ?>.</p>
    <button class="btn btn-sm btn-outline-secondary" type="button"
            hx-nonce="<?= $this->e($this->cspNonce()) ?>"
            hx-get="<?= $this->e($url) ?>"
            hx-target="<?= $this->e($target) ?>"
            hx-swap="outerHTML">Retry</button>
</div>
