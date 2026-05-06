# fpp-plugin-meross-direct

Direct Meross smart-device control commands for [Falcon Player (FPP)](https://github.com/FalconChristmas/fpp).

Control Meross smart plugs, power strips, and smart bulbs from FPP playlist actions and command presets.

Copyright 2026 Bill Tews
https://holidaypixelzone.com

---

## Requirements

- FPP 9.0 or newer
- Python 3 with pip (`python3-pip` package)
- A Meross account with at least one device added to the app
- Network access to the Meross cloud from your FPP Pi

---

## Installation

Install via the FPP Plugin Manager, or manually:

```bash
cd /home/fpp/media/plugins
git clone https://github.com/btews68/fpp-plugin-meross-direct.git
bash fpp-plugin-meross-direct/scripts/fpp_install.sh
```

The install script downloads the `meross-iot` Python library into `python_libs/` inside the plugin directory and symlinks the helper scripts into `/home/fpp/media/scripts/`.

---

## Configuration

Open **Content Setup → Plugins → Meross – Configuration** in the FPP web UI.

| Field | Description |
|---|---|
| Username / Email | Meross app login email |
| Password | Meross app password |
| API Region | `us` (North America), `eu` (Europe), `ap` (Asia Pacific) |
| Default Device UUID | UUID used when no device is specified in a command |
| Default Channel | Channel index (0 for single-plug devices; 0–3+ for power strips) |
| Friendly Name | Short alias (e.g. `Porch`) mapped to a UUID + channel |

Click **Discover Devices** to fetch device UUIDs from your account and populate the quick-select dropdown.

---

## Config file format

Settings are stored at `/home/fpp/media/config/plugin.fpp-plugin-meross-direct`:

```
MEROSS_EMAIL = you@example.com
MEROSS_PASSWORD = yourpassword
MEROSS_API_REGION = us
MEROSS_DEFAULT_DEVICE_UUID = 1902xxxxxxxxxxxxxxxxxx
MEROSS_DEFAULT_CHANNEL = 0
MEROSS_DEVICE_ALIASES = {"Porch":{"uuid":"1902xx","channel":0,"name":"Outdoor Plug"}}
```

---

## Actions

| Action | Description |
|---|---|
| `on` | Turn device on |
| `off` | Turn device off |
| `toggle` | Toggle current state |
| `level` | Set brightness 0–100 (smart bulbs only) |
| `status` | Print current power state |

---

## Command-line usage

```bash
# Turn on by alias
bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_action.sh Porch on

# Turn off default device
bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_action.sh off

# Dim a smart bulb to 60%
bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_action.sh TreeBulb level 60

# List all devices
bash /home/fpp/media/plugins/fpp-plugin-meross-direct/commands/meross_action.sh --list
```

### Helper scripts (available in FPP Script dropdowns)

```bash
meross_on.sh  [alias_or_uuid]
meross_off.sh [alias_or_uuid]
meross_dim.sh <0-100> [alias_or_uuid]
```

---

## Supported devices

| Model | Type | on/off | level |
|---|---|---|---|
| MSS110 | Smart Plug (mini) | ✓ | — |
| MSS210 | Smart Plug (2-outlet) | ✓ | — |
| MSS310 | Smart Plug (energy monitor) | ✓ | — |
| MSS425 | Power Strip (4 channels) | ✓ (per channel) | — |
| MSS620 | Outdoor Smart Plug | ✓ | — |
| MSL120 | Smart Bulb (RGB) | ✓ | ✓ |

Any device supported by the `meross-iot` library should work for `on`/`off`/`toggle`. The `level` action requires a device that supports `async_set_light_color`.

---

## License

MIT
