#!/bin/sh
# Builds cbee-product-filters.zip, ready to upload via Plugins -> Add New -> Upload.
set -e
cd "$(dirname "$0")"
rm -f cbee-product-filters.zip
zip -qr cbee-product-filters.zip cbee-product-filters
echo "Built cbee-product-filters.zip"
