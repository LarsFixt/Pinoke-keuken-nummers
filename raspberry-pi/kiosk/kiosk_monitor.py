#!/usr/bin/env python3
"""Kiosk monitor: switches the TV on/off over HDMI-CEC and reboots the Pi on request.

Listens on the public Reverb channel "kiosk-control" for App\\Events\\TvStatusUpdated
(status "on", "off" or "reboot") and asks the API for the current state on every
(re)connect, so a missed event is corrected after a network outage.

Every minute, and right after each TV command, it reports to the app what the TV
itself answers over CEC ("pow 0"), so the TV control page shows whether CEC works.

Configuration comes from the environment (/etc/kiosk-monitor/kiosk-monitor.env):
KIOSK_REVERB_HOST, KIOSK_REVERB_PORT, KIOSK_REVERB_APP_KEY, KIOSK_STATUS_URL,
KIOSK_HEARTBEAT_URL (defaults to .../kiosk/heartbeat next to the status URL) and
KIOSK_API_TOKEN (the systemd credential "kiosk-api-token" takes precedence).
"""
import json
import logging
import os
import re
import socket
import subprocess
import sys
import threading
import time
from pathlib import Path
from urllib.parse import urlparse

import requests
import websocket

VERSION = "2.1.0"
log = logging.getLogger("kiosk-monitor")

CHANNEL = "kiosk-control"
STATUS_EVENT = "App\\Events\\TvStatusUpdated"
CEC_COMMANDS = {"on": "on 0\n", "off": "standby 0\n"}
POWER_RE = re.compile(r"power status:\s*(.+?)\s*$", re.M)
HEARTBEAT_INTERVAL = 60


def setting(name, default=None):
    value = os.environ.get(name) or default
    if not value:
        sys.exit(f"missing setting {name} in /etc/kiosk-monitor/kiosk-monitor.env")
    return value


def api_token():
    creds = os.environ.get("CREDENTIALS_DIRECTORY")
    if creds and Path(creds, "kiosk-api-token").exists():
        return Path(creds, "kiosk-api-token").read_text().strip()
    return setting("KIOSK_API_TOKEN")


class Cec:
    """cec-client calls, one at a time: two at once fight over the CEC adapter."""

    def __init__(self):
        self.lock = threading.Lock()
        self.last_error = None
        self.last_state = None

    def run(self, command):
        with self.lock:
            try:
                result = subprocess.run(["cec-client", "-s", "-d", "1"], input=command.encode(), check=True,
                                        timeout=30, capture_output=True)
            except FileNotFoundError:
                self.last_error = "cec-client is not installed (apt install cec-utils)"
            except subprocess.TimeoutExpired:
                self.last_error = "cec-client did not answer within 30 seconds"
            except subprocess.CalledProcessError as e:
                output = (e.stderr or e.stdout or b"").decode(errors="replace").strip().splitlines()
                self.last_error = f"cec-client failed (exit {e.returncode}){': ' + output[-1] if output else ''}"
            else:
                self.last_error = None
                return result.stdout.decode(errors="replace")
        log.error("CEC: %s", self.last_error)
        return None

    def set_tv_state(self, state):
        command = CEC_COMMANDS.get(state)
        if command is None:
            log.warning("ignoring unknown TV state %r", state)
            return
        log.info("turning TV %s via CEC", state)
        self.run(command)
        self.last_state = state

    def power_status(self):
        """What the TV itself reports: on, standby, in transition ..., or unknown (no answer)."""
        output = self.run("pow 0\n")
        if output is None:
            return None
        match = POWER_RE.search(output)
        return match.group(1).lower() if match else "unknown"


def local_ip(host):
    """The address this Pi uses to reach the app (no traffic is sent)."""
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect((host, 443))
            return s.getsockname()[0]
    except OSError:
        return None


def uptime_seconds():
    try:
        return int(float(Path("/proc/uptime").read_text().split()[0]))
    except (OSError, ValueError, IndexError):
        return None


def browser_running():
    try:
        return subprocess.run(["pgrep", "-x", "chromium|chromium-browse"], capture_output=True).returncode == 0
    except OSError:
        return None


class Reporter(threading.Thread):
    """Sends the status to the app every minute, or right away when woken after a command."""

    def __init__(self, url, token, cec):
        super().__init__(daemon=True)
        self.url, self.token, self.cec = url, token, cec
        self.wake = threading.Event()

    def report_soon(self):
        self.wake.set()

    def status(self):
        tv_power = self.cec.power_status()
        return {
            "hostname": socket.gethostname(),
            "ip": local_ip(urlparse(self.url).hostname),
            "uptime_seconds": uptime_seconds(),
            "version": VERSION,
            "tv_power": tv_power if tv_power in ("on", "standby", "unknown") or (tv_power or "").startswith("in transition") else "unknown",
            "cec_error": self.cec.last_error,
            "browser_running": browser_running(),
        }

    def send(self):
        try:
            resp = requests.post(self.url, json=self.status(), timeout=10,
                                 headers={"Authorization": f"Bearer {self.token}", "Accept": "application/json"})
            resp.raise_for_status()
        except requests.RequestException as e:
            log.warning("status report failed: %s", e)

    def run(self):
        while True:
            self.send()
            self.wake.wait(HEARTBEAT_INTERVAL)
            if self.wake.is_set():
                time.sleep(5)  # give the TV a moment to switch before asking it again
                self.wake.clear()


def reboot():
    log.warning("remote reboot requested")
    # Allowed for this service user by the polkit rule installed with it.
    subprocess.run(["systemctl", "reboot"], check=False)


def handle_status(status, cec, reporter=None):
    if status == "reboot":
        reboot()
        return
    cec.set_tv_state(status)
    if reporter:
        reporter.report_soon()


def sync_with_api(url, token, cec, reporter=None):
    """Catch up on a state change missed while disconnected. Re-sending the state the TV
    already has would wake it up again on every reconnect, so that is skipped."""
    try:
        resp = requests.get(url, headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
                            timeout=5)
        resp.raise_for_status()
        status = resp.json().get("status", "on")
        if status != cec.last_state:
            handle_status(status, cec, reporter)
    except (requests.RequestException, ValueError) as e:
        log.error("could not fetch the TV status: %s", e)


def run():
    host = setting("KIOSK_REVERB_HOST")
    port = setting("KIOSK_REVERB_PORT", "443")
    url = f"wss://{host}:{port}/app/{setting('KIOSK_REVERB_APP_KEY')}?protocol=7&client=kiosk-monitor&version={VERSION}"
    status_url, token = setting("KIOSK_STATUS_URL"), api_token()
    heartbeat_url = os.environ.get("KIOSK_HEARTBEAT_URL") or status_url.rsplit("/", 1)[0] + "/heartbeat"

    cec = Cec()
    reporter = Reporter(heartbeat_url, token, cec)
    reporter.start()

    def on_message(ws, message):
        try:
            data = json.loads(message)
        except ValueError:
            return
        event = data.get("event")
        if event == "pusher:connection_established":
            log.info("connected, subscribing to %s", CHANNEL)
            ws.send(json.dumps({"event": "pusher:subscribe", "data": {"channel": CHANNEL}}))
            sync_with_api(status_url, token, cec, reporter)
        elif event == "pusher:ping":
            ws.send(json.dumps({"event": "pusher:pong", "data": {}}))
        elif event == STATUS_EVENT and data.get("channel") == CHANNEL:
            try:
                status = json.loads(data.get("data") or "{}").get("status")
            except ValueError:
                return
            if status:
                handle_status(status, cec, reporter)

    def on_error(ws, error):
        log.warning("websocket error: %s", error)

    # A loop instead of reconnecting from on_close: that recursed and grew the stack on every reconnect.
    while True:
        ws = websocket.WebSocketApp(url, on_message=on_message, on_error=on_error)
        # Ping well within the 60 s idle timeout of the proxy in front of Reverb, or it drops the connection.
        ws.run_forever(ping_interval=25, ping_timeout=10)
        log.info("connection closed, reconnecting in 5s")
        time.sleep(5)


if __name__ == "__main__":
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    try:
        run()
    except KeyboardInterrupt:
        pass
