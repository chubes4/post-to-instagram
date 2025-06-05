#!/bin/bash
set -e

PLUGIN_SLUG="post-to-instagram"
DIST_DIR="dist"

# Clean previous build
echo "Cleaning previous build..."
rm -rf "$DIST_DIR"
mkdir "$DIST_DIR"

# Copy only production files
echo "Copying production files..."
rsync -av --exclude='node_modules' \
          --exclude='admin/assets/src' \
          --exclude='.git' \
          --exclude='.DS_Store' \
          --exclude='*.log' \
          --exclude='webpack.config.js' \
          --exclude='package.json' \
          --exclude='package-lock.json' \
          --exclude='build-dist.sh' \
          --exclude='dist' \
          --exclude='*.zip' \
          ./ "$DIST_DIR/$PLUGIN_SLUG"

# Zip it up
echo "Zipping plugin..."
cd "$DIST_DIR"
zip -r "${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG"
cd ..

echo "✅ Build complete: $DIST_DIR/${PLUGIN_SLUG}.zip" 