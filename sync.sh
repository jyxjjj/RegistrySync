#!/bin/bash

set -e

if [ -z "$REGISTRY_URL" ]; then
    echo "Error: REGISTRY_URL 环境变量未设置！"
    exit 1
fi

if [ -z "$MESSAGE_ENDPOINT_URL" ]; then
    echo "Error: MESSAGE_ENDPOINT_URL 环境变量未设置！"
    exit 1
fi

if [ -z "$MESSAGE_TOKEN_NAME" ]; then
    echo "Error: MESSAGE_TOKEN_NAME 环境变量未设置！"
    exit 1
fi

if [ -z "$MESSAGE_TOKEN" ]; then
    echo "Error: MESSAGE_TOKEN 环境变量未设置！"
    exit 1
fi

echo "Start sync..."
composer install --no-interaction --no-ansi --no-dev --optimize-autoloader >/dev/null 2>&1
php sync
