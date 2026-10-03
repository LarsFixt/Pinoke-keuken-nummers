#!/bin/sh
# Installs the ticket bridge on Raspberry Pi OS (Bookworm or newer).
# Normally run by ../deploy.sh; by hand: sudo ./install.sh
#
#   MIRROR=eth0 UPLINK=wlan0 sudo -E ./install.sh
#
# MIRROR is the interface on the switch mirror port, UPLINK the one with internet.
set -eu

MIRROR=${MIRROR:-eth0}
UPLINK=${UPLINK:-wlan0}
TIMEZONE=${TIMEZONE:-Europe/Amsterdam}

[ "$(id -u)" -eq 0 ] || { echo "run as root (sudo)" >&2; exit 1; }
[ "$MIRROR" != "$UPLINK" ] || { echo "MIRROR and UPLINK must be different interfaces" >&2; exit 1; }
cd "$(dirname "$0")"

echo "== packages"
apt-get update
apt-get install -y python3 python3-pil tesseract-ocr tesseract-ocr-nld nftables unattended-upgrades

echo "== program"
install -d -m 755 /opt/kitchen-tickets
install -m 755 ticket_reader.py /opt/kitchen-tickets/
install -m 644 kitchen-tickets.service kitchen-tickets-reboot.path kitchen-tickets-reboot.service /etc/systemd/system/

echo "== configuration"
install -d -m 700 /etc/kitchen-tickets
if [ ! -f /etc/kitchen-tickets/kitchen-tickets.env ]; then
    sed "s/^TICKETS_IFACE=.*/TICKETS_IFACE=$MIRROR/" kitchen-tickets.env.example > /etc/kitchen-tickets/kitchen-tickets.env
    chmod 600 /etc/kitchen-tickets/kitchen-tickets.env
    echo "Created /etc/kitchen-tickets/kitchen-tickets.env - check TICKETS_API_URL and TICKETS_PRINTER_IP"
fi
if [ ! -f /etc/kitchen-tickets/secret ]; then
    (umask 077 && openssl rand -hex 32 > /etc/kitchen-tickets/secret)
    echo
    echo "Generated a new shared secret. Put it in Laravel's .env, then remove it from your terminal history:"
    echo "  TICKET_BRIDGE_SECRET=$(cat /etc/kitchen-tickets/secret)"
    echo
fi

echo "== clock (signatures are only accepted within 5 minutes)"
timedatectl set-timezone "$TIMEZONE"
timedatectl set-ntp true

echo "== mirror port $MIRROR: link up, no IP address, never transmits"
if command -v nmcli >/dev/null && systemctl is-active -q NetworkManager; then
    nmcli -t -f NAME,DEVICE connection show | awk -F: -v dev="$MIRROR" '$2 == dev && $1 != "mirror-port" {print $1}' |
        while read -r name; do nmcli connection modify "$name" connection.autoconnect no; nmcli connection down "$name" || true; done
    nmcli connection show mirror-port >/dev/null 2>&1 ||
        nmcli connection add type ethernet ifname "$MIRROR" con-name mirror-port
    nmcli connection modify mirror-port connection.autoconnect yes ipv4.method disabled ipv6.method disabled
    nmcli connection up mirror-port
else
    echo "NetworkManager not found: make sure $MIRROR gets no IP address yourself" >&2
fi

echo "== firewall"
sed -e "s/@MIRROR@/$MIRROR/g" -e "s/@UPLINK@/$UPLINK/g" nftables.conf > /etc/nftables.conf
chmod 600 /etc/nftables.conf
systemctl enable nftables
nft -f /etc/nftables.conf

echo "== automatic security updates"
printf 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades

systemctl daemon-reload
systemctl enable kitchen-tickets
systemctl enable --now kitchen-tickets-reboot.path
systemctl restart kitchen-tickets
echo "Done. Follow it with: journalctl -u kitchen-tickets -f"
