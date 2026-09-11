# Hydra CSRF

Synchronizer-token CSRF protection: one secret token per session, compared in
constant time against whatever an unsafe request submits. State lives entirely
in the session, so the guard is stateless and the package ships **no**
`ServiceProvider`. The guard autowires from the session and `Signer` bindings.
