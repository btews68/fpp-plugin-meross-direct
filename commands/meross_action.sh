#!/bin/bash
# Usage:
#   meross_action.sh <device_uuid_or_alias> <on|off|toggle|level|status> [value]
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

ARG1="${1:-}"
ARG2="${2:-}"
ARG3="${3:-}"

if [[ "$ARG1" == "--list" ]]; then
    python3 "$PYTHON_SCRIPT" --list
    exit 0
fi

case "$ARG1" in
    on|off|toggle|level|status)
        DEVICE_ID=""
        ACTION="$ARG1"
        VALUE="$ARG2"
        ;;
    *)
        DEVICE_ID="$ARG1"
        ACTION="$ARG2"
        VALUE="$ARG3"
        ;;
esac

if [[ -z "$ACTION" ]]; then
    echo "Usage: $0 <device_uuid_or_alias> <on|off|toggle|level|status> [value]"
    echo "   or: $0 <on|off|toggle|level|status> [value]"
    echo "   or: $0 --list"
    exit 2
fi

# Forward any --channel N trailing args to the Python script
python3 "$PYTHON_SCRIPT" "$DEVICE_ID" "$ACTION" "$VALUE" "${@}"  # passes leftover args like --channel N
