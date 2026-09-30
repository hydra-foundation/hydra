---
section: The admin
kind: added
---
A dashboard card can carry buttons: `Widget::action('flush', 'Flush', FlushCache::class, confirm: '…')` runs a `ModuleActionInterface` from the card's footer, and the card comes back as itself with what the action said inside it, or with a `WriteRejected` refusal and a 422. The press posts to a route added beside the card's own (`…/w/{widget}/{action}`), is held to the dashboard's ability and the card's own, and is announced as `ActionTaken` named `{widget}.{action}`, so the admin log and audit hear it like any other action. Without htmx it goes back to the dashboard. A name that cannot sit in a URL, a name used twice on one card, or a class that is not a module action is refused where the widget is declared.
