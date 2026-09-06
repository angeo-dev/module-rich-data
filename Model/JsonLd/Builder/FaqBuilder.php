<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Angeo\RichData\Model\JsonLd\IdFactory;
use Angeo\RichData\Model\Page\FaqPageMatcher;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds FAQPage from CMS page content.
 *
 * Context keys:
 *   'cms_page_content'    => string
 *   'cms_page_identifier' => string
 *   'page_url'            => string
 */
class FaqBuilder extends AbstractBuilder
{
    private const MIN_QUESTION_LENGTH = 10;
    private const MIN_ANSWER_LENGTH   = 20;
    private const MAX_PAIRS           = 10;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly FaqPageMatcher $faqPageMatcher,
        private readonly IdFactory $idFactory,
    ) {
        parent::__construct($scopeConfig);
    }

    public function getType(): string
    {
        return 'faq';
    }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/faq/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $identifier = (string) ($context['cms_page_identifier'] ?? '');
        $allowed    = $this->toList($this->getConfig('angeo_rich_data/faq/cms_identifiers', $store));

        if (!$this->faqPageMatcher->matches($identifier, $allowed)) {
            return null;
        }

        $content = (string) ($context['cms_page_content'] ?? '');
        if (trim($content) === '') {
            return null;
        }

        $pairs = $this->extractExplicitPairs($content);
        if ($pairs === []) {
            $pairs = $this->extractHeuristicPairs($content);
        }

        if ($pairs === []) {
            return null;
        }

        $entities = [];
        foreach ($pairs as $pair) {
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $pair['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $pair['answer'],
                ],
            ];
        }

        $pageUrl = (string) ($context['page_url'] ?? $this->idFactory->base($store));

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            '@id'        => $this->idFactory->forPage($pageUrl, IdFactory::FRAGMENT_FAQ),
            'url'        => $pageUrl,
            'mainEntity' => $entities,
        ];
    }

    /**
     * data-faq-question / data-faq-answer attribute pairs.
     */
    private function extractExplicitPairs(string $html): array
    {
        $pairs = [];

        preg_match_all(
            '/data-faq-question=["\']([^"\']+)["\'][^>]*data-faq-answer=["\']([^"\']+)["\']/i',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $pairs[] = [
                'question' => htmlspecialchars_decode($match[1]),
                'answer'   => htmlspecialchars_decode($match[2]),
            ];

            if (count($pairs) >= self::MAX_PAIRS) {
                break;
            }
        }

        return $pairs;
    }

    /**
     * Heuristic: <h2>/<h3> immediately followed by <p>.
     *
     * Length checks use mb_strlen; 1.x counted bytes, so a short Cyrillic or
     * Greek heading passed a check written for characters.
     */
    private function extractHeuristicPairs(string $html): array
    {
        $pairs = [];

        preg_match_all(
            '/<h[23][^>]*>(.*?)<\/h[23]>\s*<p[^>]*>(.*?)<\/p>/si',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $question = trim(strip_tags($match[1]));
            $answer   = trim(strip_tags($match[2]));

            if (mb_strlen($question) < self::MIN_QUESTION_LENGTH
                || mb_strlen($answer) < self::MIN_ANSWER_LENGTH
            ) {
                continue;
            }

            $pairs[] = [
                'question' => $question,
                'answer'   => mb_substr($answer, 0, 2000),
            ];

            if (count($pairs) >= self::MAX_PAIRS) {
                break;
            }
        }

        return $pairs;
    }
}
