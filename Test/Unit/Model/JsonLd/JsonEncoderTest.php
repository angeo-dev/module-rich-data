<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\JsonLd;

use Angeo\RichData\Model\JsonLd\JsonEncoder;
use PHPUnit\Framework\TestCase;

class JsonEncoderTest extends TestCase
{
    private JsonEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new JsonEncoder(false);
    }

    /**
     * The regression this class exists for: a product name containing a closing
     * script tag must not be able to terminate the JSON-LD element.
     */
    public function testClosingScriptTagCannotEscapeTheScriptElement(): void
    {
        $json = $this->encoder->encode([
            '@type' => 'Product',
            'name'  => 'Hoodie </script><img src=x onerror=alert(1)>',
        ]);

        $this->assertStringNotContainsString('</script>', $json);
        $this->assertStringNotContainsString('<img', $json);

        $script = $this->encoder->toScript($json);
        $this->assertSame(1, substr_count($script, '</script>'), 'exactly one closing tag, the real one');
    }

    public function testAngleBracketsAmpersandsAndQuotesAreEscaped(): void
    {
        $json = $this->encoder->encode(['name' => '<b>Tom & "Jerry\'s"</b>']);

        foreach (['<', '>', '&', '"', "'"] as $character) {
            $this->assertStringNotContainsString(
                $character,
                str_replace(['{', '}', ':', ',', '"name"'], '', $json),
                'raw ' . $character . ' must not survive encoding'
            );
        }
    }

    public function testEscapedPayloadStillDecodesToTheOriginalValue(): void
    {
        $value = 'Größe 42 </script> & "quoted"';

        $decoded = json_decode($this->encoder->encode(['name' => $value]), true);

        $this->assertSame($value, $decoded['name']);
    }

    public function testUnicodeIsNotMangled(): void
    {
        $decoded = json_decode($this->encoder->encode(['name' => 'Куртка зимова']), true);

        $this->assertSame('Куртка зимова', $decoded['name']);
    }

    public function testToScriptWrapsPayload(): void
    {
        $script = $this->encoder->toScript('{"a":1}');

        $this->assertStringContainsString('<script type="application/ld+json">', $script);
        $this->assertStringContainsString('{"a":1}', $script);
    }
}
