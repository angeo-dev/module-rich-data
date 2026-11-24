<?php
declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\Text;

use Angeo\RichData\Model\Text\PlainText;
use PHPUnit\Framework\TestCase;

/**
 * 2.1.0: product text cleaning and the description fallback.
 */
class PlainTextTest extends TestCase
{
    public function testCleansMarkupDirectivesAndEntities(): void
    {
        self::assertSame(
            'Soft fleece. Machine wash & tumble dry.',
            PlainText::clean('<style>#html-body .x{color:red}</style><div data-content-type="row"><p>Soft&nbsp;fleece.</p>'
                . '{{widget type="Magento\\Cms\\Block\\Widget\\Block" block_id="1"}}<p>Machine wash &amp; tumble dry.</p></div>')
        );
        self::assertSame('', PlainText::clean("<p>&nbsp;</p>\n "));
        self::assertSame('', PlainText::clean(null));
    }

    public function testEmptyShortDescriptionNoLongerHidesTheDescription(): void
    {
        // demo.angeo.dev ariel-roll-sleeve-sweatshirt: "missing: description"
        self::assertSame(
            'A sweatshirt for cool mornings, with roll-up sleeves and a soft lining.',
            PlainText::pick(['<p> </p>', '<p>A sweatshirt for cool mornings, with roll-up sleeves and a soft lining.</p>'], 50)
        );
    }

    public function testShortTextWhenNothingLonger(): void
    {
        self::assertSame('Warm jacket', PlainText::pick(['Warm jacket', '', null], 50));
        self::assertSame('', PlainText::pick(['', null]));
    }

    public function testTruncateAtWord(): void
    {
        self::assertSame('one two three…', PlainText::truncate('one two three four five', 16));
        self::assertSame('short', PlainText::truncate('short', 16));
    }
}
