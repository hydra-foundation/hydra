# Hydra Console

The generic verbs every Hydra app needs, lifted out of the app skeleton so 
two projects don't carry two copies. This package ships the *commands*; 
the app owns its `bin/console` entrypoint, wires them to its composition root, 
and adds its own app-specific generators.
