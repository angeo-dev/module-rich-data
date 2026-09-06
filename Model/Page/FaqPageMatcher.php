<?php

declare(strict_types=1);

namespace Angeo\RichData\Model\Page;

/**
 * Decides whether a CMS page may publish FAQPage markup.
 *
 * 1.x published FAQPage on any CMS page that happened to contain an <h2>
 * followed by a <p>, and — through a homepage content fallback — on the
 * homepage as well. Google has restricted FAQ rich results to government and
 * health sites since 2023, so for a store the markup wins nothing while looking
 * like keyword stuffing on pages that are not FAQs at all.
 *
 * The page must now be named. An explicit identifier list wins; with the list
 * left empty the matcher falls back to identifiers that contain "faq".
 */
class FaqPageMatcher
{
    public function matches(string $identifier, array $allowedIdentifiers): bool
    {
        $identifier = trim(strtolower($identifier));
        if ($identifier === '') {
            return false;
        }

        if ($allowedIdentifiers === []) {
            return str_contains($identifier, 'faq');
        }

        foreach ($allowedIdentifiers as $allowed) {
            if (strtolower(trim((string) $allowed)) === $identifier) {
                return true;
            }
        }

        return false;
    }
}
