# Hydra Nyholm

`hydrakit/http` is deliberately free of any PSR-7 vendor. It depends only on the 
PSR interfaces and defines a `ServerRequestProviderInterface` seam for building 
the incoming request from the environment. This package is the default adapter 
that fills that seam with [nyholm/psr7](https://github.com/Nyholm/psr7).
