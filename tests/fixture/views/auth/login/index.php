<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Tests\Fixture\ViewModels\LoginViewModel $vm */ ?>
<?php $this->extends('layouts/base') ?>

<?php $this->start('title') ?>Sign in · Fixture<?php $this->stop() ?>

<div class="auth">
    <h1 class="auth-title">Sign in</h1>
    <?= $this->partial('auth/login/form', ['vm' => $vm]) ?>
</div>
