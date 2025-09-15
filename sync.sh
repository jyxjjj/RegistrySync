#!/bin/bash

set -e

if [ -z "$REGISTRY_URL" ]; then
  echo "Error: REGISTRY_URL 环境变量未设置！"
  exit 1
fi

echo "Installing skopeo..."
brew install skopeo
skopeo --version

echo "Start sync..."

DESTINATION_REGISTRY=$REGISTRY_URL

IMAGES=(
    "docker.io library/fedora latest 42"
    "docker.io library/mariadb lts 11.8.2"
    "docker.io library/redis latest 8.2.0"
    "docker.io library/postgres latest 17.5"
    "docker.io dpage/pgadmin4 latest 9.6.0"
    "docker.io openlistteam/openlist latest v4.1.0"
    "docker.io adguard/adguardhome latest v0.107.64"
)

function syncImageTag() {
    REGISTRY=$1
    IMAGE_NAME=$2
    IMAGE_TAG=$3
    echo "================================================================"
    echo -e "\033[34mSyncing $IMAGE_NAME:$IMAGE_TAG...\033[0m"
    skopeo copy \
        --dest-precompute-digests \
        --preserve-digests \
        --retry-times 10 \
        --override-arch amd64 --override-os linux \
        docker://$REGISTRY/$IMAGE_NAME:$IMAGE_TAG docker://$DESTINATION_REGISTRY/$IMAGE_NAME:$IMAGE_TAG
    echo -e "\033[32mSuccessfully synced $IMAGE_NAME:$IMAGE_TAG\033[0m"
    echo "================================================================"
}

function syncImage() {
    REGISTRY=$1
    IMAGE_NAME=$2
    IMAGE_TAG=$3
    IMAGE_VERSION=$4
    if [[ "$IMAGE_TAG" == "NULL" ]]; then
        syncImageTag $REGISTRY $IMAGE_NAME $IMAGE_VERSION
        tagImage $REGISTRY $IMAGE_NAME $IMAGE_VERSION
    else
        syncImageTag $REGISTRY $IMAGE_NAME $IMAGE_TAG
        syncImageTag $REGISTRY $IMAGE_NAME $IMAGE_VERSION
    fi
}

function tagImage() {
    REGISTRY=$1
    IMAGE_NAME=$2
    IMAGE_VERSION=$3
    echo "================================================================"
    echo -e "\033[34mTagging $IMAGE_NAME:$IMAGE_VERSION...\033[0m"
    skopeo copy \
        --dest-precompute-digests \
        --preserve-digests \
        --retry-times 10 \
        --override-arch amd64 --override-os linux \
        docker://$DESTINATION_REGISTRY/$IMAGE_NAME:$IMAGE_VERSION docker://$DESTINATION_REGISTRY/$IMAGE_NAME:latest
    echo -e "\033[32mSuccessfully tagged $IMAGE_NAME:$IMAGE_VERSION\033[0m"
    echo "================================================================"
}

function checkImage() {
    REGISTRY=$1
    IMAGE_NAME=$2
    IMAGE_TAG=$3
    IMAGE_VERSION=$4
    if [[ "$IMAGE_TAG" == "NULL" ]]; then
        echo -e "\033[32m$IMAGE_NAME:$IMAGE_VERSION is up to date\033[0m"
        return 0
    fi
    L=$(skopeo inspect --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_TAG | jq -r '.Digest')
    R=$(skopeo inspect --override-arch amd64 --override-os linux docker://$REGISTRY/$IMAGE_NAME:$IMAGE_VERSION | jq -r '.Digest')
    if [ "$L" == "$R" ]; then
        echo -e "\033[32m$IMAGE_NAME:$IMAGE_VERSION is up to date\033[0m"
        return 0
    fi
    echo -e "\033[31m$IMAGE_NAME:$IMAGE_VERSION is outdated\033[0m"
    echo -e "\033[31m$L != $R\033[0m"
    return 1
}

function checkAndSyncImage() {
    REGISTRY=$1
    IMAGE_NAME=$2
    IMAGE_TAG=$3
    IMAGE_VERSION=$4
    if checkImage $REGISTRY $IMAGE_NAME $IMAGE_TAG $IMAGE_VERSION; then
        syncImage $REGISTRY $IMAGE_NAME $IMAGE_TAG $IMAGE_VERSION
    else
        echo -e "\033[31mFailed to sync $IMAGE_NAME\033[0m"
    fi
}

#region STARTUP
for IMAGE in "${IMAGES[@]}"; do
    # 检查并同步镜像
    IFS=' ' read -r REGISTRY IMAGE_NAME IMAGE_TAG IMAGE_VERSION <<<"$IMAGE"
    checkAndSyncImage $REGISTRY $IMAGE_NAME $IMAGE_TAG $IMAGE_VERSION
done
#endregion

echo "Successfully synced all images."
