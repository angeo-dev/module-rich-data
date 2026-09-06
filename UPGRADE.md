# Upgrading to 2.0.0

```bash
composer require angeo/module-rich-data:^2.0
bin/magento setup:upgrade
bin/magento cache:flush
```

2.0.0 contains a security fix. If you run any 1.x version, upgrade rather than
pin. See the CHANGELOG for what the fix covers.

## What changes without you doing anything

**The markup shape changes.** Instead of several `<script type="application/ld+json">`
blocks you get one, containing an `@graph` array with the same schemas inside.
This is valid JSON-LD and is what Google, ChatGPT and Perplexity all read. If
anything downstream of your store parses the page and expects the old shape —
a scraper, a test, a monitoring check — point it at `@graph` or switch the output
mode back (below).

**Prices may change on VAT-inclusive stores.** They now match the price shown on
the page. If your storefront shows €121 including VAT, the markup says 121 where
it used to say 100. This is the correct value; expect Google Search Console to
re-process the pages.

**Configurable products may become `ProductGroup`.** `Publish configurable
products as ProductGroup` defaults to on. Each variant then carries its own
offer. To keep the 1.x single-Product output, set it to No.

**FAQPage stops appearing on most pages.** Only CMS pages named in
`Angeo → Rich Data → FAQPage schema → CMS page identifiers`, or whose identifier
contains "faq", publish it. If you relied on the old behaviour, list the pages
explicitly.

**`SearchAction` is off by default.** Google retired the sitelinks search box in
November 2024. Turn it back on if some other consumer of your markup uses it.

## Rolling back the output shape without downgrading

**Stores → Configuration → Angeo → Rich Data (JSON-LD) → General → Output mode**
→ `One script tag per schema (1.x behaviour)`.

Everything else in 2.0.0 — the security fix, the price fix, the query fixes —
stays active in legacy mode. Only the markup layout reverts.

## Settings that moved

| 1.x | 2.0.0 |
|---|---|
| Product schema → Include BreadcrumbList | Breadcrumb schema → Enabled |

The old field still exists and still works as a master switch, so a store that
had breadcrumbs off keeps them off. It will be removed in 3.0.0.

## Checking the result

```bash
bin/magento angeo:rich-data:validate --store=default
bin/magento angeo:rich-data:validate --category-id=42
bin/magento angeo:rich-data:validate --cms-identifier=faq
bin/magento angeo:rich-data:validate --json | jq .
```

Then run one product URL and one category URL through Google's Rich Results Test.
