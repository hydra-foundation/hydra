---
section: Validation
kind: added
---
`Rules\Honeypot` fails when a field people never see has anything in it, the way a bot that fills every input leaves it. Absent and empty both pass. In `csrf`, `Rules\SubmittedAfter` checks a signed start time the form carries: a post sooner than three seconds after the page loaded is "too fast", and one older than a day, unsigned or forged has "expired". `csrf` now requires `hydrakit/validation`.
