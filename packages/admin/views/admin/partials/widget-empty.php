<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var string $message */ ?>
<?php /* A card with nothing in it, said deliberately. Set the period to today
   on a quiet morning and half the dashboard is empty; without a treatment of
   its own, an empty card is a heading, one grey sentence and a large void,
   which reads as a fetch that failed rather than as an answer. */ ?>
<p class="widget-empty"><?= $this->e($message) ?></p>
