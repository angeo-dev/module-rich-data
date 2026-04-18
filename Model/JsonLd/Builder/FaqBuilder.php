<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\JsonLd\Builder;

use Magento\Store\Api\Data\StoreInterface;

/**
 * Builds FAQPage JSON-LD from CMS page content.
 *
 * Parses Q&A pairs from CMS page HTML using two strategies:
 * 1. Explicit data-faq-question / data-faq-answer attributes (recommended)
 * 2. Heuristic: <h2>/<h3> followed by <p> (auto-detection)
 *
 * Context keys:
 *   'cms_page_content' => string  — raw CMS page HTML content
 *   'page_type'        => string  — Magento full action name
 */
class FaqBuilder extends AbstractBuilder
{
    private const MIN_ANSWER_LENGTH = 20;

    public function getType(): string { return 'faq'; }

    protected function getEnabledConfigPath(): string
    {
        return 'angeo_rich_data/faq/enabled';
    }

    public function build(StoreInterface $store, array $context = []): ?array
    {
        $content = $context['cms_page_content'] ?? '';
        if (!$content) {
            return null;
        }

        $pairs = $this->extractExplicitPairs($content)
            ?: $this->extractHeuristicPairs($content);

        if (empty($pairs)) {
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

        return [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        ];
    }

    /**
     * Parse data-faq-question / data-faq-answer attribute pairs.
     * Recommended markup: <div data-faq-question="..." data-faq-answer="..."/>
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
        foreach ($matches as $m) {
            $pairs[] = ['question' => htmlspecialchars_decode($m[1]), 'answer' => htmlspecialchars_decode($m[2])];
        }
        return $pairs;
    }

    /**
     * Heuristic: find <h2>/<h3> followed immediately by <p>.
     * Works on most FAQ page structures without markup changes.
     */
    private function extractHeuristicPairs(string $html): array
    {
        $pairs = [];
        // Extract h2/h3 + following p pairs
        preg_match_all(
            '/<h[23][^>]*>(.*?)<\/h[23]>\s*<p[^>]*>(.*?)<\/p>/si',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $question = trim(strip_tags($m[1]));
            $answer   = trim(strip_tags($m[2]));

            if (strlen($question) < 10 || strlen($answer) < self::MIN_ANSWER_LENGTH) {
                continue;
            }

            $pairs[] = ['question' => $question, 'answer' => mb_substr($answer, 0, 2000)];

            if (count($pairs) >= 10) {
                break; // cap at 10 Q&A pairs
            }
        }

        return $pairs;
    }
}
