<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $url the notifications path, such as /admin/notifications */ ?>
<?php /** @var string $place sidebar or topbar: drawn in both, so its ids carry where */ ?>
<?php /* The bell draws itself empty. The badge asks for its count on load, and
   from then on refetches itself when the user's topic is broadcast; it is the
   only part that does, so an open menu is never replaced from under the
   reader. The menu asks for the list each time it opens, since that is when
   somebody wants it and the only time it is certainly current. */ ?>
<div class="admin-bell dropdown" hx-nonce="<?= $this->e($this->cspNonce()) ?>"><button type="button" class="admin-bell-button"
            data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications">
        <i class="bi bi-bell" aria-hidden="true"></i>
        <span id="admin-bell-badge-<?= $this->e($place) ?>" class="admin-bell-badge"
              hx-nonce="<?= $this->e($this->cspNonce()) ?>"
              hx-get="<?= $this->e($url . '/badge?place=' . $place) ?>"
              hx-trigger="load"
              hx-swap="outerHTML"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end admin-bell-menu"
         hx-nonce="<?= $this->e($this->cspNonce()) ?>"
         hx-get="<?= $this->e($url) ?>"
         hx-trigger="show.bs.dropdown from:closest .admin-bell"
         hx-swap="innerHTML">
        <p class="admin-bell-empty">Loading…</p>
    </div>
</div><!-- /admin-bell -->
