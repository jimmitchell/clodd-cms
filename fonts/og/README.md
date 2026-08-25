# OG card fonts

The social card in `src/OgImage.php` is set in **Nimbus Sans** — URW's Helvetica
clone, drawn as the PostScript substitute for it and freely redistributable
(AFPL/GPL with font exception). Upstream is
[ArtifexSoftware/urw-base35-fonts](https://github.com/ArtifexSoftware/urw-base35-fonts),
which is what Debian's `fonts-urw-base35` packages.

    og-regular.otf   NimbusSans-Regular.otf
    og-bold.otf      NimbusSans-Bold.otf

**Why a file here rather than the host's font.** GD needs a real font — the
`system-ui` the pages ask for resolves in the reader's browser, not on the
server — and resolving it from the host made the card's face a property of the
machine: a rebuilt server or a missing apt package would quietly restyle every
card, and a card is the one thing this CMS produces that nobody here ever looks
at. Pinned, a card drawn on a laptop and a card drawn in production are the same
picture.

**Why a Helvetica clone.** A card is read in a timeline beside everyone else's,
where a neutral grotesque is the closest a single fixed face gets to "whatever
that reader's system font is". Liberation Sans, tried first, is Arial's
letterforms rather than Helvetica's — angled terminals on C and t, a spurred G,
a curled R leg.

**Changing it.** Drop in a replacement pair under the same two names, in `.otf`
or `.ttf`, and bump `OgImage::DESIGN_VERSION` — without that bump every card
already on disk keeps its old face on a build that reports success. Nothing in
`OgImage` is tuned to a named face (`lineHeight()` measures the resolved font),
so no other change is needed. Both files must be present; a lone regular falls
through to `OgImage::SYSTEM_FONTS`.

Nginx denies `/fonts/`, so nothing here is reachable over the web.
