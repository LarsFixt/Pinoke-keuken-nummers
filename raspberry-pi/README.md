# Raspberry Pi projects

- `ticket-bridge/`: a headless Pi on the switch mirror port. It reads kitchen tickets and posts them, signed, to the app.
- `kiosk/`: the Pi that drives the kitchen display TV.

Code is pushed to the Pis over SSH with `./deploy.sh <project> <user@host>`; the Pis never get access to this repository. This folder is left out of the Laravel deploy (`.gitattributes` export-ignore).
