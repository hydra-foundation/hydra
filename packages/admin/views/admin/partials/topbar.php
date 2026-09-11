<?php /** @var \Hydra\View\Template $this */ ?>
<?php /* The narrow-screen counterpart to the sidebar, which at that width is a
   drawer rather than a rail. It carries no screen name of its own: only the
   frame is swapped on navigation, so anything stateful here would go stale
   while the breadcrumb directly below it stayed right. */ ?>
<div class="admin-topbar">
    <button class="admin-toggle" type="button"
            aria-controls="admin-sidebar"
            aria-expanded="false"
            aria-label="Open navigation">
        <i class="bi bi-list" aria-hidden="true"></i>
    </button>

    <a class="admin-brand" href="/admin">Hydra</a>
</div>

<?php /* Sits outside the drawer so a tap anywhere off it closes it. It stays in
   the document at all widths and is taken out of reach by pointer-events, so
   there is no open/closed state here for the stylesheet to disagree with. */ ?>
<div class="admin-scrim"></div>
