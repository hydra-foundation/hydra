---
section: Validation
kind: added
---
Public forms can trap spam bots without a captcha. `<?= $this->honeypot() ?>` in a form prints a field people never see plus a signed start time (hidden by the app's `.form-trap` class, so a strict `style-src` still hides it), and `rules()` hands back the checks for both: `Rules\Honeypot` (in `validation`) fails when the hidden field has anything in it, and `Rules\SubmittedAfter` (in `csrf`) fails a post sent sooner than three seconds after the page loaded as "too fast", and one older than a day, unsigned or forged as "expired". `error()` picks the message to show above the form. `PhpView` takes the `Hydra\Csrf\Honeypot` as a new optional `honeypot:` argument. `csrf` now requires `hydrakit/validation`.
