#!/usr/bin/env bash
#
# SuperApple ERP — non-destructive production verification.
#
# Runs read-only checks and reports whether the app is healthy. It writes
# nothing to the database and prints no secrets. Exits non-zero if either the
# health check or the integrity check fails.
#
# USAGE:
#   cd /var/www/superapple
#   bash scripts/verify-production.sh
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$APP_DIR"
status=0

echo "===================================================="
echo " 1) Application overview (php artisan about)"
echo "===================================================="
php artisan about || status=1

echo
echo "===================================================="
echo " 2) Environment & accounting health (app:health-check)"
echo "===================================================="
if php artisan app:health-check; then
  echo "✔ health-check passed"
else
  echo "✗ health-check FAILED"; status=1
fi

echo
echo "===================================================="
echo " 3) Data integrity (app:verify-integrity)"
echo "===================================================="
if php artisan app:verify-integrity; then
  echo "✔ verify-integrity passed"
else
  echo "✗ verify-integrity FAILED"; status=1
fi

echo
echo "===================================================="
echo " 4) Scheduled tasks (php artisan schedule:list)"
echo "===================================================="
php artisan schedule:list || status=1

echo
echo "===================================================="
echo " 5) Failed queue jobs (php artisan queue:failed)"
echo "===================================================="
php artisan queue:failed || true

echo
if [ "$status" -eq 0 ]; then
  echo "✔ ALL VERIFICATION CHECKS PASSED."
else
  echo "✗ VERIFICATION FOUND PROBLEMS — do not go live until resolved."
fi
exit "$status"
