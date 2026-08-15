<?php

declare(strict_types=1);

namespace CMS;

/**
 * Rewrites raw `<img>` tags in rendered HTML through ImageTag.
 *
 * ImageRenderer is registered against CommonMark's Image *node*, so it only
 * fires for Markdown `![alt](/media/x.jpg)` syntax. Bodies are parsed with
 * 'html_input' => 'allow', so an image written as literal HTML — which is how
 * the whole imported Micro.blog archive is written — went straight through
 * untouched: no width/height, no WebP, no srcset.
 *
 * That was 134 references across the built site pointing at 103.8 MB of
 * original JPEG and PNG while 21.4 MB of WebP siblings sat beside them on disk,
 * already generated and never asked for. Some archive pages were six megabytes.
 *
 * This runs over the HTML after conversion, so it catches both syntaxes without
 * the Markdown path having to know about it.
 */
final class ResponsiveImages
{
    /**
     * Rewrite every local `<img>` in $html that is not already inside a
     * `<picture>`.
     */
    public static function upgrade(string $html, string $mediaDir): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        // Split on whole <picture> blocks and skip them: ImageRenderer already
        // built those, and wrapping one again would nest <picture> in <picture>
        // and give the browser two competing sources.
        $parts = preg_split(
            '#(<picture\b.*?</picture>)#is',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if ($parts === false) {
            return $html;
        }

        foreach ($parts as $i => $part) {
            if (stripos(ltrim($part), '<picture') === 0) {
                continue;
            }

            $rewritten = preg_replace_callback(
                '#<img\b([^>]*)/?>#i',
                static fn(array $m): string => self::rewriteTag($m[0], $m[1], $mediaDir),
                $part
            );

            if ($rewritten !== null) {
                $parts[$i] = $rewritten;
            }
        }

        return implode('', $parts);
    }

    /**
     * Re-render one tag, or hand back the original when there is nothing to
     * gain. Returning $original unchanged is always safe, so every branch that
     * cannot improve the tag takes it.
     */
    private static function rewriteTag(string $original, string $attrString, string $mediaDir): string
    {
        $attrs = self::parseAttributes($attrString);

        $src = $attrs['src'] ?? '';
        if (!is_string($src) || !str_starts_with($src, '/media/')) {
            return $original;
        }

        // Already carries a srcset: the author (or something else) has taken
        // charge of the sources, so leave the decision alone.
        if (isset($attrs['srcset'])) {
            return $original;
        }

        $alt = (string) ($attrs['alt'] ?? '');
        unset($attrs['src'], $attrs['alt']);

        // Whatever the author wrote wins over ImageTag's defaults, which is
        // what lets an explicit loading="eager" survive this pass.
        return ImageTag::render($mediaDir, $src, $alt, $attrs);
    }

    /**
     * Attributes of a tag, as name => value.
     *
     * Values are entity-decoded on the way out because ImageTag escapes
     * everything it emits; leaving them encoded would turn `&amp;` into
     * `&amp;amp;` on every pass. A valueless attribute maps to its own name,
     * which is how it renders back out.
     *
     * @return array<string,string>
     */
    private static function parseAttributes(string $attrString): array
    {
        preg_match_all(
            '#([a-zA-Z_:][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?#',
            $attrString,
            $matches,
            PREG_SET_ORDER
        );

        $attrs = [];
        foreach ($matches as $m) {
            $name = strtolower($m[1]);

            if (($m[2] ?? '') !== '') {
                $value = $m[2];
            } elseif (($m[3] ?? '') !== '') {
                $value = $m[3];
            } elseif (($m[4] ?? '') !== '') {
                $value = $m[4];
            } else {
                // Either a valueless attribute or an explicitly empty one; both
                // render correctly as name="".
                $value = count($m) > 2 ? '' : $name;
            }

            $attrs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attrs;
    }
}
