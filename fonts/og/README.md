# OG card fonts

Normally empty. The social card in `src/OgImage.php` is drawn with whatever
sans the host provides — DejaVu on the Debian server and in Docker, Arial on a
macOS development machine — so there is no font shipped here.

Drop `og-regular.ttf` and `og-bold.ttf` in this directory to pin the card to
one face instead. Both must be present; either one alone is ignored and the
host's font is used. TTF only — GD cannot read WOFF2.

Nginx denies `/fonts/`, so nothing placed here is reachable over the web.
