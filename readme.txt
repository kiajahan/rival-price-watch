=== Rival Price Watch ===
Contributors: lazykia
Tags: woocommerce, price tracking, competitor prices, price monitor, price alert
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track competitor prices for your WooCommerce products. Daily checks, price history and an email when a rival is cheaper.

== Description ==

Rival Price Watch tells you when a competitor sells the same product for less.

1. Open a product and paste the link of the same product on one or more competitor sites.
2. The plugin visits those pages on a schedule and reads their price.
3. **WooCommerce → Rival Prices** shows every product, your price, the cheapest rival and the difference, with the most undercut products first.
4. You get one email when a competitor becomes cheaper than you.

**How prices are read**

Most online shops publish their product data in a standard format (schema.org) so that Google can show prices in search results. Rival Price Watch reads that same data, so it works with most WooCommerce, Shopify and other modern stores with no setup. It also understands Open Graph price tags and microdata.

**Features**

* Up to 10 competitor links per product
* Pick the right version of a product (SKU, barcode, or words such as "256GB black") when a competitor page lists several
* A "Test" button shows exactly what is read from a link before you save it
* Optional alert threshold: only email when a rival is cheaper by at least X percent
* Checks twice a day, daily, weekly or only on demand
* Large catalogues are checked in small background batches
* Price history chart on each product
* Out-of-stock rival offers are ignored when finding the cheapest rival
* Shows the difference as a percentage and as an amount in your currency
* Markets by currency: rivals are grouped by the currency they sell in (USD, EUR, IRT, ...) and compared in that currency. Set your own price per market (for example 79 USD or 72 EUR); without it the shop price is converted (marked ≈)
* Overview filter per market, and alert emails grouped by currency
* 45 well-known currencies: rival prices in another currency are converted to your shop currency before comparing. Rates can be entered by hand or filled in with one click (official rates from open.er-api.com, only when you click)
* Iranian rial and toman (IRT) are converted to each other automatically. For rial and toman shops, rates are entered by hand because official rates are far from the market rate
* A price multiplier per link, for sites that show prices without tax
* Reads prices written with Persian or Arabic digits and both 1,234.56 and 1.234,56 styles
* Fully translatable; Persian (فارسی) translation and right-to-left layout included
* Compatible with WooCommerce High-Performance Order Storage

**Polite by design**

Checks identify themselves with a clear user agent, wait between requests (3 seconds by default), and never try to get around blocks or CAPTCHAs. If a site blocks automatic visits, the product shows "error" for that link with the reason. Only monitor sites whose terms allow it.

**For developers**

* `rpw_request_args` filters the HTTP request arguments.
* `rpw_extracted_price` lets you read prices from a site that does not publish structured data.

== Third-party services ==

This plugin connects to outside services only in these cases:

**Competitor product pages you add.** To read prices, the plugin downloads the web pages whose links you enter in a product's "Rival prices" box, on the schedule you choose or when you click "Check now". Only a normal page request is sent (with a user agent naming this plugin and your site address). No customer, order or personal data is sent.

**ExchangeRate-API (open.er-api.com).** Only when an administrator clicks "Fill in today's official rates" under Settings → Exchange rates, the plugin requests https://open.er-api.com/v6/latest/USD to get current exchange rates. No data about your site, products or customers is sent. The button is not shown for shops that use the Iranian rial or toman.

* Service: https://www.exchangerate-api.com/
* Terms of use: https://www.exchangerate-api.com/terms
* Privacy policy: https://www.exchangerate-api.com/privacy

== Installation ==

1. Upload the plugin and activate it. WooCommerce must be active.
2. Edit a product and fill the **Rival prices** box.
3. Click **Check now** to see the first result, or wait for the schedule.
4. Adjust the schedule and alert email under **WooCommerce → Rival Prices → Settings**.

== Frequently Asked Questions ==

= A link shows "no price found" =

That page does not publish price data in a standard format. A developer can add support with the `rpw_extracted_price` filter.

= The price is from the wrong version of the product =

Some pages list several versions (colours, storage sizes) of one product. Fill in the **Variant** field of that link with the SKU or barcode of the right version, or with words that describe it (for example `256GB black`). Click **Test** to see which product and price are read.

= I did not get an alert email =

If WordPress cannot send the email, the alert is kept and sending is tried again, and a notice on the Rival Prices page tells you why it failed. Check that your site can send email (an SMTP plugin usually fixes it).

= A link shows "error" with HTTP 403 or 429 =

The site is blocking automatic visits. Increase the pause between requests, or remove that link. The plugin will not try to bypass blocks.

= Checks don't run on time =

WordPress runs scheduled tasks when someone visits your site. On quiet sites, add a real cron job on your server that calls wp-cron.php.

= A link says "needs an exchange rate" =

That competitor sells in another currency. Go to WooCommerce → Rival Prices → Settings → Exchange rates and fill in the rate for that currency.

= Does the plugin contact any other service? =

Only the competitor pages you add, and open.er-api.com if you click "Fill in today's official rates". Nothing else is sent anywhere.

= Does it change my prices? =

No. It only reads and reports. Your prices are never changed.

== Screenshots ==

1. Overview of all products and their cheapest rival.
2. The Rival prices box on a product, with price history.
3. Settings.

== Changelog ==

= 0.2.1 =
* Fixed: a variant hint that is an exact SKU, GTIN or MPN now always picks that offer, even when another offer whose code only contains it is cheaper.
* Fixed: for an AggregateOffer, the individual offers inside it are read first, so a sold-out variant's low price is no longer used and every variant can be matched. lowPrice is used only when there are no individual offers.
* Fixed: offers in different currencies are no longer compared as bare numbers. The link's currency is preferred, otherwise the page's first currency.
* Fixed: "Check now" no longer drops a queued retry when the check fails for a temporary reason (timeout, HTTP 429 / 5xx); the retry is kept or added.
* Fixed: a check whose result could not be saved to the database is tried again instead of being treated as done.
* Fixed: if a background batch stops unexpectedly, a recovery batch is already scheduled, so the queue continues on its own.

= 0.2.0 =
* Fixed: prices with three decimals (for example 1.234 KWD) in meta tags and microdata were read as 1234.
* Fixed: a nested offer lost its currency and its out-of-stock status.
* Fixed: currency meta tags written with `name=` were ignored.
* Fixed: alert emails could be lost. An alert is now marked as sent only after the email was really sent; failed emails are kept and tried again.
* Fixed: "Check now" on one product did not send an alert when all links were checked during the click.
* Fixed: two links that differ only by capital letters in the path were treated as one link.
* Fixed: deleting the plugin left exchange rates and per-currency prices behind.
* New: variant matching per link, and a "Test" button that previews what is read from a page.
* New: background checks use a job table. Each result is saved at once, failed pages (timeouts, HTTP 429/5xx) are retried, and an interrupted run continues by itself.
* New: alert threshold in percent.
* Faster overview page on large catalogues.

= 0.1.0 =
* First release.
