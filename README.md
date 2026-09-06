# Angeo Rich Data — Magento 2

[![Packagist](https://img.shields.io/packagist/v/angeo/module-rich-data.svg)](https://packagist.org/packages/angeo/module-rich-data)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-8.2%20%E2%80%93%208.5-8892BF.svg)](https://php.net)

**Publishes one linked JSON-LD `@graph` per page so ChatGPT, Gemini, Perplexity and Google read your catalog as connected data instead of loose fragments.** Fixes the schema signals in `angeo/module-aeo-audit`.

---

## What this module fixes

| AEO Audit signal | Before | After |
|---|---|---|
| Product schema — JSON-LD structured data | FAIL / WARN | PASS |
| Merchant policies — return & shipping schema | FAIL | PASS (when enabled & configured) |
| Product schema — AggregateRating | WARN | PASS (when reviews exist) |
| JSON-LD quality — BreadcrumbList | WARN | PASS (product, category and CMS pages) |
| FAQPage schema — AI answer eligibility | WARN | PASS (on pages you nominate) |

---

## Schema types injected

| Schema | Pages | Key fields |
|---|---|---|
| `Product` | Product pages | name, description, image, sku, gtin/mpn, brand, offers, aggregateRating |
| `ProductGroup` | Configurable product pages | hasVariant, variesBy, productGroupID, per-variant offers |
| `Organization` | All pages | name, description, url, logo, sameAs, contactPoint |
| `WebSite` | Homepage | name, url, publisher |
| `BreadcrumbList` | Product, category, CMS pages | full trail |
| `CollectionPage` | Category pages | ItemList of the products on the current page |
| `FAQPage` | Nominated CMS pages | detected Q&A pairs |

Every node has a stable `@id`, so `offers.seller` points at the same Organization on every page of the store rather than repeating a name string.

---

## Installation

```bash
composer require angeo/module-rich-data
bin/magento setup:upgrade
bin/magento cache:flush
```

Upgrading from 1.x? Read [UPGRADE.md](UPGRADE.md) — the markup shape changes and prices become tax-inclusive on VAT-display stores.

---

## Output

```json
{
  "@context": "https://schema.org",
  "@graph": [
    { "@type": "Organization", "@id": "https://shop.example/#organization", "name": "Example Store" },
    {
      "@type": "Product",
      "@id": "https://shop.example/alpine-jacket.html#product",
      "name": "Alpine Jacket",
      "offers": {
        "@type": "Offer",
        "price": "121.00",
        "priceCurrency": "EUR",
        "availability": "https://schema.org/InStock",
        "seller": { "@type": "Organization", "@id": "https://shop.example/#organization" }
      }
    },
    { "@type": "BreadcrumbList", "@id": "https://shop.example/alpine-jacket.html#breadcrumb" }
  ]
}
```

Prefer the 1.x layout of one script tag per schema? **General → Output mode → One script tag per schema.**

---

## Configuration

**Stores → Configuration → Angeo → Rich Data (JSON-LD)**

### General
- Enabled
- Output mode — `@graph` (default) or legacy one-tag-per-schema

### Product schema
- Enable, AggregateRating, SKU, Brand (attribute-driven)
- GTIN / MPN (attribute-driven, off by default)
- Item condition
- Publish configurable products as ProductGroup, and a variant cap
- `priceValidUntil` window in days (0 to omit)
- In-stock / out-of-stock availability URLs

Prices follow the store's tax display setting, so the marked-up price matches the price on the page. Configurable, grouped and bundle products publish an `AggregateOffer` with `lowPrice` and `highPrice`.

### Breadcrumb schema
- Enable — runs on product, category and CMS pages

### Merchant policies (return & shipping)
`offers.hasMerchantReturnPolicy` and `offers.shippingDetails`, required by Google and ChatGPT Shopping since January 2026. **Off by default** — enable only once your real return window, shipping rate and delivery times are known, because publishing wrong policy values can hurt eligibility.

### Organization schema
Name, description, logo URL, sameAs URLs, contact telephone and type.

### WebSite schema
Enable, plus an optional `SearchAction`. Google removed the sitelinks search box on 21 November 2024, so `SearchAction` is off by default and produces no Google feature; the option stays for other consumers.

### CollectionPage schema
ItemList of the products on the current category page. Paging and sort order are honoured. Layered-navigation filters are not reflected, because the layer is not resolved yet when the page head renders.

### FAQPage schema
- Enable
- **CMS page identifiers** — comma-separated allow-list. Leave empty to allow identifiers containing "faq". The homepage is never treated as an FAQ page.

Google restricts FAQ rich results to government and health sites, so treat this as machine-readable content for AI answer engines rather than a Google rich result.

---

## FAQ page markup

Explicit attributes are the reliable option:

```html
<div data-faq-question="What is your return policy?"
     data-faq-answer="We offer 30-day returns on all items in original condition.">
</div>
```

Without them the module falls back to a heuristic: `<h2>`/`<h3>` followed by `<p>`.

---

## Extending with custom schemas

Implement `Angeo\RichData\Api\Data\SchemaInterface` and register via `di.xml`:

```xml
<type name="Angeo\RichData\Model\JsonLd\Renderer\SchemaRenderer">
    <arguments>
        <argument name="builders" xsi:type="array">
            <item name="my_schema" xsi:type="object">Vendor\Module\Model\JsonLd\Builder\MySchemaBuilder</item>
        </argument>
    </arguments>
</type>
```

Set an `@id` on your root node and it joins the graph like any built-in schema.

---

## CLI validation

```bash
bin/magento angeo:rich-data:validate --store=default
bin/magento angeo:rich-data:validate --store=default --product-id=42
bin/magento angeo:rich-data:validate --category-id=11
bin/magento angeo:rich-data:validate --cms-identifier=faq
bin/magento angeo:rich-data:validate --json | jq .
```

```
Store:       default
Page URL:    https://shop.example/alpine-jacket.html
Output mode: graph

Found 3 node(s):
  Node 1: Organization
  Node 2: ProductGroup
    PASS 4 variant(s)
  Node 3: BreadcrumbList
  All nodes are merged into one @graph document.

All JSON-LD nodes look valid.
```

Exit code is non-zero when a node is missing an `@id`, an offer is incomplete or two nodes collide — usable in CI.

---

## Tests

```bash
composer install
vendor/bin/phpunit
```

---

## The Angeo AI Suite

| Module | Purpose |
|---|---|
| `angeo/module-aeo-audit` | AEO audit — detects missing schema |
| `angeo/module-rich-data` | **This module** — fixes missing schema |
| `angeo/module-llms-txt` | Generates `/llms.txt` |
| `angeo/module-robots-txt-aeo` | AI crawler rules in `robots.txt` |
| `angeo/module-openai-product-feed-api` | ACP REST API for ChatGPT Shopping |

---

## Security

Found a vulnerability? See [SECURITY.md](SECURITY.md). 2.0.0 fixes a script-breakout issue present in every 1.x release.

---

## License

MIT — see [LICENSE](LICENSE)
