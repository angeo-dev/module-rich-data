# Angeo Rich Data — Magento 2

[![Packagist](https://img.shields.io/packagist/v/angeo/module-rich-data.svg)](https://packagist.org/packages/angeo/module-rich-data)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)

**Fixes the "Product schema — JSON-LD structured data" signal in `angeo/module-aeo-audit`. Injects spec-compliant JSON-LD on product pages, CMS pages, and homepage.**

---

## What this module fixes

| AEO Audit signal | Before | After |
|-----------------|--------|-------|
| Product schema — JSON-LD structured data | FAIL / WARN | PASS |
| FAQPage schema — AI answer eligibility | WARN | PASS (on FAQ CMS pages) |
| Product schema — AggregateRating | WARN | PASS (when reviews exist) |

---

## Schema types injected

| Schema | Pages | Key fields |
|--------|-------|------------|
| `Product` | All product pages | name, description, image, sku, offers.price, offers.priceCurrency, offers.availability, aggregateRating |
| `Organization` | All pages | name, url, logo, sameAs, contactPoint |
| `WebSite` | Homepage only | name, url, potentialAction/SearchAction |
| `BreadcrumbList` | Product pages | category path |
| `FAQPage` | CMS pages with FAQ content | auto-detected Q&A pairs |

---

## Installation

```bash
composer require angeo/module-rich-data
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## Configuration

**Stores → Configuration → Angeo → Rich Data (JSON-LD)**

### Product schema
- Enable/disable
- Include AggregateRating (from Magento reviews)
- Include BreadcrumbList
- Include SKU
- Include Brand (configurable attribute)
- Item condition (New/Used/Refurbished)

### Organization schema
- Organization name (default: store name)
- Logo URL
- sameAs social URLs (comma-separated)
- Contact telephone + type

### WebSite schema
- Enable/disable
- Include SearchAction (Sitelinks Searchbox)

### FAQPage schema
- Enable/disable — auto-detected from CMS page content

---

## FAQ page markup (recommended)

Add `data-faq-question` / `data-faq-answer` attributes to your CMS FAQ page for explicit Q&A detection:

```html
<div data-faq-question="What is your return policy?"
     data-faq-answer="We offer 30-day returns on all items in original condition.">
</div>
```

Without these attributes the module uses heuristic detection: `<h2>/<h3>` followed by `<p>` are treated as question/answer pairs.

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

---

## CLI validation

```bash
# Validate on random product
bin/magento angeo:rich-data:validate --store=en_us

# Validate specific product on specific store
bin/magento angeo:rich-data:validate --store=en_us --product-id=42
```

Output example:
```
Validating JSON-LD for: [42] Alpine Hiking Jacket
  Store:   default
  URL:     https://mystore.com/alpine-jacket

Found 3 JSON-LD block(s):
  Block 1: @type:Organization — valid JSON ✓
  Block 2: @type:Product — valid JSON ✓
    PASS aggregateRating present
  Block 3: @type:BreadcrumbList — valid JSON ✓

All JSON-LD blocks are valid.
```

---

## The Angeo AI Suite

| Module | Purpose |
|--------|---------|
| `angeo/module-aeo-audit` | AEO audit — detects missing schema |
| `angeo/module-rich-data` | **This module** — fixes missing schema |
| `angeo/module-llms-txt` | Generates `/llms.txt` |
| `angeo/module-openai-product-feed-api` | ACP REST API for ChatGPT Shopping |

---

## License

MIT — see [LICENSE](LICENSE)
