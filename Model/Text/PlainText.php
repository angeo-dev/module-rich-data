<?php
declare(strict_types=1);

namespace Angeo\RichData\Model\Text;

/**
 * Product text as plain text an agent or a link preview can use (2.1.0).
 *
 * Magento attribute values are HTML, Page Builder markup or CMS directives.
 * strip_tags() alone keeps <style>/<script> bodies and "&nbsp;", and a short
 * description that is only whitespace or markup looked non-empty, so the
 * real description was never used and the schema had no description at all.
 *
 * @since 2.1.0
 */
class PlainText
{
    public static function clean(?string $html): string
    {
        $text = (string) $html;
        if ($text === '') {
            return '';
        }
        $text = (string) preg_replace('#<(script|style|template|noscript)\b[^>]*>.*?</\1\s*>#is', ' ', $text);
        $text = (string) preg_replace('/\{\{[^}]*\}\}/', ' ', $text);   // {{widget ...}}, {{media ...}}
        $text = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', ' ', $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * First candidate whose clean text is at least $min characters, else the
     * longest non-empty one, else ''.
     *
     * @param list<?string> $candidates raw values, in order of preference
     */
    public static function pick(array $candidates, int $min = 1): string
    {
        $longest = '';
        foreach ($candidates as $raw) {
            $text = self::clean($raw);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) >= $min) {
                return $text;
            }
            if (mb_strlen($text) > mb_strlen($longest)) {
                $longest = $text;
            }
        }

        return $longest;
    }

    /** Cut at a word boundary and add "…" when longer than $max. */
    public static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > (int) ($max * 0.6)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,.;:-") . '…';
    }
}
