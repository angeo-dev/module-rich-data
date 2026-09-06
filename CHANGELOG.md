# Changelog

All notable changes to `angeo/module-rich-data` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0] - 2026-09-04

### Security

- **JSON-LD could break out of its own `<script>` element.** The renderer encoded
  with `JSON_UNESCAPED_SLASHES` and the template printed the result with
  `@noEscape`, so any `</script>` sequence in a product name, description,
  category name or configuration field ended the element early and turned the
  rest of the value into markup — a stored XSS vector reachable by anyone who can
  edit catalog text. Encoding now happens in one place
  (`Model\JsonLd\JsonEncoder`) with slashes escaped again and
  `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` on top, so `<`,
  `>`, `&`, `'` and `"` cannot survive as raw characters. Every store running
  1.0.x–1.2.0 should upgrade.

### Added

- **Linked `@graph` output.** All schemas are now written as one
  `{"@context": ..., "@graph": [...]}` document, and every node carries a stable
  `@id` (`…#product`, `…#organization`, `…#breadcrumb`, …). Offers reference the
  Organization node as seller, WebSite references it as publisher, CollectionPage
  references the WebSite. In 1.x each schema was an isolated block, so nothing
  told an AI engine that the three of them described one page.
- **`Output mode` setting** under Angeo → Rich Data → General. `graph` is the new
  default; `legacy` reproduces the 1.x one-script-per-schema output for anyone who
  needs to roll back without downgrading the package.
- **ProductGroup for configurable products.** With
  `Publish configurable products as ProductGroup` enabled, a configurable emits
  `ProductGroup` with `hasVariant`, `variesBy`, `productGroupID` and
  `inProductGroupWithID`, and every variant carries its own price, stock and
  variant attributes. Supported by Google since February 2024 and used by AI
  shopping surfaces to tell one product in six sizes apart from six products.
  Capped by `Maximum variants per product` (default 20, hard limit 50).
- **`AggregateOffer` for price ranges.** Configurable, grouped and bundle products
  now publish `lowPrice` / `highPrice` instead of one arbitrary number.
- **BreadcrumbList on category and CMS pages**, not only product pages, with its
  own configuration group.
- **`priceValidUntil` window setting** in days, and the option to omit the field.
- **FAQ page allow-list**: `CMS page identifiers` under the FAQPage group.
- **CLI**: `--category-id`, `--cms-identifier` and `--json` options on
  `angeo:rich-data:validate`, plus store emulation, duplicate-`@id` detection and
  ProductGroup checks.
- `i18n/en_US.csv`, a GitHub Actions matrix on PHP 8.2–8.5, `require-dev` with
  PHPUnit and PHPStan, and unit tests for the encoder, renderer, product builder,
  breadcrumbs, FAQ builder and FAQ matcher.

### Fixed

- **Published prices no longer ignore tax.** `Product::getFinalPrice()` returns the
  raw catalog price with no tax adjustment, so on a store that displays prices
  including VAT the marked-up price was visibly lower than the page. Prices now
  come from the pricing framework's `final_price` amount, which carries the same
  tax adjustment the storefront shows.
- **Organization and WebSite no longer vanish on incomplete pages.** The ViewModel
  returned an empty string as soon as `current_product` or `current_category` was
  missing, which suppressed every other schema on that page too.
- **`Magento_CatalogInventory` is now declared.** `StockRegistryInterface` was used
  without the module appearing in `composer.json` or the `sequence`.
- **Out-of-stock products on MSI stores.** Salability is now read through
  `Product::isSalable()`, which MSI plugs into; the legacy stock registry remains
  a fallback. Multi-source stores previously published sold-out items as
  `InStock`.
- **N+1 queries removed.** Category breadcrumbs loaded one category per repository
  call — a dozen loads for a product in eight categories — and the category
  ItemList resolved fifty product URLs one query at a time. Both now use a single
  collection query.
- **Category ItemList follows the page.** Paging and sort parameters are applied,
  so page 3 of a category no longer publishes the products from page 1.
- **FAQPage is no longer published on unrelated pages.** 1.x emitted it on any CMS
  page containing an `<h2>` followed by a `<p>`, and on the homepage through a
  content fallback. A page must now be named in the allow-list, or have an
  identifier containing "faq". The homepage is never treated as an FAQ page.
- **FAQ heading length is measured in characters**, not bytes, so short Cyrillic
  and Greek headings are filtered the same way as Latin ones.
- **`priceValidUntil` no longer relies on an accidental date format.** 1.x used
  `date('Y-12-31', …)`, which produced the right string only because `1`, `2` and
  `3` happen not to be format characters.

### Changed

- `SearchAction` is **off by default**. Google removed the sitelinks search box
  from search results on 21 November 2024, so the markup no longer produces a
  Google feature. It is harmless and the option remains for other consumers, but
  the documentation no longer advertises it as a Google feature.
- Registry access is confined to `Model\Page\CurrentEntity` instead of being
  called from the ViewModel, with a repository fallback when the registry is empty.
- Rendering logic was split out of the ViewModel into `Model\Page\*` services, so
  the ViewModel only assembles context.
- Output is compact by default; set `prettyPrint` on `JsonEncoder` via `di.xml` to
  get readable JSON while developing.

### Migration notes

See [UPGRADE.md](UPGRADE.md). No schema or data migration is required:

```
bin/magento setup:upgrade
bin/magento cache:flush
```

## [1.2.0] - 2026-06-13

### Added
- **CollectionPage + ItemList on category pages** (`CollectionPageBuilder`).
- **Organization `description`.**

### Changed
- `ViewModel\JsonLd` builds context for `catalog_category_view`.

## [1.1.0] - 2026-06-08

Never tagged on Packagist; its changes shipped inside 1.2.0.

### Added
- **Merchant return policy** (`offers.hasMerchantReturnPolicy`).
- **Shipping details** (`offers.shippingDetails`).
- **GTIN / MPN identifiers** on Product schema. Off by default.
- New admin config group **Merchant policies (return & shipping)**.

### Fixed
- **BreadcrumbList now actually renders on product pages.** The builder expected a
  `breadcrumbs` context key the ViewModel never supplied.
- `availability_in_stock` / `availability_out_of_stock` exposed in the admin.

## [1.0.2] - 2026-04-18

### Added
- Initial public release: Product, Organization, WebSite, BreadcrumbList and
  FAQPage JSON-LD builders, admin configuration, `angeo:rich-data:validate`
  CLI command, and unit tests.
