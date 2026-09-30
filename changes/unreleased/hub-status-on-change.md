---
section: Broadcast
kind: fixed
---
The SSE hub writes its status the moment it loses or regains its Redis subscription, not only every ten seconds, so System Health's Live updates card no longer says Reconnecting for up to ten seconds after the hub has recovered.
