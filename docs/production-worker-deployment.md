# Patronr Production Worker Deployment

This document explains how to run Patronr Messenger workers on production servers without changing the application architecture in a major way.

It is designed for the current project state:

- Symfony Messenger is already installed
- the current active transport is `async`
- the current failure transport is `failed`
- the worker command already works locally under WSL Supervisor
- payment-specific transport and inbox relay configs now exist for new M-Pesa event processing

The goal is to run the same background processing model on production so workers keep running without open SSH terminals.

---

## 1. Current Principle

We are not changing the app in a major way for deployment.

Production should keep the same core behavior:

- web requests are still served normally
- Messenger still handles async jobs
- the worker runs as a long-lived process
- the process manager keeps it alive

At the current stage, production only needs one long-running Messenger worker for:

- `async`

For the new external-event payment flow, production should also run:

- `patronr-inbox-relay`
- `patronr-messenger-payments`

Later, when the transport split is added, production can expand to:

- `patronr-inbox-relay`
- `patronr-messenger-payments`
- `patronr-messenger-notifications`
- `patronr-messenger-integrations`
- `patronr-messenger-maintenance`

But right now we keep it minimal.

---

## 2. Recommended Production Model

Use a Linux process manager to keep the worker running.

Recommended option:

- Supervisor

For hosted environments such as CyberPanel, Supervisor is often the easier practical choice because:

- it is a standard long-running process manager
- it does not require the web app itself to change
- it fits the need of keeping workers alive after deploys and reboots

Rule:

- the worker command stays the same
- local WSL and production can use the same Supervisor model

---

## 3. Current Production Command

At the current project stage, the production worker command is:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-reset
```

Run it from the Patronr project root on the server.

Example:

```bash
cd /home/patronr.com/public_html
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-reset
```

This is the same runtime idea as local WSL, but with the production environment and Linux paths.

---

## 4. One-Time Setup

Before starting the worker, make sure the Messenger transport tables exist.

Run once on production from the project root:

```bash
php bin/console messenger:setup-transports
```

This creates the required Messenger database tables such as `messenger_messages` if they do not already exist.

If the app deploys into a production environment explicitly, use:

```bash
APP_ENV=prod php bin/console messenger:setup-transports
```

---

## 5. Supervisor Setup

This is the recommended path for production if you want a low-change, practical deployment.

### 5.1 Example Supervisor config

The source template should live in the deployed project at:

```text
/home/patronr.com/public_html/deploy/supervisor/patronr-messenger-async.conf
```

Additional templates now live at:

```text
/home/patronr.com/public_html/deploy/supervisor/patronr-inbox-relay.conf
/home/patronr.com/public_html/deploy/supervisor/patronr-messenger-payments.conf
```

Copy it into Supervisor's active config directory:

```text
/etc/supervisor/conf.d/patronr-messenger-async.conf
```

Template config:

```ini
[program:patronr-messenger-async]
directory=/path/to/patronr
command=php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --no-reset
user=your-user
autostart=true
autorestart=true
startsecs=5
stopwaitsecs=30
stdout_logfile=/path/to/patronr/var/log/supervisor-async.log
stderr_logfile=/path/to/patronr/var/log/supervisor-async-error.log
environment=APP_ENV="prod"
```

Replace:

- `/path/to/patronr`
- `your-user`

with the real server values.

For this project, the intended production values are:

- `directory=/home/patronr.com/public_html`
- `user=patronr.com`
- `stdout_logfile=/home/patronr.com/public_html/var/log/supervisor-async.log`
- `stderr_logfile=/home/patronr.com/public_html/var/log/supervisor-async-error.log`

Example server install flow:

```bash
cd /home/patronr.com/public_html
sudo cp /home/patronr.com/public_html/deploy/supervisor/patronr-messenger-async.conf /etc/supervisor/conf.d/patronr-messenger-async.conf
sudo sed -i 's|/path/to/patronr|/home/patronr.com/public_html|g' /etc/supervisor/conf.d/patronr-messenger-async.conf
sudo sed -i 's|your-user|patronr.com|g' /etc/supervisor/conf.d/patronr-messenger-async.conf
```

### 5.2 Load Supervisor config

After creating the config:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start patronr-messenger-async
sudo supervisorctl status
```

Useful commands:

```bash
sudo supervisorctl restart patronr-messenger-async
sudo supervisorctl stop patronr-messenger-async
sudo supervisorctl status patronr-messenger-async
```

---

## 6. Deploy Flow

The deployment flow should stay simple.

Recommended sequence:

1. pull latest code
2. install/update dependencies if needed
3. clear/warm cache if your deploy process does that
4. run database migrations if required
5. run `messenger:setup-transports` if transports are newly introduced
6. restart the Messenger worker process

Example:

```bash
cd /home/patronr.com/public_html
composer install --no-dev --optimize-autoloader
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console messenger:setup-transports
sudo supervisorctl restart patronr-messenger-async
```

## 7. Logging and Monitoring

The worker should always have a visible log source.

With Supervisor:

- `stdout_logfile`
- `stderr_logfile`

Minimum checks after deployment:

- worker process is running
- no immediate crash loop
- Messenger transport tables exist
- queued messages can be consumed

---

## 8. Current Scope vs Future Scope

Current scope:

- one worker
- one async lane

Future scope:

- inbox relay
- payments worker
- notifications worker
- integrations worker
- maintenance worker

When the transport split happens later, the deployment model remains the same.

Only the process list grows.

That means this documentation is intentionally low-change and future-compatible.

---

## 9. Production Notes For This Project

- Local WSL uses `APP_ENV=wsl` to avoid cache collisions with Windows/XAMPP.
- Production should use `APP_ENV=prod`.
- The production worker must run from the real deployed project root.
- Supervisor must be responsible for keeping the worker alive after crashes and server restarts.
- Workers should never depend on an open SSH terminal.

---

## 10. Practical Recommendation

For this project right now:

- use Supervisor in WSL locally
- use Supervisor on production

This keeps the app behavior aligned across environments while avoiding major infrastructure changes.
