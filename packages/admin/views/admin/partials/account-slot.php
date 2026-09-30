<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $account the application's markup */ ?>
<?php /** @var string $place topbar or sidebar */ ?>
<?php /** @var bool $oob */ ?>
<?php /* The account inside .admin-account, apart from the bell beside it, so it
   can be swapped without taking the bell's open menu with it. One per place,
   hence the id carries the place. display: contents keeps it out of the row. */ ?>
<div id="admin-account-<?= $this->e($place) ?>" class="admin-account-who"<?= ($oob ?? false) ? ' hx-swap-oob="true"' : '' ?> hx-nonce="<?= $this->e($this->cspNonce()) ?>"><?= $account ?></div>
