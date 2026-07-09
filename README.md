# Hydra PHP-DI

The one place the framework names a concrete dependency-injection engine.

`hydrakit/core` defines the `ContainerInterface` the framework resolves services
through — `get`/`has`/`singleton`/`instance`/`bound` — but names no DI engine.
This package is the default adapter that backs that contract with
[PHP-DI](https://php-di.org/). An app that prefers another engine writes its own
adapter against the same interface, and this package never loads — exactly as
`hydrakit/nyholm` is the swappable PSR-7 adapter.

## Usage

The app's composition root builds the container and hands it to the kernel:

```php
use Hydra\PhpDi\Container;

$container = Container::create();          // wraps a fresh PHP-DI container

Kernel::application($container, $environment)
    ->register(new NyholmServiceProvider)
    ->register(new SignerServiceProvider)
    ->register(new HttpServiceProvider(/* ... */))
    ->register(new AppServiceProvider);
```

`Container::create()` is the common case, so the composition root needn't name
`DI\Container` itself. To tune PHP-DI (definitions, compilation, proxies), build
the underlying container yourself and inject it:

```php
$phpdi = (new \DI\ContainerBuilder())->enableCompilation(__DIR__ . '/cache')->build();
$container = new Container($phpdi);
```

## Binding semantics

PHP-DI caches every resolved entry, so a binding is inherently shared:

- `singleton($abstract, $concrete)` — a class-string is autowired; a callable is
  used as a factory. This is the only binding kind; there is no transient
  counterpart (per the contract's documented YAGNI stance).
- `instance($abstract, $object)` — register an already-constructed object.
- `bound($abstract)` — whether the container can **resolve** the abstract, which
  for an autowiring engine includes any instantiable class, not only explicitly
  registered ones. Use it as a capability check, never as evidence a provider ran.
