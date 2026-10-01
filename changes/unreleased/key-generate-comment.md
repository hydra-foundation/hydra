---
section: Console
kind: fixed
---
`key:generate` works on a fresh `cp .env.example .env` again. It took the
inline comment on `APP_KEY=   # Generate with: …` for a key and refused with
"APP_KEY is already set"; it now reads the line by the env loader's own rule
(`Environment::parseValue()`, now public), and keeps the comment when it
writes the key.
