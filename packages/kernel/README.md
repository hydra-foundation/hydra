# Hydra Kernel

The framework's default composition root and HTTP plumbing. 
It exists to keep the wiring that is identical across every 
Hydra app in **one place**, so a consumer's `AppServiceProvider` 
holds only *policy* and not the boilerplate that used to be 
copied into each app and drift.
