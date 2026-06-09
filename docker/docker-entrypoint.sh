#!/bin/sh
# If either process becomes unreliable or needs auto-restart, switch to supervisord.
set -e

php-fpm &
PHP_FPM_PID=$!

trap "kill $PHP_FPM_PID; exit" SIGTERM SIGINT

nginx -g "daemon off;"

kill $PHP_FPM_PID
