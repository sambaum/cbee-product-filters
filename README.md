# CBee Product Filters

Version 0.7.3. Filter bar for WooCommerce archive pages: pills above the product list, a full Filter view, a sort pill.

**This is the last version that still supports the old shortcodes** (`[cbee_availability_filter]`,
`[cbee_attribute_filter]`, `[cbee_reset_filter]`). They are deprecated (administrators see a notice under each) and are
removed in 0.8.0. Git tag: `v0.7.3-last-shortcodes`.

## The bar: `[cbee_filters]`
- One shortcode, or tick "Show the bar automatically above the product list" (hook `woocommerce_before_shop_loop`).
- Pills: Filter button first, applied filters next (with ×), then the rest, the sort pill last on the right.
- Tapping a pill opens a popover (desktop) or bottom sheet (phone) with one value per row. Picks are collected and
  applied once, when the popover or the full view closes. Pill ×, toggle pills, sorting and "Zurücksetzen" apply at once.
- Full view (Filter button): drawer on desktop, full screen on phone; accordions per filter, toggles for
  Sofort verfügbar and Sale.
- Without JavaScript the pills link to the full view (`#cbee-pf-full`) and every option is a plain link.
- Sorting uses WooCommerce's own options (`woocommerce_catalog_orderby`), `?orderby=`.

## Settings (CBee Filters in the admin bar)
Per filter (two toggles plus every WooCommerce attribute): enabled, title, order, "in bar" (pill) and colour dots.
Values follow the order set on the attribute in WooCommerce. Exception: `grosse` lists single sizes first, then ranges,
each ascending (filter `cbee_pf_size_attributes`).
Colour dots: set a colour on each term (Products > Attributes > Configure terms) or leave it automatic, derived from the
name (`Blau/Türkis` gives a split dot).

## Query engine
- URL parameters: `sofort=1` (legacy `sofort_verfuegbar=1` still read), `sale=1`, `filter_<attribute>=<slug,slug>`,
  `query_type_<attribute>=or`, `orderby`.
- A variable product matches when one variation satisfies all active conditions (stock, sale, selected sizes).
- Stock counts only with numeric `_stock` > 0; a selected size always means an orderable variation of that size.
- Filtered pages send `X-LiteSpeed-Cache-Control: public,max-age=300` (filter `cbee_pf_cache_ttl`; 0 = no-cache).
- No-reload filtering: links and pagination fetch the target URL, swap the product section
  (`#main .ct-container > section`) and the bar, update the address bar and title. Any failure falls back to a normal
  page load. Event `cbee-pf:updated` fires after each swap. Depends on Blocksy's markup.
- Product cards link to the product page with the chosen size preselected (`attribute_pa_<x>`), when exactly one of the
  selected values exists on that product.

## Install / Test
1. `./build.sh`, upload `cbee-product-filters.zip` on the test site.
2. Purge page cache and CDN.
