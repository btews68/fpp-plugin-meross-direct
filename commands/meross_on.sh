#!/bin/bash
# Usage:
#   meross_on.sh [device_uuid_or_alias]
#
# If no device/alias is provided, MEROSS_DEFAULT_DEVICE_UUID is used.

set -euo pipefail

SOURCE="${BASH_SOURCE[0]}"
while [[ -h "$SOURCE" ]]; do
    DIR="$(cd -P "$(dirname "$SOURCE")" && pwd)"
    SOURCE="$(readlink "$SOURCE")"
    [[ "$SOURCE" != /* ]] && SOURCE="$DIR/$SOURCE"
done
SCRIPT_DIR="$(cd -P "$(dirname "$SOURCE")" && pwd)"
ACTION_SCRIPT="$SCRIPT_DIR/meross_action.sh"

TARGET="${*:-}"
if [[ "$TARGET" =~ ^\".*\"$ || "$TARGET" =~ ^\'.*\'$ ]]; then
    TARGET="${TARGET:1:-1}"
fi

if [[ -n "$TARGET" ]]; then
    exec bash "$ACTION_SCRIPT" "$TARGET" on
fi

exec bash "$ACTION_SCRIPT" on
