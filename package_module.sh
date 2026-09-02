#!/bin/bash
# QCallback Module Packager
# Creates a distributable package for the Queue Callback module

MODULE_NAME="queue_callback_v17"
VERSION="17.0.2"
PACKAGE_NAME="${MODULE_NAME}-${VERSION}-beta"
BUILD_DIR="/tmp/${PACKAGE_NAME}"
SOURCE_DIR="/home/trent/dev/${MODULE_NAME}"

echo "=== QCallback Module Packager ==="
echo "Version: ${VERSION}"
echo ""

# Clean up any previous build
if [ -d "${BUILD_DIR}" ]; then
    echo "Cleaning up previous build..."
    rm -rf "${BUILD_DIR}"
fi

# Create build directory structure
echo "Creating build directory..."
mkdir -p "${BUILD_DIR}/${MODULE_NAME}"

# Copy module files
echo "Copying module files..."
cd "${SOURCE_DIR}"

# Core module files
cp module.xml "${BUILD_DIR}/${MODULE_NAME}/"
cp Qcallback.class.php "${BUILD_DIR}/${MODULE_NAME}/"
cp functions.inc.php "${BUILD_DIR}/${MODULE_NAME}/"
cp hooks.php "${BUILD_DIR}/${MODULE_NAME}/"
cp install.php "${BUILD_DIR}/${MODULE_NAME}/"
cp uninstall.php "${BUILD_DIR}/${MODULE_NAME}/"
cp page.qcallback.php "${BUILD_DIR}/${MODULE_NAME}/"
cp page.qcallback_tab.php "${BUILD_DIR}/${MODULE_NAME}/"
cp process_callbacks.php "${BUILD_DIR}/${MODULE_NAME}/"
cp intelligent_callback_processor.php "${BUILD_DIR}/${MODULE_NAME}/"

# AGI scripts
mkdir -p "${BUILD_DIR}/${MODULE_NAME}/agi-bin"
cp agi-bin/queuecallback-store.agi "${BUILD_DIR}/${MODULE_NAME}/agi-bin/"
cp agi-bin/queuecallback-check.agi "${BUILD_DIR}/${MODULE_NAME}/agi-bin/"
cp agi-bin/queuecallback-complete.agi "${BUILD_DIR}/${MODULE_NAME}/agi-bin/"
cp agi-bin/queuecallback-result.agi "${BUILD_DIR}/${MODULE_NAME}/agi-bin/"

# Views
mkdir -p "${BUILD_DIR}/${MODULE_NAME}/views"
cp views/callback_config.php "${BUILD_DIR}/${MODULE_NAME}/views/"
cp views/callback_config_standalone.php "${BUILD_DIR}/${MODULE_NAME}/views/"
cp views/callback_overview.php "${BUILD_DIR}/${MODULE_NAME}/views/"
cp views/queue_callbacks.php "${BUILD_DIR}/${MODULE_NAME}/views/"
cp views/cron.php "${BUILD_DIR}/${MODULE_NAME}/views/"
cp views/bootnav.php "${BUILD_DIR}/${MODULE_NAME}/views/"

# Set permissions
echo "Setting permissions..."
find "${BUILD_DIR}" -type f -name "*.php" -exec chmod 644 {} \;
find "${BUILD_DIR}" -type f -name "*.agi" -exec chmod 755 {} \;
find "${BUILD_DIR}" -type f -name "*.xml" -exec chmod 644 {} \;

# Create package
echo "Creating package..."
cd /tmp
tar -czf "${PACKAGE_NAME}.tar.gz" "${PACKAGE_NAME}"

# Move package to current directory
mv "${PACKAGE_NAME}.tar.gz" "${SOURCE_DIR}/"

# Clean up
rm -rf "${BUILD_DIR}"

echo ""
echo "=== Package Created ==="
echo "File: ${SOURCE_DIR}/${PACKAGE_NAME}.tar.gz"
echo ""
ls -lh "${SOURCE_DIR}/${PACKAGE_NAME}.tar.gz"
