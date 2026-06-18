#!/usr/bin/env bash
# EAPMS — start all services via yner_main (Linux/macOS)
cd "$(dirname "$0")/yner_main"
php artisan eapms:start "$@"
