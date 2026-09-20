#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
BANCO="chat_test_$(date +%s)_$$"
[[ "$BANCO" =~ ^chat_test_[0-9]+_[0-9]+$ ]] || exit 1
sql() {
    docker exec -i chat-mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --protocol=tcp -h127.0.0.1'
}
# CREATE sem IF NOT EXISTS: nunca reutilizar ou apagar uma base preexistente.
sql <<< "CREATE DATABASE $BANCO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
trap 'sql <<< "DROP DATABASE $BANCO;"' EXIT
sql <<< "GRANT ALL PRIVILEGES ON $BANCO.* TO 'chat'@'%';"
docker exec -u sail -w /var/www/html -e DB_CONNECTION=mysql -e DB_DATABASE="$BANCO" -e DB_URL= chat-app php vendor/bin/phpunit --colors=never
