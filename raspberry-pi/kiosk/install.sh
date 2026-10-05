#!/bin/sh
# Installs the kiosk on Raspberry Pi OS (Bookworm or newer, Wayland desktop with auto-login).
# Normally run by ../deploy.sh; by hand: sudo ./install.sh
#
#   KIOSK_USER=pin-viewer KIOSK_URL=https://keuken.pinoke.net KIOSK_REVERB_APP_KEY=... sudo -E ./install.sh
#
# KIOSK_USER is the desktop user that is logged in automatically and shows the browser.
# KIOSK_REVERB_APP_KEY is the public Reverb key (REVERB_APP_KEY, the one the browser also uses);
# KIOSK_REVERB_HOST defaults to order.larsfixt.nl.
set -eu

KIOSK_USER=${KIOSK_USER:-${SUDO_USER:-}}
KIOSK_URL=${KIOSK_URL:-https://keuken.pinoke.net}
KIOSK_REVERB_HOST=${KIOSK_REVERB_HOST:-order.larsfixt.nl}
KIOSK_REVERB_APP_KEY=${KIOSK_REVERB_APP_KEY:-}
KIOSK_LOCALE=${KIOSK_LOCALE:-nl_NL.UTF-8}

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
    sed -i -e "s|^KIOSK_REVERB_HOST=.*|KIOSK_REVERB_HOST=$KIOSK_REVERB_HOST|" \
        -e "s|^KIOSK_REVERB_APP_KEY=.*|KIOSK_REVERB_APP_KEY=$KIOSK_REVERB_APP_KEY|" \
        -e "s|^KIOSK_STATUS_URL=.*|KIOSK_STATUS_URL=${KIOSK_URL%/}/api/kiosk/tv-status|" \
        /etc/kiosk-monitor/kiosk-monitor.env
    echo "Created /etc/kiosk-monitor/kiosk-monitor.env"
fi
if ! grep -q '^KIOSK_REVERB_APP_KEY=.' /etc/kiosk-monitor/kiosk-monitor.env; then
    echo "WARNING: KIOSK_REVERB_APP_KEY is empty in /etc/kiosk-monitor/kiosk-monitor.env:" >&2
    echo "         TV on/off and reboot from the app will not work until it is filled in." >&2
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

echo "== browser languages (the display follows the language the browser asks for)"
install -d -m 755 /etc/chromium/policies/managed
lang_tag=$(echo "$KIOSK_LOCALE" | cut -d. -f1 | tr _ -)
printf '{\n    "ForcedLanguages": ["%s", "%s"]\n}\n' "$lang_tag" "${lang_tag%%-*}" > /etc/chromium/policies/managed/kiosk.json
chmod 644 /etc/chromium/policies/managed/kiosk.json

echo "== system language $KIOSK_LOCALE"
if command -v raspi-config >/dev/null; then
    raspi-config nonint do_change_locale "$KIOSK_LOCALE"
else
    sed -i "s/^# *\($KIOSK_LOCALE\)/\1/" /etc/locale.gen
    locale-gen
    update-locale LANG="$KIOSK_LOCALE"
fi

echo "== Wi-Fi power saving off (it causes lag and dropped connections)"
if command -v nmcli >/dev/null && systemctl is-active -q NetworkManager; then
    nmcli -t -f NAME,TYPE connection show | awk -F: '$2 == "802-11-wireless" {print $1}' |
        while read -r name; do nmcli connection modify "$name" 802-11-wireless.powersave 2; done
    for dev in $(nmcli -t -f DEVICE,TYPE device | awk -F: '$2 == "wifi" {print $1}'); do iw dev "$dev" set power_save off 2>/dev/null || true; done
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
