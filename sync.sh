#!/bin/bash

set -e

if [ -z "$REGISTRY_URL" ]; then
    echo "Error: REGISTRY_URL 环境变量未设置！"
    exit 1
fi

echo "Installing dependencies..."
export HOMEBREW_NO_AUTO_UPDATE=1
export HOMEBREW_DOWNLOAD_CONCURRENCY=8
brew update >/dev/null 2>&1
# brew upgrade >/dev/null 2>&1
brew tap shivammathur/php >/dev/null 2>&1
brew install skopeo shivammathur/php/php@8.4 >/dev/null 2>&1
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1

echo "Installed versions:"
echo -n "Skopeo: "
skopeo --version | awk '{print $3}'
echo -n "PHP: "
php -v | head -n 1 | awk '{print $2}'
echo -n "Composer: "
composer --version 2>&1 | head -n 1 | awk '{print $3}'

echo "Start sync..."
composer install --no-interaction --no-ansi --no-dev --optimize-autoloader >/dev/null 2>&1
php sync
