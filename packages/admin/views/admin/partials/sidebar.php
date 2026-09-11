<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /* One nav for both widths: a rail on a wide screen, the drawer the top bar
   opens on a narrow one. Rendering it twice would mean two copies of #admin-nav
   in the document, and the out-of-band swap that marks the current module can
   only reach one of them. */ ?>
<nav id="admin-sidebar" class="admin-sidebar" aria-label="Admin">
    <a class="admin-brand" href="/admin">Hydra</a>

    <?= $this->partial('admin/partials/nav', ['screen' => $screen, 'oob' => false]) ?>

    <form class="admin-signout" hx-post="/logout">
        <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Sign out</button>
    </form>
</nav>
