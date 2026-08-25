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
 * The type cannot be lifted the same way. `theme.css` asks for `system-ui`,
 * which resolves in the *reader's* browser, while this draws on the server —
 * there is no single face that is "the site's" any more. So the card is set in
 * Nimbus Sans, pinned in `fonts/og/`: a free Helvetica clone, chosen because a
 * card is read in a timeline next to everyone else's and a neutral grotesque
 * is the closest a fixed face gets to "whatever that reader's system font is".
 * SYSTEM_FONTS is only the fallback if the pin goes missing.
 *
 * Pinned rather than resolved because the alternative made the face a property
 * of the host: a rebuilt server, a different base image, or a missing apt
 * package would each quietly restyle every card. Nothing here is tuned to a
 * named face even so — TITLE_LINE_HEIGHT in particular is measured from the
 * resolved font rather than written down, so changing the pin stays a
 * one-directory operation.
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
    public const DESIGN_VERSION = 8;

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
     * GD needs a file — `system-ui` means nothing to FreeType — so the stack
     * `theme.css` names is approximated by the host's own default sans.
     *
     * This is the *fallback*, not the design: `fonts/og/` carries the face the
     * card is meant to be set in, and the list below only runs when that pin is
     * missing. Nothing here should ever be reached on a healthy checkout.
     *
     * The order is a preference, not a guess at what exists — several of these
     * are usually installed together. Nimbus Sans leads because it is the same
     * face as the pin, so a host that has lost the pin degrades to the nearest
     * thing rather than to something else entirely. Liberation Sans next: it is
     * Arial's metrics and Arial's letterforms, which is a *different* grotesque
     * — angled terminals on C and t, a spurred G, a curled R leg where
     * Helvetica cuts all three flat. DejaVu behind that is the near-universal
     * floor, and wider and rounder still.
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
        // Nimbus Sans — the pinned face, if the host happens to carry it too.
        // Debian: fonts-urw-base35. GD reads CFF outlines, so .otf is fine.
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
     * in either outline format. GD reads CFF (`.otf`) as happily as TrueType,
     * and several of the free grotesques worth pinning — Nimbus Sans among
     * them — ship only as OTF, so refusing that extension would have meant
     * converting a font to satisfy a string literal.
     *
     * Both halves must be present in the same format-agnostic pair; a lone
     * regular falls through to SYSTEM_FONTS rather than drawing the title in
     * a face that is not bold.
     */
    private const OVERRIDE_STEM       = ['og-regular', 'og-bold'];
    private const OVERRIDE_EXTENSIONS = ['otf', 'ttf'];

    private string $fontRegular;
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

        // ── Site title (top left, muted, regular) ────────────────────────────
        // Cap-aligned to the padding rather than baseline-aligned, so the gap
        // above it reads as the same 80px the title keeps below.
        $metaFoot = $pad;
        if ($siteTitle !== '') {
            $metaBox   = imagettfbbox(self::META_SIZE, 0, $this->fontRegular, $siteTitle);
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
                $this->drawCircle($img, $avatar, $pad, $pad, $edge);
                imagedestroy($avatar);

                // The name sits on the avatar's centre line rather than sharing
                // its top edge — the lockup reads as one object that way.
                $metaX    = $pad + $edge + self::AVATAR_GAP;
                $metaY    = $pad + (int) round(($edge + $ascent - $descent) / 2);
                $metaFoot = $pad + $edge;
            }

            imagettftext($img, self::META_SIZE, 0, $metaX, $metaY, $metaColor, $this->fontRegular, $siteTitle);
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
     * Copy $avatar onto $dst as a circle with its top-left at ($x, $y).
     *
     * GD has no circular crop, so this is a per-pixel copy that skips anything
     * outside the radius. The outermost pixel is blended toward the card ground
     * by how far it falls inside the edge — without it the circle renders with a
     * hard stair-stepped rim, which is very visible against a flat background.
     */
    private function drawCircle(\GdImage $dst, \GdImage $avatar, int $x, int $y, int $edge): void
    {
        $radius = $edge / 2;
        [$bgR, $bgG, $bgB] = self::BG_COLOR;

        for ($py = 0; $py < $edge; $py++) {
            for ($px = 0; $px < $edge; $px++) {
                $distance = sqrt((($px + 0.5) - $radius) ** 2 + (($py + 0.5) - $radius) ** 2);
                if ($distance > $radius) {
                    continue;
                }

                $rgb = imagecolorat($avatar, $px, $py);
                $r   = ($rgb >> 16) & 0xFF;
                $g   = ($rgb >> 8) & 0xFF;
                $b   = $rgb & 0xFF;

                $coverage = min(1.0, $radius - $distance);
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
