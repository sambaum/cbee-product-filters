# AGENTS.md

Instructions for AI coding assistants working on this repo.

## What this is
`cbee-product-filters/` is a WordPress/WooCommerce plugin (PHP 7.4+, vanilla JS/CSS, no dependencies) that replaces the
`[cbee_sofort_verfuegbar]` snippet and, step by step, the WooCommerce attribute filter blocks in the Blocksy sidebar.
Requirements and decisions: `requirements.md`.

- `cbee-product-filters.php`: query engine (one SQL fragment via `posts_clauses`), cache header, the `[cbee_filters]` bar, sort and settings (`includes/admin.php`).
- `assets/filters.css`: styles, themed through Blocksy CSS variables.

## Rules (do not break)
- Filters combine with AND; several values of one attribute combine with OR.
- Stock counts only with numeric `_stock` > 0 (backorder does not count).
- Sale and stock are evaluated on the SAME variation.
- Same `filter_<attr>` URL parameters as WooCommerce. Filters are plain links; JS (step 3) is an enhancement with fallback.
- No dependencies, no jQuery, no external requests. Keep it small.
- Visitor-facing texts are German; admin UI (if any) is English.
- Escape output and sanitize input. Comments only where a reader cannot see the reason.
- Never touch prod (cbee.ch). Test site only: test.cbee.ch.

## Every change
1. Bump the version in both places in `cbee-product-filters.php` (header and `CBEE_PF_VERSION`).
2. `php -l` the PHP file.
3. `./build.sh` (or `build.bat`). Never `Compress-Archive`.
4. Test on test.cbee.ch; purge page cache and CDN after installing.
