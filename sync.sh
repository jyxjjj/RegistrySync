#!/bin/bash

set -e

if [ -z "$GITHUB_ACTOR" ]; then
    echo "Error: GITHUB_ACTOR 环境变量未设置！"
    echo "Example: 'GITHUB_ACTOR=jyxjjj'."
    exit 1
fi

if [ -z "$GHCR_TOKEN" ]; then
    echo "Error: GHCR_TOKEN 环境变量未设置！"
    echo "Example: 'GHCR_TOKEN=xxx_xxxxxxxxxxxxxxxx'."
    exit 1
fi

if [ -z "$REGISTRY_URL" ]; then
    echo "Error: REGISTRY_URL 环境变量未设置！"
    echo "Example: 'REGISTRY_URL=registry.example.com', without http/https prefix."
    exit 1
fi

if [ -z "$MESSAGE_ENDPOINT_URL" ]; then
    echo "Error: MESSAGE_ENDPOINT_URL 环境变量未设置！"
    echo "Example: 'MESSAGE_ENDPOINT_URL=https://example.com/api/message'."
    exit 1
fi

if [ -z "$MESSAGE_TOKEN_NAME" ]; then
    echo "Error: MESSAGE_TOKEN_NAME 环境变量未设置！"
    echo "Example: 'MESSAGE_TOKEN_NAME=X-Token'."
    exit 1
fi

if [ -z "$MESSAGE_TOKEN" ]; then
    echo "Error: MESSAGE_TOKEN 环境变量未设置！"
    echo "Example: 'MESSAGE_TOKEN=YOUR_TOKEN_VALUE'."
    exit 1
fi

echo "Installing dependencies..."

export HOMEBREW_NO_AUTO_UPDATE=1
export HOMEBREW_DOWNLOAD_CONCURRENCY=8

brew update
brew tap shivammathur/php

brew install skopeo shivammathur/php/php

[ ! -f /usr/local/bin/composer ] &&
    curl -fSsL https://getcomposer.org/composer.phar -o /usr/local/bin/composer &&
    sudo chown root:wheel /usr/local/bin/composer &&
    sudo chmod +x /usr/local/bin/composer

sudo composer self-update

mkdir -p ./.composer/cache
composer config --cache-dir ./.composer/cache
composer install \
    --no-interaction \
    --prefer-dist \
    --no-dev \
    --optimize-autoloader

echo "Installed versions:"
echo -n "Skopeo: "
skopeo --version | awk '{print $3}'
echo -n "PHP: "
php -v | head -n 1 | awk '{print $2}'
echo -n "Composer: "
composer --version 2>&1 | head -n 1 | awk '{print $3}'
echo "-n Vendor packages: "
composer show

skopeo login ghcr.io -u "${GITHUB_ACTOR}" -p "${GHCR_TOKEN}"

echo "Start sync..."
php sync
skopeo logout ghcr.io
