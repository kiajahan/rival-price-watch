# Rival Price Watch

Track competitor prices for your WooCommerce products. Paste the link of the same product on competitor sites, and the plugin checks their price on a schedule, keeps a price history, and emails you when a rival becomes cheaper.

- **Reads prices automatically** from the product data shops publish for Google (schema.org JSON-LD, Open Graph, microdata), so most WooCommerce, Shopify and modern stores work without setup.
- **Markets by currency.** Rivals are grouped by the currency they sell in (USD, EUR, GBP, IRT, …) and compared in that currency. Set your own price per market, or let the plugin convert your shop price with your exchange rates (marked ≈).
- **45 currencies**, with Iranian rial ⇄ toman handled automatically, and a fix for Iranian shops that label toman prices as IRR in their data.
- **Difference as % and amount**, overview of all products (most undercut first), price-history chart, email alerts grouped by currency.
- **Right product, right version.** When a competitor page lists several versions, match the one you sell by SKU, barcode or words ("256GB black"). A **Test** button shows exactly what was read from a page.
- **Reliable background checks and alerts.** Checks run as a durable job queue with retries; an alert email that fails to send is kept and retried, never silently dropped.
- **Polite by design:** identifies itself, pauses between requests, never tries to get around blocks or CAPTCHAs.
- **Persian (fa_IR) translation** and right-to-left layout included.
- Only administrators and shop managers see it; nothing is added to the shop front end.

## Install

Download the latest release zip (or the plugin from wordpress.org once published), then in WordPress go to **Plugins → Add New → Upload Plugin**. WooCommerce must be active. Open **WooCommerce → Rival Prices**.

## Development

```
includes/      plugin code (price extractor, currency conversion, checker, admin screens, product box)
languages/     translation template and Persian translation
tests/         plain-PHP tests (extractor, currency, link identity) and tests/integration (runs inside WordPress)
tools/         build_translations.py — rebuilds .pot/.po/.mo/.l10n.php from the source strings
```

Run the tests (no WordPress needed):

```
php tests/test-extractor.php
php tests/test-currency.php
php tests/test-url-hash.php
```

Run the integration tests (queue, retries, alert emails, upgrade from 0.1.0) inside a **local test site** with WooCommerce and this plugin active. They create and delete test products; competitor pages and email are faked, so nothing leaves the site:

```
php tests/integration/run.php /path/to/wordpress/wp-load.php
```

Both sets run on every push in GitHub Actions (`.github/workflows/tests.yml`).

Rebuild translations after changing strings:

```
python tools/build_translations.py
```

Developer hooks: `rpw_request_args` (HTTP request arguments) and `rpw_extracted_price` (add support for a site that publishes no structured price data).

## License

GPLv2 or later. See `readme.txt` for the WordPress.org readme.
