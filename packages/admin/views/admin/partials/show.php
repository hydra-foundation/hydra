<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ShowViewModel $vm */ ?>
<dl class="admin-show row">
    <?php foreach ($vm->fields() as $field): ?>
        <dt class="col-sm-3"><?= $this->e($field->label()) ?></dt>
        <dd class="col-sm-9 text-break type-<?= $this->e($field->type()->value) ?>"><?= $this->e($vm->value($field)) ?></dd>
    <?php endforeach ?>
</dl>

<div class="admin-show-actions d-flex gap-2">
    <?php if ($vm->editUrl() !== null): ?>
        <a class="btn btn-primary"
           href="<?= $this->e($vm->editUrl()) ?>"
           hx-nonce="<?= $this->e($this->cspNonce()) ?>"
           hx-get="<?= $this->e($vm->editUrl()) ?>"
           hx-target="#admin-frame"
           hx-push-url="true">Edit</a>
    <?php endif ?>

    <a class="btn btn-outline-secondary"
       href="<?= $this->e($vm->listUrl()) ?>"
       hx-nonce="<?= $this->e($this->cspNonce()) ?>"
       hx-get="<?= $this->e($vm->listUrl()) ?>"
       hx-target="#admin-frame"
       hx-push-url="true">Back</a>

    <?php foreach ($vm->rowActions() as $index => $button): ?>
        <form class="<?= $index === 0 ? 'ms-auto' : '' ?>"
              method="post"
              action="<?= $this->e($button->url) ?>"
              hx-nonce="<?= $this->e($this->cspNonce()) ?>"
              hx-post="<?= $this->e($button->url) ?>"
              hx-target="#admin-frame"<?php if ($button->prompt !== null): ?>

              hx-confirm="<?= $this->e($button->prompt) ?>"<?php endif ?>>
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn-outline-primary"><?= $this->e($button->label) ?></button>
        </form>
    <?php endforeach ?>

    <?php if ($vm->deleteUrl() !== null): ?>
        <form class="<?= $vm->rowActions() === [] ? 'ms-auto' : '' ?>"
              method="post"
              action="<?= $this->e($vm->deleteUrl()) ?>"
              hx-nonce="<?= $this->e($this->cspNonce()) ?>"
              hx-post="<?= $this->e($vm->deleteUrl()) ?>"
              hx-target="#admin-frame"
              hx-confirm="<?= $this->e($vm->deletePrompt()) ?>">
            <?= $this->csrf() ?>
            <button type="submit" class="btn btn-outline-danger"><?= $this->e($vm->deleteLabel()) ?></button>
        </form>
    <?php endif ?>
</div>
