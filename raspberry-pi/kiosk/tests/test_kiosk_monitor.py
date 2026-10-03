"""Run with: python3 -m unittest   (from raspberry-pi/kiosk)

cec-client is replaced by a fake script on PATH that records what it was sent and
answers like a TV would, so the CEC handling is tested without a TV.
"""
import os
import stat
import sys
import tempfile
import types
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

# The Pi has python3-requests and python3-websocket; stub them where they are missing.
for name in ("requests", "websocket"):
    try:
        __import__(name)
    except ImportError:
        module = types.ModuleType(name)
        module.RequestException = Exception
        sys.modules[name] = module

import kiosk_monitor  # noqa: E402

FAKE_CEC_CLIENT = """#!/bin/sh
# Records the command and answers like libcec's cec-client in single command mode.
cmd=$(cat)
printf '%s\\n' "$*|$cmd" >> "$FAKE_CEC_LOG"
case "$cmd" in
    "pow 0") echo "opening a connection to the CEC adapter..."; echo "power status: $FAKE_TV_POWER" ;;
esac
exit "${FAKE_CEC_EXIT:-0}"
"""


class CecTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        fake = Path(self.dir.name, "cec-client")
        fake.write_text(FAKE_CEC_CLIENT)
        fake.chmod(fake.stat().st_mode | stat.S_IEXEC)
        self.log = Path(self.dir.name, "cec.log")
        self.env = {"PATH": f"{self.dir.name}:{os.environ['PATH']}", "FAKE_CEC_LOG": str(self.log),
                    "FAKE_TV_POWER": "standby"}
        self.old_env = {k: os.environ.get(k) for k in self.env}
        os.environ.update(self.env)
        self.cec = kiosk_monitor.Cec()

    def tearDown(self):
        for key, value in self.old_env.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value
        os.environ.pop("FAKE_CEC_EXIT", None)
        self.dir.cleanup()

    def sent(self):
        return self.log.read_text().splitlines()

    def test_switching_the_tv_sends_the_same_cec_commands_as_before(self):
        self.cec.set_tv_state("on")
        self.cec.set_tv_state("off")

        self.assertEqual(self.sent(), ["-s -d 1|on 0", "-s -d 1|standby 0"])
        self.assertIsNone(self.cec.last_error)

    def test_reads_the_power_state_the_tv_reports(self):
        self.assertEqual(self.cec.power_status(), "standby")

        os.environ["FAKE_TV_POWER"] = "on"
        self.assertEqual(self.cec.power_status(), "on")

    def test_a_tv_that_does_not_answer_is_unknown(self):
        os.environ["FAKE_TV_POWER"] = "unknown"

        self.assertEqual(self.cec.power_status(), "unknown")

    def test_a_failing_cec_client_is_reported_instead_of_crashing(self):
        os.environ["FAKE_CEC_EXIT"] = "1"

        self.cec.set_tv_state("on")

        self.assertIn("cec-client failed (exit 1)", self.cec.last_error)
        self.assertIsNone(self.cec.power_status())

    def test_a_missing_cec_client_is_reported(self):
        os.environ["PATH"] = self.dir.name + "/nowhere"

        self.cec.set_tv_state("on")

        self.assertIn("cec-utils", self.cec.last_error)

    def test_unknown_states_are_not_sent_to_the_tv(self):
        self.cec.set_tv_state("dance")

        self.assertFalse(self.log.exists())


class HandleStatusTest(unittest.TestCase):
    def test_reboot_reboots_and_does_not_touch_the_tv(self):
        calls = []
        cec = types.SimpleNamespace(set_tv_state=lambda s: calls.append(("tv", s)))
        original, kiosk_monitor.reboot = kiosk_monitor.reboot, lambda: calls.append(("reboot",))
        try:
            kiosk_monitor.handle_status("reboot", cec)
        finally:
            kiosk_monitor.reboot = original

        self.assertEqual(calls, [("reboot",)])

    def test_a_tv_command_asks_for_a_fresh_status_report(self):
        calls = []
        cec = types.SimpleNamespace(set_tv_state=lambda s: calls.append(("tv", s)))
        reporter = types.SimpleNamespace(report_soon=lambda: calls.append(("report",)))

        kiosk_monitor.handle_status("off", cec, reporter)

        self.assertEqual(calls, [("tv", "off"), ("report",)])


if __name__ == "__main__":
    unittest.main()
