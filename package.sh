#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MODULE_NAME="qcallback"
VERSION="17.0.4.1_Beta_4"
OUTPUT_DIR="$SCRIPT_DIR/dist"
PACKAGE="$OUTPUT_DIR/${MODULE_NAME}-${VERSION}.tgz"

mkdir -p "$OUTPUT_DIR"

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

mkdir -p "$TMPDIR/$MODULE_NAME"
cp -r \
  "$SCRIPT_DIR/agi-bin" \
  "$SCRIPT_DIR/announcements" \
  "$SCRIPT_DIR/views" \
  "$SCRIPT_DIR/Qcallback.class.php" \
  "$SCRIPT_DIR/functions.inc.php" \
  "$SCRIPT_DIR/freepbx_menu.conf" \
  "$SCRIPT_DIR/hooks.php" \
  "$SCRIPT_DIR/install.php" \
  "$SCRIPT_DIR/intelligent_callback_processor.php" \
  "$SCRIPT_DIR/module.xml" \
  "$SCRIPT_DIR/page.qcallback.php" \
  "$SCRIPT_DIR/page.qcallback_reports.php" \
  "$SCRIPT_DIR/page.qcallback_security.php" \
  "$SCRIPT_DIR/page.qcallback_tab.php" \
  "$SCRIPT_DIR/process_callbacks.php" \
  "$SCRIPT_DIR/uninstall.php" \
  "$SCRIPT_DIR/LICENSE" \
  "$SCRIPT_DIR/README.md" \
  "$TMPDIR/$MODULE_NAME/"

tar -czf "$PACKAGE" -C "$TMPDIR" "$MODULE_NAME"

echo "Packaged: $PACKAGE"
