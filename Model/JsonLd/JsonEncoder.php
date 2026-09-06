<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd;

/**
 * Encodes schema arrays for safe injection inside a <script> element.
 *
 * SECURITY: JSON-LD is printed unescaped inside <script type="application/ld+json">.
 * Any "</script>" sequence coming from catalog data or store configuration would
 * otherwise terminate the element early and turn attacker-controlled product text
 * into executable markup.
 *
 * Up to 2.0.0 the renderer used JSON_UNESCAPED_SLASHES, which is exactly what made
 * that breakout possible: it prints "</script>" verbatim. Slashes are now escaped
 * again and JSON_HEX_* neutralises <, >, &, ' and " as well, so no value can leave
 * the string literal. \uXXXX escapes are ordinary JSON and every JSON-LD consumer
 * decodes them transparently.
 */
class JsonEncoder
{
    private const FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_THROW_ON_ERROR;

    public function __construct(
        private readonly bool $prettyPrint = true,
    ) {
    }

    /**
     * @param array $data
     * @throws \JsonException
     */
    public function encode(array $data): string
    {
        $flags = self::FLAGS;
        if ($this->prettyPrint) {
            $flags |= JSON_PRETTY_PRINT;
        }

        return (string) json_encode($data, $flags);
    }

    /**
     * Wrap an encoded payload in a JSON-LD script element.
     */
    public function toScript(string $json): string
    {
        return sprintf("\n<script type=\"application/ld+json\">\n%s\n</script>", $json);
    }
}
