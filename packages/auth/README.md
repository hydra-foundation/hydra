# Hydra Auth

Authentication. Identity only. It answers *who is logged in* and provides the
verbs to change that (attempt/login/logout), behind a swappable guard. It does
not do permissions: those are `hydrakit/authorization`. It does not know your
users: that one contract is left for the app to fulfil.
