# CBee Product Filters

Version 0.1.0 (step 1 of 3): "Sofort verfügbar" and "Sale" filters for WooCommerce archive pages.

- Shortcode `[cbee_product_filters]` (alias `[cbee_sofort_verfuegbar]`) shows two checkboxes (plain links).
- URL parameters: `sofort_verfuegbar=1`, `sale=1`. Works together with `filter_<attribute>=<slug,slug>`.
- A variable product matches when one variation satisfies all active conditions (stock, sale, selected sizes).
- Filtered pages send `X-LiteSpeed-Cache-Control: public,max-age=300` (filter `cbee_pf_cache_ttl`; 0 = no-cache).

## Install / Test
1. `./build.sh`, upload `cbee-product-filters.zip` on the test site, activate.
2. Remove the old snippet first: it registers the same shortcode name and would clash.
3. Check `/product-category/oberteile/?sale=1`, `?sofort_verfuegbar=1`, `?sale=1&sofort_verfuegbar=1&filter_grosse=<slug>`.
4. Purge page cache and CDN.

Attributes on the shop: Grösse `pa_grosse`, Kopfumfang `pa_kopfumfang-groesse`, Schal `pa_schal-groesse`, Stoffart `pa_stoffart`.
