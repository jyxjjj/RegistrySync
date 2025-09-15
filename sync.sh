#!/bin/bash

set -e

echo "Installing skopeo..."

brew install skopeo

skopeo --version

echo "Start sync..."

echo "Successfully synced all images."
