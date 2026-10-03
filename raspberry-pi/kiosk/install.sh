#!/bin/sh
# Installs the kiosk on Raspberry Pi OS (Bookworm or newer, Wayland desktop with auto-login).
# Normally run by ../deploy.sh; by hand: sudo ./install.sh
#
#   KIOSK_USER=pin-viewer KIOSK_URL=https://keuken.pinoke.net sudo -E ./install.sh
#
# KIOSK_USER is the desktop user that is logged in automatically and shows the browser.
set -eu

KIOSK_USER=${KIOSK_USER:-${SUDO_USER:-}}
KIOSK_URL=${KIOSK_URL:-https://keuken.pinoke.net}

[ "$(id -u)" -eq 0 ] || { echo "run as root (sudo)" >&2; exit 1; }
[ -n "$KIOSK_USER" ] && id "$KIOSK_USER" >/dev/null 2>&1 || { echo "set KIOSK_USER to the desktop user" >&2; exit 1; }
cd "$(dirname "$0")"
HOME_DIR=$(getent passwd "$KIOSK_USER" | cut -d: -f6)
as_user() { runuser -u "$KIOSK_USER" -- "$@"; }

echo "== packages"
apt-get update
apt-get install -y chromium cec-utils python3-requests python3-websocket nftables unattended-upgrades
apt-get purge -y unclutter 2>/dev/null || true   # X11 only; does nothing on Wayland

echo "== remove the previous setup"
rm -f /etc/cron.d/nightly-update
if [ -f /etc/systemd/system/tv-monitor.service ]; then
    systemctl disable --now tv-monitor.service || true
    rm -f /etc/systemd/system/tv-monitor.service
fi

echo "== kiosk monitor (TV on/off, remote reboot)"
id kiosk-monitor >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin kiosk-monitor
install -d -m 755 /opt/kiosk-monitor
install -m 755 kiosk_monitor.py kiosk-browser.sh /opt/kiosk-monitor/
install -m 644 kiosk-monitor.service kiosk-reboot.service kiosk-reboot.timer /etc/systemd/system/
install -m 644 50-kiosk-monitor.rules /etc/polkit-1/rules.d/
install -d -m 700 /etc/kiosk-monitor
if [ ! -f /etc/kiosk-monitor/kiosk-monitor.env ]; then
    install -m 600 kiosk-monitor.env.example /etc/kiosk-monitor/kiosk-monitor.env
    echo "Created /etc/kiosk-monitor/kiosk-monitor.env - fill in KIOSK_REVERB_APP_KEY"
fi
if [ ! -s /etc/kiosk-monitor/api-token ]; then
    printf "KIOSK_API_TOKEN (from Laravel's .env, input hidden): "
    stty -echo 2>/dev/null || true
    read -r token
    stty echo 2>/dev/null || true
    echo
    (umask 077 && printf '%s\n' "$token" > /etc/kiosk-monitor/api-token)
fi

echo "== browser and invisible cursor for $KIOSK_USER"
as_user python3 make_blank_cursor.py "$HOME_DIR/.icons"
as_user mkdir -p "$HOME_DIR/.config/labwc"
# labwc (Raspberry Pi OS since late 2024). A user autostart replaces the desktop's, so no panel either.
as_user sh -c "printf '%s\n' 'XCURSOR_THEME=blank' 'XCURSOR_SIZE=24' > '$HOME_DIR/.config/labwc/environment'"
as_user sh -c "printf '%s\n' '/opt/kiosk-monitor/kiosk-browser.sh $KIOSK_URL &' > '$HOME_DIR/.config/labwc/autostart'"
# wayfire (older Bookworm images)
as_user sh -c "cat > '$HOME_DIR/.config/wayfire.ini'" <<WAYFIRE
[autostart]
chromium = /opt/kiosk-monitor/kiosk-browser.sh $KIOSK_URL

[input]
cursor_theme = blank

[idle]
dpms_timeout = -1
screensaver_timeout = -1
WAYFIRE
if command -v raspi-config >/dev/null; then
    raspi-config nonint do_blanking 1   # 1 = screen blanking off
fi

echo "== firewall"
install -m 600 nftables.conf /etc/nftables.conf
systemctl enable nftables
nft -f /etc/nftables.conf

echo "== automatic security updates and nightly reboot at 04:00"
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades

systemctl daemon-reload
systemctl enable --now kiosk-reboot.timer
systemctl enable kiosk-monitor
systemctl restart kiosk-monitor
echo "Done. Reboot to start the kiosk session: sudo reboot"
