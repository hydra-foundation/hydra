# Hydra Session

A request-scoped key/value store that persists across requests for one client, 
backed by native `$_SESSION` behind two deliberately split interfaces. The data 
surface goes to controllers; the lifecycle surface is held only by the 
framework's session middleware.
