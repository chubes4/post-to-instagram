#!/bin/bash
# Production build script for Post to Instagram WordPress Plugin
# Creates clean production package in /dist with only essential files

set -e

PLUGIN_SLUG="post-to-instagram"
DIST_DIR="dist"
ROOT_DIR="$(pwd)"

echo "🚀 Building $PLUGIN_SLUG for production..."

# Clean previous builds
echo "🧹 Cleaning previous builds..."
rm -rf "$DIST_DIR"
mkdir -p "$DIST_DIR"

# Install production dependencies (if composer.json exists)
if [ -f "composer.json" ]; then
    echo "📦 Installing production dependencies..."
    composer install --no-dev --optimize-autoloader --no-interaction
fi

# Build Gutenberg editor assets
if [ -f "package.json" ]; then
    echo "🔨 Building Gutenberg editor assets..."
    npm install > /dev/null
    npx wp-scripts build inc/Assets/src/js/post-editor.js --output-path=inc/Assets/dist/js/
fi

# Copy files with exclusions from .buildignore
echo "📁 Copying production files..."
if [ -f ".buildignore" ]; then
    # Use rsync with exclude-from for .buildignore patterns
    rsync -av --exclude-from=.buildignore ./ "$DIST_DIR/$PLUGIN_SLUG/"
else
    # Fallback exclusions if .buildignore doesn't exist
    rsync -av --exclude='node_modules' \
              --exclude='vendor' \
              --exclude='admin/assets/src' \
              --exclude='.git' \
              --exclude='.DS_Store' \
              --exclude='.gitignore' \
              --exclude='.cursor' \
              --exclude='*.log' \
              --exclude='webpack.config.js' \
              --exclude='package.json' \
              --exclude='package-lock.json' \
              --exclude='composer.lock' \
              --exclude='build*.sh' \
              --exclude='dist' \
              --exclude='*.zip' \
              --exclude='CLAUDE.md' \
              --exclude='README.md' \
              ./ "$DIST_DIR/$PLUGIN_SLUG/"
fi

# Build validation - ensure essential plugin files exist
echo "✅ Validating build..."
ESSENTIAL_FILES=(
    "$DIST_DIR/$PLUGIN_SLUG/post-to-instagram.php"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Core/Admin.php"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Core/Auth.php"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Core/RestApi.php"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Core/Actions/Post.php"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Core/Actions/Schedule.php"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Core/Actions/Cleanup.php"
    "$DIST_DIR/$PLUGIN_SLUG/auth/oauth-handler.html"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Assets/dist/js/post-editor.js"
    "$DIST_DIR/$PLUGIN_SLUG/inc/Assets/dist/js/post-editor.asset.php"
)

for file in "${ESSENTIAL_FILES[@]}"; do
    if [ ! -f "$file" ]; then
        echo "❌ ERROR: Essential file missing: $file"
        exit 1
    fi
done

# Create ZIP in /dist
echo "📦 Creating production ZIP..."
cd "$DIST_DIR"
zip -r "${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG/" > /dev/null
cd "$ROOT_DIR"

# Restore dev dependencies (if composer.json exists)
if [ -f "composer.json" ]; then
    echo "🔄 Restoring development dependencies..."
    composer install --no-interaction > /dev/null
fi

echo "✅ Production build complete: $DIST_DIR/${PLUGIN_SLUG}.zip"
echo "📊 Build size: $(du -h "$DIST_DIR/${PLUGIN_SLUG}.zip" | cut -f1)"