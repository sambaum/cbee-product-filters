# CBee Product Filters

Version 0.4.1 (step 3 of 3): "Sofort verfügbar", "Sale" and attribute filters for WooCommerce archive pages.

- Shortcode `[cbee_availability_filter]` shows two checkboxes (plain links).
- URL parameters: `sofort=1` (legacy `sofort_verfuegbar=1` still read), `sale=1`. Works together with `filter_<attribute>=<slug,slug>`.
- A variable product matches when one variation satisfies all active conditions (stock, sale, selected sizes).
- Filtered pages send `X-LiteSpeed-Cache-Control: public,max-age=300` (filter `cbee_pf_cache_ttl`; 0 = no-cache).

- Shortcode `[cbee_attribute_filter attribute="grosse" title="Grösse"]` (slug without `pa_`) lists only values that have an
  orderable (not out-of-stock) variation, with a product count that respects all other active filters.
  Plain product attributes (Stoffart) are counted per product, stock does not matter.
- Links write `filter_<slug>=a,b&query_type_<slug>=or`, the same parameters as WooCommerce.
- A selected size always means an orderable variation of that size.

## Install / Test
1. `./build.sh`, upload `cbee-product-filters.zip` on the test site, activate.
2. Remove the old snippet first: it registers the same shortcode name and would clash.
3. Check `/product-category/oberteile/?sale=1`, `?sofort_verfuegbar=1`, `?sale=1&sofort_verfuegbar=1&filter_grosse=<slug>`.
4. Purge page cache and CDN.

Attributes on the shop: Grösse `pa_grosse`, Kopfumfang `pa_kopfumfang-groesse`, Schal `pa_schal-groesse`, Stoffart `pa_stoffart`.

## No-reload filtering (0.4.1)
Clicks on filter links and pagination fetch the target URL, swap the product section (`#main .ct-container > section`)
and the `.cbee-pf` panels, update the address bar (`pushState`) and the title. Any failure, or a panel count that differs
between old and new page, falls back to a normal page load. Event `cbee-pf:updated` fires after each swap.
Depends on Blocksy's markup; the sort dropdown still reloads the page.

## Sale badge
Moved to the CBee Customizing plugin (Mods tab) in 0.4.10.
