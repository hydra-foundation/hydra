<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ListViewModel $vm */ ?>
<?php $columns = $vm->columns() ?>
<?php $criteria = $vm->page->criteria ?>
<?php /* The list state the toolbar form does not hold inputs for. It renders
   inside the swappable body, where the criteria are current, and the toolbar
   pulls it in with hx-include, so searching or filtering keeps the order and
   the filter link rather than dropping back to the module's defaults. The page
   is deliberately absent: narrowing a list returns to the start of it. */ ?>
<div id="admin-sort-state" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <?php if ($criteria->sort !== null): ?>
        <input type="hidden" name="sort" value="<?= $this->e($criteria->sort) ?>">
        <input type="hidden" name="dir" value="<?= $this->e($criteria->direction) ?>">
    <?php endif ?>
    <?php if ($criteria->view !== null): ?>
        <input type="hidden" name="view" value="<?= $this->e($criteria->view->key()) ?>">
    <?php endif ?>
</div>

<?= $this->partial('admin/partials/links', ['vm' => $vm]) ?>

<div class="table-responsive" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <table class="table table-hover align-middle mb-3">
        <thead>
            <tr>
                <?php foreach ($columns as $field): ?>
                    <?php /* The same type class the cells carry, so a column
                       that aligns one way aligns that way whole: a right-set
                       number under a left-set heading reads as two columns. */ ?>
                    <th scope="col" class="type-<?= $this->e($field->type()->value) ?>">
                        <?php if ($field->isSortable()): ?>
                            <a class="text-decoration-none text-body-emphasis"
                               href="<?= $this->e($vm->sortLink($field)) ?>"
                               hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                               hx-get="<?= $this->e($vm->sortLink($field)) ?>"
                               hx-target="#admin-body"
                               hx-push-url="true">
                                <?= $this->e($field->label()) ?>
                                <?php if ($vm->sortedBy($field) !== null): ?>
                                    <span aria-hidden="true"><?= $vm->sortedBy($field) === 'asc' ? '&uarr;' : '&darr;' ?></span>
                                <?php endif ?>
                            </a>
                        <?php else: ?>
                            <?= $this->e($field->label()) ?>
                        <?php endif ?>
                    </th>
                <?php endforeach ?>
                <?php if ($vm->hasRowActions()): ?>
                    <th scope="col" class="text-end"><span class="visually-hidden">Actions</span></th>
                <?php endif ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vm->page->rows as $row): ?>
                <tr>
                    <?php foreach ($columns as $field): ?>
                        <td class="type-<?= $this->e($field->type()->value) ?>"><?= $this->e($vm->cell($field, $row)) ?></td>
                    <?php endforeach ?>
                    <?php if ($vm->hasRowActions()): ?>
                        <td class="text-end">
                            <div class="btn-group">
                                <?php if ($vm->showUrl($row) !== null): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= $this->e($vm->showUrl($row)) ?>"
                                       hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                                       hx-get="<?= $this->e($vm->showUrl($row)) ?>"
                                       hx-target="#admin-frame"
                                       hx-push-url="true">View</a>
                                <?php endif ?>
                                <?php if ($vm->editUrl($row) !== null): ?>
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="<?= $this->e($vm->editUrl($row)) ?>"
                                       hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                                       hx-get="<?= $this->e($vm->editUrl($row)) ?>"
                                       hx-target="#admin-frame"
                                       hx-push-url="true">Edit</a>
                                <?php endif ?>

                                <?php foreach ($vm->rowActions($row) as $button): ?>
                                    <form class="btn-group"
                                          method="post"
                                          action="<?= $this->e($button->url) ?>"
                                          hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                                          hx-post="<?= $this->e($button->url) ?>"
                                          hx-target="#admin-frame"<?php if ($button->prompt !== null): ?>

                                          hx-confirm="<?= $this->e($button->prompt) ?>"<?php endif ?>>
                                        <?= $this->csrf() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-primary"><?= $this->e($button->label) ?></button>
                                    </form>
                                <?php endforeach ?>

                                <?php if ($vm->deleteUrl($row) !== null): ?>
                                    <?php /* A form, not a link: deleting is a POST, and this still
                                       works when htmx is not the one sending it. It is a btn-group
                                       of its own because Bootstrap joins direct children only, and
                                       the form is what sits between the group and the button. */ ?>
                                    <form class="btn-group"
                                          method="post"
                                          action="<?= $this->e($vm->deleteUrl($row)) ?>"
                                          hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                                          hx-post="<?= $this->e($vm->deleteUrl($row)) ?>"
                                          hx-target="#admin-frame"
                                          hx-confirm="<?= $this->e($vm->deletePrompt()) ?>">
                                        <?= $this->csrf() ?>
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><?= $this->e($vm->deleteLabel()) ?></button>
                                    </form>
                                <?php endif ?>
                            </div>
                        </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>

            <?php if ($vm->page->isEmpty()): ?>
                <tr>
                    <td class="text-center text-body-secondary py-4" colspan="<?= count($columns) + ($vm->hasRowActions() ? 1 : 0) ?>">Nothing to show.</td>
                </tr>
            <?php endif ?>
        </tbody>
    </table>
</div>

<?= $this->partial('admin/partials/pagination', ['vm' => $vm]) ?>
