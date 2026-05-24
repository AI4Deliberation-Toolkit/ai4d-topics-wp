#!/usr/bin/env bash
set -euo pipefail

# Build a distribution zip of the AI4D Topics plugin.
# Excludes dev files (tests, composer config, bin scripts, vendor) so the zip
# contains only what WP needs to run the plugin.

VERSION=$(grep -E '^\s*\*\s*Version:' ai4d-topics.php | sed -E 's/.*Version:\s*//')
BUILD_DIR="build/ai4d-topics"
ZIP_FILE="build/ai4d-topics-${VERSION}.zip"

rm -rf "$BUILD_DIR" "$ZIP_FILE"
mkdir -p "$BUILD_DIR"

# Copy only the files needed at runtime.
cp ai4d-topics.php uninstall.php "$BUILD_DIR/"
cp -r includes "$BUILD_DIR/"
cp -r assets "$BUILD_DIR/"
cp -r languages "$BUILD_DIR/" 2>/dev/null || true

# Optional README (helpful for the site operator).
cp README.md "$BUILD_DIR/"

cd build
zip -r "../$ZIP_FILE" "ai4d-topics"

echo "Built: $ZIP_FILE"
