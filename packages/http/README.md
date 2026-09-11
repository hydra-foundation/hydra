# Hydra HTTP

The request lifecycle: a PSR-15 middleware pipeline wrapping a router, typed 
HTTP errors, and the glue that turns a controller's return value into an emitted 
PSR-7 response. Depends only on PSR-7/-15/-17 interfaces; the concrete request, 
response, and factory implementations are the application's to bind.
