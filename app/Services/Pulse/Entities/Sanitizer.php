<?php

namespace App\Services\Pulse\Entities;

use Illuminate\Support\Str;

/**
 * User text on the way in. Strips tags and control characters, collapses whitespace, caps length. Output is
 * escaped again by Blade; this is defence in depth and a way to keep markup out of listings and notifications.
 */
class Sanitizer
{
    /** One line: names, titles, taglines. */
    public static function line(?string $text, int $max): ?string
    {
        $text = self::clean($text);

        if ($text === null) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : Str::limit($text, $max, '', true);
    }

    /** Paragraphs: keeps line breaks, at most one blank line in a row. */
    public static function text(?string $text, int $max): ?string
    {
        $text = self::clean($text);

        if ($text === null) {
            return null;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = (string) preg_replace('/ ?\n ?/u', "\n", $text);
        $text = trim((string) preg_replace('/\n{3,}/', "\n\n", $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /**
     * A list of short slugs-or-words: skills, tags, topics. Drops empties and duplicates (case-insensitive).
     *
     * @param  iterable<mixed>|null  $items
     * @return list<string>
     */
    public static function list(?iterable $items, int $maxItems, int $maxLength): array
    {
        $out = [];
        $seen = [];

        foreach ($items ?? [] as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $value = self::line((string) $item, $maxLength);

            if ($value === null || isset($seen[mb_strtolower($value)])) {
                continue;
            }

            $seen[mb_strtolower($value)] = true;
            $out[] = $value;

            if (count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }

    /** Emails, phone-like numbers and links removed: used on public text that must not carry a way to reach someone. */
    public static function withoutContactDetails(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '', $text);
        $text = (string) preg_replace('~(?:https?://|www\.)\S+~iu', '', $text);
        $text = (string) preg_replace('/(?<!\w)\+?\d[\d\s().\-]{7,}\d(?!\w)/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text)) ?: null;
    }

    private static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text); // entities that decoded into tags
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $text);

        return $text;
    }
}
