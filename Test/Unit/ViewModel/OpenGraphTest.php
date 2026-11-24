<?php
declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\ViewModel;

use Angeo\RichData\ViewModel\OpenGraph;
use PHPUnit\Framework\TestCase;

/**
 * 2.1.0: og:description of 50–160 characters.
 */
class OpenGraphTest extends TestCase
{
    public function testMetaDescriptionWinsWhenLongEnough(): void
    {
        $meta = 'Joust Duffle Bag: a roomy gym bag with a shoe pocket and padded strap.';
        self::assertSame($meta, OpenGraph::pickDescription($meta, 'Gym bag', 'Long text'));
    }

    public function testShortShortDescriptionFallsBackToDescription(): void
    {
        $og = OpenGraph::pickDescription('', 'Nice tee', str_repeat('Soft organic cotton tee for every day. ', 6));
        self::assertGreaterThanOrEqual(OpenGraph::MIN, mb_strlen($og));
        self::assertLessThanOrEqual(OpenGraph::MAX, mb_strlen($og));
        self::assertStringContainsString('Soft organic cotton', $og);
    }

    public function testReadsTheProductDataNotMagicGetters(): void
    {
        // On a real Product getShortDescription() is magic; method_exists() is false.
        $product = $this->createMock(\Magento\Catalog\Model\Product::class);
        $product->method('getData')->willReturnCallback(static fn ($key = '', $index = null) => [
            'short_description' => 'Gym bag',
            'description'       => '<p>The Joust Duffle Bag holds your gear, shoes and a towel, with a padded strap.</p>',
        ][$key] ?? null);

        self::assertStringStartsWith('The Joust Duffle Bag holds', (new OpenGraph())->description($product));
    }

    public function testNothingBetterKeepsWhatThereIs(): void
    {
        self::assertSame('Nice tee', OpenGraph::pickDescription(null, 'Nice tee', null));
    }
}
