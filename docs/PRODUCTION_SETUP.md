# Production setup — broadcasting, queue, cache

Practical checklist for deploying FoodKing with real-time (Pusher/Soketi) and background workers.

## 1. Environment (`.env` / `.env.production`)

Copy `.env.example` and set at least:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example

# Broadcasting — required in production (fail-fast boot guard)
BROADCAST_DRIVER=pusher

# Queue — must not be `sync` in production
QUEUE_CONNECTION=redis

# Cache / sessions (Redis recommended at scale)
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Pusher (or Laravel WebSockets / Soketi-compatible host)
PUSHER_APP_ID=your-app-id
PUSHER_APP_KEY=your-key
PUSHER_APP_SECRET=your-secret
PUSHER_HOST=
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1

# If using redis broadcaster (Laravel) instead of Pusher client
# BROADCAST_DRIVER=redis
```

After changes: `php artisan config:cache` and restart PHP-FPM/queue workers.

## 2. Queue workers (systemd)

Run a dedicated worker so jobs (notifications, heavy tasks) are not lost.

Example unit `/etc/systemd/system/foodking-queue.service`:

```ini
[Unit]
Description=FoodKing queue worker
After=network.target redis.service

[Service]
User=www-data
Group=www-data
Restart=always
WorkingDirectory=/var/www/foodking
ExecStart=/usr/bin/php artisan queue:work --queue=high,default --sleep=3 --tries=3 --max-time=3600
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now foodking-queue
```

Adjust `User`, `WorkingDirectory`, and PHP path to match the server.

## 3. Horizon (optional)

If `laravel/horizon` is installed and `QUEUE_CONNECTION=redis`, use Horizon for:

- Dashboard of queues and failed jobs
- Balanced workers per queue

Typical setup: `php artisan horizon` under systemd (same pattern as `queue:work`, but single Horizon process managing workers). Configure `config/horizon.php` for environment-specific supervisors. Do not run both Horizon and duplicate `queue:work` on the same queues without coordination.

## 4. Diagnostics

| Goal | Command |
|------|---------|
| Verbose worker | `php artisan queue:work --verbose` |
| Show broadcasting config | `php artisan config:show broadcasting` |
| Show queue config | `php artisan config:show queue` |
| Redis ping | `redis-cli ping` |
| Redis info | `redis-cli info server` |

If websockets fail, verify `BROADCAST_*` / Pusher credentials, firewall (443), and that `php artisan websockets:serve` or Soketi is running when using self-hosted websockets.

---

**Reminder:** Production boots will throw if `BROADCAST_DRIVER` is missing/`null` or if `QUEUE_CONNECTION` is `sync`. See `AppServiceProvider` guards.
