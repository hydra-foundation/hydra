# Hydra Core

The framework-agnostic foundation: the application object, the contracts every
other package depends on, environment loading, and a base service provider.
Core defines interfaces and orchestration. It ships no concrete container,
kernel, or HTTP layer. Those are bound by the application at runtime.
