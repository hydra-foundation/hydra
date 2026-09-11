<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var list<string> $errors */ ?>
<?php /* What a source refused that no single control can be blamed for. */ ?>
<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger" role="alert"><?= $this->e($error) ?></div>
<?php endforeach ?>
