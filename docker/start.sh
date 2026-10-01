#!/bin/sh
# Starts one role of the Bookly image. Migrations are NOT run here: on Railway they run once per
# deploy as the web service's pre-deploy command (php artisan migrate --force).
set -e

# Cache config, routes and events from this container's environment variables (fast boot, fewer files read).
php artisan config:cache
php artisan route:cache
php artisan event:cache

case "${CONTAINER_ROLE:-web}" in
  web)
    # public/ is the web root; unknown paths go to public/index.php (Laravel).
    exec frankenphp php-server --root public/ --listen ":${PORT:-8080}"
    ;;
  worker)
    # Emails and alerts. Restarts itself every hour to release memory; the host restarts the container.
    exec php artisan queue:work --tries=3 --backoff=10 --max-time=3600 --sleep=3
    ;;
  scheduler)
    # Runs routes/console.php schedules (daily expired-token cleanup).
    exec php artisan schedule:work
    ;;
  *)
    echo "Unknown CONTAINER_ROLE '${CONTAINER_ROLE}' (use web, worker or scheduler)" >&2
    exit 1
    ;;
esac
