#!/bin/sh
# Copy one of the Pi projects to a Pi and run its installer there.
#
#   ./deploy.sh ticket-bridge pi@ticket-bridge.local
#   ./deploy.sh kiosk pi@kiosk.local
#
# The Pis never get access to this repository: code is pushed to them over SSH.
# Extra environment for the installer can be passed as KEY=value after the host,
# e.g. ./deploy.sh ticket-bridge pi@host MIRROR=eth0 UPLINK=wlan0
set -eu

project=${1:?usage: deploy.sh <ticket-bridge|kiosk> <user@host> [KEY=value ...]}
host=${2:?usage: deploy.sh <ticket-bridge|kiosk> <user@host> [KEY=value ...]}
shift 2

cd "$(dirname "$0")"
[ -x "$project/install.sh" ] || { echo "unknown project: $project" >&2; exit 1; }

rsync -av --delete --exclude tests/ --exclude __pycache__/ --exclude '*.env' --exclude secret \
    "$project/" "$host:~/$project-install/"
ssh -t "$host" "cd ~/$project-install && sudo env $* ./install.sh"
