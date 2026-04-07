#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"

PLUGIN_DIR="jetpack-store-manager"
ZIP_NAME="ZIP-PARA-WORDPRESS.zip"

echo "Preparando ZIP del plugin..."

if [ ! -d "$PLUGIN_DIR" ]; then
  echo "ERROR: No se encontró la carpeta '$PLUGIN_DIR'."
  read -r -p "Presiona Enter para cerrar..."
  exit 1
fi

rm -f "$ZIP_NAME" "jetpack-store-manager.zip" "mediavault-manager.zip"
export COPYFILE_DISABLE=1
if command -v ditto >/dev/null 2>&1; then
  ditto -c -k --sequesterRsrc --keepParent "$PLUGIN_DIR" "$ZIP_NAME"
elif [ -x /usr/bin/zip ]; then
  /usr/bin/zip -qr "$ZIP_NAME" "$PLUGIN_DIR"
else
  echo "ERROR: No se encontró herramienta para crear ZIP (ditto/zip)."
  if [ -t 0 ]; then
    read -r -p "Presiona Enter para cerrar..."
  fi
  exit 1
fi

echo ""
echo "LISTO."
echo "Archivo generado:"
echo "$(pwd)/$ZIP_NAME"
echo ""
echo "Sube ese archivo en WordPress > Plugins > Add New > Upload Plugin"
if [ "${JPSM_NO_OPEN:-0}" != "1" ]; then
  open . >/dev/null 2>&1 || true
fi
if [ -t 0 ]; then
  read -r -p "Presiona Enter para cerrar..."
fi
