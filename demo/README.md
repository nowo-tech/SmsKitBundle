# SMS Kit Bundle demos

## Symfony 8 (FrankenPHP + MySQL)

```bash
cd demo/symfony8
cp .env.example .env   # if needed
make up      # prints "Demo started at: http://localhost:8086"
make setup   # encryption key, schema, demo settings (log provider)
```

- App: http://localhost:8086 (send a message through `SmsSenderInterface`; the log provider writes it, masked, to the log)
- SMS settings: http://localhost:8086/admin/settings/sections/sms (provider, sender, test recipient, encrypted tokens, "Send a test SMS")
- Default `FRANKENPHP_MODE=worker` (change in `.env` and recreate)
- `make test-e2e` / `make demo-screenshots` (Playwright, REQ-DEMO-013)

The demo opens the settings UI without login (`nowo_instance_settings.security.allow_unauthenticated: true`, accepted
only because the demo runs with `kernel.debug`) and uses the **log** provider, so nothing is ever sent. Never copy that
to production.

See [DEMO-FRANKENPHP.md](../docs/DEMO-FRANKENPHP.md).
