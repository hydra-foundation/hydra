<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\ViewModels\ScreenViewModel $screen */ ?>
<?php /* The landing page an admin has before anybody writes one: what this
   visitor may reach, which is the one thing the package knows without asking
   the application. Point a PageScreen at "admin/dashboard" to get it, and
   override it with a file of the same name to say something of your own. */ ?>
<?php $modules = array_values(array_filter($screen->navigation, static fn (array $item): bool => !$item['active'])) ?>

<?php if ($modules === []): ?>
    <p class="text-body-secondary">Nothing here yet. Modules you can reach will appear on this page.</p>
<?php else: ?>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3">
        <?php foreach ($modules as $module): ?>
            <div class="col">
                <div class="card admin-tile h-100">
                    <div class="card-body">
                        <h2 class="card-title h5 mb-1">
                            <a class="stretched-link text-decoration-none text-body-emphasis"
                               href="<?= $this->e($module['url']) ?>"
                               hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                               hx-get="<?= $this->e($module['url']) ?>"
                               hx-target="#admin-frame"
                               hx-push-url="true">
                                <?php if ($module['icon'] !== null): ?><i class="bi bi-<?= $this->e($module['icon']) ?> me-1"></i><?php endif ?>
                                <?= $this->e($module['title']) ?>
                            </a>
                        </h2>
                    </div>
                </div>
            </div>
        <?php endforeach ?>
    </div>
<?php endif ?>
