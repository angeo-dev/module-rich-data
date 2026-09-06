# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 2.0.x | yes |
| 1.x | no — upgrade to 2.0.0 |

## Reporting a vulnerability

Email **info@angeo.dev** with a description, affected versions and a reproduction
if you have one. Please do not open a public GitHub issue for a suspected
vulnerability.

You will get an acknowledgement within 72 hours. Fixes for confirmed issues are
released as a patch version, credited in the changelog unless you prefer
otherwise.

## Known advisories

**2.0.0 — JSON-LD script breakout (fixed).** All versions from 1.0.0 to 1.2.0
encoded JSON-LD with `JSON_UNESCAPED_SLASHES` and printed it unescaped, so a
`</script>` sequence in catalog or configuration text could terminate the script
element and inject markup. Anyone able to edit product names, descriptions,
category names or the module's configuration fields could reach it. Fixed in
2.0.0 by escaping slashes and HTML-significant characters in
`Model\JsonLd\JsonEncoder`. There is no configuration workaround; upgrade.
