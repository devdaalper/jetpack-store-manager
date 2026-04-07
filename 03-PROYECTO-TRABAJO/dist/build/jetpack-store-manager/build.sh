#!/usr/bin/env bash
set -euo pipefail

# Shortcut for producing the production ZIP artifact.
# Output: ./dist/mediavault-manager.zip

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "${DIR}/scripts/build-plugin.sh"
