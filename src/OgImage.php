<?php

declare(strict_types=1);

namespace CMS;

use RuntimeException;

/**
 * Generates an Open Graph PNG image (1200×630) for a published post.
 *
 * Uses PHP GD with FreeType. Requires the gd extension compiled with
 * --with-freetype (standard in php:8.x-fpm Docker images with the extra
 * libfreetype6-dev apt package installed).
 *
 * The card is the site's dark scheme, not a palette of its own. Its three
 * colours are lifted verbatim from the dark-mode tokens in `theme.css`, so a
 * social preview — usually the first thing anyone sees of a post — looks like
 * the page it opens.
 *
 * The type is the site's too, again: `theme.css` loads DM Sans, and the static
 * cut of it is pinned in `fonts/og/` as og-regular/og-bold. That is what the
 * pin is for — GD needs a real file and cannot read the variable `.woff2` the
 * pages download, so the family has to arrive here as a second copy rather than
 * as the same asset. Keep the two in step: a card set in a face the page does
 * not use is the one kind of mismatch nobody here will see.
 *
 * Pinned rather than resolved from the host, which is what 1.32.0 did while the
 * pages were on `system-ui`: that made the card's face a property of the
 * machine, where a rebuilt server or a missing apt package quietly restyles
 * every card. SYSTEM_FONTS survives only as the fallback if the pin goes
 * missing, and nothing here is tuned to a named face regardless — the title's
 * line height is measured from the resolved bold rather than written down, so
 * changing the pin stays a one-directory operation.
 *
 * A host with no usable font throws, and `Builder::buildOgImage()` catches it:
 * the post keeps whatever card it already had and the build still reports
 * success. That is the intended degradation — see the note on the avatar
 * below — but it is silent, so `bin/build.php` stderr is the only place a
 * fontless server announces itself.
 *
 * Change any of that and bump DESIGN_VERSION, or every post already on disk
 * keeps its old card: `Builder::buildOgImage()` only redraws when the hash it
 * stamps changes, and until 1.23.0 that hash knew about the text and the font
 * file but nothing about the design.
 */
class OgImage
{
    /**
     * Stamped into the Builder's OG hash so a design change invalidates the
     * images already written. Bump it whenever the drawing below changes.
     */
    public const DESIGN_VERSION = 12;

    private const WIDTH   = 1200;
    private const HEIGHT  = 630;
    private const PADDING = 80;

    // Colours (R, G, B), taken from the dark-mode block of theme.css.
    private const BG_COLOR    = [26,  23,  21];   // #1A1715 --color-bg
    private const TITLE_COLOR = [237, 230, 220];  // #EDE6DC --color-text
    private const META_COLOR  = [163, 154, 142];  // #A39A8E --color-muted

    // Type scale. The title carries the card — a preview is read at thumbnail
    // size in a timeline, where it is the only line that survives — but the site
    // name still has to be legible at that size, which is what sets its floor.
    private const META_SIZE  = 36;
    private const TITLE_MAX  = 61;
    private const TITLE_MIN  = 32;

    /**
     * How many lines the title may take before it is set smaller. Four lines of
     * TITLE_MAX is very close to the full height below the site name, so the
     * fit loop can shrink to TITLE_MIN without the block ever climbing into it.
     */
    private const TITLE_LINES_MAX = 4;

    /** Clear air between the site name and the tallest the title may reach. */
    private const TITLE_CLEARANCE = 48;

    /**
     * The avatar's diameter, and the gap to the site name beside it.
     *
     * Deliberately *not* the header's proportions. `.site-header__avatar` is
     * 32px against a 1rem name, and scaling that ratio onto the card puts the
     * face at a size that is a smudge rather than a likeness — a card is read
     * at about a third of its width in a timeline, not at reading distance.
     */
    private const AVATAR_EDGE = 76;
    private const AVATAR_GAP  = 26;

    /**
     * The avatar's corner radius, as a fraction of its edge. 0.5 is a circle,
     * which is what `.site-header__avatar` and `.wm-avatar` are.
     *
     * A fraction rather than a measurement, because the two avatars are drawn
     * at sizes that have nothing to do with each other — the header's is 32px
     * and this is 76px, so only the *ratio* makes them read as the same object.
     *
     * It tracks the avatars in theme.css, and pointedly not --radius: the site's
     * panels are cornered with that token and a face is not a panel. Between
     * 1.35.0 and 1.42.0 this was 6/32, following --radius; the circle is back
     * because it is the one shape on the card that says "person" rather than
     * "card". Change the avatars' radius and this follows them, not the token.
     */
    private const AVATAR_RADIUS_RATIO = 0.5;

    /**
     * Air between title lines, as a fraction of the em, added to the ink the
     * resolved face actually measures — see lineHeight().
     *
     * A line carrying both an ascender and a descender is most of an em of ink
     * on its own (a shade over 1.2em in the faces this has been run against),
     * so a line height at or under that sets lines that physically overlap: a
     * "p" comes down into the "t" on the line below. Writing the total down as
     * a constant only works while the face is known, and it no longer is — a
     * value tuned for one face is a collision on the next. So the ink is
     * measured and this is the air on top of it.
     *
     * Raising it costs lines: with an avatar drawn there is room for exactly
     * TITLE_LINES_MAX at TITLE_MAX, and no more.
     */
    private const LINE_AIR = 0.09;

    /**
     * The probe lineHeight() measures. Ascenders, cap height, and the deepest
     * descenders a Latin face draws — whatever the real title turns out to be,
     * its ink fits inside this.
     */
    private const EXTENT_PROBE = 'HXhkbdfl gjpqy';

    /**
     * Where to look for a sans to draw with, most-preferred first, as
     * [regular, bold] pairs.
     *
     * GD needs a file, and with the pin gone there is none of the site's own
     * face to hand — so the card falls back to the host's default sans.
     *
     * This is the *fallback*, not the design: `fonts/og/` carries the face the
     * card is meant to be set in, and the list below only runs when that pin is
     * missing. Nothing here should ever be reached on a healthy checkout.
     *
     * The order is a preference, not a guess at what exists — several of these
     * are usually installed together. None of them is DM Sans: no stock host
     * carries a geometric humanist, so a fallback card is a visibly different
     * card, not a near miss. What is left to choose between is grotesques, and
     * the list runs from the most neutral outward — Nimbus Sans (Helvetica's
     * letterforms), then Liberation Sans (Arial's: angled terminals on C and t,
     * a spurred G, a curled R leg), then DejaVu, wider and rounder still, as
     * the near-universal floor.
     *
     * Arial rather than SF on macOS for a dull reason: SFNS.ttf is a variable
     * font and FreeType hands GD its default instance, so a "bold" drawn from
     * it would come back regular.
     *
     * Consequence worth knowing: cards drawn from two different entries here
     * are not the same file. The font is stamped into the Builder's hash by
     * path *and* mtime, so falling back — or recovering — redraws the whole set
     * rather than leaving a mix of two faces.
     */
    private const SYSTEM_FONTS = [
        // Nimbus Sans — Debian's fonts-urw-base35, and the Dockerfile installs
        // it. GD reads CFF outlines, so .otf is fine.
        ['/usr/share/fonts/opentype/urw-base35/NimbusSans-Regular.otf',
         '/usr/share/fonts/opentype/urw-base35/NimbusSans-Bold.otf'],
        ['/usr/share/fonts/opentype/urw-base35/NimbusSanL-Reg.otf',
         '/usr/share/fonts/opentype/urw-base35/NimbusSanL-Bol.otf'],
        // Debian / Ubuntu — the Docker image and the server.
        ['/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
         '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf'],
        ['/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
         '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf'],
        ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
         '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
        ['/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf',
         '/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf'],
        // Alpine, Fedora, RHEL.
        ['/usr/share/fonts/liberation-sans/LiberationSans-Regular.ttf',
         '/usr/share/fonts/liberation-sans/LiberationSans-Bold.ttf'],
        ['/usr/share/fonts/dejavu/DejaVuSans.ttf',
         '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'],
        // macOS, for local development.
        ['/System/Library/Fonts/Supplemental/Arial.ttf',
         '/System/Library/Fonts/Supplemental/Arial Bold.ttf'],
        ['/Library/Fonts/Arial.ttf',
         '/Library/Fonts/Arial Bold.ttf'],
    ];

    /**
     * The pinned face: `og-regular` and `og-bold` in the override directory,
     * in either outline format. DM Sans arrives as TrueType; GD reads CFF
     * (`.otf`) just as happily, and several of the free faces worth pinning
     * ship only as OTF, so refusing that extension would have meant converting
     * a font to satisfy a string literal.
     *
     * Both halves must be present in the same format-agnostic pair; a lone
     * regular falls through to SYSTEM_FONTS rather than drawing the title in
     * a face that is not bold.
     */
    private const OVERRIDE_STEM       = ['og-regular', 'og-bold'];
    private const OVERRIDE_EXTENSIONS = ['otf', 'ttf'];

    /**
     * Resolved, and — since 1.34.0 put the site name in bold to match
     * `.site-header__title` — currently drawn nowhere. It is kept, and the pin
     * is still required to be a complete pair, because `fonts/og/` holds a
     * *family*: accepting a lone bold would let a half-installed pin pass as a
     * whole one, and the next text element the card grows (a date, a byline)
     * is the one that wants this cut.
     */
    private string $fontRegular;

    /** Everything the card draws today. */
    private string $fontBold;

    /**
     * @param string $overrideDir The directory holding the pinned face — see
     *                            OVERRIDE_STEM. A complete pair there wins over
     *                            SYSTEM_FONTS; anything else falls through.
     *                            This is the normal path, not an escape hatch:
     *                            `fonts/og/` is where the card's face lives.
     */
    public function __construct(string $overrideDir = '')
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is not loaded.');
        }

        [$this->fontRegular, $this->fontBold] = $this->resolveFonts($overrideDir);
    }

    /**
     * The bold face this instance draws with, as `path@mtime`.
     *
     * `Builder::buildOgImage()` stamps it into the OG hash. Both halves matter:
     * the path because moving to a host with a different sans has to redraw
     * every card, and the mtime because a font package updated in place keeps
     * its path while the outlines change underneath it.
     */
    public function fontStamp(): string
    {
        return $this->fontBold . '@' . (@filemtime($this->fontBold) ?: 0);
    }

    /**
     * @return array{0: string, 1: string} The regular and bold faces to draw with.
     */
    private function resolveFonts(string $overrideDir): array
    {
        $overrideDir = rtrim($overrideDir, '/\\');

        if ($overrideDir !== '') {
            [$regularStem, $boldStem] = self::OVERRIDE_STEM;
            foreach (self::OVERRIDE_EXTENSIONS as $ext) {
                $regular = $overrideDir . '/' . $regularStem . '.' . $ext;
                $bold    = $overrideDir . '/' . $boldStem . '.' . $ext;
                if (is_readable($regular) && is_readable($bold)) {
                    return [$regular, $bold];
                }
            }
        }

        foreach (self::SYSTEM_FONTS as [$regular, $bold]) {
            if (is_readable($regular) && is_readable($bold)) {
                return [$regular, $bold];
            }
        }

        throw new RuntimeException(
            'No font found to draw the OG card with. Expected '
            . implode(' and ', self::OVERRIDE_STEM) . '.{'
            . implode(',', self::OVERRIDE_EXTENSIONS) . '}'
            . ($overrideDir === '' ? ' in the OG font directory' : ' in ' . $overrideDir)
            . ', and no system sans is installed either '
            . '(Debian: apt-get install fonts-urw-base35).'
        );
    }

    /**
     * Generate and save the OG image PNG.
     *
     * @param string $siteTitle  The site name shown in smaller text at the top.
     * @param string $postTitle  The post title, set large and hung off the foot.
     * @param string $outputPath Absolute path to write the PNG file.
     * @param string $avatarPath Absolute path to a local avatar image, or ''.
     *                           Anything unreadable is skipped rather than
     *                           raised: a missing face is worth a plainer card,
     *                           never no card at all.
     */
    public function generate(
        string $siteTitle,
        string $postTitle,
        string $outputPath,
        string $avatarPath = ''
    ): void {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            throw new RuntimeException('imagecreatetruecolor() failed.');
        }

        // Background
        $bg = imagecolorallocate($img, ...self::BG_COLOR);
        imagefilledrectangle($img, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $bg);

        $titleColor = imagecolorallocate($img, ...self::TITLE_COLOR);
        $metaColor  = imagecolorallocate($img, ...self::META_COLOR);

        $pad  = self::PADDING;
        $maxW = self::WIDTH - ($pad * 2);

        // ── Site title (top left, muted, bold) ───────────────────────────────
        // Bold because `.site-header__title` is: the name is the same lockup in
        // both places, and setting it lighter here made the card's header read
        // as a caption rather than as the site signing its own work.
        //
        // It stays META_COLOR rather than following the page to --color-text.
        // The page can afford two elements at full strength because a reader is
        // at reading distance; a card is scanned at about a third of its width
        // in a timeline, where the post title is the only line that survives and
        // a full-strength name beside it would compete for the one glance the
        // card gets. Weight matches the page, value keeps the card's hierarchy.
        //
        // Cap-aligned to the padding rather than baseline-aligned, so the gap
        // above it reads as the same 80px the title keeps below.
        $metaFoot = $pad;
        if ($siteTitle !== '') {
            $metaBox   = imagettfbbox(self::META_SIZE, 0, $this->fontBold, $siteTitle);
            $ascent    = abs($metaBox[7]);
            $descent   = max(0, $metaBox[1]);
            $metaY     = $pad + $ascent;
            $metaFoot  = $metaY + $descent;
            $metaX     = $pad;

            // Prepared before anything is drawn, so an unreadable file leaves
            // the plain name exactly where it would have been.
            $avatar = $avatarPath === '' ? null : $this->prepareAvatar($avatarPath);

            if ($avatar !== null) {
                $edge = self::AVATAR_EDGE;
                $this->drawAvatarMask($img, $avatar, $pad, $pad, $edge);
                imagedestroy($avatar);

                // The name sits on the avatar's centre line rather than sharing
                // its top edge — the lockup reads as one object that way.
                $metaX    = $pad + $edge + self::AVATAR_GAP;
                $metaY    = $pad + (int) round(($edge + $ascent - $descent) / 2);
                $metaFoot = $pad + $edge;
            }

            imagettftext($img, self::META_SIZE, 0, $metaX, $metaY, $metaColor, $this->fontBold, $siteTitle);
        }

        // ── Post title (hung off the foot, bold, word-wrapped) ────────────────
        // Anchored to the bottom, not centred: a short title and a long one then
        // share a baseline, which is what makes a run of cards look like a set.
        $titleSize = $this->fitFontSize($postTitle, $this->fontBold, $maxW);
        $lines     = $this->wrapText($postTitle, $this->fontBold, $titleSize, $maxW);

        $lineH = $this->lineHeight($titleSize);

        // A title long enough to still overrun at TITLE_MIN would grow upwards
        // through the site name and off the top. Nothing writes one today, but
        // an over-long card is a silently broken card, so trim it to fit.
        $room     = self::HEIGHT - $pad - $metaFoot - self::TITLE_CLEARANCE;
        $maxLines = max(1, (int) ($room / $lineH));
        if (count($lines) > $maxLines) {
            $lines   = array_slice($lines, 0, $maxLines);
            $lastIdx = $maxLines - 1;
            $lines[$lastIdx] = $this->ellipsize($lines[$lastIdx], $this->fontBold, $titleSize, $maxW);
        }

        // wrapText() never breaks inside a word, so a single long one — a URL, a
        // hashtag, a German compound — comes back as one over-wide line and runs
        // off the right edge whatever size it is set at.
        $lines = array_map(
            fn (string $line): string => $this->fitLineWidth($line, $this->fontBold, $titleSize, $maxW),
            $lines
        );

        // Sit the last line's *descender* on the padding, so a title ending in
        // "g" keeps the same visual margin as one ending in "e".
        $lastBox   = imagettfbbox($titleSize, 0, $this->fontBold, end($lines));
        $descent   = max(0, $lastBox[1]);
        $lastBaseY = self::HEIGHT - $pad - $descent;

        $lineCount = count($lines);
        foreach ($lines as $i => $line) {
            $y = $lastBaseY - (($lineCount - 1 - $i) * $lineH);
            imagettftext($img, $titleSize, 0, $pad, $y, $titleColor, $this->fontBold, $line);
        }

        // ── Save ──────────────────────────────────────────────────────────────
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create output directory: ' . $dir);
        }

        if (!imagepng($img, $outputPath, 9)) {
            throw new RuntimeException('imagepng() failed writing to ' . $outputPath);
        }
        imagedestroy($img);
    }

    // ── Private helpers ───────────────────────────────────────────────────────


    /**
     * The avatar, centre-cropped square and resampled to AVATAR_EDGE, with any
     * transparency already composited onto the card ground.
     *
     * The crop is the same rule `Media::squareWebpDataUri()` applies to the
     * header copy — take the largest centred square — so the face on the card
     * and the face on the page are framed alike. Returns null for anything GD
     * will not open, which is the caller's signal to draw the name alone.
     */
    private function prepareAvatar(string $path): ?\GdImage
    {
        if (!is_file($path)) {
            return null;
        }

        $source = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($path),
            'png'         => @imagecreatefrompng($path),
            'webp'        => @imagecreatefromwebp($path),
            'gif'         => @imagecreatefromgif($path),
            default       => false,
        };

        if ($source === false) {
            return null;
        }

        try {
            $srcW = imagesx($source);
            $srcH = imagesy($source);
            if ($srcW < 1 || $srcH < 1) {
                return null;
            }

            $side   = min($srcW, $srcH);
            $edge   = self::AVATAR_EDGE;
            $square = imagecreatetruecolor($edge, $edge);
            if ($square === false) {
                return null;
            }

            // Fill with the card ground first and leave blending on, so a PNG
            // with a transparent surround composites onto the card instead of
            // arriving as a black box.
            imagefilledrectangle($square, 0, 0, $edge - 1, $edge - 1, imagecolorallocate($square, ...self::BG_COLOR));
            imagealphablending($square, true);
            imagecopyresampled(
                $square,
                $source,
                0,
                0,
                (int) (($srcW - $side) / 2),
                (int) (($srcH - $side) / 2),
                $edge,
                $edge,
                $side,
                $side
            );

            return $square;
        } finally {
            imagedestroy($source);
        }
    }

    /**
     * Copy $avatar onto $dst masked to AVATAR_RADIUS_RATIO, top-left at ($x, $y).
     *
     * GD has no rounded crop, so this is a per-pixel copy that skips anything
     * outside the shape. The outermost pixel is blended toward the card ground
     * by how far it falls inside the edge — without it the rim renders hard and
     * stair-stepped, which is very visible against a flat background.
     *
     * How far inside the shape a pixel falls is the signed distance to a rounded
     * box: push the sample point in from each side by the corner radius, and
     * what is left is a plain box whose distance is easy to take. That reduces
     * to the straight-edge distance along the flats and to the arc's distance in
     * the corners, which is the whole point — one expression covers both, so the
     * blend along a flat edge and the blend around a corner cannot drift apart.
     * At the ratio's top end the flats vanish entirely and the same expression
     * is a circle, which is why a shape change here is a constant and not code.
     */
    private function drawAvatarMask(\GdImage $dst, \GdImage $avatar, int $x, int $y, int $edge): void
    {
        $radius = $edge * self::AVATAR_RADIUS_RATIO;
        $half   = $edge / 2;
        // How far the corner arcs' centres sit in from the square's own centre.
        $inset  = $half - $radius;
        [$bgR, $bgG, $bgB] = self::BG_COLOR;

        for ($py = 0; $py < $edge; $py++) {
            for ($px = 0; $px < $edge; $px++) {
                $qx = abs(($px + 0.5) - $half) - $inset;
                $qy = abs(($py + 0.5) - $half) - $inset;

                $outside = sqrt(max($qx, 0.0) ** 2 + max($qy, 0.0) ** 2);
                $inside  = min(max($qx, $qy), 0.0);
                // Negative inside the shape, so flip it to a depth.
                $depth   = $radius - ($outside + $inside);

                if ($depth <= 0.0) {
                    continue;
                }

                $rgb = imagecolorat($avatar, $px, $py);
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;

                $coverage = min(1.0, $depth);
                if ($coverage < 1.0) {
                    $r = (int) round($r * $coverage + $bgR * (1 - $coverage));
                    $g = (int) round($g * $coverage + $bgG * (1 - $coverage));
                    $b = (int) round($b * $coverage + $bgB * (1 - $coverage));
                }

                // Truecolor, so the packed integer is the colour — no allocation.
                imagesetpixel($dst, $x + $px, $y + $py, ($r << 16) | ($g << 8) | $b);
            }
        }
    }

    /**
     * Find the largest font size at which $text wraps to no more than
     * TITLE_LINES_MAX lines inside $maxWidth.
     */
    private function fitFontSize(string $text, string $font, int $maxWidth): int
    {
        for ($size = self::TITLE_MAX; $size >= self::TITLE_MIN; $size--) {
            $lines = $this->wrapText($text, $font, $size, $maxWidth);
            if (count($lines) <= self::TITLE_LINES_MAX) {
                return $size;
            }
        }
        return self::TITLE_MIN;
    }

    /**
     * Distance between title baselines at $fontSize: the bold face's real ink
     * for a line with both an ascender and a descender, plus LINE_AIR of the em.
     *
     * Measured rather than written down because the face is resolved at run
     * time — see LINE_AIR. FreeType hints at the requested size, so this is
     * measured at the size it will be drawn at rather than scaled from one em.
     */
    private function lineHeight(int $fontSize): int
    {
        $box     = imagettfbbox($fontSize, 0, $this->fontBold, self::EXTENT_PROBE);
        $ink     = $box === false ? $fontSize * 1.2 : abs($box[7]) + max(0, $box[1]);
        return (int) round($ink + ($fontSize * self::LINE_AIR));
    }

    /** Width of $text in pixels at $fontSize. */
    private function textWidth(string $text, string $font, int $fontSize): int
    {
        $bbox = imagettfbbox($fontSize, 0, $font, $text);
        return (int) abs($bbox[4] - $bbox[0]);
    }

    /** $text as-is when it fits within $maxWidth, and ellipsized when it does not. */
    private function fitLineWidth(string $text, string $font, int $fontSize, int $maxWidth): string
    {
        return $this->textWidth($text, $font, $fontSize) <= $maxWidth
            ? $text
            : $this->ellipsize($text, $font, $fontSize, $maxWidth);
    }

    /**
     * $text cut down until it and a trailing ellipsis fit within $maxWidth.
     * Always ellipsizes, so it also marks a title truncated for height.
     */
    private function ellipsize(string $text, string $font, int $fontSize, int $maxWidth): string
    {
        $body = rtrim($text);

        while ($body !== '') {
            $candidate = rtrim($body) . '…';
            if ($this->textWidth($candidate, $font, $fontSize) <= $maxWidth) {
                return $candidate;
            }
            $body = mb_substr($body, 0, -1);
        }

        return '…';
    }

    /**
     * Break $text into lines that each fit within $maxWidth at $fontSize.
     *
     * @return string[]
     */
    private function wrapText(string $text, string $font, int $fontSize, int $maxWidth): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $line  = '';

        foreach ($words as $word) {
            $test = $line === '' ? $word : $line . ' ' . $word;

            if ($this->textWidth($test, $font, $fontSize) > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line    = $word;
            } else {
                $line = $test;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }
}
