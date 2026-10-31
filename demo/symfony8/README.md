# SMS Kit Bundle – Symfony 8 demo

Symfony 8.1 application on FrankenPHP (worker mode by default) and MySQL showing `nowo-tech/sms-kit-bundle` on top of
`nowo-tech/instance-settings-bundle`:

- the `sms` instance settings section contributed by the bundle (provider, sender, test recipient, Twilio / SMS Bridge
  credentials encrypted with DoctrineEncryptBundle) and its **Send a test SMS** action;
- the home page sending a message through `SmsSenderInterface` (FormKit form), with the result and the masked number;
- the **log** provider preselected by the seed: messages are written (masked, without the body) to the log — open the
  Symfony profiler to see them — and nothing leaves the container.

## Setup

```bash
make up      # containers + composer install, prints "Demo started at: http://localhost:8086"
make setup   # key, schema (doctrine:schema:update — demo only, use migrations in an application), seed
```

## Commands

- `php bin/console app:demo:seed` – seed demo settings (idempotent)
- `php bin/console nowo:sms:send +15550100123 "Hello"` – send through the active provider
- `make test` – `lint:container` + `lint:twig`
- `make test-e2e` / `make demo-screenshots` – Playwright (REQ-DEMO-013)
