---
section: The filesystem
kind: fixed
---
`StorageContractTestCase` no longer fails at random. Its "stored as `.bin`"
case used random bytes, and libmagic reads about one random string in a
hundred as a type it knows. It now uses fixed bytes, so a driver's contract
test passes or fails the same way every time.
