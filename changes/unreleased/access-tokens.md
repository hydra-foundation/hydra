---
section: Authentication
kind: added
---
`ApiTokens::hashOf()` gives the hash the store keeps for a token, or null for a
string that is not a well-formed one. `authenticate()` finds a token by it, and
so can anything else holding a secret and wanting its row, such as an admin
screen handed a leaked token.
