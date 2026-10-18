<?php
/**
 * Plugin Name: CBee Product Filters
 * Description: Lean product filters for WooCommerce: "Sofort verfügbar", "Sale" and stock-aware attribute filters, evaluated per variation in SQL.
 * Version: 0.4.9
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cbee-product-filters
 */

defined( 'ABSPATH' ) || exit;

const CBEE_PF_VERSION = '0.4.9';

// Query parameter names. The old snippet's name (sofort_verfuegbar) is still accepted, but never written: that snippet
// hooks the same parameter and loads the whole catalogue whenever it sees it, which made every click take seconds.
const CBEE_PF_STOCK  = 'sofort';
const CBEE_PF_LEGACY = 'sofort_verfuegbar';
const CBEE_PF_SALE  = 'sale';

// Rows shown before "Mehr anzeigen"; filters.css hides rows after the 10th (nth-child(n+11)).
const CBEE_PF_LIMIT = 10;

function cbee_pf_active( $key ) {
	if ( CBEE_PF_STOCK === $key && isset( $_GET[ CBEE_PF_LEGACY ] ) && '1' === $_GET[ CBEE_PF_LEGACY ] ) { // phpcs:ignore WordPress.Security.NonceVerification
		return true;
	}
	return isset( $_GET[ $key ] ) && '1' === $_GET[ $key ]; // phpcs:ignore WordPress.Security.NonceVerification
}

/** Selected values per attribute taxonomy, from the filter_<attr> parameters WooCommerce uses. */
function cbee_pf_selected_attributes() {
	$out = array();
	foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $key, 'filter_' ) || ! is_string( $value ) || '' === $value ) {
			continue;
		}
		$taxonomy = 'pa_' . sanitize_title( substr( $key, 7 ) );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}
		$slugs = array_filter( array_map( 'sanitize_title', explode( ',', wp_unslash( $value ) ) ) );
		if ( $slugs ) {
			$out[ $taxonomy ] = array_values( $slugs );
		}
	}
	return $out;
}

/** True when the attribute is used by variations (a size), false when it is a plain product attribute (fabric). */
function cbee_pf_is_variation_attr( $taxonomy ) {
	static $cache = array();
	if ( ! isset( $cache[ $taxonomy ] ) ) {
		global $wpdb;
		$cache[ $taxonomy ] = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", 'attribute_' . $taxonomy ) );
	}
	return $cache[ $taxonomy ];
}

function cbee_pf_selected_variation_attributes() {
	return array_filter( cbee_pf_selected_attributes(), 'cbee_pf_is_variation_attr', ARRAY_FILTER_USE_KEY );
}

function cbee_pf_engine_needed() {
	return cbee_pf_active( CBEE_PF_STOCK ) || cbee_pf_active( CBEE_PF_SALE ) || cbee_pf_selected_variation_attributes();
}

add_action( 'woocommerce_product_query', function ( $q ) {
	if ( cbee_pf_engine_needed() ) {
		$q->set( 'cbee_pf', 1 );
	}
} );

// The counter query must not see the selection of its own attribute. WordPress turns the chosen size into the
// taxonomy/term query vars and adds it as a term condition, so clear those too, after everyone else has run.
add_action( 'pre_get_posts', function ( $q ) {
	$skip = $q->get( 'cbee_pf_skip' );
	if ( $skip ) {
		$q->set( $skip, '' );
		if ( $q->get( 'taxonomy' ) === $skip ) {
			$q->set( 'taxonomy', '' );
			$q->set( 'term', '' );
		}
		$q->set( 'tax_query', cbee_pf_drop_taxonomy( $q->get( 'tax_query' ), $skip ) );
	}
}, PHP_INT_MAX );

add_filter( 'posts_clauses', function ( $clauses, $q ) {
	if ( ! $q->get( 'cbee_pf' ) ) {
		return $clauses;
	}
	$attributes = cbee_pf_selected_variation_attributes();
	unset( $attributes[ $q->get( 'cbee_pf_skip' ) ] );
	$clauses['where'] .= ' AND ' . cbee_pf_sql( cbee_pf_active( CBEE_PF_STOCK ), cbee_pf_active( CBEE_PF_SALE ), $attributes );
	return $clauses;
}, 10, 2 );

/**
 * Conditions one variation (alias v) must meet. It has to be orderable, so a size that is sold out
 * is never offered; stock, sale and the selected sizes are all checked on this same variation.
 */
function cbee_pf_variation_conditions( $stock, $sale, $attributes ) {
	global $wpdb;
	$cond = array(
		"v.post_type = 'product_variation'",
		"v.post_status = 'publish'",
		cbee_pf_orderable( 'v.ID' ),
	);
	if ( $stock ) {
		$cond[] = cbee_pf_in_stock( 'v.ID' );
	}
	if ( $sale ) {
		$cond[] = cbee_pf_on_sale( 'v.ID' );
	}
	foreach ( $attributes as $taxonomy => $slugs ) {
		$in     = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$cond[] = $wpdb->prepare(
			"NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} va WHERE va.post_id = v.ID AND va.meta_key = %s AND va.meta_value <> '' AND va.meta_value NOT IN ({$in}))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			array_merge( array( 'attribute_' . $taxonomy ), $slugs )
		);
	}
	return $cond;
}

// Stock comes from WooCommerce's lookup table (primary key join, numeric columns): far faster than casting postmeta strings.
function cbee_pf_in_stock( $post ) {
	global $wpdb;
	return "EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_product_meta_lookup ls WHERE ls.product_id = {$post} AND ls.stock_quantity > 0)";
}

// A missing lookup row counts as orderable, so a product is never hidden because of an incomplete lookup table.
function cbee_pf_orderable( $post ) {
	global $wpdb;
	return "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}wc_product_meta_lookup lo WHERE lo.product_id = {$post} AND lo.stock_status = 'outofstock')";
}

// An active sale shows up as _price < _regular_price (WooCommerce updates _price when a scheduled sale starts or ends).
function cbee_pf_on_sale( $post ) {
	global $wpdb;
	return "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pr JOIN {$wpdb->postmeta} rg ON rg.post_id = pr.post_id AND rg.meta_key = '_regular_price'
		WHERE pr.post_id = {$post} AND pr.meta_key = '_price' AND pr.meta_value <> '' AND rg.meta_value <> ''
		AND CAST(pr.meta_value AS DECIMAL(12,4)) < CAST(rg.meta_value AS DECIMAL(12,4)))";
}

/**
 * WHERE fragment on the products table. A variable product matches when ONE variation meets every
 * condition. Simple products match on their own stock / price; size filters do not apply to them.
 */
function cbee_pf_sql( $stock, $sale, $attributes ) {
	global $wpdb;
	$p = $wpdb->posts;

	$variable = "EXISTS (SELECT 1 FROM {$p} v WHERE v.post_parent = {$p}.ID AND " . implode( ' AND ', cbee_pf_variation_conditions( $stock, $sale, $attributes ) ) . ')';

	$simple = array( "NOT EXISTS (SELECT 1 FROM {$p} sv WHERE sv.post_parent = {$p}.ID AND sv.post_type = 'product_variation')" );
	if ( $stock ) {
		$simple[] = cbee_pf_in_stock( "{$p}.ID" );
	}
	if ( $sale ) {
		$simple[] = cbee_pf_on_sale( "{$p}.ID" );
	}
	if ( $sale || $attributes ) {
		$simple[] = cbee_pf_orderable( "{$p}.ID" );
	}

	return '(' . $variable . ' OR (' . implode( ' AND ', $simple ) . '))';
}

/**
 * Filtered pages depend on stock, which changes without any purge. A short TTL keeps them fresh enough
 * and still cached. Plain archive pages are left to the cache plugin's normal rules.
 */
add_action( 'template_redirect', function () {
	$filtered = cbee_pf_engine_needed() || cbee_pf_selected_attributes();
	if ( headers_sent() || ! $filtered ) {
		return;
	}
	$ttl = (int) apply_filters( 'cbee_pf_cache_ttl', 300 );
	header( 'X-LiteSpeed-Cache-Control: ' . ( $ttl > 0 ? 'public,max-age=' . $ttl : 'no-cache' ) );
} );

/** Admin-only debug: add ?cbee_debug=1 to a page and read the browser console. Off for everyone else. */
function cbee_pf_debug( $label, $data ) {
	static $log = array();
	if ( ! isset( $_GET['cbee_debug'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( ! $log ) {
		add_action( 'wp_footer', function () use ( &$log ) {
			echo '<script>console.log("cbee-pf", ' . wp_json_encode( $log ) . ');</script>';
		}, 99 );
	}
	$log[] = array( $label => $data );
}

/** Current page URL without pagination; $changes maps parameter => value, null removes it. */
function cbee_pf_url( array $changes ) {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = preg_replace( '#/page/\d+/?#', '/', strtok( $uri, '?' ) );
	$args = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
	unset( $args['paged'], $args['product-page'], $args['cbee_debug'] );
	foreach ( $changes as $key => $value ) {
		if ( null === $value ) {
			unset( $args[ $key ] );
		} else {
			$args[ $key ] = $value;
		}
	}
	return esc_url( add_query_arg( array_map( 'wp_unslash', $args ), home_url( $path ) ) );
}

function cbee_pf_enqueue() {
	wp_enqueue_style( 'cbee-pf', plugins_url( 'assets/filters.css', __FILE__ ), array(), CBEE_PF_VERSION );
	wp_enqueue_script( 'cbee-pf', plugins_url( 'assets/filters.js', __FILE__ ), array(), CBEE_PF_VERSION, true );
}

function cbee_pf_item( $label, $url, $on, $count = null ) {
	return sprintf(
		'<a class="cbee-pf-check%s" href="%s" rel="nofollow" role="checkbox" aria-checked="%s"><span class="cbee-pf-box"></span><span class="cbee-pf-label">%s</span>%s</a>',
		$on ? ' is-on' : '',
		$url,
		$on ? 'true' : 'false',
		esc_html( $label ),
		null === $count ? '' : '<span class="cbee-pf-count">' . (int) $count . '</span>'
	);
}

/** Filters only make sense where a product list is shown. */
function cbee_pf_is_archive() {
	return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
}

function cbee_pf_render() {
	if ( ! cbee_pf_is_archive() ) {
		return '';
	}
	cbee_pf_enqueue();

	$items = array(
		CBEE_PF_STOCK => 'Sofort verfügbar',
		CBEE_PF_SALE  => 'Sale',
	);
	$html  = '<div class="cbee-pf"><div class="cbee-pf-title">Verfügbarkeit</div>';
	foreach ( $items as $key => $label ) {
		$on    = cbee_pf_active( $key );
		$html .= cbee_pf_item( $label, cbee_pf_url( array( $key => $on ? null : '1', CBEE_PF_LEGACY => null ) ), $on );
	}
	return $html . '</div>';
}

/** [cbee_reset_filter]: clears every filter. Place it where it suits, e.g. at the top or bottom of the filters. */
function cbee_pf_render_reset() {
	if ( ! cbee_pf_is_archive() ) {
		return '';
	}
	cbee_pf_enqueue();
	if ( ! ( cbee_pf_engine_needed() || cbee_pf_selected_attributes() ) ) {
		return '<div class="cbee-pf" hidden></div>'; // keeps the panel count stable for the no-reload swap
	}
	$clear = array( CBEE_PF_STOCK => null, CBEE_PF_LEGACY => null, CBEE_PF_SALE => null );
	foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
			$clear[ $key ] = null;
		}
	}
	return '<div class="cbee-pf"><a class="cbee-pf-reset" href="' . cbee_pf_url( $clear ) . '" rel="nofollow">Filter zurücksetzen</a></div>';
}
add_shortcode( 'cbee_reset_filter', 'cbee_pf_render_reset' );

add_shortcode( 'cbee_availability_filter', 'cbee_pf_render' );

/** Removes the clauses of one taxonomy from a tax_query, nested groups included. */
function cbee_pf_drop_taxonomy( $clauses, $taxonomy ) {
	$out = array();
	foreach ( (array) $clauses as $key => $clause ) {
		if ( is_array( $clause ) && isset( $clause['taxonomy'] ) && $clause['taxonomy'] === $taxonomy ) {
			continue;
		}
		$out[ $key ] = is_array( $clause ) && ! isset( $clause['taxonomy'] ) ? cbee_pf_drop_taxonomy( $clause, $taxonomy ) : $clause;
	}
	return $out;
}

/** IDs of the products the current archive would show, ignoring the selection of $taxonomy (so its other values stay visible). */
function cbee_pf_context_ids( $taxonomy ) {
	global $wp_query;
	$args                     = $wp_query->query_vars;
	$args['tax_query']        = cbee_pf_drop_taxonomy( isset( $args['tax_query'] ) ? $args['tax_query'] : array(), $taxonomy );
	$args['fields']           = 'ids';
	$args['nopaging']         = true;
	$args['posts_per_page']   = -1;
	$args['no_found_rows']    = true;
	$args['orderby']          = 'none';
	$args['cbee_pf']          = 1;
	unset( $args[ $taxonomy ] );
	if ( isset( $args['taxonomy'] ) && $args['taxonomy'] === $taxonomy ) {
		unset( $args['taxonomy'], $args['term'] );
	}
	$args['cbee_pf_skip']     = $taxonomy;
	$args['suppress_filters'] = false;
	if ( ! cbee_pf_engine_needed() && ! cbee_pf_is_variation_attr( $taxonomy ) ) {
		$args['cbee_pf'] = 0;
	}
	$query = new WP_Query( $args );
	cbee_pf_debug( 'context ' . $taxonomy, array( 'sql' => $query->request, 'tax_query' => $query->tax_query->queries ) );
	return array_map( 'intval', $query->posts );
}

/** slug => number of products, honouring every other active filter. */
function cbee_pf_term_counts( $taxonomy, $ids ) {
	global $wpdb;
	if ( ! $ids ) {
		return array();
	}
	$list   = implode( ',', $ids );
	$others = cbee_pf_selected_variation_attributes();
	unset( $others[ $taxonomy ] );
	$cond = cbee_pf_variation_conditions( cbee_pf_active( CBEE_PF_STOCK ), cbee_pf_active( CBEE_PF_SALE ), $others );

	// Values carried by variations (sizes): counted per product with an orderable variation of that value.
	$sizes = $wpdb->prepare(
		"SELECT va.meta_value AS slug, COUNT(DISTINCT v.post_parent) AS n FROM {$wpdb->posts} v
		JOIN {$wpdb->postmeta} va ON va.post_id = v.ID AND va.meta_key = %s
		WHERE v.post_parent IN ({$list}) AND va.meta_value <> '' AND " . implode( ' AND ', $cond ) . ' GROUP BY va.meta_value', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		'attribute_' . $taxonomy
	);
	// Values set on the product itself (fabric), for products whose variations do not carry this attribute.
	$product = $wpdb->prepare(
		"SELECT t.slug AS slug, COUNT(DISTINCT tr.object_id) AS n FROM {$wpdb->term_relationships} tr
		JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
		JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		WHERE tr.object_id IN ({$list}) AND NOT EXISTS (
			SELECT 1 FROM {$wpdb->posts} cv JOIN {$wpdb->postmeta} cm ON cm.post_id = cv.ID AND cm.meta_key = %s AND cm.meta_value <> ''
			WHERE cv.post_parent = tr.object_id AND cv.post_type = 'product_variation')
		GROUP BY t.slug", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$taxonomy,
		'attribute_' . $taxonomy
	);
	$out = array();
	foreach ( array( $sizes, $product ) as $sql ) {
		foreach ( $wpdb->get_results( $sql ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ $row->slug ] = ( isset( $out[ $row->slug ] ) ? $out[ $row->slug ] : 0 ) + (int) $row->n;
		}
	}
	return $out;
}

/** [cbee_attribute_filter attribute="grosse" title="Grösse"]: attribute slug without the pa_ prefix. */
function cbee_pf_render_attribute( $atts ) {
	$atts     = shortcode_atts( array( 'attribute' => '', 'title' => '' ), $atts );
	$slug     = sanitize_title( $atts['attribute'] );
	$taxonomy = 'pa_' . $slug;
	if ( ! function_exists( 'wc_attribute_label' ) || ! taxonomy_exists( $taxonomy ) || ! cbee_pf_is_archive() ) {
		return '';
	}
	cbee_pf_enqueue();

	$counts   = cbee_pf_term_counts( $taxonomy, cbee_pf_context_ids( $taxonomy ) );
	$selected = cbee_pf_selected_attributes();
	$selected = isset( $selected[ $taxonomy ] ) ? $selected[ $taxonomy ] : array();

	$orderby = wc_attribute_orderby( $taxonomy );
	$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'menu_order' === $orderby ? 'menu_order' : 'name' ) );
	if ( is_wp_error( $terms ) ) {
		return '';
	}
	// Sizes such as "62", "62-68", "68": by their numbers, smallest first.
	$numeric = $terms && ! array_filter( $terms, function ( $t ) {
		return ! preg_match( '/^\d/', $t->name );
	} );
	if ( $numeric || 'name_num' === $orderby ) {
		usort( $terms, function ( $a, $b ) {
			preg_match_all( '/\d+/', $a->name, $na );
			preg_match_all( '/\d+/', $b->name, $nb );
			// Element by element: a plain array comparison would look at the length first and put every single size before all ranges.
			foreach ( $na[0] as $i => $x ) {
				if ( ! isset( $nb[0][ $i ] ) ) {
					return 1;
				}
				if ( (int) $x !== (int) $nb[0][ $i ] ) {
					return (int) $x <=> (int) $nb[0][ $i ];
				}
			}
			return count( $na[0] ) <=> count( $nb[0] ) ?: strnatcmp( $a->name, $b->name );
		} );
	}

	$rows         = array();
	$selected_pos = -1;
	foreach ( $terms as $term ) {
		$on = in_array( $term->slug, $selected, true );
		if ( empty( $counts[ $term->slug ] ) && ! $on ) {
			continue;
		}
		$next   = $on ? array_values( array_diff( $selected, array( $term->slug ) ) ) : array_merge( $selected, array( $term->slug ) );
		$url    = cbee_pf_url( array(
			'filter_' . $slug     => $next ? implode( ',', $next ) : null,
			'query_type_' . $slug => $next ? 'or' : null,
		) );
		if ( $on ) {
			$selected_pos = count( $rows );
		}
		$rows[] = cbee_pf_item( $term->name, $url, $on, isset( $counts[ $term->slug ] ) ? $counts[ $term->slug ] : 0 );
	}
	if ( ! $rows ) {
		return '<div class="cbee-pf" hidden></div>'; // keeps the panel count stable for the no-reload swap
	}
	$title    = '' !== $atts['title'] ? $atts['title'] : wc_attribute_label( $taxonomy );
	$collapse = count( $rows ) > CBEE_PF_LIMIT && $selected_pos < CBEE_PF_LIMIT;
	$more     = $collapse ? '<button type="button" class="cbee-pf-more" aria-expanded="false">Mehr anzeigen</button><noscript><style>.cbee-pf-list.is-collapsed .cbee-pf-check{display:flex}.cbee-pf-more{display:none}</style></noscript>' : '';
	return '<div class="cbee-pf"><div class="cbee-pf-title">' . esc_html( $title ) . '</div><div class="cbee-pf-list' . ( $collapse ? ' is-collapsed' : '' ) . '">' . implode( '', $rows ) . '</div>' . $more . '</div>';
}
add_shortcode( 'cbee_attribute_filter', 'cbee_pf_render_attribute' );

/**
 * Sale badge with the discount: "-10%", or "bis zu -20%" when only some variations are on sale
 * or the discount differs per variation. Percentages are rounded up.
 */
add_filter( 'woocommerce_sale_flash', function ( $html, $post, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}
	$percents = array();
	$total    = 1;
	if ( $product->is_type( 'variable' ) ) {
		$prices  = $product->get_variation_prices( true );
		$total   = count( $prices['regular_price'] );
		foreach ( $prices['regular_price'] as $id => $regular ) {
			$sale = $prices['sale_price'][ $id ];
			if ( (float) $regular > 0 && (float) $sale < (float) $regular ) {
				$percents[] = (int) ceil( ( 1 - (float) $sale / (float) $regular ) * 100 );
			}
		}
	} elseif ( (float) $product->get_regular_price() > 0 && '' !== $product->get_sale_price() ) {
		$percents[] = (int) ceil( ( 1 - (float) $product->get_sale_price() / (float) $product->get_regular_price() ) * 100 );
	}
	if ( ! $percents ) {
		return $html;
	}
	$max  = max( $percents );
	$text = ( count( $percents ) < $total || min( $percents ) !== $max ? 'bis zu ' : '' ) . '-' . $max . '%';
	return preg_replace( '/>[^<]+</', '>' . esc_html( $text ) . '<', $html, 1 );
}, 99, 3 ); // late: Blocksy filters the same badge and would overwrite ours
