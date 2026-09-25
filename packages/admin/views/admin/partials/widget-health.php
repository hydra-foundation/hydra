<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Widgets\Status $status */ ?>
<?php /** @var string $headline */ ?>
<?php /** @var string $caption */ ?>
<?php /** @var list<array{label: string, value: string, tone?: string}> $rows */ ?>
<?php /** @var string|null $note */ ?>
<?php /** @var array{href: string, text: string}|null $link */ ?>
<div class="widget-figures">
    <div>
        <span class="widget-stat <?= $this->e($status->tone()) ?>"><?= $this->e($headline) ?></span>
        <span class="widget-caption"><?= $this->e($caption) ?></span>
    </div>
</div>
<ul class="widget-rows">
    <?php foreach ($rows as $row): ?>
        <li>
            <span class="widget-caption"><?= $this->e($row['label']) ?></span>
            <span class="widget-figure <?= $this->e($row['tone'] ?? '') ?>"><?= $this->e($row['value']) ?></span>
        </li>
    <?php endforeach ?>
</ul>
<?php if ($note !== null): ?>
    <p class="widget-caption widget-foot"><?= $this->e($note) ?></p>
<?php endif ?>
<?php if (($link ?? null) !== null): ?>
    <p class="widget-caption widget-foot"><a href="<?= $this->e($link['href']) ?>" rel="noopener" target="_blank"><?= $this->e($link['text']) ?></a></p>
<?php endif ?>
