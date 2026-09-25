<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Updates\Update $update */ ?>
<?php if ($update->available()): ?>
<?php $label = $this->e($update->newest . ' available' . ($update->security ? ' (security fix)' : '')) ?>
 · <?= $update->notes === null ? $label : '<a href="' . $this->e($update->notes) . '" rel="noopener" target="_blank">' . $label . '</a>' ?>
<?php endif ?>
