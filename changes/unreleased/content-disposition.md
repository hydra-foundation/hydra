---
section: HTTP
kind: added
---
`ContentDisposition::header()` builds an `inline` or `attachment` header with a filename made safe for it: an ASCII `filename=` for old clients and the name as written in RFC 5987 `filename*=`. `Responder::download()` now uses it, with no change to what it sends.
