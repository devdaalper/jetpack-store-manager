#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  security_gate.sh [--build] [--zip <path>] [--repo-only] [--zip-only]

Runs high-signal security checks to prevent shipping secrets/PII and dev artifacts.

Recommended pre-release:
  ./build.sh
  bash SKILLS/mediavault-manager-security-gate/scripts/security_gate.sh

Options:
  --build       Build the dist ZIP before scanning (calls ./build.sh).
  --zip PATH    Scan a specific ZIP (default: newest dist/*.zip if present).
  --repo-only   Only scan the working tree (skip ZIP scan).
  --zip-only    Only scan the ZIP (skip repo scan).
EOF
}

ZIP_PATH=""
DO_BUILD=0
REPO_ONLY=0
ZIP_ONLY=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --zip)
      ZIP_PATH="${2:-}"
      shift 2
      ;;
    --build)
      DO_BUILD=1
      shift
      ;;
    --repo-only)
      REPO_ONLY=1
      shift
      ;;
    --zip-only)
      ZIP_ONLY=1
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "[ERROR] Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../.." && pwd)"

if [[ "${DO_BUILD}" -eq 1 ]]; then
  if [[ ! -x "${REPO_ROOT}/build.sh" ]]; then
    echo "[ERROR] build.sh not found or not executable at: ${REPO_ROOT}/build.sh" >&2
    exit 2
  fi
  (cd "${REPO_ROOT}" && ./build.sh)
fi

SCAN_TREE_PY="${SCRIPT_DIR}/scan_tree.py"
SCAN_ZIP_PY="${SCRIPT_DIR}/scan_zip.py"

status=0

if [[ "${ZIP_ONLY}" -ne 1 ]]; then
  if ! python3 "${SCAN_TREE_PY}" --root "${REPO_ROOT}"; then
    status=1
  fi
fi

if [[ "${REPO_ONLY}" -ne 1 ]]; then
  if [[ -z "${ZIP_PATH}" ]]; then
    # Pick newest dist ZIP if present.
    ZIP_PATH="$(ls -t "${REPO_ROOT}/dist/"*.zip 2>/dev/null | head -n 1 || true)"
  else
    # Allow relative paths.
    if [[ "${ZIP_PATH}" != /* ]]; then
      ZIP_PATH="${REPO_ROOT}/${ZIP_PATH}"
    fi
  fi

  if [[ -z "${ZIP_PATH}" ]]; then
    echo "[ERROR] No ZIP found to scan. Build one first (./build.sh) or pass --zip <path>." >&2
    exit 2
  fi

  if ! python3 "${SCAN_ZIP_PY}" --zip "${ZIP_PATH}"; then
    status=1
  fi
fi

if [[ "${status}" -eq 0 ]]; then
  echo "[OK] security_gate: passed"
else
  echo "[FAIL] security_gate: failed (see findings above)" >&2
fi

exit "${status}"

