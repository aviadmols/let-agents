#!/bin/sh
# Starts the container in the role Railway asks for.
#   APP_ROLE=web        Octane on FrankenPHP, listens on $PORT
#   APP_ROLE=worker     queue worker ($QUEUE_NAMES, default "default")
#   APP_ROLE=scheduler  Laravel scheduler
set -eu

cd /app

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

role="${APP_ROLE:-web}"

migrate_with_retry() {
  # The private network and the database can take a few seconds to answer on a cold start.
  attempt=1
  until php artisan migrate --force; do
    if [ "$attempt" -ge 10 ]; then
      echo "Migrations failed after $attempt attempts." >&2
      return 1
    fi
    echo "Migration attempt $attempt failed, retrying in 5 seconds." >&2
    attempt=$((attempt + 1))
    sleep 5
  done
}

case "$role" in
  web)
    # Only the web service migrates, and only when told to, so three services starting at
    # once never race on the same migration.
    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
      migrate_with_retry
    fi

    # First operator on a server with no shell. The password is read from the environment
    # by name, so it never appears in a command line or a log. Existing users are only
    # promoted, so this is safe to leave on for every boot.
    if [ -n "${BOOTSTRAP_OPERATOR_EMAIL:-}" ]; then
      php artisan admin:operator "$BOOTSTRAP_OPERATOR_EMAIL" \
        --password-env=BOOTSTRAP_OPERATOR_PASSWORD \
        --locale="${BOOTSTRAP_OPERATOR_LOCALE:-he}" \
        --no-interaction \
        || echo "Operator bootstrap failed; see the message above." >&2
    fi

    # Report wiring problems in the first lines of the log without blocking the boot.
    php artisan system:check || echo "system:check reported problems; see above." >&2

    exec php artisan octane:frankenphp \
      --host=0.0.0.0 \
      --port="${PORT:-8080}" \
      --max-requests="${OCTANE_MAX_REQUESTS:-500}"
    ;;
  worker)
    exec php artisan queue:work \
      --queue="${QUEUE_NAMES:-default}" \
      --sleep=1 \
      --tries=3 \
      --timeout=1500 \
      --max-time=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    echo "Unknown APP_ROLE: $role (expected web, worker or scheduler)" >&2
    exit 1
    ;;
esac
