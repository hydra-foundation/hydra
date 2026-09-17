<!doctype html>
<?php /** @var \Hydra\View\Template $this */ ?>
<?php /** @var \Hydra\Tests\Fixture\Config\CspConfig $csp */ ?>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $this->section('title', 'Fixture') ?></title>
    <?= $this->section('meta', '') ?>
    <?php /* Naming the extension is the whole switch. hx-csp gates htmx on a
       nonce it recovers from the response's policy header, so with no policy
       being sent there is nothing to recover and every swap would be stripped:
       turning CSP off has to turn the gate off with it. */ ?>
    <?php if ($csp->enabled): ?>
    <meta name="htmx-config" content='extensions:"hx-csp",safeEval:true'>
    <?php endif ?>
    <script nonce="<?= $this->e($this->cspNonce()) ?>" src="/js/vendor/htmx.min.js" defer></script>
</head>
<body hx-nonce="<?= $this->e($this->cspNonce()) ?>" hx-headers:inherited='{"X-CSRF-Token": "<?= $this->e($this->csrfToken()) ?>"}'>
    <?php /* Where a failed htmx request lands: the error renderer swaps into it
       out of band, so a refusal is read here instead of replacing whatever the
       reader was working in. */ ?>
    <div id="app-error" role="alert"></div>
    <main><?= $this->section('content') ?></main>
</body>
</html>
