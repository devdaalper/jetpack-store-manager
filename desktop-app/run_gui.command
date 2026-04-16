#!/bin/bash
set -euo pipefail

cd "$(dirname "$0")"
echo "Iniciando JPSM BPM Desktop..."

pick_python() {
  local candidates=(
    "/Library/Frameworks/Python.framework/Versions/3.13/bin/python3.13"
    "/Library/Frameworks/Python.framework/Versions/3.12/bin/python3.12"
    "/Library/Frameworks/Python.framework/Versions/3.11/bin/python3.11"
    "/usr/local/bin/python3.13"
    "/usr/local/bin/python3.12"
    "/usr/local/bin/python3.11"
    "python3.13"
    "python3.12"
    "python3.11"
    "python3"
  )

  local py
  for py in "${candidates[@]}"; do
    if command -v "$py" >/dev/null 2>&1; then
      if "$py" - <<'PY'
import sys
raise SystemExit(0 if sys.version_info >= (3, 11) else 1)
PY
      then
        echo "$py"
        return 0
      fi
    fi
  done
  return 1
}

PY_BIN="$(pick_python || true)"
if [ -z "$PY_BIN" ]; then
  echo "ERROR: Se requiere Python 3.11+."
  echo "Instala Python desde https://www.python.org/downloads/macos/"
  if [ -t 0 ]; then
    read -r -p "Presiona Enter para cerrar..."
  fi
  exit 1
fi

echo "Python detectado: $("$PY_BIN" -V 2>&1)"

if [ ! -d ".venv" ]; then
  echo "Creando entorno virtual..."
  "$PY_BIN" -m venv .venv
fi

source .venv/bin/activate

if ! python - <<'PY'
import importlib.util
mods = ("PySide6", "requests", "keyring", "numpy", "librosa", "soundfile")
raise SystemExit(0 if all(importlib.util.find_spec(m) is not None for m in mods) else 1)
PY
then
  echo "Instalando dependencias..."
  python -m pip install --upgrade pip setuptools wheel
  python -m pip install --prefer-binary -r requirements.txt
else
  echo "Dependencias listas."
fi

echo "Abriendo interfaz..."
PYTHONPATH=src python -m bpm_desktop.main

