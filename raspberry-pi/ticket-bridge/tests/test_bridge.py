"""Run with: python3 -m unittest   (from raspberry-pi/ticket-bridge)"""
import argparse
import os
import shutil
import subprocess
import tempfile
import sys
import unittest
from datetime import datetime
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import ticket_reader  # noqa: E402

FIXTURES = Path(__file__).parent / "fixtures"


class SigningTest(unittest.TestCase):
    def test_signature_matches_the_vector_shared_with_laravel(self):
        # Same vector as tests/Feature/KitchenTicketApiTest.php in the Laravel app.
        headers = ticket_reader.sign_request("test-secret-0123456789abcdef0123456789", b'{"id":"vector"}',
                                             timestamp=1759420800, nonce="00112233445566778899aabbccddeeff")
        self.assertEqual(headers["X-Bridge-Signature"],
                         "60bf299b647ad781157d59a112ac37f5884eb9ecfdc14ebc133db4e283803369")

    def test_every_request_gets_a_fresh_nonce(self):
        first = ticket_reader.sign_request("s" * 32, b"{}")
        second = ticket_reader.sign_request("s" * 32, b"{}")
        self.assertNotEqual(first["X-Bridge-Nonce"], second["X-Bridge-Nonce"])
        self.assertRegex(first["X-Bridge-Nonce"], r"^[0-9a-f]{32}$")


class CollectingSpool:
    def __init__(self):
        self.payloads = []

    def put(self, payload):
        self.payloads.append(payload)


@unittest.skipUnless(shutil.which("tesseract"), "tesseract-ocr is not installed")
class ReplayTest(unittest.TestCase):
    def test_replaying_the_capture_yields_the_three_tickets(self):
        args = argparse.Namespace(state_dir=None, no_archive=True, codepage="cp858", lang="nld", psm=6,
                                  min_confidence=75.0, printer_ip="172.220.230.190", dry_run=False)
        spool = CollectingSpool()
        proc = ticket_reader.Processor(args, spool)
        table = ticket_reader.FlowTable(args.printer_ip, 9100, proc.job)
        for ts, linktype, frame in ticket_reader.pcap_frames(FIXTURES / "tickets.pcap"):
            if pkt := ticket_reader.parse_tcp(linktype, frame):
                table.packet(pkt, ts)
                table.expire(ts)
        table.expire(0, everything=True)

        self.assertEqual([p["ticket_number"] for p in spool.payloads], ["0317", "0318", "0319"])
        self.assertEqual(spool.payloads[0]["items"], [{"qty": 1, "name": "Br. Kroket", "notes": []}])
        self.assertTrue(all(p["warnings"] == [] for p in spool.payloads))
        self.assertNotIn("image_png_base64", spool.payloads[0])
        self.assertRegex(spool.payloads[0]["printed_at"], r"^2026-10-02T18:01:00[+-]\d\d:\d\d$")


class FakeApi:
    def __init__(self, answer):
        self.answer, self.posts = answer, []

    def post(self, path, body):
        self.posts.append((path, body))
        return self.answer


class HeartbeatTest(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.spool = ticket_reader.Spool(Path(self.dir.name, "spool"), FakeApi({"status": "created"}))

    def tearDown(self):
        self.dir.cleanup()

    def test_the_status_counts_waiting_and_refused_tickets(self):
        self.spool.put({"id": "a"})
        Path(self.spool.failed, "b.json").write_text("{}")
        heartbeat = ticket_reader.Heartbeat(FakeApi({"status": "ok"}), self.spool, "lo", self.dir.name)

        status = heartbeat.status()

        self.assertEqual((status["spool_pending"], status["spool_failed"]), (1, 1))
        self.assertEqual(status["iface"], "lo")

    def test_a_reboot_command_leaves_the_request_file_for_the_root_path_unit(self):
        heartbeat = ticket_reader.Heartbeat(FakeApi({"status": "ok"}), self.spool, "lo", self.dir.name)

        heartbeat.carry_out("reboot")

        self.assertTrue(Path(self.dir.name, "reboot-requested").exists())

    def test_no_command_does_nothing(self):
        heartbeat = ticket_reader.Heartbeat(FakeApi({"status": "ok"}), self.spool, "lo", self.dir.name)

        heartbeat.carry_out(None)

        self.assertFalse(Path(self.dir.name, "reboot-requested").exists())

    def test_the_spool_deletes_a_ticket_the_app_accepted(self):
        self.spool.put({"id": "a"})

        self.assertTrue(self.spool.drain())
        self.assertEqual(list(self.spool.dir.glob("*.json")), [])


@unittest.skipUnless(shutil.which("tesseract"), "tesseract-ocr is not installed")
class ObserveModeTest(unittest.TestCase):
    def test_observe_mode_archives_the_tickets_and_the_report_lists_them_in_order(self):
        with tempfile.TemporaryDirectory() as state:
            env = {**os.environ, "TICKETS_DRY_RUN": "1"}
            script = str(Path(__file__).resolve().parent.parent / "ticket_reader.py")
            replay = subprocess.run([sys.executable, script, "--pcap", str(FIXTURES / "tickets.pcap"),
                                     "--printer-ip", "172.220.230.190", "--state-dir", state],
                                    env=env, capture_output=True, text=True, timeout=120)
            day = datetime.now().strftime("%Y-%m-%d")
            report = subprocess.run([sys.executable, script, "--report", day, "--state-dir", state],
                                    capture_output=True, text=True, timeout=30)

        self.assertEqual(replay.returncode, 0, replay.stderr)
        numbers = [line.split()[1] for line in report.stdout.splitlines()[1:4]]
        self.assertEqual(numbers, ["0317", "0318", "0319"])
        self.assertIn("3 tickets, 3 with a number, 0 with warnings", report.stdout)


if __name__ == "__main__":
    unittest.main()
