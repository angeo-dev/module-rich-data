<?php

declare(strict_types=1);

namespace Angeo\RichData\Test\Unit\Model\Page;

use Angeo\RichData\Model\Page\FaqPageMatcher;
use PHPUnit\Framework\TestCase;

class FaqPageMatcherTest extends TestCase
{
    private FaqPageMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new FaqPageMatcher();
    }

    public function testExplicitListWins(): void
    {
        $this->assertTrue($this->matcher->matches('shipping-questions', ['shipping-questions', 'returns']));
        $this->assertFalse($this->matcher->matches('about-us', ['shipping-questions']));
    }

    public function testExplicitListIsCaseInsensitive(): void
    {
        $this->assertTrue($this->matcher->matches('FAQ', ['faq']));
    }

    public function testWithoutAListOnlyFaqLikeIdentifiersMatch(): void
    {
        $this->assertTrue($this->matcher->matches('faq', []));
        $this->assertTrue($this->matcher->matches('shipping-faq', []));
        $this->assertFalse($this->matcher->matches('about-us', []));
    }

    /**
     * The 1.x behaviour this replaces: any CMS page with an h2 and a p, and the
     * homepage on top of that.
     */
    public function testHomepageIdentifierDoesNotMatchByDefault(): void
    {
        $this->assertFalse($this->matcher->matches('home', []));
        $this->assertFalse($this->matcher->matches('', []));
    }
}
