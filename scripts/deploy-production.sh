#!/usr/bin/env bash
#
# SuperApple ERP — production deploy/update script.
#
# Safe, idempotent steps for deploying a NEW release of already-cloned code.
# It contains NO secrets and NO hard-coded passwords. It STOPS on any error.
#
# PRE-REQUISITES (do these once, manually, before the first run):
#   - Code cloned and the correct branch checked out.
#   - A valid production .env exists (APP_ENV=production, APP_DEBUG=false,
#     a real APP_KEY, and the database credentials). See .env.example / docs.
#   - A DATABASE BACKUP has been taken (this script does NOT back up the DB —
#     it needs credentials it must not handle; see docs/BACKUP_RESTORE.md).
#
# USAGE:
#   cd /var/www/superapple
#   bash scripts/deploy-production.sh
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"
echo "▶ Deploying from: $APP_DIR"

# 0) Safety: refuse to run without a production .env.
if [ ! -f .env ]; then
  echo "✗ .env not found. Create it from .env.example first." >&2
  exit 1
fi

# 1) Enter maintenance mode (best-effort; ignore if not yet installed).
php artisan down --render="errors::503" || true

cleanup() { php artisan up || true; }
trap cleanup EXIT

# 2) PHP dependencies (production only — no dev packages).
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 3) Front-end build (only if Node is available on this server; otherwise build
#    in CI and ship public/build). Skips gracefully when npm is absent.
if command -v npm >/dev/null 2>&1; then
  npm ci
  npm run build
else
  echo "ℹ npm not found — assuming public/build was shipped from CI."
fi

# 4) Database migrations (forced, non-interactive). BACKUP FIRST (see above).
php artisan migrate --force

# 5) Rebuild caches with the current code + config.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache || true

# 6) Restart the queue workers so they pick up the new code.
php artisan queue:restart

# 7) Leave maintenance mode (also handled by the EXIT trap).
php artisan up
trap - EXIT

echo "✔ Deploy complete. Now run: bash scripts/verify-production.sh"
