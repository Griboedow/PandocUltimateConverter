#!/bin/bash
set -e

MW_INSTALL_DIR="/var/www/html"
DB_PATH="/var/www/data"
SETTINGS_FILE="$MW_INSTALL_DIR/LocalSettings.php"

# Only install once
if [ ! -f "$SETTINGS_FILE" ]; then
    echo "==> Installing MediaWiki..."
    mkdir -p "$DB_PATH"

    php "$MW_INSTALL_DIR/maintenance/install.php" \
        --dbtype sqlite \
        --dbpath "$DB_PATH" \
        --server "http://localhost:8080" \
        --scriptpath "" \
        --lang en \
        --pass "WikiAdmin123!" \
        "TestWiki" \
        "WikiAdmin"

    echo "==> Configuring extension..."
    cat >> "$SETTINGS_FILE" << 'EOLSETTINGS'

# --- PandocUltimateConverter test configuration ---
wfLoadExtension( 'PandocUltimateConverter' );
$wgEnableUploads = true;
$wgFileExtensions = array_merge(
    $wgFileExtensions,
    [ 'doc', 'docx', 'odt', 'pdf', 'txt', 'html', 'md', 'rtf' ]
);
# Show full errors in test environment
$wgShowExceptionDetails = true;
$wgShowDBErrorBacktrace = true;
EOLSETTINGS

    # Fix all file permissions so Apache (www-data) can read them
    chown -R www-data:www-data "$DB_PATH"
    chown -R www-data:www-data "$MW_INSTALL_DIR/images"
    chown www-data:www-data "$SETTINGS_FILE"
    echo "==> MediaWiki installation complete."
fi

exec apache2-foreground
