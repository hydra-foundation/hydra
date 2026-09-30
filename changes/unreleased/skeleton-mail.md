---
section: The skeleton
kind: added
---
**Administration → Mail** lists every message the app sent, newest first: to, subject, transport and when, searchable by recipient and subject. Each row opens the whole message: from, to, cc, bcc, and both bodies shown as text, so stored HTML is never rendered. **Send a test email**, above the table, sends one message to your own address in the request rather than through the queue: a transport that refuses says why in the notice, and one that works shows up at the top of the list. Messages are recorded in a new `sent_mail` table by a listener on `MessageSent`, which logs and swallows a failed write so a queued email is never sent twice, and a daily `PruneSentMail` task deletes mail sent over thirty days ago. A send that failed is still a failed job, and is not listed here.

### Upgrading

Run the `create_sent_mail_table` migration. Copy `SentMailRepository`, `Listeners/RecordSentMailListener.php`, `MailModule`, `SentMailSource`, `Actions/SendTestEmail.php` and `Tasks/PruneSentMail.php`. In `AppServiceProvider`, listen for `Hydra\Mail\Events\MessageSent` with `RecordSentMailListener` (resolved when the event fires, like the audit listener), register `MailModule` after `RateLimitsModule`, and schedule `PruneSentMail` daily. The recording needs the event dispatcher bound before the mailer is first resolved, which the kernel's `EventServiceProvider` already does.
