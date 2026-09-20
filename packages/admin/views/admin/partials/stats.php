<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var list<array{label: string, value: int|string, icon?: string|null, caption?: string|null}> $stats */ ?>
<?php /* The body of a summary strip: grand totals, one figure each. Shipped so
   that an application supplying the numbers does not also have to draw them. */ ?>
<?php if ($stats === []): ?>
    <p class="text-body-secondary mb-0">Nothing to total yet.</p>
<?php else: ?>
    <ul class="admin-stats">
        <?php foreach ($stats as $stat): ?>
            <li class="admin-stat">
                <?php if (($stat['icon'] ?? null) !== null): ?>
                    <i class="bi bi-<?= $this->e($stat['icon']) ?> admin-stat-icon" aria-hidden="true"></i>
                <?php endif ?>
                <span class="admin-stat-figure"><?= $this->e((string) $stat['value']) ?></span>
                <span class="admin-stat-label"><?= $this->e($stat['label']) ?></span>
                <?php if (($stat['caption'] ?? null) !== null): ?>
                    <span class="admin-stat-caption"><?= $this->e($stat['caption']) ?></span>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
