# Patronr Supervisor configs

This folder holds Supervisor templates for Patronr worker processes.

The goal is to keep local WSL and production Linux using the same process manager model.

Repository folder:

- `deploy/supervisor`

Typical source locations:

- local WSL: `/mnt/c/xampp/htdocs/angavu/deploy/supervisor`
- production: `/home/patronr.com/public_html/deploy/supervisor`

Active long-running workers:

| Program | Transport | Handles |
|---|---|---|
| `patronr-messenger-async` | async | General background jobs |
| `patronr-inbox-relay` | — | M-Pesa external_event_inbox relay |
| `patronr-messenger-payments` | payments | M-Pesa STK / C2B callbacks |
| `patronr-messenger-notifications` | notifications | SMS dispatch, email |
| `patronr-messenger-integrations` | integrations | Webhook fan-out forwarding |

## Worker commands

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-reset
php bin/console app:async:inbox-relay --batch-size=20 --sleep-ms=500
php bin/console messenger:consume payments --time-limit=3600 --memory-limit=256M --no-reset
php bin/console messenger:consume notifications --time-limit=3600 --memory-limit=256M --no-reset
php bin/console messenger:consume integrations --time-limit=3600 --memory-limit=256M --no-reset
```

## Local WSL example

Copy the template into Supervisor's config directory and replace the placeholders.

Suggested WSL values:

- `directory=/mnt/c/xampp/htdocs/angavu`
- `user=buyout_solutions`
- `stdout_logfile=/mnt/c/xampp/htdocs/angavu/var/log/supervisor-async.log`
- `stderr_logfile=/mnt/c/xampp/htdocs/angavu/var/log/supervisor-async-error.log`
- `environment=APP_ENV="wsl"`

Example install flow in WSL:

```bash
sudo cp /mnt/c/xampp/htdocs/angavu/deploy/supervisor/patronr-messenger-async.conf /etc/supervisor/conf.d/
sudo cp /mnt/c/xampp/htdocs/angavu/deploy/supervisor/patronr-inbox-relay.conf /etc/supervisor/conf.d/
sudo cp /mnt/c/xampp/htdocs/angavu/deploy/supervisor/patronr-messenger-payments.conf /etc/supervisor/conf.d/
sudo cp /mnt/c/xampp/htdocs/angavu/deploy/supervisor/patronr-messenger-notifications.conf /etc/supervisor/conf.d/
sudo cp /mnt/c/xampp/htdocs/angavu/deploy/supervisor/patronr-messenger-integrations.conf /etc/supervisor/conf.d/

for f in async inbox-relay payments notifications integrations; do
  sudo sed -i 's|/path/to/patronr|/mnt/c/xampp/htdocs/angavu|g' /etc/supervisor/conf.d/patronr-messenger-${f}.conf 2>/dev/null || true
  sudo sed -i 's|/path/to/patronr|/mnt/c/xampp/htdocs/angavu|g' /etc/supervisor/conf.d/patronr-inbox-relay.conf 2>/dev/null || true
  sudo sed -i 's|your-user|buyout_solutions|g' /etc/supervisor/conf.d/patronr-messenger-${f}.conf 2>/dev/null || true
  sudo sed -i 's|APP_ENV=\"prod\"|APP_ENV=\"wsl\"|g' /etc/supervisor/conf.d/patronr-messenger-${f}.conf 2>/dev/null || true
done
sudo sed -i 's|/path/to/patronr|/mnt/c/xampp/htdocs/angavu|g' /etc/supervisor/conf.d/patronr-inbox-relay.conf
sudo sed -i 's|your-user|buyout_solutions|g' /etc/supervisor/conf.d/patronr-inbox-relay.conf
sudo sed -i 's|APP_ENV=\"prod\"|APP_ENV=\"wsl\"|g' /etc/supervisor/conf.d/patronr-inbox-relay.conf

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start patronr-messenger-async
sudo supervisorctl start patronr-inbox-relay
sudo supervisorctl start patronr-messenger-payments
sudo supervisorctl start patronr-messenger-notifications
sudo supervisorctl start patronr-messenger-integrations
sudo supervisorctl status
```

## Production example

Use the same template with production values.

Typical production changes:

- `directory=/home/patronr.com/public_html`
- `user=patronr.com`
- `stdout_logfile=/home/patronr.com/public_html/var/log/supervisor-async.log`
- `stderr_logfile=/home/patronr.com/public_html/var/log/supervisor-async-error.log`
- `environment=APP_ENV="prod"`

Example production install flow:

```bash
sudo cp /home/patronr.com/public_html/deploy/supervisor/patronr-messenger-async.conf /etc/supervisor/conf.d/
sudo cp /home/patronr.com/public_html/deploy/supervisor/patronr-inbox-relay.conf /etc/supervisor/conf.d/
sudo cp /home/patronr.com/public_html/deploy/supervisor/patronr-messenger-payments.conf /etc/supervisor/conf.d/
sudo cp /home/patronr.com/public_html/deploy/supervisor/patronr-messenger-notifications.conf /etc/supervisor/conf.d/
sudo cp /home/patronr.com/public_html/deploy/supervisor/patronr-messenger-integrations.conf /etc/supervisor/conf.d/

for f in async inbox-relay payments notifications integrations; do
  sudo sed -i 's|/path/to/patronr|/home/patronr.com/public_html|g' /etc/supervisor/conf.d/patronr-messenger-${f}.conf 2>/dev/null || true
  sudo sed -i 's|your-user|patronr.com|g' /etc/supervisor/conf.d/patronr-messenger-${f}.conf 2>/dev/null || true
done
sudo sed -i 's|/path/to/patronr|/home/patronr.com/public_html|g' /etc/supervisor/conf.d/patronr-inbox-relay.conf
sudo sed -i 's|your-user|patronr.com|g' /etc/supervisor/conf.d/patronr-inbox-relay.conf
```

Then load it with:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start patronr-messenger-async
```

## Common commands

```bash
sudo supervisorctl status
sudo supervisorctl status patronr-messenger-async
sudo supervisorctl restart patronr-messenger-async
sudo supervisorctl stop patronr-messenger-async
```

## Notes

- Run `php bin/console messenger:setup-transports` once before starting the worker if the Messenger tables do not exist yet.
- Local WSL should keep using `APP_ENV=wsl` to avoid cache collisions with Windows/XAMPP `APP_ENV=dev`.
- When transport lanes are split later, add new Supervisor programs beside this one rather than changing the pattern.
