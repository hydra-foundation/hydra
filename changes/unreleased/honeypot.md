---
section: Validation
kind: added
---
Public forms can trap spam bots without a captcha. `Hydra\Csrf\Honeypot` prints a field people never see plus a signed start time, and `rules()` hands back the checks for both: `Rules\Honeypot` (in `validation`) fails when the hidden field has anything in it, and `Rules\SubmittedAfter` (in `csrf`) fails a post sent sooner than three seconds after the page loaded as "too fast", and one older than a day, unsigned or forged as "expired". `error()` picks the message to show above the form. `csrf` now requires `hydrakit/validation`.
