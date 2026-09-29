---
section: Mail
kind: added
---
`Mailer` announces each message a transport accepts as `Hydra\Mail\Events\MessageSent`, carrying the message as sent and the transport's name, when a PSR-14 dispatcher is bound. A send that fails announces nothing, and with no dispatcher bound the mailer is unchanged. The package still stores nothing: an app that wants a log of sent mail listens for the event.
