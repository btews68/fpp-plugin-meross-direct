#!/bin/bash

# fpp-plugin-meross-direct uninstall script

FPP_MEDIA_DIR="${MEDIADIR:-${MEDIA_DIR:-/home/fpp/media}}"
FPP_SCRIPTS_DIR="$FPP_MEDIA_DIR/scripts"

rm -f "$FPP_SCRIPTS_DIR/meross_on.sh"
rm -f "$FPP_SCRIPTS_DIR/meross_off.sh"
rm -f "$FPP_SCRIPTS_DIR/meross_dim.sh"

echo "Uninstalling fpp-plugin-meross-direct"
