<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var list<array{label: string, figure: string, share: int|null, caption: string, tone: string}> $gauges */ ?>
<?php if ($gauges === []): ?>
    <?= $this->partial('admin/partials/widget-empty', ['message' => 'Nothing here reports a limit.']) ?>
<?php else: ?>
    <ul class="widget-bars">
        <?php foreach ($gauges as $gauge): ?>
            <li>
                <div class="widget-bar-head">
                    <code><?= $this->e($gauge['label']) ?></code>
                    <span class="widget-figure <?= $this->e($gauge['tone']) ?>"><?= $this->e($gauge['figure']) ?></span>
                </div>
                <?php /* A share of nothing draws an empty track, which reads as
                   a gauge at zero rather than as one that does not apply. */ ?>
                <?php if ($gauge['share'] !== null): ?>
                    <progress class="widget-bar <?= $this->e($gauge['tone']) ?>" max="100"
                              value="<?= $this->e((string) $gauge['share']) ?>"
                              aria-hidden="true"></progress>
                <?php endif ?>
                <p class="widget-caption"><?= $this->e($gauge['caption']) ?></p>
            </li>
        <?php endforeach ?>
    </ul>
<?php endif ?>
