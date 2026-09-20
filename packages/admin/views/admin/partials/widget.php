<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Widget $widget */ ?>
<?php /** @var string|null $url */ ?>
<?php /** @var array<string, mixed>|null $data null until the card has fetched itself */ ?>
<?php /* One card, in both of its states: the placeholder the grid draws, and
   the filled version that replaces it. The two are one template because they
   are one card — the id, the width and the heading have to survive the swap,
   and a second template is how they stop matching.

   The filled copy carries no hx-get unless the widget asked to refresh, which
   is what ends the exchange after a single round trip. */ ?>
<?php $data ??= null ?>
<?php $loading = $data === null && $url !== null ?>
<?php $polling = $data !== null && $url !== null && $widget->refresh() > 0 ?>
<?php $id = 'admin-widget-' . $widget->key() ?>

<?php /* The nonce is unconditional: it vouches for the card, not for the fetch
   on it, and htmx gates every root it swaps in whether or not that root asks
   for anything. A filled card that does not poll asks for nothing, and tying
   the nonce to the fetch left every one of those refused on arrival. */ ?>
<div class="card admin-widget h-100" id="<?= $this->e($id) ?>"
     hx-nonce="<?= $this->e($this->cspNonce()) ?>"
     <?php if ($loading || $polling): ?>
     hx-get="<?= $this->e((string) $url) ?>"
     hx-trigger="<?= $loading ? 'load' : '' ?><?= $loading && $polling ? ', ' : '' ?><?= $polling ? 'every ' . $this->e((string) $widget->refresh()) . 's' : '' ?>"
     hx-swap="outerHTML"
     <?php endif ?>>
    <div class="card-body">
        <div class="admin-widget-head">
            <h2 class="admin-widget-title">
                <?php if ($widget->icon() !== null): ?><i class="bi bi-<?= $this->e($widget->icon()) ?>" aria-hidden="true"></i><?php endif ?>
                <?= $this->e($widget->title()) ?>
            </h2>
            <?php /* Offered only once there is something to replace: a card
               still fetching itself is already doing what the button asks. */ ?>
            <?php if ($data !== null && $url !== null): ?>
                <?= $this->partial('admin/partials/refresh', [
                    'url' => $url,
                    'target' => '#' . $id,
                    'label' => $widget->title(),
                ]) ?>
            <?php endif ?>
        </div>

        <?php if ($data === null): ?>
            <div class="admin-widget-loading placeholder-glow" aria-hidden="true">
                <span class="placeholder col-7"></span>
                <span class="placeholder col-4"></span>
                <span class="placeholder col-6"></span>
            </div>
            <span class="visually-hidden">Loading <?= $this->e($widget->title()) ?></span>
        <?php else: ?>
            <?= $this->partial($widget->template(), $data) ?>
        <?php endif ?>
    </div>
</div>
