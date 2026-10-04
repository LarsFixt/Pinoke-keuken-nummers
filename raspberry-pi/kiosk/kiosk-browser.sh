#!/bin/sh
# Started by the desktop session (labwc or wayfire autostart). Keeps Chromium running full screen.
URL=${1:?usage: kiosk-browser.sh <url>}
PREFS="$HOME/.config/chromium/Default/Preferences"

while true; do
    # After a crash or power cut Chromium shows a "restore pages?" bar: mark the last exit as clean.
    if [ -f "$PREFS" ]; then
        sed -i -e 's/"exited_cleanly":false/"exited_cleanly":true/' -e 's/"exit_type":"[^"]*"/"exit_type":"Normal"/' "$PREFS"
    fi

    chromium "$URL" \
        --kiosk \
        --ozone-platform=wayland \
        --noerrdialogs \
        --disable-infobars \
        --no-first-run \
        --disable-session-crashed-bubble \
        --overscroll-history-navigation=0 \
        --disable-pinch \
        --disable-features=Translate,TranslateUI \
        --check-for-update-interval=31536000 \
        --password-store=basic \
        --autoplay-policy=no-user-gesture-required

    sleep 3
done
