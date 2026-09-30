<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Admin\Widget $widget */ ?>
<?php /** @var string|null $url */ ?>
<?php /** @var array<string, mixed>|null $data null until the card has fetched itself */ ?>
<?php /** @var \Hydra\Admin\Notice|null $notice what a button on the card just said */ ?>
<?php /* One card, in both of its states: the placeholder the grid draws, and
   the filled version that replaces it. The two are one template because they
   are one card — the id, the width and the heading have to survive the swap,
   and a second template is how they stop matching.

   The filled copy carries no hx-get unless the widget asked to refresh, which
   is what ends the exchange after a single round trip. */ ?>
<?php $data ??= null ?>
<?php $notice ??= null ?>
<?php /** @var bool|null $live whether the admin is live, so a card that listens may */ ?>
<?php $live ??= false ?>
<?php $loading = $data === null && $url !== null ?>
<?php $polling = $data !== null && $url !== null && $widget->refresh() > 0 ?>
<?php /* Only the filled copy listens: the placeholder is already on its way
   to being replaced, and a broadcast then would fetch the card twice. */ ?>
<?php $listening = $data !== null && $url !== null && $live && $widget->topics() !== [] ?>
<?php $triggers = $widget->triggers($loading, $polling, $listening) ?>
<?php $id = 'admin-widget-' . $widget->key() ?>

<?php /* The nonce is unconditional: it vouches for the card, not for the fetch
   on it, and htmx gates every root it swaps in whether or not that root asks
   for anything. A filled card that does not poll asks for nothing, and tying
   the nonce to the fetch left every one of those refused on arrival.

   aria-busy and not aria-live: the card replaces itself outerHTML, so a live
   region declared here is destroyed before the text it would announce arrives.
   busy is read on demand and survives that.

   The shape is on the card and not only on the placeholder, because it says
   what the card holds in both states: a card holding one block wants that block
   to take the height the row turns out to be, rather than sit at its own size
   with air underneath. */ ?>
<div class="card admin-widget h-100 is-<?= $this->e($widget->shape()->value) ?>" id="<?= $this->e($id) ?>"
     role="region" aria-labelledby="<?= $this->e($id) ?>-title"
     aria-busy="<?= $data === null ? 'true' : 'false' ?>"
     hx-nonce="<?= $this->e($this->cspNonce()) ?>"
     <?php if ($listening): ?>
     data-stream="<?= $this->e(implode(' ', $widget->topics())) ?>"
     <?php endif ?>
     <?php if ($triggers !== []): ?>
     hx-get="<?= $this->e((string) $url) ?>"
     hx-trigger="<?= $this->e(implode(', ', $triggers)) ?>"
     hx-swap="outerHTML"
     <?php endif ?>>
    <div class="card-body">
        <div class="admin-widget-head">
            <h2 class="admin-widget-title admin-eyebrow" id="<?= $this->e($id) ?>-title">
                <?php if ($widget->icon() !== null): ?><i class="bi bi-<?= $this->e($widget->icon()) ?>" aria-hidden="true"></i><?php endif ?>
                <?= $this->e($widget->title()) ?>
            </h2>
            <?php /* Offered only once there is something to replace — a card
               still fetching itself is already doing what the button asks — and
               only where the widget asked for one. The page's own refresh is up
               in the masthead; a button per card meant six controls each doing a
               sixth of a job, at an opacity nobody was going to find. */ ?>
            <?php if ($data !== null && $url !== null && $widget->isRefreshable()): ?>
                <?= $this->partial('admin/partials/refresh', [
                    'url' => $url,
                    'target' => '#' . $id,
                    'label' => $widget->title(),
                    'class' => 'admin-widget-refresh',
                ]) ?>
            <?php endif ?>
        </div>

        <?php if ($data === null): ?>
            <?php /* As many bars as the widget reserved, at the stride of the
               shape it declared, so the card is about the height it will be and
               the grid stops settling under the reader as five cards land one
               after another. The shape is what makes the count mean anything:
               five rows of a list and five ranked bars are not the same
               placeholder, and for a while they were. */ ?>
            <div class="admin-widget-loading is-<?= $this->e($widget->shape()->value) ?>" aria-hidden="true">
                <?php for ($line = 0; $line < $widget->reserve(); $line++): ?>
                    <span class="admin-bar"></span>
                <?php endfor ?>
            </div>
            <span class="visually-hidden">Loading <?= $this->e($widget->title()) ?></span>
            <?php if ($url !== null): ?>
                <?= $this->partial('admin/partials/widget-failed', ['url' => $url, 'target' => '#' . $id, 'label' => $widget->title()]) ?>
            <?php endif ?>
        <?php else: ?>
            <?php /* Inside the card, because the card is all a button's answer
               replaces: the page's notice region is outside it and would keep
               whatever it said before. */ ?>
            <?php if ($notice !== null): ?>
                <div class="alert alert-<?= $this->e($notice->style()) ?> admin-widget-notice py-1 px-2 small"
                     role="<?= $this->e($notice->role()) ?>"><?= $this->e($notice->text) ?></div>
            <?php endif ?>
            <?= $this->partial($widget->template(), $data) ?>
            <?php /* Only on the filled card: one still fetching itself shows
               nothing to act on yet. Each button replaces the card with its
               answer; without htmx the form posts and the dashboard comes back. */ ?>
            <?php if ($url !== null && $widget->actions() !== []): ?>
                <?php $base = explode('?', $url, 2)[0] ?>
                <div class="admin-widget-actions d-flex gap-2 mt-3">
                    <?php foreach ($widget->actions() as $action): ?>
                        <?php $target = $base . '/' . rawurlencode($action->name) ?>
                        <form method="post"
                              action="<?= $this->e($target) ?>"
                              hx-nonce="<?= $this->e($this->cspNonce()) ?>"
                              hx-post="<?= $this->e($target) ?>"
                              hx-target="#<?= $this->e($id) ?>"
                              hx-swap="outerHTML"<?php if ($action->confirm !== null): ?>

                              hx-confirm="<?= $this->e($action->confirm) ?>"<?php endif ?>>
                            <?= $this->csrf() ?>
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $this->e($action->label) ?></button>
                        </form>
                    <?php endforeach ?>
                </div>
            <?php endif ?>
        <?php endif ?>
    </div>
</div>
