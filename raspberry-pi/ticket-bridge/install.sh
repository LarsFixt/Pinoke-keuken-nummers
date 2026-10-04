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

# The mirror port loses its IP address and the firewall only allows SSH over the uplink,
# so make sure we are not about to cut off the connection this install runs over.
ip -4 -o addr show dev "$UPLINK" 2>/dev/null | grep -q inet ||
    { echo "$UPLINK has no IP address: connect the uplink (Wi-Fi) first and deploy over it" >&2; exit 1; }
ssh_ip=$(echo "${SSH_CONNECTION:-}" | cut -d' ' -f3)
if [ -n "$ssh_ip" ] && ip -4 -o addr show dev "$MIRROR" 2>/dev/null | grep -q " $ssh_ip/"; then
    echo "You are connected over $MIRROR, which becomes the mirror port and loses its address." >&2
    echo "Reconnect over $UPLINK ($(ip -4 -o addr show dev "$UPLINK" | awk '{print $4}' | cut -d/ -f1)) and run the deploy again." >&2
    exit 1
fi

echo "== packages"
apt-get update
apt-get install -y python3 python3-pil tesseract-ocr tesseract-ocr-nld nftables unattended-upgrades

echo "== program"
install -d -m 755 /opt/kitchen-tickets
install -m 755 ticket_reader.py /opt/kitchen-tickets/
printf '#!/bin/sh\n# What the reader read on a day (default today): kitchen-tickets-report [YYYY-MM-DD]\nexec python3 /opt/kitchen-tickets/ticket_reader.py --report "$@" --state-dir /var/lib/kitchen-tickets\n' \
    > /usr/local/bin/kitchen-tickets-report
chmod 755 /usr/local/bin/kitchen-tickets-report
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
    nmcli connection up mirror-port ||
        echo "$MIRROR is not plugged in yet: it comes up by itself once the mirror cable is connected"
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
if grep -q '^TICKETS_DRY_RUN=1' /etc/kitchen-tickets/kitchen-tickets.env; then
    echo "Done, in OBSERVE MODE: tickets are read and archived, nothing is sent to the app."
    echo "  live log:  journalctl -u kitchen-tickets -f"
    echo "  report:    sudo kitchen-tickets-report"
    echo "Go live later by setting TICKETS_DRY_RUN=0 in /etc/kitchen-tickets/kitchen-tickets.env"
else
    echo "Done, LIVE. Follow it with: journalctl -u kitchen-tickets -f"
fi
