#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p test-results/coverage
rm -f test-results/coverage/php.xml
XDEBUG_MODE=coverage php -d pcov.enabled=0 vendor/bin/phpunit \
    --coverage-clover test-results/coverage/php.xml \
    --coverage-html test-results/coverage/php
php tests/coverage/verificar-php.php
