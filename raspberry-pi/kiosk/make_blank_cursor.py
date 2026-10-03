#!/usr/bin/env python3
"""Writes a fully transparent Xcursor theme to <dir>/blank.

Wayland compositors (labwc, wayfire) ignore unclutter, which only works on X11.
Pointing XCURSOR_THEME at this theme makes the cursor invisible instead.
Usage: make_blank_cursor.py ~/.icons
"""
import struct
import sys
from pathlib import Path

# Every cursor name Chromium and the compositors ask for, so none falls back to a visible default.
NAMES = """default left_ptr arrow top_left_arrow pointer hand hand1 hand2 text xterm ibeam
vertical-text wait watch progress left_ptr_watch half-busy crosshair cross tcross move fleur
grab grabbing openhand closedhand dnd-move dnd-none all-scroll not-allowed no-drop forbidden
crossed_circle help question_arrow context-menu cell plus copy alias dnd-copy dnd-link
col-resize row-resize sb_h_double_arrow sb_v_double_arrow h_double_arrow v_double_arrow
n-resize s-resize e-resize w-resize ne-resize nw-resize se-resize sw-resize ew-resize ns-resize
nesw-resize nwse-resize top_side bottom_side left_side right_side top_left_corner
top_right_corner bottom_left_corner bottom_right_corner size_hor size_ver size_bdiag size_fdiag
zoom-in zoom-out right_ptr center_ptr draft pencil X_cursor""".split()


def xcursor(size=1):
    """Xcursor file with one fully transparent size x size image."""
    image = struct.pack("<9I", 36, 0xFFFD0002, size, 1, size, size, 0, 0, 0) + b"\0" * (4 * size * size)
    header = struct.pack("<4sIII", b"Xcur", 16, 0x10000, 1)
    toc = struct.pack("<III", 0xFFFD0002, size, 16 + 12)
    return header + toc + image


def main():
    theme = Path(sys.argv[1]).expanduser() / "blank"
    cursors = theme / "cursors"
    cursors.mkdir(parents=True, exist_ok=True)
    (theme / "index.theme").write_text("[Icon Theme]\nName=blank\nComment=Invisible cursor for kiosks\n")
    (cursors / "default").write_bytes(xcursor())
    for name in NAMES:
        link = cursors / name
        if name != "default" and not link.exists():
            link.symlink_to("default")


if __name__ == "__main__":
    main()
