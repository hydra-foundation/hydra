<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /** @var string $body */ ?>
<?php /** @var string|null $toolbar */ ?>
<?php /** @var array<string, mixed> $data */ ?>
<?php /* htmx lifts the title out of this head block and drops the block itself. */ ?>
<head><title><?= $this->e($screen->title) ?> · Admin</title></head>
<?= $this->partial('admin/partials/frame', ['screen' => $screen, 'body' => $body, 'toolbar' => $toolbar, 'data' => $data]) ?>
<?= $this->partial('admin/partials/nav', ['screen' => $screen, 'oob' => true]) ?>
<?php /* The account slot sits outside the frame as well, and is what a save on
   one screen most often changes: a new picture, a new name. Sent to both places
   it is drawn; nothing is sent when the application fills no slot. */ ?>
<?php $account = trim($this->partial('admin/partials/account')) ?>
<?php if ($account !== ''): ?>
<?php foreach (['topbar', 'sidebar'] as $place): ?>
<?= trim($this->partial('admin/partials/account-slot', ['account' => $account, 'place' => $place, 'oob' => true])) ?>
<?php endforeach ?>
<?php endif ?>
