#!/usr/bin/env python3
"""
Meross Direct Control for Falcon Player.

Usage:
  meross_control.py --list
  meross_control.py <device_uuid_or_alias> <on|off|toggle|level> [value]

Requires: meross-iot (installed by fpp_install.sh)
"""

import asyncio
import json
import logging
import os
import subprocess
import sys
from pathlib import Path

# Redirect all library logging to stderr so stdout stays clean JSON
logging.basicConfig(level=logging.WARNING, stream=sys.stderr)
logging.getLogger().setLevel(logging.WARNING)

PLUGIN_NAME = "fpp-plugin-meross-direct"
PLUGIN_CONFIG = Path("/home/fpp/media/config") / f"plugin.{PLUGIN_NAME}"
PYTHON_LIB_DIR = Path(__file__).resolve().parents[1] / "python_libs"

if PYTHON_LIB_DIR.exists():
    sys.path.insert(0, str(PYTHON_LIB_DIR))


def _try_import_meross():
    try:
        from meross_iot.http_api import MerossHttpClient as _MerossHttpClient  # type: ignore
        from meross_iot.manager import MerossManager as _MerossManager  # type: ignore
        return _MerossHttpClient, _MerossManager
    except ModuleNotFoundError:
        return None, None


def _bootstrap_meross_dependency() -> bool:
    PYTHON_LIB_DIR.mkdir(parents=True, exist_ok=True)
    base = [
        sys.executable,
        "-m",
        "pip",
        "install",
        "--upgrade",
        "--target",
        str(PYTHON_LIB_DIR),
    ]

    def _run_install(args: list[str], timeout_sec: int = 180) -> bool:
        try:
            result = subprocess.run(base + args, capture_output=True, text=True, check=False, timeout=timeout_sec)
            if result.returncode == 0:
                return True
            result = subprocess.run(
                base[:4] + ["--break-system-packages"] + base[4:] + args,
                capture_output=True,
                text=True,
                check=False,
                timeout=timeout_sec,
            )
            return result.returncode == 0
        except Exception:
            return False

    # Avoid meross-iot extras that can trigger slow source builds (brotli on armv7).
    deps_ok = _run_install([
        "aiohttp>=3.8,<4",
        "requests>=2.19.1,<3",
        "paho-mqtt>=2.1.0,<3",
        "pycryptodomex>=3.20.0",
    ])
    if not deps_ok:
        return False

    pkg_ok = _run_install(["--no-deps", "meross-iot"])
    if pkg_ok and str(PYTHON_LIB_DIR) not in sys.path:
        sys.path.insert(0, str(PYTHON_LIB_DIR))
    return pkg_ok


MerossHttpClient, MerossManager = _try_import_meross()
if MerossHttpClient is None or MerossManager is None:
    bootstrapped = _bootstrap_meross_dependency()
    if bootstrapped:
        MerossHttpClient, MerossManager = _try_import_meross()

if MerossHttpClient is None or MerossManager is None:
    print(
        "Missing Python dependency 'meross-iot'. "
        "Run the plugin install script or install manually:\n"
        f"  python3 -m pip install --target {PYTHON_LIB_DIR} meross-iot"
    )
    raise SystemExit(5)

# ── Region → base-URL map ─────────────────────────────────────────────────────
REGION_URLS: dict[str, str] = {
    "us": "https://iotx-us.meross.com",
    "eu": "https://iotx-eu.meross.com",
    "ap": "https://iotx-ap.meross.com",
}

# ── Config helpers ────────────────────────────────────────────────────────────

def read_plugin_config(path: Path) -> dict:
    cfg: dict = {}
    if not path.exists():
        return cfg
    for line in path.read_text(encoding="utf-8", errors="ignore").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        cfg[key.strip()] = value.strip().strip('"').strip("'")
    return cfg


def die(msg: str, code: int = 1) -> None:
    print(msg, file=sys.stderr)
    raise SystemExit(code)


def _norm(value) -> str:
    return "" if value is None else str(value).strip()


def parse_json_setting(cfg: dict, key: str, default):
    raw = cfg.get(key, "").strip()
    if not raw:
        return default
    try:
        return json.loads(raw)
    except json.JSONDecodeError as exc:
        die(f"Invalid JSON in config key {key}: {exc}", 4)


def _safe_int(value, default: int = 0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default

# ── Alias / UUID resolution ───────────────────────────────────────────────────

def resolve_device(
    requested: str,
    default_uuid: str,
    default_channel: int,
    aliases: dict,
) -> tuple[str, int, str]:
    """Return (uuid, channel, alias_used).

    Tries:
      1. requested as alias key (exact, then case-insensitive)
      2. requested as raw UUID
      3. fallback to default_uuid / default_channel
    """
    candidate = _norm(requested)

    if isinstance(aliases, dict):
        # Exact alias key match
        if candidate in aliases:
            entry = aliases[candidate]
            if isinstance(entry, dict):
                return _norm(entry.get("uuid", "")), _safe_int(entry.get("channel", 0)), candidate
            return _norm(entry), 0, candidate

        # Case-insensitive alias key match
        lowered = candidate.lower()
        for alias_key, alias_val in aliases.items():
            if str(alias_key).lower() != lowered:
                continue
            if isinstance(alias_val, dict):
                return _norm(alias_val.get("uuid", "")), _safe_int(alias_val.get("channel", 0)), str(alias_key)
            return _norm(alias_val), 0, str(alias_key)

        # Match by stored device name inside alias entries
        for alias_key, alias_val in aliases.items():
            if not isinstance(alias_val, dict):
                continue
            stored_name = _norm(alias_val.get("name", "")).lower()
            if stored_name and stored_name == candidate.lower():
                return _norm(alias_val.get("uuid", "")), _safe_int(alias_val.get("channel", 0)), str(alias_key)

    # candidate is a raw UUID
    if candidate:
        return candidate, 0, ""

    # Fallback to default
    return _norm(default_uuid), default_channel, ""


# ── Async helpers ─────────────────────────────────────────────────────────────

def _channel_list(device) -> list[dict]:
    """Build a serialisable channel list from a Meross device."""
    raw_channels = getattr(device, "channels", None) or []
    if not raw_channels:
        return [{"index": 0, "name": "main"}]
    result = []
    for idx, ch in enumerate(raw_channels):
        name = ""
        if hasattr(ch, "name"):
            name = ch.name or ""
        elif isinstance(ch, dict):
            name = ch.get("devName") or ch.get("name") or ""
        result.append({"index": idx, "name": name or str(idx)})
    return result


def _online(device) -> str:
    status = getattr(device, "online_status", None)
    if status is None:
        return "unknown"
    return status.name if hasattr(status, "name") else str(status)


def _make_meross_manager(http_client):
    # meross-iot constructor signatures vary across versions.
    try:
        return MerossManager(http_client=http_client)
    except TypeError:
        pass
    try:
        return MerossManager(meross_cloud_client=http_client)
    except TypeError:
        pass
    return MerossManager(http_client)


async def _async_list_devices(email: str, password: str, api_url: str) -> int:
    timeout = max(5, _safe_int(os.environ.get("MEROSS_API_TIMEOUT", "30"), 30))
    manager = None

    try:
        http_client = await asyncio.wait_for(
            MerossHttpClient.async_from_user_password(
                api_base_url=api_url,
                email=email,
                password=password,
            ),
            timeout=timeout,
        )
    except asyncio.TimeoutError:
        die(
            f"Timed out connecting to Meross cloud after {timeout}s. "
            "Check network, DNS, and MEROSS_API_REGION.",
            6,
        )

    try:
        manager = _make_meross_manager(http_client)
        await asyncio.wait_for(manager.async_init(), timeout=timeout)
        await asyncio.wait_for(manager.async_device_discovery(), timeout=timeout)

        devices = manager.find_devices()
        result = []
        for device in devices:
            result.append(
                {
                    "uuid": device.uuid,
                    "name": device.name,
                    "deviceType": device.type,
                    "online": _online(device),
                    "firmwareVersion": getattr(device, "firmware_version", ""),
                    "hardwareVersion": getattr(device, "hardware_version", ""),
                    "channels": _channel_list(device),
                    "supportsLevel": hasattr(device, "async_set_light_color"),
                }
            )

        print(json.dumps({"ok": True, "count": len(result), "devices": result}, indent=2))
        return 0
    except asyncio.TimeoutError:
        die(
            f"Timed out during Meross discovery after {timeout}s. "
            "If this persists, verify account region (us/eu/ap) and internet access from FPP.",
            6,
        )
    finally:
        if manager is not None:
            try:
                await manager.async_stop()
            except Exception:
                pass
        try:
            await http_client.async_logout()
        except Exception:
            pass


async def _async_control(
    email: str,
    password: str,
    api_url: str,
    uuid: str,
    channel: int,
    action: str,
    value: str,
    requested_label: str,
    alias_used: str,
) -> int:
    timeout = max(5, _safe_int(os.environ.get("MEROSS_API_TIMEOUT", "30"), 30))
    manager = None

    try:
        http_client = await asyncio.wait_for(
            MerossHttpClient.async_from_user_password(
                api_base_url=api_url,
                email=email,
                password=password,
            ),
            timeout=timeout,
        )
    except asyncio.TimeoutError:
        die(
            f"Timed out connecting to Meross cloud after {timeout}s. "
            "Check network, DNS, and MEROSS_API_REGION.",
            6,
        )

    try:
        manager = _make_meross_manager(http_client)
        await asyncio.wait_for(manager.async_init(), timeout=timeout)
        await asyncio.wait_for(manager.async_device_discovery(), timeout=timeout)

        devices = manager.find_devices(device_uuids=[uuid])
        if not devices:
            die(f"Device UUID '{uuid}' not found in your Meross account.", 4)

        device = devices[0]
        await asyncio.wait_for(device.async_update(), timeout=timeout)

        result_data: dict = {}

        if action == "on":
            await asyncio.wait_for(device.async_turn_on(channel=channel), timeout=timeout)
            result_data = {"status": "on"}

        elif action == "off":
            await asyncio.wait_for(device.async_turn_off(channel=channel), timeout=timeout)
            result_data = {"status": "off"}

        elif action == "toggle":
            is_on = device.is_on(channel=channel)
            if is_on:
                await asyncio.wait_for(device.async_turn_off(channel=channel), timeout=timeout)
                result_data = {"status": "off", "was": "on"}
            else:
                await asyncio.wait_for(device.async_turn_on(channel=channel), timeout=timeout)
                result_data = {"status": "on", "was": "off"}

        elif action == "level":
            try:
                level = max(0, min(100, int(value)))
            except (TypeError, ValueError):
                die("For action=level, value must be an integer 0-100", 4)

            if not hasattr(device, "async_set_light_color"):
                die(
                    f"Device '{device.name}' ({device.type}) does not support brightness/level control. "
                    "Only smart bulbs and similar devices support the 'level' action.",
                    4,
                )

            await asyncio.wait_for(
                device.async_set_light_color(channel=channel, luminance=level),
                timeout=timeout,
            )
            result_data = {"luminance": level}

        elif action == "status":
            await asyncio.wait_for(device.async_update(), timeout=timeout)
            try:
                is_on = device.is_on(channel=channel)
                result_data["power"] = "on" if is_on else "off"
            except Exception:
                result_data["power"] = "unknown"
            if hasattr(device, "get_light_color"):
                try:
                    color = device.get_light_color(channel=channel)
                    result_data["lightColor"] = color
                except Exception:
                    pass

        else:
            die(f"Unknown action '{action}'. Supported: on, off, toggle, level, status", 2)

        print(
            json.dumps(
                {
                    "ok": True,
                    "requested": requested_label,
                    "uuid": uuid,
                    "channel": channel,
                    "aliasUsed": alias_used,
                    "deviceName": device.name,
                    "deviceType": device.type,
                    "action": action,
                    "result": result_data,
                },
                indent=2,
            )
        )
        return 0
    except asyncio.TimeoutError:
        die(
            f"Timed out executing action '{action}' after {timeout}s. "
            "Verify the device is online and reachable from Meross cloud.",
            6,
        )

    finally:
        if manager is not None:
            try:
                await manager.async_stop()
            except Exception:
                pass
        try:
            await http_client.async_logout()
        except Exception:
            pass


# ── Entry point ───────────────────────────────────────────────────────────────

def main() -> int:
    cfg = read_plugin_config(PLUGIN_CONFIG)

    email = cfg.get("MEROSS_EMAIL", "").strip()
    password = cfg.get("MEROSS_PASSWORD", "").strip()
    default_uuid = cfg.get("MEROSS_DEFAULT_DEVICE_UUID", "").strip()
    default_channel = _safe_int(cfg.get("MEROSS_DEFAULT_CHANNEL", "0"))
    region = cfg.get("MEROSS_API_REGION", "us").strip().lower() or "us"
    api_url = REGION_URLS.get(region, REGION_URLS["us"])
    aliases = parse_json_setting(cfg, "MEROSS_DEVICE_ALIASES", {})

    if not email or not password:
        die(
            f"Missing MEROSS_EMAIL/MEROSS_PASSWORD in {PLUGIN_CONFIG}. "
            "Set plugin configuration values first.",
            3,
        )

    # --list: discover and print all devices
    if len(sys.argv) >= 2 and sys.argv[1] == "--list":
        return asyncio.run(_async_list_devices(email, password, api_url))

    # Strip --channel N from argv before positional parsing
    argv = sys.argv[1:]
    channel_override: int | None = None
    i = 0
    filtered: list[str] = []
    while i < len(argv):
        if argv[i] == '--channel' and i + 1 < len(argv):
            channel_override = _safe_int(argv[i + 1], 0)
            i += 2
        else:
            filtered.append(argv[i])
            i += 1

    if len(filtered) < 2:
        die(
            "Usage:\n"
            "  meross_control.py --list\n"
            "  meross_control.py <device_uuid_or_alias> <on|off|toggle|level|status> [value]",
            2,
        )

    requested = filtered[0].strip()
    action = filtered[1].strip().lower()
    value = filtered[2] if len(filtered) > 2 else ""

    uuid, channel, alias_used = resolve_device(requested, default_uuid, default_channel, aliases)
    if channel_override is not None:
        channel = channel_override

    if not uuid:
        die(
            "No device UUID supplied and MEROSS_DEFAULT_DEVICE_UUID is not configured. "
            f"Check {PLUGIN_CONFIG}.",
            2,
        )

    return asyncio.run(
        _async_control(email, password, api_url, uuid, channel, action, value, requested, alias_used)
    )


if __name__ == "__main__":
    raise SystemExit(main())
