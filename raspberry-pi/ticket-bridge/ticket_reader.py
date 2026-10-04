#!/usr/bin/env python3
"""Kitchen ticket reader.

Passively listens on a mirrored switch port for print jobs sent to an Epson
receipt printer (raw TCP, port 9100), rebuilds the raster image the Windows
driver sends, OCRs it with Tesseract and posts the result as JSON to an API.

Every request is signed with HMAC-SHA256 (see sign_request), so the API can
check it came from this Pi, was not changed on the way and is not a replay.

Live:    sudo ./ticket_reader.py --iface eth0 --printer-ip 172.220.230.190 --api-url https://... --secret-file secret
Replay:  ./ticket_reader.py --pcap tests/fixtures/tickets.pcap --printer-ip 172.220.230.190 --dry-run
Report:  ./ticket_reader.py --report [YYYY-MM-DD]   (what was read that day, from the archive)

Observe mode (--dry-run or TICKETS_DRY_RUN=1) reads and archives every ticket but sends
nothing, so the reader can be checked against the paper tickets before going live.

Requires: python3, python3-pil, tesseract-ocr, tesseract-ocr-nld
"""
import argparse
import hashlib
import hmac
import io
import json
import logging
import os
import queue
import re
import secrets
import signal
import socket
import struct
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse

from PIL import Image, ImageOps

log = logging.getLogger("tickets")
VERSION = "2.1.0"


class Stats:
    """What the reader is doing, reported to the TV control page with every heartbeat."""

    capturing = False
    tickets_seen = 0
    last_ticket_at = None


# ---------------------------------------------------------------- capture

def pcap_frames(path):
    """Yield (timestamp, linktype, frame) from a classic pcap file."""
    with open(path, "rb") as f:
        hdr = f.read(24)
        magic = struct.unpack("<I", hdr[:4])[0]
        if magic in (0xA1B2C3D4, 0xA1B23C4D):
            endian = "<"
        elif magic in (0xD4C3B2A1, 0x4D3CB2A1):
            endian = ">"
        else:
            sys.exit("Not a classic pcap file (capture with: tcpdump -w file.pcap)")
        linktype = struct.unpack(endian + "I", hdr[20:24])[0]
        while True:
            rec = f.read(16)
            if len(rec) < 16:
                return
            ts_sec, ts_usec, incl_len, _ = struct.unpack(endian + "IIII", rec)
            yield ts_sec + ts_usec / 1e6, linktype, f.read(incl_len)


def open_capture(iface):
    sock = socket.socket(socket.AF_PACKET, socket.SOCK_RAW, socket.ntohs(0x0003))
    try:
        sock.bind((iface, 0))
        # Mirrored frames aren't addressed to us; without promiscuous mode the kernel drops them.
        SOL_PACKET, PACKET_ADD_MEMBERSHIP, PACKET_MR_PROMISC = 263, 1, 1
        mreq = struct.pack("iHH8s", socket.if_nametoindex(iface), PACKET_MR_PROMISC, 0, b"")
        sock.setsockopt(SOL_PACKET, PACKET_ADD_MEMBERSHIP, mreq)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4 << 20)
        sock.settimeout(0.5)
    except OSError:
        sock.close()
        raise
    return sock


def live_frames(iface):
    """Yield (timestamp, linktype, frame) from a raw socket in promiscuous mode.

    Yields (timestamp, None, None) about twice a second when idle so the
    caller can expire finished connections. Survives the link going down or
    the interface disappearing (cable or USB adapter unplugged) by reopening.
    """
    sock, down_since = None, None
    while True:
        if sock is None:
            try:
                sock = open_capture(iface)
                Stats.capturing = True
                log.info("listening on %s (promiscuous)%s", iface,
                         f" after {time.time() - down_since:.0f}s outage" if down_since else "")
                down_since = None
            except OSError as e:
                Stats.capturing = False
                if down_since is None:
                    down_since = time.time()
                    log.error("cannot capture on %s (%s), retrying", iface, e)
                time.sleep(2)
                yield time.time(), None, None
                continue
        try:
            frame = sock.recv(65535)
        except socket.timeout:
            yield time.time(), None, None
            continue
        except OSError as e:  # ENETDOWN, ENODEV, ...
            log.error("capture on %s lost (%s), tickets are missed until it's back", iface, e)
            Stats.capturing = False
            sock.close()
            sock, down_since = None, time.time()
            continue
        yield time.time(), 1, frame


def parse_tcp(linktype, frame):
    """Return (src, sport, dst, dport, seq, flags, payload) for IPv4/TCP frames."""
    if linktype == 1:  # Ethernet
        if len(frame) < 14:
            return None
        off, ethertype = 14, struct.unpack("!H", frame[12:14])[0]
        while ethertype in (0x8100, 0x88A8):  # VLAN tags
            ethertype = struct.unpack("!H", frame[off + 2:off + 4])[0]
            off += 4
        if ethertype != 0x0800:
            return None
    elif linktype == 113:  # Linux cooked capture
        off = 16
    else:
        return None
    ip = frame[off:]
    if len(ip) < 20 or ip[9] != 6:
        return None
    ihl = (ip[0] & 0x0F) * 4
    total = struct.unpack("!H", ip[2:4])[0]
    src, dst = socket.inet_ntoa(ip[12:16]), socket.inet_ntoa(ip[16:20])
    tcp = ip[ihl:total]
    if len(tcp) < 20:
        return None
    sport, dport, seq = struct.unpack("!HHI", tcp[:8])
    doff = (tcp[12] >> 4) * 4
    return src, sport, dst, dport, seq, tcp[13], bytes(tcp[doff:])


FIN, SYN, RST = 0x01, 0x02, 0x04


class Flow:
    """One TCP connection from the POS to the printer, reassembled by sequence number."""

    def __init__(self, now):
        self.base = None
        self.segs = {}
        self.first_seen = self.last_seen = now
        self.closed_at = None

    def add(self, seq, flags, payload, now):
        self.last_seen = now
        if flags & SYN:
            self.base = (seq + 1) & 0xFFFFFFFF
        if flags & (FIN | RST) and self.closed_at is None:
            self.closed_at = now
        if not payload:
            return
        if self.base is None:
            self.base = seq
        rel = (seq - self.base) & 0xFFFFFFFF
        if rel >= 1 << 31:  # segment before our base (missed SYN + reordering): rebase
            shift = (self.base - seq) & 0xFFFFFFFF
            self.segs = {off + shift: data for off, data in self.segs.items()}
            self.base, rel = seq, 0
        if len(payload) > len(self.segs.get(rel, b"")):  # mirror ports can duplicate packets
            self.segs[rel] = payload

    def assemble(self):
        out, missing = bytearray(), 0
        for off, data in sorted(self.segs.items()):
            if off > len(out):
                missing += off - len(out)
                out += b"\0" * (off - len(out))
            if off + len(data) > len(out):
                out += data[len(out) - off:]
        return bytes(out), missing


class FlowTable:
    def __init__(self, printer_ip, port, on_job, idle=5.0, grace=1.0):
        self.printer_ip, self.port, self.on_job = printer_ip, port, on_job
        self.idle, self.grace = idle, grace
        self.flows = {}

    def packet(self, pkt, now):
        src, sport, dst, dport, seq, flags, payload = pkt
        if dst == self.printer_ip and dport == self.port:
            key = (src, sport)
        elif src == self.printer_ip and sport == self.port:
            key = (dst, dport)  # only used to notice the printer closing the connection
            flow = self.flows.get(key)
            if flow and flags & (FIN | RST) and flow.closed_at is None:
                flow.closed_at = now
            return
        else:
            return
        flow = self.flows.get(key)
        if flow is None or (flags & SYN and flow.segs):
            if flow:
                self._finish(key, flow)
            flow = self.flows[key] = Flow(now)
        flow.add(seq, flags, payload, now)

    def expire(self, now, everything=False):
        for key, flow in list(self.flows.items()):
            done = (flow.closed_at is not None and now - flow.closed_at >= self.grace) \
                or now - flow.last_seen >= self.idle
            if everything or done:
                self._finish(key, flow)

    def _finish(self, key, flow):
        self.flows.pop(key, None)
        data, missing = flow.assemble()
        if data:
            self.on_job({"pos_ip": key[0], "started": flow.first_seen,
                         "data": data, "missing_bytes": missing})


# ---------------------------------------------------------------- ESC/POS

# Fixed argument byte counts for commands we only need to skip.
ESC_ARGS = {b"@": 0, b"!": 1, b"E": 1, b"-": 1, b"a": 1, b"d": 1, b"t": 1,
            b"R": 1, b"M": 1, b"G": 1, b"J": 1, b"2": 0, b"3": 1, b"p": 3,
            b"r": 1, b"{": 1, b"V": 1, b"=": 1, b"c": 2, b"$": 2, b"\\": 2,
            b" ": 1, b"%": 1, b"i": 0, b"m": 0, b"e": 1, b"D": 0, b"B": 2,
            b"?": 1, b"U": 1, b"T": 1, b"L": 0, b"S": 0, b"W": 8}
GS_ARGS = {b"!": 1, b"B": 1, b"h": 1, b"w": 1, b"H": 1, b"f": 1, b"b": 1,
           b"L": 2, b"W": 2, b"P": 2, b"r": 1, b"a": 1, b"I": 1, b"/": 1,
           b":": 0, b"$": 2, b"\\": 2, b"^": 3, b"y": 1}
FS_ARGS = {b"&": 0, b".": 0, b"!": 1, b"-": 1, b"C": 1, b"W": 1, b"p": 2, b"S": 2}


def raster_image(width, height, data, xscale=1, yscale=1):
    """1 bit = black dot, rows padded to whole bytes (ESC/POS raster format)."""
    rowbytes = (width + 7) // 8
    data = data[:rowbytes * height].ljust(rowbytes * height, b"\0")
    img = Image.frombytes("1", (rowbytes * 8, height), data, "raw", "1;I").crop((0, 0, width, height))
    if xscale > 1 or yscale > 1:
        img = img.resize((width * xscale, height * yscale), Image.NEAREST)
    return img


def graphics_image(body):
    """Body of GS ( L / GS 8 L, starting at m. Returns an image for fn 112 (store raster)."""
    if len(body) < 10 or body[1] != 112:
        return None
    _m, _fn, _a, bx, by, color, xl, xh, yl, yh = body[:10]
    if color not in (0x31, 49):  # second colour on two-colour printers: ignore
        return None
    return raster_image(xl + 256 * xh, yl + 256 * yh, body[10:], bx, by)


def split_tickets(data, codepage="cp858"):
    """Walk the ESC/POS stream and return [{"images": [...], "text": str}], one per cut."""
    tickets, images, text = [], [], bytearray()
    gap = 0

    def cut():
        nonlocal images, text
        if images or text.strip():
            tickets.append({"images": images, "text": text.decode(codepage, "replace")})
        images, text = [], bytearray()

    i, n = 0, len(data)
    while i < n:
        b = data[i]
        if b in (0x1B, 0x1D, 0x1C) and i + 1 < n:
            cmd, p = data[i + 1:i + 2], i + 2
            if b == 0x1D and cmd in (b"(", b"8") and p < n and data[p] == ord("L"):  # graphics
                if cmd == b"(":
                    ln, body = data[p + 1] + 256 * data[p + 2], p + 3
                else:
                    ln, body = int.from_bytes(data[p + 1:p + 5], "little"), p + 5
                img = graphics_image(data[body:body + ln])
                if img:
                    if images and gap:
                        images.append(Image.new("1", (img.width, min(gap, 60)), 1))
                    images.append(img)
                    gap = 0
                i = body + ln
            elif b == 0x1D and cmd == b"(" and p + 2 < n:  # other GS ( x
                i = p + 3 + data[p + 1] + 256 * data[p + 2]
            elif b == 0x1B and cmd == b"(" and p + 2 < n:
                i = p + 3 + data[p + 1] + 256 * data[p + 2]
            elif b == 0x1D and cmd == b"v" and p + 5 < n:  # GS v 0 m xL xH yL yH
                xb, h = data[p + 2] + 256 * data[p + 3], data[p + 4] + 256 * data[p + 5]
                images.append(raster_image(xb * 8, h, data[p + 6:p + 6 + xb * h]))
                gap = 0
                i = p + 6 + xb * h
            elif b == 0x1D and cmd == b"V":  # cut: m [n]
                cut()
                i = p + (2 if p < n and data[p] >= 65 else 1)
            elif b == 0x1B and cmd == b"*" and p + 2 < n:  # column bit image: skip
                m, nl, nh = data[p:p + 3]
                i = p + 3 + (nl + 256 * nh) * (3 if m >= 32 else 1)
            elif b == 0x1D and cmd == b"k" and p < n:  # barcode
                if data[p] <= 6:
                    end = data.find(b"\x00", p + 1)
                    i = end + 1 if end >= 0 else n
                else:
                    i = p + 2 + (data[p + 1] if p + 1 < n else 0)
            elif b == 0x1B and cmd == b"J" and p < n:  # feed n dots
                gap += data[p]
                i = p + 1
            elif b == 0x1B and cmd == b"d" and p < n:  # feed n lines
                text += b"\n" * min(data[p], 3)
                gap += 30 * data[p]
                i = p + 1
            elif b == 0x1B and cmd == b"i" or b == 0x1B and cmd == b"m":
                cut()
                i = p
            else:
                table = {0x1B: ESC_ARGS, 0x1D: GS_ARGS, 0x1C: FS_ARGS}[b]
                i = p + table.get(cmd, 1)
            continue
        if b == 0x10 and i + 2 < n and data[i + 1] in (0x04, 0x05, 0x14):
            i += 3  # real-time status requests
            continue
        if b in (0x0A, 0x09) or b >= 0x20:
            text.append(b)
        i += 1
    cut()
    return tickets


def stack(images):
    width = max(im.width for im in images)
    out = Image.new("1", (width, sum(im.height for im in images)), 1)
    y = 0
    for im in images:
        out.paste(im, (0, y))
        y += im.height
    return out


# ---------------------------------------------------------------- OCR

def ocr(img, lang="nld", psm=6):
    """Return (lines, per-line min word confidence, mean confidence).

    Tesseract likes ~30px text and a white margin."""
    gray = ImageOps.expand(img.convert("L"), border=24, fill=255)
    gray = gray.resize((gray.width * 2, gray.height * 2), Image.LANCZOS)
    buf = io.BytesIO()
    gray.save(buf, "PNG")
    res = subprocess.run(["tesseract", "stdin", "stdout", "-l", lang, "--psm", str(psm), "tsv"],
                         input=buf.getvalue(), capture_output=True, timeout=120)
    if res.returncode != 0:
        raise RuntimeError(res.stderr.decode(errors="replace").strip())
    lines, confs = {}, []
    for row in res.stdout.decode("utf-8", "replace").splitlines()[1:]:
        cols = row.split("\t")
        if len(cols) < 12 or cols[0] != "5" or not cols[11].strip():
            continue
        lines.setdefault((int(cols[2]), int(cols[3]), int(cols[4])), []).append((cols[11], float(cols[10])))
        confs.append(float(cols[10]))
    ordered = [words for _, words in sorted(lines.items())]
    text = [" ".join(w for w, _ in words) for words in ordered]
    line_conf = [round(min(c for _, c in words), 1) for words in ordered]
    return text, line_conf, round(sum(confs) / len(confs), 1) if confs else 0.0


# ---------------------------------------------------------------- ticket layout
# Rules for this POS's "PRODUCTIEBON" layout. Adjust here when the layout differs.

MONTHS = {"jan": 1, "feb": 2, "mrt": 3, "mar": 3, "apr": 4, "mei": 5, "may": 5, "jun": 6,
          "jul": 7, "aug": 8, "sep": 9, "okt": 10, "oct": 10, "nov": 11, "dec": 12}
HEADER_RE = re.compile(r"PRODUCTIE\s*BON\s*[:;]?\s*(.*)", re.I)
ITEM_RE = re.compile(r"^([0-9Il|]{1,3})\s*[xX×]\s*(\S.*)$")
DATE_RE = re.compile(r"(\d{1,2})\s*-\s*([A-Za-z]{3})\s*-\s*(\d{4})\s+(\d{1,2})[:.](\d{2})")
# Footer: "Kassa <register> <register name> <ticket number>", e.g. "Kassa 3 Keuken 0320",
# "Kassa 1 bar links OUD 0308". Ticket numbers count per register. Digits are matched
# loosely because OCR can read 0 as O, 1 as I/l, 5 as S, 8 as B.
FOOTER_RE = re.compile(r"K\s*a\s*s\s*s\s*a\s*([0-9OoIl|]{1,2})\s+(.*?)\s*([0-9OoIl|SB]{3,6})\s*$", re.I)
TRAILING_NUMBER_RE = re.compile(r"(?:^|\s)([0-9OoIl|SB]{3,6})\s*$")
DIGIT_FIXES = str.maketrans("OoIl|SB", "0011158")
TABLE_RE = re.compile(r"^\s*Tafel\s*[:#]?\s*(\S+)", re.I)


def as_digits(s):
    d = s.translate(DIGIT_FIXES)
    return d if d.isdigit() else None


def parse_ticket(lines, line_conf=None):
    t = {"station": None, "register": None, "register_name": None, "ticket_number": None, "ticket_number_confidence": None,
         "table": None, "printed_at": None, "items": [], "other_lines": []}
    line_conf = line_conf or [None] * len(lines)
    for line, conf in zip(lines, line_conf):
        line = line.strip()
        if m := HEADER_RE.search(line):
            t["station"] = m.group(1).strip() or None
        elif m := DATE_RE.search(line):
            d, mon, y, hh, mm = m.groups()
            if mon.lower() in MONTHS:
                t["printed_at"] = datetime(int(y), MONTHS[mon.lower()], int(d), int(hh), int(mm)).astimezone().isoformat()
            else:
                t["other_lines"].append(line)
        elif (m := FOOTER_RE.search(line)) and as_digits(m.group(3)):
            reg = as_digits(m.group(1))
            t["register"] = int(reg) if reg else None
            t["ticket_number"] = as_digits(m.group(3))
            t["ticket_number_confidence"] = conf
            t["register_name"] = m.group(2).strip() or None
        elif (m := TABLE_RE.match(line)) and not t["items"]:
            t["table"] = m.group(1)
        elif m := ITEM_RE.match(line):
            qty = int(re.sub(r"[Il|]", "1", m.group(1)))
            t["items"].append({"qty": qty, "name": m.group(2).strip(), "notes": []})
        elif t["items"] and line:
            t["items"][-1]["notes"].append(line)  # modifiers / remarks below an item
        elif line:
            t["other_lines"].append(line)
    if t["ticket_number"] is None:  # footer not recognised: fall back to a number ending the last line
        for line, conf in reversed(list(zip(lines, line_conf))):
            if line.strip():
                if (m := TRAILING_NUMBER_RE.search(line)) and as_digits(m.group(1)):
                    t["ticket_number"], t["ticket_number_confidence"] = as_digits(m.group(1)), conf
                    t["ticket_number_guessed"] = True
                break
    return t


# ---------------------------------------------------------------- pipeline

class Processor:
    def __init__(self, args, spool):
        self.args, self.spool = args, spool
        self.archive = Path(args.state_dir, "archive") if not args.no_archive else None

    def job(self, job):
        tickets = split_tickets(job["data"], self.args.codepage)
        if not tickets:
            log.info("job from %s: %d bytes, nothing printable (status query?)",
                     job["pos_ip"], len(job["data"]))
        for idx, tk in enumerate(tickets):
            try:
                self.ticket(job, idx, tk)
            except Exception:
                log.exception("failed to process ticket from %s", job["pos_ip"])

    def ticket(self, job, idx, tk):
        warnings = []
        if job["missing_bytes"]:
            warnings.append(f"capture missed {job['missing_bytes']} bytes")
        img, conf = None, None
        lines = [l for l in tk["text"].splitlines() if l.strip()]
        line_conf = [100.0] * len(lines)  # plain text from the POS needs no OCR
        if tk["images"]:
            img = stack(tk["images"])
            ocr_lines, ocr_conf, conf = ocr(img, self.args.lang, self.args.psm)
            lines += ocr_lines
            line_conf += ocr_conf
            if conf < self.args.min_confidence:
                warnings.append(f"low OCR confidence {conf}")
        parsed = parse_ticket(lines, line_conf)
        if parsed["ticket_number"] is None:
            warnings.append("NO TICKET NUMBER")
        elif parsed.pop("ticket_number_guessed", False):
            warnings.append("ticket number taken from last line, footer not recognised")
        elif (parsed["ticket_number_confidence"] or 0) < self.args.min_confidence:
            warnings.append(f"ticket number uncertain (confidence {parsed['ticket_number_confidence']})")
        if not parsed["items"]:
            warnings.append("no item lines recognised")

        digest = hashlib.sha1(job["data"]).hexdigest()
        payload = {
            "id": f"{digest[:16]}-{idx}",
            "captured_at": datetime.fromtimestamp(job["started"], timezone.utc).isoformat(),
            "pos_ip": job["pos_ip"],
            "printer_ip": self.args.printer_ip,
            **parsed,
            "raw_text": "\n".join(lines),
            "ocr_confidence": conf,
            "warnings": warnings,
        }

        log.info("ticket %s (%s): %s%s", parsed["ticket_number"], parsed["station"],
                 ", ".join(f"{i['qty']}x {i['name']}" for i in parsed["items"]) or "-",
                 f"  [{'; '.join(warnings)}]" if warnings else "")
        if self.archive:
            day = self.archive / datetime.now().strftime("%Y-%m-%d")
            if not day.exists():
                day.mkdir(parents=True)
                self.prune_archive()
            stem = day / f"{datetime.now():%H%M%S}-{payload['id']}"
            if img is not None:
                stem.with_suffix(".png").write_bytes(png_bytes(img))
            stem.with_suffix(".json").write_text(json.dumps(payload, indent=2, ensure_ascii=False))
        if self.args.dry_run:
            if sys.stdout.isatty():  # under systemd the log line and the archive are enough
                print(json.dumps(payload, indent=2, ensure_ascii=False), flush=True)
        else:
            self.spool.put(payload)
        Stats.tickets_seen += 1
        Stats.last_ticket_at = datetime.now(timezone.utc).isoformat()


    def prune_archive(self):
        cutoff = time.time() - self.args.archive_days * 86400
        for day in self.archive.iterdir():
            if day.is_dir() and day.stat().st_mtime < cutoff:
                for f in day.iterdir():
                    f.unlink()
                day.rmdir()


def png_bytes(img):
    buf = io.BytesIO()
    img.save(buf, "PNG", optimize=True)
    return buf.getvalue()


def sign_request(secret, body, timestamp=None, nonce=None):
    """Headers that authenticate `body` (bytes) to the API.

    The signature covers "{timestamp}.{nonce}.{body}", so changing the body breaks it,
    an old request is refused once the timestamp is outside the API's window and a
    copied request is refused because the API only accepts each nonce once.
    """
    timestamp = str(int(time.time()) if timestamp is None else timestamp)
    nonce = nonce or secrets.token_hex(16)
    message = timestamp.encode() + b"." + nonce.encode() + b"." + body
    return {
        "X-Bridge-Timestamp": timestamp,
        "X-Bridge-Nonce": nonce,
        "X-Bridge-Signature": hmac.new(secret.encode(), message, hashlib.sha256).hexdigest(),
    }


class Api:
    """Signed JSON POSTs to the Laravel app."""

    def __init__(self, url, key_id, secret, timeout=10):
        self.url, self.key_id, self.secret, self.timeout = url.rstrip("/"), key_id, secret, timeout

    def post(self, path, body):
        """POST and return the JSON answer, which always has a "status" field.

        Anything else than a JSON answer from the app (a captive portal, a proxy
        error page) raises, so a ticket is never dropped because of it.
        """
        req = urllib.request.Request(self.url + path, data=body, method="POST",
                                     headers={"Content-Type": "application/json", "Accept": "application/json",
                                              "X-Bridge-Key": self.key_id, **sign_request(self.secret, body)})
        with urllib.request.urlopen(req, timeout=self.timeout) as r:
            try:
                answer = json.loads(r.read(4096))
                answer["status"]
                return answer
            except (ValueError, KeyError, TypeError):
                raise OSError(f"unexpected answer from {self.url + path} (HTTP {r.status})") from None


class Heartbeat(threading.Thread):
    """Reports to the app every 30 seconds and carries out the command it may get back.

    The Pi accepts no incoming connections, so a restart or reboot asked for on the
    TV control page arrives in the answer to this heartbeat.
    """

    def __init__(self, api, spool, iface, state_dir, interval=30):
        super().__init__(daemon=True)
        self.api, self.spool, self.iface, self.state_dir, self.interval = api, spool, iface, Path(state_dir), interval

    def status(self):
        return {
            "hostname": socket.gethostname(),
            "version": VERSION,
            "uptime_seconds": read_uptime(),
            "iface": self.iface,
            "iface_up": read_link_up(self.iface),
            "capturing": Stats.capturing,
            "tickets_seen": Stats.tickets_seen,
            "last_ticket_at": Stats.last_ticket_at,
            "spool_pending": len(list(self.spool.dir.glob("*.json"))),
            "spool_failed": len(list(self.spool.failed.glob("*.json"))),
        }

    def run(self):
        while True:
            try:
                answer = self.api.post("/heartbeat", json.dumps(self.status()).encode())
                self.carry_out(answer.get("command"))
            except (urllib.error.URLError, OSError) as e:
                log.warning("heartbeat failed (%s)", e)
            time.sleep(self.interval)

    def carry_out(self, command):
        if command == "restart":
            log.warning("restart requested from the TV control page")
            os.kill(os.getpid(), signal.SIGTERM)  # systemd starts us again (Restart=always)
        elif command == "reboot":
            log.warning("reboot requested from the TV control page")
            # The root-owned kitchen-tickets-reboot.path unit watches for this file and reboots;
            # the reader itself has no permission to reboot.
            (self.state_dir / "reboot-requested").touch()
        elif command:
            log.warning("ignoring unknown command %r", command)


def read_uptime():
    try:
        return int(float(Path("/proc/uptime").read_text().split()[0]))
    except (OSError, ValueError, IndexError):
        return None


def read_link_up(iface):
    """True when a cable is plugged into the mirror port and the switch is on the other end."""
    try:
        return Path("/sys/class/net", iface, "operstate").read_text().strip() == "up"
    except OSError:
        return None


class Spool(threading.Thread):
    """Durable outbox: tickets are written to disk first and deleted once the API accepts them.

    Requests are signed when they are sent, not when they are spooled, so tickets
    that waited out an outage still fall inside the API's timestamp window.
    """

    def __init__(self, directory, api):
        super().__init__(daemon=True)
        self.dir, self.api = Path(directory), api
        self.failed = self.dir / "failed"
        self.failed.mkdir(parents=True, exist_ok=True)
        self.wake = threading.Event()

    def put(self, payload):
        name = f"{time.time_ns()}-{payload['id']}.json"
        tmp = self.dir / (name + ".tmp")
        tmp.write_text(json.dumps(payload, ensure_ascii=False))
        tmp.rename(self.dir / name)
        self.wake.set()

    def send_one(self, path):
        try:
            status = self.api.post("", path.read_bytes())["status"]
            if status not in ("created", "duplicate"):
                raise OSError(f"unexpected status {status!r}")
            log.info("sent %s (%s)", path.name, status)
            path.unlink()
            return True
        except urllib.error.HTTPError as e:
            if e.code == 401:  # wrong secret or clock: fixable on our side, so keep the ticket
                log.error("API refused the signature (HTTP 401): check the secret, key id and the clock")
                return False
            if 400 <= e.code < 500 and e.code not in (408, 425, 429):
                log.error("API rejected %s (HTTP %s), moved to failed/", path.name, e.code)
                path.rename(self.failed / path.name)
                return True
            log.warning("API error HTTP %s, will retry", e.code)
        except (urllib.error.URLError, OSError) as e:
            log.warning("API unreachable (%s), will retry", e)
        return False

    def drain(self):
        for path in sorted(self.dir.glob("*.json")):
            if not self.send_one(path):
                return False
        return True

    def run(self):
        backoff = 1
        while True:
            if self.drain():
                backoff = 1
                self.wake.wait(30)
            else:
                time.sleep(backoff)
                backoff = min(backoff * 2, 300)
            self.wake.clear()


def report(state_dir, day):
    """Print what was read on one day, from the archive, to check it against the paper tickets."""
    folder = Path(state_dir, "archive", day)
    tickets = sorted((json.loads(p.read_text()) for p in folder.glob("*.json")), key=lambda t: t["captured_at"]) \
        if folder.is_dir() else []
    if not tickets:
        print(f"No tickets archived for {day} in {folder}")
        return
    print(f"{'time':8}  {'number':6}  {'register':14}  {'conf':>5}  items / warnings")
    for t in tickets:
        captured = datetime.fromisoformat(t["captured_at"]).astimezone().strftime("%H:%M:%S")
        register = f"{t.get('register') or '?'} {t.get('register_name') or ''}".strip()
        items = ", ".join(f"{i['qty']}x {i['name']}" for i in t["items"]) or "-"
        conf = t.get("ticket_number_confidence")
        print(f"{captured:8}  {t['ticket_number'] or '????':6}  {register[:14]:14}  {conf if conf is not None else '-':>5}  "
              f"{items}{'  !! ' + '; '.join(t['warnings']) if t['warnings'] else ''}")
    numbered = [t for t in tickets if t["ticket_number"]]
    print(f"\n{len(tickets)} tickets, {len(numbered)} with a number, "
          f"{sum(1 for t in tickets if t['warnings'])} with warnings (check their PNG in {folder})")


def read_secret(ap, args):
    """The shared secret: systemd credential first (never in the environment), then --secret-file."""
    creds = os.environ.get("CREDENTIALS_DIRECTORY")
    path = Path(creds, "bridge-secret") if creds else Path(args.secret_file) if args.secret_file else None
    if path is None:
        ap.error("no secret: run under systemd (LoadCredential=bridge-secret) or pass --secret-file")
    secret = path.read_text().strip()
    if len(secret) < 32:
        ap.error(f"secret in {path} is too short (generate one with: openssl rand -hex 32)")
    return secret


def check_url(ap, args):
    """Only HTTPS, so the ticket contents and signatures can't be read on the way."""
    url = urlparse(args.api_url)
    if url.scheme == "https":
        return
    if url.scheme == "http" and args.allow_insecure_localhost and url.hostname in ("localhost", "127.0.0.1", "::1"):
        log.warning("sending to %s without TLS (development only)", args.api_url)
        return
    ap.error("--api-url must use https://")


def main():
    env = os.environ.get
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--iface", default=env("TICKETS_IFACE", "eth0"), help="mirror port interface")
    ap.add_argument("--printer-ip", default=env("TICKETS_PRINTER_IP"))
    ap.add_argument("--port", type=int, default=int(env("TICKETS_PORT", "9100")))
    ap.add_argument("--api-url", default=env("TICKETS_API_URL"),
                    help="e.g. https://keuken.pinoke.net/api/kitchen-tickets")
    ap.add_argument("--key-id", default=env("TICKETS_KEY_ID", "bridge-1"), help="must match TICKET_BRIDGE_KEY_ID")
    ap.add_argument("--secret-file", help="file holding the shared secret; under systemd the "
                    "bridge-secret credential is used instead")
    ap.add_argument("--allow-insecure-localhost", action="store_true",
                    help="development only: allow http:// to localhost")
    ap.add_argument("--state-dir", default=env("STATE_DIRECTORY", env("TICKETS_STATE_DIR", "./state")),
                    help="spool + archive location")
    ap.add_argument("--no-archive", action="store_true", help="don't keep PNG/JSON copies of tickets")
    ap.add_argument("--archive-days", type=int, default=int(env("TICKETS_ARCHIVE_DAYS", "30")))
    ap.add_argument("--lang", default=env("TICKETS_OCR_LANG", "nld"))
    ap.add_argument("--psm", type=int, default=6, help="tesseract page segmentation mode")
    ap.add_argument("--min-confidence", type=float, default=75.0)
    ap.add_argument("--codepage", default="cp858")
    ap.add_argument("--pcap", help="replay a tcpdump capture instead of listening live")
    ap.add_argument("--dry-run", action="store_true", default=env("TICKETS_DRY_RUN", "") == "1",
                    help="observe only: read and archive tickets but send nothing (also TICKETS_DRY_RUN=1)")
    ap.add_argument("--report", nargs="?", const=datetime.now().strftime("%Y-%m-%d"), metavar="YYYY-MM-DD",
                    help="print the tickets archived on that day (default today) and exit")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args()

    if args.report:
        report(args.state_dir, args.report)
        return
    if not args.printer_ip:
        ap.error("--printer-ip (or TICKETS_PRINTER_IP) is required")

    logging.basicConfig(level=logging.DEBUG if args.verbose else logging.INFO,
                        format="%(asctime)s %(levelname)s %(message)s")
    if args.dry_run and not args.pcap:
        log.warning("OBSERVE MODE: tickets are read and archived in %s, nothing is sent to the app",
                    Path(args.state_dir, "archive"))
    api = None if args.dry_run else Api(args.api_url or ap.error("--api-url (or TICKETS_API_URL) is required unless --dry-run"),
                                        args.key_id, read_secret(ap, args))
    if api:
        check_url(ap, args)
    spool = Spool(Path(args.state_dir, "spool"), api) if api else None
    proc = Processor(args, spool)

    if args.pcap:  # replay: process synchronously, then try to deliver once
        table = FlowTable(args.printer_ip, args.port, proc.job)
        for ts, lt, frame in pcap_frames(args.pcap):
            if pkt := parse_tcp(lt, frame):
                table.packet(pkt, ts)
                table.expire(ts)
        table.expire(0, everything=True)
        if spool and not spool.drain():
            log.warning("some tickets are still in %s", spool.dir)
        return

    jobs = queue.Queue()

    def worker():
        while True:
            proc.job(jobs.get())

    threading.Thread(target=worker, daemon=True).start()
    if spool:
        spool.start()
        Heartbeat(api, spool, args.iface, args.state_dir).start()
    table = FlowTable(args.printer_ip, args.port, jobs.put)
    last_expire = 0.0
    for now, lt, frame in live_frames(args.iface):
        if frame and (pkt := parse_tcp(lt, frame)):
            table.packet(pkt, now)
        if now - last_expire >= 0.5:
            table.expire(now)
            last_expire = now


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        pass
