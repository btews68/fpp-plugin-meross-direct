#!/bin/bash
# Usage:
#   meross_action.sh [--channel N] <device_uuid_or_alias> <on|off|toggle|level|status> [value]
#   meross_action.sh <on|off|toggle|level|status> [value]
#   meross_action.sh --list
#
# Configuration file:
#   /home/fpp/media/config/plugin.fpp-plugin-meross-direct
#
# Required entries:
#   MEROSS_EMAIL    = you@example.com
#   MEROSS_PASSWORD = yourpassword
#
# Optional entries:
#   MEROSS_DEFAULT_DEVICE_UUID = <uuid>
#   MEROSS_DEFAULT_CHANNEL     = 0
#   MEROSS_API_REGION          = us   # us | eu | ap
#   MEROSS_DEVICE_ALIASES      = {"Porch":{"uuid":"abc123","channel":0}}

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PYTHON_SCRIPT="$SCRIPT_DIR/meross_control.py"

if [[ "${1:-}" == "--list" ]]; then
    python3 "$PYTHON_SCRIPT" --list
    exit 0
fi

# Parse --channel N and collect positional args separately
CHANNEL_ARG=()
POSITIONAL=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --channel)
            CHANNEL_ARG=(--channel "$2")
            shift 2
            ;;
        *)
            POSITIONAL+=("$1")
            shift
            ;;
    esac
done

# Determine DEVICE_ID, ACTION, VALUE from positional args
case "${POSITIONAL[0]:-}" in
    on|off|toggle|level|status)
        DEVICE_ID=""
        ACTION="${POSITIONAL[0]:-}"
        VALUE="${POSITIONAL[1]:-}"
        ;;
    *)
        DEVICE_ID="${POSITIONAL[0]:-}"
        ACTION="${POSITIONAL[1]:-}"
        VALUE="${POSITIONAL[2]:-}"
        ;;
esac

if [[ -z "$ACTION" ]]; then
    echo "Usage: $0 [--channel N] <device_uuid_or_alias> <on|off|toggle|level|status> [value]"
    echo "   or: $0 [--channel N] <on|off|toggle|level|status> [value]"
    echo "   or: $0 --list"
    exit 2
fi

python3 "$PYTHON_SCRIPT" "$DEVICE_ID" "$ACTION" "$VALUE" "${CHANNEL_ARG[@]+"${CHANNEL_ARG[@]}"}"
