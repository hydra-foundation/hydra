<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $url the notifications path, such as /admin/notifications */ ?>
<?php /** @var string $place sidebar or topbar: drawn in both, so its ids carry where */ ?>
<?php /* The bell draws itself empty. The badge asks for its count on load, and
   from then on refetches itself when the user's topic is broadcast; it is the
   only part that does, so an open menu is never replaced from under the
   reader. The menu asks for the list each time it opens, since that is when
   somebody wants it and the only time it is certainly current.

   The list request sits on the bell and not on the menu: Bootstrap fires
   show.bs.dropdown on the button, and it bubbles here. A listener on the menu
   would need from:closest .admin-bell, and htmx 4 reads a modifier only up
   to its first space.

   The menu is placed with a fixed strategy because the rail scrolls: placed
   inside it, the menu would be clipped at the rail's edge. */ ?>
<div class="admin-bell dropdown" hx-nonce="<?= $this->e($this->cspNonce()) ?>"
     hx-get="<?= $this->e($url) ?>"
     hx-trigger="show.bs.dropdown"
     hx-target="find .admin-bell-menu"
     hx-swap="innerHTML"><button type="button" class="admin-bell-button"
            data-bs-toggle="dropdown" data-bs-auto-close="outside"
            data-bs-popper-config='{"strategy":"fixed"}'
            aria-expanded="false" aria-label="Notifications">
        <i class="bi bi-bell" aria-hidden="true"></i>
        <span id="admin-bell-badge-<?= $this->e($place) ?>" class="admin-bell-badge"
              hx-nonce="<?= $this->e($this->cspNonce()) ?>"
              hx-get="<?= $this->e($url . '/badge?place=' . $place) ?>"
              hx-trigger="load"
              hx-swap="outerHTML"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end admin-bell-menu"
         hx-nonce="<?= $this->e($this->cspNonce()) ?>">
        <p class="admin-bell-empty">Loading…</p>
    </div>
</div><!-- /admin-bell -->
