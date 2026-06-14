# Changelog

All notable changes to `angeo/module-rich-data` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-06-13

### Added
- **CollectionPage + ItemList on category pages** (`CollectionPageBuilder`).
  Emits a `CollectionPage` whose `mainEntity` is an `ItemList` of the category's
  enabled, catalog-visible products (capped at 50). Read by the Gemini Shopping
  Graph, which previously had no machine-readable product listing on category
  pages. Toggle under Angeo Rich Data → CollectionPage schema (on by default).
- **Organization `description`.** The Organization schema now includes a
  `description` field (configurable under Angeo Rich Data → Organization →
  Organization description). AI engines read it as the brand entity summary;
  its absence was previously flagged by the AEO audit.

### Changed
- `ViewModel\JsonLd` now builds context for `catalog_category_view` (current
  category + a lightweight product list) so the new CollectionPage builder has
  data to render.

## [1.1.0] - 2026-06-08

### Added
- **Merchant return policy** (`offers.hasMerchantReturnPolicy`). Emits a
  spec-compliant `MerchantReturnPolicy` (applicableCountry, merchantReturnDays,
  returnPolicyCategory, returnMethod, returnFees). Required by Google &
  ChatGPT Shopping since January 2026. Configurable under
  **Stores → Configuration → Angeo → Rich Data → Merchant policies**.
- **Shipping details** (`offers.shippingDetails`). Emits `OfferShippingDetails`
  with `shippingRate` (MonetaryAmount), `shippingDestination` (DefinedRegion)
  and optional `deliveryTime` (handling + transit `QuantitativeValue`).
- **GTIN / MPN identifiers** on Product schema, read from configurable product
  attributes. Improves AI/Google product matching. Off by default.
- New admin config group **Merchant policies (return & shipping)** and new
  Product fields: Include GTIN/MPN, GTIN attribute, MPN attribute, In-stock
  availability URL, Out-of-stock availability URL.
- `Model/Config/Source/ReturnFee` source model for the return-fee dropdown.
- Unit coverage for the merchant return policy and shipping details output.

### Fixed
- **BreadcrumbList now actually renders on product pages.** The
  `BreadcrumbBuilder` expected a `breadcrumbs` context key that the ViewModel
  never supplied, so the breadcrumb schema silently never appeared even with
  the toggle enabled. The ViewModel now builds the full trail
  (Home → category path → product) from the product's deepest active category
  and passes it into the render context. This resolves the persistent
  "BreadcrumbList missing" finding in `angeo/module-aeo-audit`.
- `availability_in_stock` / `availability_out_of_stock` are now exposed in the
  admin (previously only present as config.xml defaults and not editable). The
  builder also falls back to sane schema.org defaults if either value is blank.

### Changed
- ViewModel now logs render failures via `LoggerInterface` instead of silently
  swallowing them, matching the error handling already used in `SchemaRenderer`.

### Migration notes
- No schema or data migration required. After upgrading, run:
  ```
  bin/magento setup:upgrade
  bin/magento cache:flush
  ```
- Merchant return policy and shipping details are **disabled by default** to
  avoid publishing inaccurate policy data. Enable and fill them in under
  **Stores → Configuration → Angeo → Rich Data → Merchant policies** once your
  real return window, shipping rate and delivery times are known. Publishing
  incorrect policy values can hurt eligibility, so opt in deliberately.

## [1.0.2] - 2026-04-18

### Added
- Initial public release: Product, Organization, WebSite, BreadcrumbList and
  FAQPage JSON-LD builders, admin configuration, `angeo:rich-data:validate`
  CLI command, and unit tests.
