#!/bin/bash

set -e

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

echo "Start sync..."
composer install --no-interaction --no-ansi --no-dev --optimize-autoloader
php sync
skopeo logout ghcr.io
