<?php

declare(strict_types=1);

namespace CMS;

/**
 * Marker class for XML-RPC dateTime.iso8601 values.
 * Pass an instance to XmlRpc::encodeValue() to emit the correct typed element
 * instead of encoding the string as a plain <string>.
 */
class DateTimeValue
{
    public function __construct(public readonly string $iso) {}
}

/**
 * Minimal XML-RPC parser and response encoder for the MetaWeblog API.
 *
 * All methods are static — no instantiation needed.
 */
class XmlRpc
{
    // ── Parsing ───────────────────────────────────────────────────────────────

    /**
     * Parse a raw XML-RPC request body.
     *
     * @return array{method: string, params: array}
     * @throws \RuntimeException on malformed XML
     */
    public static function parseRequest(string $body): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            throw new \RuntimeException('Invalid XML');
        }

        $method = (string) $xml->methodName;
        $params = [];

        foreach ($xml->params->param ?? [] as $param) {
            $params[] = self::parseValue($param->value);
        }

        return ['method' => $method, 'params' => $params];
    }

    private static function parseValue(\SimpleXMLElement $node): mixed
    {
        $children = $node->children();

        // No typed child → treat as string (XML-RPC spec default)
        if (count($children) === 0) {
            return (string) $node;
        }

        $type  = $children[0]->getName();
        $child = $children[0];

        return match ($type) {
            'string'           => (string) $child,
            'int', 'i4', 'i8' => (int) (string) $child,
            'boolean'          => (bool) (int) (string) $child,
            'double'           => (float) (string) $child,
            'dateTime.iso8601' => (string) $child,
            'base64'           => base64_decode((string) $child),
            'nil'              => null,
            'array'            => self::parseArray($child),
            'struct'           => self::parseStruct($child),
            default            => (string) $child,
        };
    }

    private static function parseStruct(\SimpleXMLElement $node): array
    {
        $result = [];
        foreach ($node->member as $member) {
            $name          = (string) $member->name;
            $result[$name] = self::parseValue($member->value);
        }
        return $result;
    }

    private static function parseArray(\SimpleXMLElement $node): array
    {
        $result = [];
        foreach ($node->data->value ?? [] as $value) {
            $result[] = self::parseValue($value);
        }
        return $result;
    }

    // ── Encoding ──────────────────────────────────────────────────────────────

    /**
     * Encode a PHP value as an XML-RPC <value> node string.
     *
     * Type rules:
     *   null      → <string></string>
     *   bool      → <boolean>
     *   int       → <int>
     *   float     → <double>
     *   string    → <string>
     *   int-keyed → <array>
     *   str-keyed → <struct>
     */
    public static function encodeValue(mixed $value): string
    {
        if ($value instanceof DateTimeValue) {
            return '<value><dateTime.iso8601>' . htmlspecialchars($value->iso, ENT_XML1, 'UTF-8') . '</dateTime.iso8601></value>';
        }

        if ($value === null) {
            return '<value><string></string></value>';
        }

        if (is_bool($value)) {
            return '<value><boolean>' . ($value ? '1' : '0') . '</boolean></value>';
        }

        if (is_int($value)) {
            return '<value><int>' . $value . '</int></value>';
        }

        if (is_float($value)) {
            return '<value><double>' . $value . '</double></value>';
        }

        if (is_string($value)) {
            // Strip characters that are invalid in XML 1.0 (control chars except tab/LF/CR).
            // If these reach the output, XML parsers like MarsEdit's will reject the entire post.
            $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
            return '<value><string>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</string></value>';
        }

        if (is_array($value)) {
            // Empty array → always encode as array (range(0,-1) bug workaround)
            if (count($value) === 0) {
                return '<value><array><data></data></array></value>';
            }
            // Associative → struct; sequential → array
            if (array_keys($value) !== range(0, count($value) - 1)) {
                $members = '';
                foreach ($value as $k => $v) {
                    $members .= '<member>'
                        . '<name>' . htmlspecialchars((string) $k, ENT_XML1, 'UTF-8') . '</name>'
                        . self::encodeValue($v)
                        . '</member>';
                }
                return '<value><struct>' . $members . '</struct></value>';
            }

            $items = '';
            foreach ($value as $v) {
                $items .= self::encodeValue($v);
            }
            return '<value><array><data>' . $items . '</data></array></value>';
        }

        // Fallback
        return '<value><string>' . htmlspecialchars((string) $value, ENT_XML1, 'UTF-8') . '</string></value>';
    }

    /**
     * Wrap an encoded value in a full <methodResponse> success envelope.
     */
    public static function encodeResponse(mixed $value): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<methodResponse><params><param>'
            . self::encodeValue($value)
            . '</param></params></methodResponse>';
    }

    /**
     * Build a <methodResponse><fault> envelope.
     */
    public static function encodeFault(int $code, string $message): string
    {
        $fault = self::encodeValue(['faultCode' => $code, 'faultString' => $message]);
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<methodResponse><fault>' . $fault . '</fault></methodResponse>';
    }

    // ── Datetime helpers ──────────────────────────────────────────────────────

    /**
     * Convert a stored UTC datetime string to XML-RPC dateTime.iso8601 format.
     * MarsEdit expects YYYYMMDDThh:mm:ss (no timezone suffix).
     */
    public static function isoDate(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return date('Ymd') . 'T00:00:00';
        }
        $ts = strtotime($datetime);
        return $ts !== false ? date('Ymd\TH:i:s', $ts) : date('Ymd') . 'T00:00:00';
    }

    /**
     * Parse a dateTime.iso8601 string from MarsEdit into a UTC 'Y-m-d H:i:s' string.
     *
     * MarsEdit sends dates in the blog's configured local timezone.
     * Accepts: YYYYMMDDThh:mm:ss  or  YYYY-MM-DDThh:mm:ss  (with optional Z/offset)
     */
    public static function parseDate(string $iso, string $timezone): string
    {
        if ($iso === '') {
            return date('Y-m-d H:i:s');
        }

        // Normalise compact format: 20260301T14:30:00 → 2026-03-01T14:30:00
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2}):(\d{2}):(\d{2})$/', $iso, $m)) {
            $iso = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}";
        }

        try {
            $tz = ($timezone !== '') ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');

            // If the string already has a timezone indicator, honour it.
            if (str_ends_with($iso, 'Z') || preg_match('/[+-]\d{2}:\d{2}$/', $iso)) {
                $dt = new \DateTime($iso);
            } else {
                $dt = new \DateTime($iso, $tz);
            }

            $dt->setTimezone(new \DateTimeZone('UTC'));
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            error_log('[XmlRpc] parseDate failed for "' . $iso . '" (timezone: "' . $timezone . '"): ' . $e->getMessage());
            return date('Y-m-d H:i:s');
        }
    }

    /**
     * Pull the publication date out of an incoming struct, or null if it carries none.
     *
     * WordPress clients may send the local field, the GMT field, or both, and
     * plenty send only the GMT one. Reading just the local key meant $pubAt came
     * back null for those clients, the caller fell back to now, and a post the
     * author had scheduled published immediately.
     *
     * The GMT variant wins when present: it is unambiguous, where the local one
     * has to be interpreted against whatever the site timezone happens to be.
     * Null is a meaningful return — it is what lets a caller tell "no date
     * supplied" (keep the existing published_at) from "a date that resolves to
     * now", so do not collapse it to a default here.
     *
     * @param array<string,mixed> $struct
     */
    public static function parseStructDate(
        array $struct,
        string $localKey,
        string $gmtKey,
        string $timezone
    ): ?string {
        $gmt = trim((string) ($struct[$gmtKey] ?? ''));
        if ($gmt !== '') {
            // Parsed against UTC rather than the site timezone. An explicit
            // offset carried in the value still wins, which is gentler than
            // WordPress's own handling — it forces a Z onto the string.
            return self::parseDate($gmt, 'UTC');
        }

        $local = trim((string) ($struct[$localKey] ?? ''));

        return $local !== '' ? self::parseDate($local, $timezone) : null;
    }
}
