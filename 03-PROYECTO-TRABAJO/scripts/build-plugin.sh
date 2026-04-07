#!/usr/bin/env bash
set -euo pipefail

# Build a production-ready ZIP for deployment to WordPress.
# - Excludes local/dev-only folders (docs, tests, skills, etc.)
# - Installs Composer deps without dev packages
#
# Usage:
#   ./scripts/build-plugin.sh
# Output:
#   ./dist/mediavault-manager.zip

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="${ROOT_DIR}/dist"
BUILD_DIR="${DIST_DIR}/build"
ZIP_PATH="${DIST_DIR}/mediavault-manager.zip"

mkdir -p "${DIST_DIR}"
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"

PLUGIN_SLUG="jetpack-store-manager"
TARGET_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
mkdir -p "${TARGET_DIR}"

# Copy only the runtime plugin files.
rsync -a --delete \
  --exclude "/.git/" \
  --exclude "/.github/" \
  --exclude "/.phpunit.cache/" \
  --exclude "/_ai_knowledge/" \
  --exclude "/SKILLS/" \
  --exclude "/docs/" \
  --exclude "/apps/" \
  --exclude "/desktop-bpm-app/" \
  --exclude "/tests/" \
  --exclude "/scripts/" \
  --exclude "/node_modules/" \
  --exclude "/debug_index.php" \
  --exclude "/msg" \
  --exclude "/phpunit.xml" \
  --exclude "/AGENTS.md" \
  --exclude "/.DS_Store" \
  --exclude "/dist/" \
  --exclude "/vendor/" \
  --exclude "**/.DS_Store" \
  --exclude "/includes/modules/downloader/" \
  "${ROOT_DIR}/" "${TARGET_DIR}/"

# Install Composer dependencies (production only) into the build output.
if command -v composer >/dev/null 2>&1; then
  if [[ -f "${ROOT_DIR}/composer.json" ]]; then
    (
      cd "${TARGET_DIR}"
      cp "${ROOT_DIR}/composer.json" "${TARGET_DIR}/composer.json"
      cp "${ROOT_DIR}/composer.lock" "${TARGET_DIR}/composer.lock" 2>/dev/null || true
      composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
    )
  fi
else
  echo "ERROR: composer not found. Install Composer or build on a machine that has it." >&2
  exit 1
fi

# Create ZIP
rm -f "${ZIP_PATH}"
(
  cd "${BUILD_DIR}"
  /usr/bin/zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}"
)

echo "Built: ${ZIP_PATH}"
