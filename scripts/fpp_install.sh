#!/bin/bash

# fpp-plugin-meross-direct install script

. ${FPPDIR}/scripts/common

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PY_LIB_DIR="$PLUGIN_DIR/python_libs"
RUN_USER="$(id -un)"
RUN_GROUP="$(id -gn)"
FPP_MEDIA_DIR="${MEDIADIR:-${MEDIA_DIR:-/home/fpp/media}}"
FPP_SCRIPTS_DIR="$FPP_MEDIA_DIR/scripts"

mkdir -p "$PY_LIB_DIR"

if ! touch "$PY_LIB_DIR/.fpp_write_test" >/dev/null 2>&1; then
	echo "python_libs is not writable by $RUN_USER, attempting to fix ownership"
	if [ -n "${SUDO:-}" ]; then
		${SUDO} mkdir -p "$PY_LIB_DIR"
		${SUDO} chown -R "$RUN_USER:$RUN_GROUP" "$PY_LIB_DIR"
	elif command -v sudo >/dev/null 2>&1; then
		sudo mkdir -p "$PY_LIB_DIR"
		sudo chown -R "$RUN_USER:$RUN_GROUP" "$PY_LIB_DIR"
	else
		echo "ERROR: Cannot fix permissions on $PY_LIB_DIR (sudo unavailable)."
		exit 1
	fi
fi

if ! touch "$PY_LIB_DIR/.fpp_write_test" >/dev/null 2>&1; then
	echo "ERROR: $PY_LIB_DIR is still not writable by $RUN_USER after permission fix."
	exit 1
fi
rm -f "$PY_LIB_DIR/.fpp_write_test"

echo "Installing Python dependency: meross-iot"

if python3 -m pip --version >/dev/null 2>&1; then
	PIP_CMD=(python3 -m pip)
elif command -v pip3 >/dev/null 2>&1; then
	PIP_CMD=(pip3)
else
	echo "ERROR: pip is not available. Install python3-pip on FPP and rerun plugin install."
	exit 1
fi

if ! "${PIP_CMD[@]}" install --upgrade --target "$PY_LIB_DIR" meross-iot; then
	# Some systems require this flag due to externally-managed Python policy.
	if ! "${PIP_CMD[@]}" install --break-system-packages --upgrade --target "$PY_LIB_DIR" meross-iot; then
		echo "ERROR: Failed to install meross-iot into $PY_LIB_DIR"
		exit 1
	fi
fi

if ! python3 -c "import sys; sys.path.insert(0, '$PY_LIB_DIR'); from meross_iot.http_api import MerossHttpClient" >/dev/null 2>&1; then
	echo "ERROR: meross_iot import test failed after install."
	exit 1
fi

chmod +x "$PLUGIN_DIR/commands/meross_action.sh"
chmod +x "$PLUGIN_DIR/commands/meross_control.py"
chmod +x "$PLUGIN_DIR/commands/meross_on.sh"
chmod +x "$PLUGIN_DIR/commands/meross_off.sh"
chmod +x "$PLUGIN_DIR/commands/meross_dim.sh"

# Link helper scripts into FPP's scripts directory so they appear in Script dropdowns.
mkdir -p "$FPP_SCRIPTS_DIR"
ln -sf "$PLUGIN_DIR/commands/meross_on.sh"  "$FPP_SCRIPTS_DIR/meross_on.sh"
ln -sf "$PLUGIN_DIR/commands/meross_off.sh" "$FPP_SCRIPTS_DIR/meross_off.sh"
ln -sf "$PLUGIN_DIR/commands/meross_dim.sh" "$FPP_SCRIPTS_DIR/meross_dim.sh"

echo "Installed fpp-plugin-meross-direct"
