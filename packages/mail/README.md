# Hydra Mail

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/mail` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/mail`, and republished here on every push. A commit pushed to this
> repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Outgoing mail behind one transport contract. `SmtpTransport` delivers; `LogTransport`
writes each message to the log with its links intact; `ArrayTransport` keeps them
in memory. Only the first sends anything, so tests and local development never do.

```php
$mailer->send(Message::make()
    ->to('ada@example.com', 'Ada')
    ->subject('Reset your password')
    ->text("Follow this link: {$url}")
    ->html("<p><a href=\"{$url}\">Reset your password</a></p>"));
```

A message with no sender gets `MAIL_FROM_ADDRESS`. Every recipient must be
accepted before the message is sent, so a refused address fails the whole send
rather than delivering to some recipients. TLS is never skipped: with
`MAIL_ENCRYPTION=tls` a server that does not offer STARTTLS is an error, and
credentials are refused outright with `MAIL_ENCRYPTION=none`.

| Setting | Default | |
|---|---|---|
| `MAIL_TRANSPORT` | `smtp` | `smtp`, `log` or `array` |
| `MAIL_HOST` | | required for `smtp` |
| `MAIL_PORT` | `587` | |
| `MAIL_ENCRYPTION` | `tls` | `tls` (STARTTLS), `ssl` (implicit TLS) or `none` |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | | AUTH PLAIN, or LOGIN when PLAIN is not offered |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | | the default sender |
| `MAIL_TIMEOUT` | `10` | seconds, for the connection and each reply |
| `MAIL_EHLO_DOMAIN` | `localhost` | the name this client greets the server with |

In tests, register `Testing\FakeMailServiceProvider` after `MailServiceProvider`
and assert on the `FakeMailer` it binds:

```php
$mail = $container->get(FakeMailer::class);
$mail->assertSentTo('ada@example.com');
$mail->assertSent(fn (Message $m) => $m->getSubject() === 'Reset your password', times: 1);
```

Not yet: attachments, custom headers, and SMTPUTF8 (addresses are ASCII; names
and subjects may be anything).
