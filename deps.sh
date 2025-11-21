#!/bin/bash

set -e

echo "Installing dependencies..."

export HOMEBREW_NO_AUTO_UPDATE=1
export HOMEBREW_DOWNLOAD_CONCURRENCY=8

brew update >/dev/null 2>&1
brew tap shivammathur/php >/dev/null 2>&1

brew install skopeo shivammathur/php/php >/dev/null 2>&1

[ ! -f /usr/local/bin/composer ] &&
    curl -fSsL https://getcomposer.org/composer.phar -o /usr/local/bin/composer &&
    sudo chown root:wheel /usr/local/bin/composer &&
    sudo chmod +x /usr/local/bin/composer

sudo composer self-update >/dev/null 2>&1

echo "Installed versions:"
echo -n "Skopeo: "
skopeo --version | awk '{print $3}'
echo -n "PHP: "
php -v | head -n 1 | awk '{print $2}'
echo -n "Composer: "
composer --version 2>&1 | head -n 1 | awk '{print $3}'
