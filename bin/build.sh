#!/usr/bin/env bash
# Builds shopify-to-fluentcart-migrator.zip for upload through Plugins → Add New.
set -euo pipefail
cd "$(dirname "$0")/.."
slug=shopify-to-fluentcart-migrator
rm -rf "build/$slug" "$slug.zip"
mkdir -p "build/$slug"
cp -R shopify-to-fluentcart-migrator.php uninstall.php readme.txt includes assets languages "build/$slug/"
(cd build && zip -rq "../$slug.zip" "$slug" -x '*.DS_Store')
rm -rf build
echo "Built $slug.zip"
