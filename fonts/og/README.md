# OG card fonts

The social card in `src/OgImage.php` is set in **DM Sans** — the same family
`theme.css` loads for the site, so a shared link and the page it opens are the
one typeface. Upstream is [googlefonts/dm-fonts](https://github.com/googlefonts/dm-fonts),
under the SIL Open Font License (`../OFL.txt`).

    og-regular.ttf   DMSans-Regular.ttf
    og-bold.ttf      DMSans-Bold.ttf

**Why a second copy of a font the site already ships.** The pages download a
variable `.woff2`; GD cannot read either of those things. It needs a static
instance in an outline format FreeType understands, so the family arrives here
as its own pair of files rather than as the assets in `../`. Keep the two in
step — a card set in a face the page does not use is the one mismatch nobody
here would ever see, since a card appears on no page of this site.

**Why pinned rather than resolved from the host.** 1.32.0 drew the card with
whatever sans the machine had installed, which made the typeface a property of
the server: a rebuild or a missing apt package would quietly restyle every card.
Pinned, a card drawn on a laptop and a card drawn in production are the same
picture.

**Changing it.** Drop in a replacement pair under the same two names, in `.otf`
or `.ttf`, and bump `OgImage::DESIGN_VERSION` — without that bump every card
already on disk keeps its old face on a build that reports success. Nothing in
`OgImage` is tuned to a named face (`lineHeight()` measures the resolved font),
so no other change is needed. Both files must be present; a lone regular falls
through to `OgImage::SYSTEM_FONTS`, whose stack is grotesques — none of them
DM Sans, so a fallback card is a visibly different card rather than a near miss.

Nginx serves `/fonts/` but denies `/fonts/og/`, so the site's two `.woff2` files
are downloadable and nothing in here is.
