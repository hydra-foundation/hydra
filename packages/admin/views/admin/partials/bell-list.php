<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var list<array{notification: \Hydra\Admin\Notifications\StoredNotification, ago: string}> $items */ ?>
<?php /** @var int $unread */ ?>
<?php /** @var string $readUrl the notifications path, which the forms post under */ ?>
<?php /* The dropdown's contents. Each notice is a plain form: opening it marks it
   read and follows its link, which is a navigation, not a swap. Mark all read
   is the one swap, and brings this list back with nothing unread in it. */ ?>
<div class="admin-bell-list" hx-nonce="<?= $this->e($this->cspNonce()) ?>">
    <?php if ($items === []): ?>
        <p class="admin-bell-empty">No notifications yet.</p>
    <?php else: ?>
        <?php foreach ($items as $item): ?>
            <?php $n = $item['notification'] ?>
            <form class="admin-bell-item<?= $n->isRead() ? '' : ' is-unread' ?>" method="post"
                  action="<?= $this->e($readUrl . '/' . rawurlencode($n->id) . '/read') ?>">
                <?= $this->csrf() ?>
                <button type="submit" class="admin-bell-open">
                    <span class="admin-bell-title"><?= $this->e($n->notice->title) ?></span>
                    <?php if ($n->notice->body !== null): ?>
                        <span class="admin-bell-body"><?= $this->e($n->notice->body) ?></span>
                    <?php endif ?>
                    <time class="admin-bell-time" datetime="<?= $this->e($n->createdAt->format(DATE_ATOM)) ?>"><?= $this->e($item['ago']) ?></time>
                </button>
            </form>
        <?php endforeach ?>
        <?php if ($unread > 0): ?>
            <form class="admin-bell-actions" hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                  hx-post="<?= $this->e($readUrl . '/read-all') ?>"
                  hx-target="closest .admin-bell-list"
                  hx-swap="outerHTML">
                <?= $this->csrf() ?>
                <button type="submit" class="btn btn-sm btn-link">Mark all read</button>
            </form>
        <?php endif ?>
    <?php endif ?>
</div>
