# Kiosk

The Raspberry Pi that drives the kitchen display TV. It runs:

- **Chromium in kiosk mode** (`kiosk-browser.sh`), started by the Wayland desktop (labwc, or wayfire on older images). It is restarted automatically if it crashes, and there is no "restore pages" bar after a power cut.
- **Kiosk monitor** (`kiosk_monitor.py`), a service that listens on the Reverb channel `kiosk-control`. It turns the TV on or off over HDMI-CEC and reboots the Pi when "Reboot player" is pressed on the TV control page.

## Status on the TV control page

Every minute, and right after each TV command, the monitor sends a report to `/api/kiosk/heartbeat` using the same token. The report includes:

- whether the browser is running;
- the IP address and uptime;
- what the TV itself answers over CEC (`pow 0`).

The TV control page uses this to show:

- **Offline** after 3 minutes without a report;
- a warning when the TV does not follow the chosen setting, which means CEC is not getting through;
- the exact `cec-client` error when one occurs.

## Install

From `raspberry-pi/` on your machine:

```sh
./deploy.sh kiosk pi@kiosk.local KIOSK_USER=pin-viewer KIOSK_URL=https://keuken.pinoke.net
```

The installer asks for `KIOSK_API_TOKEN`, the same value as in Laravel's `.env`, and stores it in `/etc/kiosk-monitor/api-token`. After that, fill in `KIOSK_REVERB_APP_KEY` in `/etc/kiosk-monitor/kiosk-monitor.env` and reboot.

The desktop user must log in automatically: run `raspi-config` and choose System, Boot / Auto login, Desktop autologin.

## What changed compared to the old setup

- **Invisible mouse cursor.** `unclutter` only works on X11, and Raspberry Pi OS now runs Wayland, so it did nothing. The installer creates a transparent cursor theme (`make_blank_cursor.py`) and selects it for labwc and wayfire.
- **No secrets in the code.** The API token is now a systemd credential. The old token was stored in plain text in the script, so **rotate `KIOSK_API_TOKEN`**.
- **The reboot button works.** Laravel sends `TvStatusUpdated` with status `reboot`, while the old script waited for an event that is never sent. The reboot is allowed by a narrow polkit rule, so `sudo` is not needed.
- **Reconnecting no longer recurses.** The old `on_close` → `connect()` loop grew the stack on every reconnect.
- **The service runs as its own unprivileged user** (`kiosk-monitor`, video group for CEC). The old unit pointed at a different user and path than the setup script created.
- **Updates.** `unattended-upgrades` installs security updates, and a systemd timer reboots at 04:00. This replaces the old cron job that ran `apt-get upgrade -y && reboot`.
- **Firewall.** nftables blocks all inbound traffic except SSH.
