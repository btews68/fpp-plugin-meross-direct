#!/bin/bash
# Usage:
#   meross_dim.sh <level_0_to_100> [device_uuid_or_alias]
#
# Examples:
#   meross_dim.sh 50
#   meross_dim.sh 75 Porch
#
# Only works with Meross smart bulbs/dimmers that support brightness.

set -euo pipefail

SOURCE="${BASH_SOURCE[0]}"
while [[ -h "$SOURCE" ]]; do
    DIR="$(cd -P "$(dirname "$SOURCE")" && pwd)"
    SOURCE="$(readlink "$SOURCE")"
    [[ "$SOURCE" != /* ]] && SOURCE="$DIR/$SOURCE"
done
SCRIPT_DIR="$(cd -P "$(dirname "$SOURCE")" && pwd)"
ACTION_SCRIPT="$SCRIPT_DIR/meross_action.sh"

LEVEL="${1:-}"
TARGET=""

# Some FPP UIs may pass args as one string: "50 Porch"
if [[ $# -eq 1 && "$LEVEL" == *" "* ]]; then
    TARGET="${LEVEL#* }"
    LEVEL="${LEVEL%% *}"
else
    TARGET="${*:2}"
fi

if [[ -z "$LEVEL" ]]; then
    echo "Usage: $0 <level_0_to_100> [device_uuid_or_alias]"
    exit 2
fi

if [[ -n "$TARGET" ]]; then
    exec "$ACTION_SCRIPT" "$TARGET" level "$LEVEL"
fi

exec "$ACTION_SCRIPT" level "$LEVEL"
