<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $place sidebar or topbar: the bell is drawn in both, and ids must not repeat */ ?>
<?php /** @var int $count */ ?>
<?php /** @var string $url where the badge fetches itself */ ?>
<?php /** @var string|null $topic the user's topic while the admin is live */ ?>
<?php /* The only part of the bell that refetches itself: replacing the whole
   bell would close a dropdown somebody had open. A notice arriving, or one
   read in another tab, is a broadcast on the user's topic, and the badge asks
   again for the count. */ ?>
<span id="admin-bell-badge-<?= $this->e($place) ?>" class="admin-bell-badge"
      hx-nonce="<?= $this->e($this->cspNonce()) ?>"
      <?php if ($topic !== null): ?>
      data-stream="<?= $this->e($topic) ?>"
      hx-get="<?= $this->e($url) ?>"
      hx-trigger="sse:<?= $this->e($topic) ?> delay:300ms"
      hx-swap="outerHTML"
      <?php endif ?>>
    <?php if ($count > 0): ?>
        <span class="admin-bell-count" aria-hidden="true"><?= $count > 99 ? '99+' : $count ?></span>
        <span class="visually-hidden"><?= $count ?> unread notification<?= $count === 1 ? '' : 's' ?></span>
    <?php else: ?>
        <span class="visually-hidden">No unread notifications</span>
    <?php endif ?>
</span>
