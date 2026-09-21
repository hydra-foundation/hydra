<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\FormViewModel $vm */ ?>
<?php /* Posts at frame depth: the Renderer picks its depth from the htmx target,
   and a submit can come back as this form, or as the list once it saved. Both
   carry their own heading and notice, so the frame is the level that fits. */ ?>
<form id="admin-form"
      class="col-12 col-xl-6"
      method="post"
      action="<?= $this->e($vm->action()) ?>"
      hx-nonce="<?= $this->e($this->cspNonce()) ?>"
      hx-post="<?= $this->e($vm->action()) ?>"
      hx-target="#admin-frame"
      hx-swap="innerHTML">
    <?= $this->csrf() ?>

    <?= $this->partial('admin/partials/errors', ['errors' => $vm->formErrors()]) ?>

    <?php foreach ($vm->controls() as $control): ?>
        <?php $id = 'field-' . $control->name() ?>
        <?php $type = $control->type() ?>
        <?php $invalid = $vm->hasError($control->name()) ? ' is-invalid' : '' ?>
        <div class="mb-3">
            <?php if ($type !== \Hydra\Admin\InputType::Checkbox): ?>
                <?php /* A radio group has no one control for a label to point
                   at, so its heading is referred to instead of pointing. */ ?>
                <?php $grouped = $type === \Hydra\Admin\InputType::Radios ?>
                <<?= $grouped ? 'span' : 'label' ?> class="form-label<?= $grouped ? ' d-block' : '' ?>"
                    <?= $grouped ? 'id="' . $this->e($id) . '-label"' : 'for="' . $this->e($id) . '"' ?>>
                    <?= $this->e($control->label()) ?>
                    <?php if ($control->isRequired()): ?>
                        <span class="text-danger" aria-hidden="true">*</span>
                    <?php endif ?>
                </<?= $grouped ? 'span' : 'label' ?>>
            <?php endif ?>

            <?php if ($type === \Hydra\Admin\InputType::Select): ?>
                <select id="<?= $this->e($id) ?>"
                        name="<?= $this->e($control->name()) ?>"
                        class="form-select<?= $invalid ?>"
                        <?= $control->isReadonly() ? 'disabled' : '' ?>>
                    <?php foreach ($control->options() ?? [] as $value => $label): ?>
                        <option value="<?= $this->e($value) ?>"<?= $vm->value($control) === (string) $value ? ' selected' : '' ?>>
                            <?= $this->e($label) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            <?php elseif ($type === \Hydra\Admin\InputType::Radios): ?>
                <div role="radiogroup" aria-labelledby="<?= $this->e($id) ?>-label">
                    <?php $option = 0 ?>
                    <?php foreach ($control->options() ?? [] as $value => $label): ?>
                        <?php /* Numbered rather than named after the value: an
                           option key is a stored column value, and need not be
                           anything an id is allowed to contain. */ ?>
                        <?php $optionId = $id . '-' . $option++ ?>
                        <div class="form-check">
                            <input type="radio"
                                   id="<?= $this->e($optionId) ?>"
                                   name="<?= $this->e($control->name()) ?>"
                                   class="form-check-input<?= $invalid ?>"
                                   value="<?= $this->e($value) ?>"
                                   <?= $vm->value($control) === (string) $value ? 'checked' : '' ?>
                                   <?= $control->isReadonly() ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="<?= $this->e($optionId) ?>">
                                <?= $this->e($label) ?>
                            </label>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php elseif ($type === \Hydra\Admin\InputType::Checkbox): ?>
                <div class="form-check<?= $control->isSwitch() ? ' form-switch' : '' ?>">
                    <?php /* The hidden field is what lets a box be *un*ticked:
                       an unticked checkbox posts nothing at all, so without a
                       value ahead of it in the body a submission could only
                       ever set the flag, never clear it. A readonly control is
                       disabled rather than readonly, which browsers ignore on
                       a checkbox, and the controller drops it either way. */ ?>
                    <?php if (!$control->isReadonly()): ?>
                        <input type="hidden" name="<?= $this->e($control->name()) ?>" value="0">
                    <?php endif ?>
                    <input type="checkbox"
                           id="<?= $this->e($id) ?>"
                           name="<?= $this->e($control->name()) ?>"
                           class="form-check-input<?= $invalid ?>"
                           value="1"
                           <?= $control->isSwitch() ? 'role="switch"' : '' ?>
                           <?= $vm->checked($control) ? 'checked' : '' ?>
                           <?= $control->isReadonly() ? 'disabled' : '' ?>>
                    <label class="form-check-label" for="<?= $this->e($id) ?>">
                        <?= $this->e($control->label()) ?>
                        <?php if ($control->isRequired()): ?>
                            <span class="text-danger" aria-hidden="true">*</span>
                        <?php endif ?>
                    </label>
                </div>
            <?php elseif ($type === \Hydra\Admin\InputType::Textarea): ?>
                <textarea id="<?= $this->e($id) ?>"
                          name="<?= $this->e($control->name()) ?>"
                          class="form-control<?= $invalid ?>"
                          rows="4"
                          <?= $control->isReadonly() ? 'readonly' : '' ?>><?= $this->e($vm->value($control)) ?></textarea>
            <?php else: ?>
                <input type="<?= $this->e($type->value) ?>"
                       id="<?= $this->e($id) ?>"
                       name="<?= $this->e($control->name()) ?>"
                       class="form-control<?= $invalid ?>"
                       value="<?= $this->e($vm->value($control)) ?>"
                       autocomplete="off"
                       <?= $control->isReadonly() ? 'readonly' : '' ?>>
            <?php endif ?>

            <?php if ($vm->hasError($control->name())): ?>
                <span class="invalid-feedback d-block"><?= $this->e($vm->error($control->name())) ?></span>
            <?php elseif ($control->hint() !== null): ?>
                <span class="form-text"><?= $this->e($control->hint()) ?></span>
            <?php endif ?>
        </div>
    <?php endforeach ?>

    <div class="admin-form-actions d-flex gap-2">
        <button type="submit" name="_action" value="save" class="btn btn-primary">Save</button>
        <?php if ($vm->canApply()): ?>
            <button type="submit" name="_action" value="apply" class="btn btn-outline-primary">Apply</button>
        <?php endif ?>
        <a class="btn btn-outline-secondary"
           href="<?= $this->e($vm->cancelUrl()) ?>"
           hx-nonce="<?= $this->e($this->cspNonce()) ?>"
           hx-get="<?= $this->e($vm->cancelUrl()) ?>"
           hx-target="#admin-frame"
           hx-push-url="true">Cancel</a>
    </div>
</form>
