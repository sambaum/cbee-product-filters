# Side-bar plugin: requirements (draft)

Replaces `iks-menu-pro` (v1.12.7, ~3.6 MB, Freemius SDK) in the Blocksy sidebar, plus the custom
`[cbee_sofort_verfuegbar]` snippet. Findings below come from test.cbee.ch, observed 2026-10-11.

## What is in `.ct-sidebar` today

Home page: one widget (`block-28`) holding `[iks_menu id="2958"]`.
Category pages (e.g. `/product-category/oberteile/`): six widgets, in this order:

1. `block-29`: the iks menu (same on category pages as on the home page)
2. `block-17`: WooCommerce attribute filter block "Grösse" (loaded by JS, `is-loading`)
3. `block-33`: `[cbee_sofort_verfuegbar]` output: heading "Verfügbarkeit" plus checkbox "Sofort verfügbar"
4. `block-30`: WooCommerce attribute filter block "Kopfumfang"
5. `block-31`: WC attribute filter, attribute id 6 (also headed "Kopfumfang"); `block-32`: WC attribute filter "Stoffart"

### iks menu (id 2958)
- Source: `taxonomy` (product_cat), free mode (`data-is-pro='0'`).
- Two levels: 19 terms. Parents: Accessoires (Schals, Mützen), Oberteile (Jacken, Blusen, Shirts, Pullover, Gilets),
  Hosen (Leggings, Jogger/Trainer, Shorts, Pumphosen), Strampler/Latzhose, Bodys, Kleider, Sets, Wunschdesign.
- Parents with children have a chevron toggle (expand/collapse, chevron rotates 180° when expanded).
- Row: link plus toggle, min-height 44px, 1px bottom border, hover background, current-term background
  (`--current`), children indented 15px and with a slightly darker background.
- Not used: product counts (CSS exists, no markup), images/icons (CSS exists, no markup), search, pro features.
- Loads `public.css`, `public.js`, `menu.js` (~60 KB uncompressed) and an inline script that appends ~3 KB of
  per-instance CSS to a `<style>` tag at runtime. Pure waste for a static tree.

### `[cbee_sofort_verfuegbar]`
- Checkbox that sets or removes the query parameter `sofort_verfuegbar=1` and reloads the page (also drops
  `product-page` and `paged`). Inline `<style>` and `<script>` in the widget.
- Server side (user's snippet, `woocommerce_product_query`): when `sofort_verfuegbar=1`, loads **every** published
  product with `wc_get_products(limit -1)`, then `wc_get_product()` for each product and each variation, and sets
  `post__in`. This is the slowness: O(all products + variations) objects per filtered request.
- Rules to keep: simple product counts if it manages stock and qty > 0; variable product counts if at least one
  variation has a numeric `stock_quantity` > 0 (backorder / made-to-order does not count). If size filters
  (`filter_*` params) are selected, the in-stock variation must also match one of the selected values (OR).
- Known weaknesses of the snippet (to fix, not copy): size match compares any variation attribute to any `filter_*`
  value instead of the matching attribute; ignores disabled variations; `post__in` with a huge ID list.
- Plan: replace with one SQL query on variation meta (`_stock` > 0, `attribute_pa_<x>` IN selected, parent
  published), optionally cached in a transient keyed by the selected sizes and cleared on stock changes.

## Extra CSS in Blocksy (user-provided)
- Only the first block concerns this sidebar: `ul.wc-block-product-categories-list li` borders. That is the WC
  category-list block, which is **not on the page** (only present inside CSS); leftover from before iks, drop it.
  Rows get their separators from the new plugin's own CSS instead.
- The rest (header flex, AWS search box, `.no-sizes-grid`, fabric swatch lightbox, `.no-archive-sizes`) is unrelated
  to the sidebar and stays in Blocksy.

## Goals
- Same look and behaviour as now, minus everything unused.
- Server-rendered HTML, no AJAX, same output for all visitors (cache-safe).
- Tiny CSS and JS, no dependencies, no admin builder UI, no licensing SDK.
- Theme integration through Blocksy CSS variables (same approach as swiss-cookie-banner).
- Replace `[cbee_sofort_verfuegbar]` with a block or shortcode of the same plugin (keep the old shortcode name as an
  alias so the widgets keep working).

## Proposed shape
- Shortcode `[cbee_category_menu]` (plus optional alias `[iks_menu id=...]`, ignored id) rendering the `product_cat`
  tree as nested `<ul>`; branches use `<button aria-expanded>`; a parent of the current category starts expanded;
  current term has `aria-current="page"`.
- Tree cached in a transient, cleared on `created_product_cat`, `edited_product_cat`, `delete_product_cat`.
- Minimal JS (toggle only); the no-JS fallback shows everything expanded.
- Shortcode `[cbee_sofort_verfuegbar]` with the checkbox; the query filter hooks `pre_get_posts` /
  `woocommerce_product_query`.

## Open questions
1. Hierarchy order: alphabetical as now, or by the WooCommerce term order? (Current order looks custom, not alphabetical.)
2. Should hidden or empty categories be listed? Currently seemingly all non-empty ones. Which are excluded (e.g. Uncategorized)?
3. Does the menu also appear on product pages or only on shop and category pages? Mobile: Blocksy off-canvas?
4. (answered) "sofort verfügbar" = real stock quantity > 0, backorder does not count.
5. (answered) The other widgets are WC attribute filter blocks (Grösse, Kopfumfang x2, Stoffart); they stay as WC blocks.
   Is the second "Kopfumfang" (attribute id 6) intentional?
6. Name and slug of the plugin, same repo conventions as the cookie banner (GPL-2.0-or-later, AGENTS.md, build scripts).

## Decisions (2026-10-11)
- Scope: phase 2. A new plugin **CBee Product Filters** (iks menu stays for now; the WooCommerce attribute filter blocks
  get replaced step by step).
- Build order, each step shippable on its own:
  1. Engine plus "Sofort verfügbar" and "Sale" (replaces `[cbee_sofort_verfuegbar]`; keep it as an alias).
  2. Attribute filters (Grösse, Kopfumfang, Stoffart) rendered server-side, stock-aware (out-of-stock sizes greyed or
     hidden), counts that respect the other active filters. Same `filter_<attr>` URL parameters as WooCommerce.
  3. No-reload swap: filters are plain links/forms; JS fetches the URL, swaps grid, result count, pagination and the
     filter panel, uses `history.pushState`. Falls back to a normal page load on any error or markup mismatch.
- Filters combine with AND; sizes within one attribute combine with OR.
- Stock: a variation counts only with a numeric `_stock` > 0 (backorder does not count).
- Sale semantics (one rule for both filters, evaluated per variation):
  - No size selected: a product matches "Sale" if **any** in-stock-or-not variation is on sale. With "Sofort verfügbar" also ticked,
    the **same** variation must be on sale AND in stock (confirmed by the user).
  - Size selected: the product matches only if a variation of **a selected size** is on sale (and, with "Sofort
    verfügbar", also in stock).
  - Simple products: on sale if the sale price is active.
  - Source of truth: `wc_product_meta_lookup.onsale` for products; variations via sale price meta with sale date
    window checked (scheduled sales).
- Cache: for requests carrying filter parameters PHP sends `X-LiteSpeed-Cache-Control: public,max-age=<TTL>` (default
  300 s, setting) so stock changes never need a purge; optionally `no-cache` if measured fast enough. Verify on the
  test site that the Hostinger CDN does not cache filtered HTML.
- Testing: steps 1 and 2 verified by curl on test.cbee.ch; step 3 needs click-testing by the user.
- Never touch prod; test site only.

## Still open
- Items 1-3 of "Open questions" above (only relevant to the menu, so deferred).
- Second "Kopfumfang" filter (attribute id 6): intentional?
- Which attributes/slugs exist and what do their URL parameters look like (`filter_groesse`?). Read from test site.
