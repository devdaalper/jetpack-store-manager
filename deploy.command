#!/bin/bash
set -euo pipefail

# ─────────────────────────────────────────────────────────────────────
# JetPack Store Manager — Deploy Script
# Genera un ZIP limpio listo para subir a WordPress.
#
# Uso:
#   Doble-clic en deploy.command  (macOS)
#   ./deploy.command              (terminal)
#
# Output:
#   dist/jetpack-store-manager.zip
# ─────────────────────────────────────────────────────────────────────

PROJECT_ROOT="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_SRC="${PROJECT_ROOT}/plugin"
DIST_DIR="${PROJECT_ROOT}/dist"
BUILD_DIR="${DIST_DIR}/_build"
PLUGIN_SLUG="jetpack-store-manager"
ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}.zip"

# ─── Validar que existe el código fuente ───────────────────────────
if [[ ! -f "${PLUGIN_SRC}/jetpack-store-manager.php" ]]; then
  echo "ERROR: No se encontró el plugin en ${PLUGIN_SRC}/"
  echo "       Asegúrate de estar en la raíz del proyecto."
  if [ -t 0 ]; then read -r -p "Presiona Enter para cerrar..."; fi
  exit 1
fi

# ─── Preparar directorio de build ─────────────────────────────────
mkdir -p "${DIST_DIR}"
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}/${PLUGIN_SLUG}"

# ─── Copiar archivos del plugin (excluyendo dev-only) ─────────────
rsync -a \
  --exclude "node_modules/" \
  --exclude ".git/" \
  --exclude ".github/" \
  --exclude ".DS_Store" \
  --exclude "**/.DS_Store" \
  --exclude ".env" \
  --exclude ".env.local" \
  --exclude ".env.example" \
  --exclude ".gitignore" \
  --exclude "phpstan.neon" \
  --exclude "phpstan-baseline.neon" \
  --exclude "package.json" \
  --exclude "package-lock.json" \
  --exclude "build.mjs" \
  --exclude "composer.lock" \
  "${PLUGIN_SRC}/" "${BUILD_DIR}/${PLUGIN_SLUG}/"

# ─── Generar ZIP ──────────────────────────────────────────────────
rm -f "${ZIP_PATH}"
export COPYFILE_DISABLE=1

if command -v zip >/dev/null 2>&1; then
  (cd "${BUILD_DIR}" && zip -qr "${ZIP_PATH}" "${PLUGIN_SLUG}")
else
  echo "ERROR: No se encontró ditto ni zip."
  if [ -t 0 ]; then read -r -p "Presiona Enter para cerrar..."; fi
  exit 1
fi

# ─── Limpiar build temporal ───────────────────────────────────────
rm -rf "${BUILD_DIR}"

# ─── Resultado ────────────────────────────────────────────────────
ZIP_SIZE=$(du -h "${ZIP_PATH}" | cut -f1)
echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║  ZIP generado exitosamente                          ║"
echo "╠══════════════════════════════════════════════════════╣"
echo "║                                                      ║"
echo "  Archivo: dist/${PLUGIN_SLUG}.zip"
echo "  Tamaño:  ${ZIP_SIZE}"
echo "║                                                      ║"
echo "║  Para instalar:                                      ║"
echo "║  WordPress → Plugins → Añadir → Subir plugin        ║"
echo "║  Selecciona el ZIP y activa el plugin.               ║"
echo "╚══════════════════════════════════════════════════════╝"
echo ""

# Abrir la carpeta dist en Finder (solo en macOS)
if [[ "${JPSM_NO_OPEN:-0}" != "1" ]]; then
  open "${DIST_DIR}" >/dev/null 2>&1 || true
fi

if [ -t 0 ]; then
  read -r -p "Presiona Enter para cerrar..."
fi
