# Hydra Broadcast

Part of the [Hydra PHP framework](https://hydra.williamhleucka.com). Documentation: [hydra.williamhleucka.com/docs](https://hydra.williamhleucka.com/docs/).

> Read-only mirror. `hydrakit/broadcast` is developed in
> [hydra-foundation/hydra](https://github.com/hydra-foundation/hydra) under
> `packages/broadcast`, and republished here on every push. A commit pushed to
> this repository is overwritten by the next one; issues are disabled for that
> reason, and a pull request opened here cannot be merged. Both belong upstream.

Tell other processes that something changed.
`BroadcasterInterface::publish($topic, $event, $data)` names a topic, such as
`module.users` or `user.7`, and an event, such as `changed`.

## Topics and event names

A topic is dot-separated segments of `a-z`, `0-9`, `_` and `-`, at most 128
characters. An event name is `a-z`, `0-9`, `_`, `.` and `-`, at most 64
characters. `Topic::assertValid()` and `Topic::assertValidEvent()` throw an
`InvalidArgumentException` naming the offender.

## The envelope

What travels is an `Envelope`: the topic, the event, the data, and when it was
published in unix milliseconds, as one JSON object. The encoded envelope is at
most 64 KiB (`Envelope::MAX_BYTES`). A broadcast says what changed, not the
records: anyone allowed to listen on the topic reads the data.
