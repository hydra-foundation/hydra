<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /* What an application supplies: the admin shell, inside its own page. */ ?>
<?php $this->extends('layouts/base') ?>

<?= $this->partial('admin/partials/shell', ['screen' => $screen, 'content' => $this->section('content')]) ?>
