# Kitchen ticket bridge

A headless Raspberry Pi that listens passively on a UniFi mirror port. It picks up the print jobs the POS sends to the Epson kitchen printer (raw TCP 9100), rebuilds the ticket image and reads it with Tesseract OCR. It then posts each ticket to the Laravel app as a **signed** request. The app adds the order to the kitchen's list as "in preparation", and the customers see it too.

The Pi never talks to the POS or to the printer, and it never transmits on the mirror port. If the Pi stops, the kitchen keeps printing: they then use the numpad as before, and the kitchen screen shows "Ticket reader offline".

## Security model

| Threat | Protection |
| --- | --- |
| Someone reads or changes tickets on the way | HTTPS only (`--api-url` must be `https://`); certificates are verified. |
| Someone posts fake tickets | Every request is signed with HMAC-SHA256 over `timestamp.nonce.body` using a 256-bit shared secret. Laravel checks the signature (`VerifyTicketBridgeSignature`) before it looks at the body. |
| Someone resends a captured request | The timestamp must be within ±5 minutes, and each nonce is accepted only once. |
| The same ticket is delivered twice (the spool retries) | The ticket `id` is a hash of the print job and is stored as unique, so a second delivery gets the answer `duplicate`. |
| The Pi is reachable from the POS network | The mirror interface has no IP address, and nftables drops all inbound traffic except SSH on the uplink. It also drops all outbound traffic on the mirror port. |
| The secret leaks from the Pi | The secret is in `/etc/kitchen-tickets/secret` (root, 0600) and is handed to the service as a systemd credential, not as an environment variable. The service runs as a dynamic user with only `CAP_NET_RAW`. |
| Ticket contents leak to the public | Only the order number and status are broadcast. Ticket images stay on the Pi, and the API refuses them. |

## Install

1. Plug the Pi's Ethernet port into the mirror port. Connect the uplink, Wi-Fi or a second NIC, to a network with internet access.
2. From your machine, in `raspberry-pi/`:

   ```sh
   ./deploy.sh ticket-bridge pi@ticket-bridge.local MIRROR=eth0 UPLINK=wlan0
   ```

   The installer prints a newly generated secret, once.
3. In Laravel's `.env` on the server:

   ```
   TICKET_BRIDGE_KEY_ID=bridge-1
   TICKET_BRIDGE_SECRET=<the printed secret>
   ```
4. Watch the log for tickets arriving: `ssh pi@ticket-bridge.local journalctl -u kitchen-tickets -f`.

The Pi's clock has to be right (NTP is enabled by the installer), otherwise the API refuses the signatures. A refused ticket (HTTP 401) stays in the spool and is retried, so fixing the clock or the secret delivers it after all.

## Status and remote control

Every 30 seconds the signed heartbeat reports:

- whether the mirror cable is up;
- whether the reader is capturing;
- how many tickets are waiting or were refused.

The TV control page shows this. Admins can **restart the reader** there, and super admins can **reboot the Pi**. The Pi accepts no incoming connections, so the command travels back in the answer to the next heartbeat (within 30 seconds):

- **restart:** the reader exits and systemd starts it again;
- **reboot:** the reader cannot reboot the Pi itself. It drops a file that the root-owned `kitchen-tickets-reboot.path` unit watches for.

## Rotating the secret

```sh
ssh pi@ticket-bridge.local 'sudo sh -c "umask 077; openssl rand -hex 32 > /etc/kitchen-tickets/secret; cat /etc/kitchen-tickets/secret"'
```

Put the new value in Laravel's `.env` (and bump `TICKET_BRIDGE_KEY_ID` plus `TICKETS_KEY_ID` in `/etc/kitchen-tickets/kitchen-tickets.env` if you want the logs to show the switch). Then restart both: `sudo systemctl restart kitchen-tickets` on the Pi, and `php artisan config:cache` on the server. Tickets printed in between wait in the spool.

## Test without the Pi

```sh
python3 -m unittest                       # signing + replay of tests/fixtures/tickets.pcap
./ticket_reader.py --pcap tests/fixtures/tickets.pcap --printer-ip 172.220.230.190 --dry-run
# against a local app (http only allowed for localhost):
./ticket_reader.py --pcap tests/fixtures/tickets.pcap --printer-ip 172.220.230.190 \
    --api-url http://127.0.0.1:8000/api/kitchen-tickets --allow-insecure-localhost --secret-file ./secret
```

## Payload (one POST per ticket)

```json
{
  "id": "6e247fd44f8f6b55-0",
  "captured_at": "2026-10-02T16:01:01.252621+00:00",
  "station": "Keuken",
  "register": 3,
  "register_name": "Keuken",
  "ticket_number": "0317",
  "ticket_number_confidence": 95.5,
  "printed_at": "2026-10-02T18:01:00+02:00",
  "items": [{"qty": 1, "name": "Br. Kroket", "notes": []}],
  "raw_text": "PRODUCTIEBON: Keuken\n1xBr. Kroket\n2-okt-2026 18:01\nKassa 3 Keuken 0317",
  "ocr_confidence": 93.8,
  "warnings": []
}
```

- The ticket number is sent exactly as printed (`0317`). Laravel matches it with or without the leading zeros, so a customer typing `317` finds the same order.
- `warnings` is not empty when OCR confidence is low or no items were found. The kitchen sees a warning mark on those orders. A ticket without a number ends up under "Needs a look".
- Lines under an item (e.g. `+ mosterd`) go into that item's `notes`.

## Files on the Pi (`/var/lib/kitchen-tickets`)

- `spool/`: tickets waiting for the API. They are retried with backoff and survive reboots.
- `spool/failed/`: tickets the API rejected as invalid (HTTP 4xx other than 401/429).
- `archive/YYYY-MM-DD/`: PNG and JSON of every ticket, kept for `TICKETS_ARCHIVE_DAYS` days. Use these to check the OCR.

## Adjusting to the ticket layout

The rules are the `*_RE` regexes and `parse_ticket()` in `ticket_reader.py`, in the "ticket layout" section. `raw_text` always contains the full OCR result.
