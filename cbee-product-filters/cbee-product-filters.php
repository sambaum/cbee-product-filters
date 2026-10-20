<?php
/**
 * Plugin Name: CBee Product Filters
 * Description: Lean product filters for WooCommerce: a responsive pill bar with "Sofort verfügbar", "Sale" and stock-aware attribute filters, evaluated per variation in SQL.
 * Version: 0.8.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cbee-product-filters
 */

defined( 'ABSPATH' ) || exit;

const CBEE_PF_VERSION = '0.8.0';

// Query parameter names. The old snippet's name (sofort_verfuegbar) is still accepted, but never written: that snippet
// hooks the same parameter and loads the whole catalogue whenever it sees it, which made every click take seconds.
const CBEE_PF_STOCK  = 'sofort';
const CBEE_PF_LEGACY = 'sofort_verfuegbar';
const CBEE_PF_SALE  = 'sale';

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

/**
 * A card link carries the chosen size to the product page, so it is preselected there. Only when exactly one of the
 * selected values exists on that product: with two or more matches it would be a guess.
 */
add_filter( 'woocommerce_loop_product_link', function ( $link, $product ) {
	if ( ! $product || ! $product->is_type( 'variable' ) || ! cbee_pf_is_archive() ) {
		return $link;
	}
	$selected = cbee_pf_selected_variation_attributes();
	if ( ! $selected ) {
		return $link;
	}
	$offered = $product->get_variation_attributes();
	$args    = array();
	foreach ( $selected as $taxonomy => $slugs ) {
		$match = isset( $offered[ $taxonomy ] ) ? array_values( array_intersect( $slugs, $offered[ $taxonomy ] ) ) : array();
		if ( 1 === count( $match ) ) {
			$args[ 'attribute_' . $taxonomy ] = $match[0];
		}
	}
	return $args ? add_query_arg( $args, $link ) : $link;
}, 10, 2 );

/** Filters only make sense where a product list is shown. */
function cbee_pf_is_archive() {
	return function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() );
}

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

/**
 * Terms of one attribute for the current list, sorted, with counts and toggle URLs. Terms without products in the
 * current list are left out unless selected. Null when the attribute does not apply here.
 */
function cbee_pf_attribute_terms( $slug ) {
	$taxonomy = 'pa_' . $slug;
	if ( ! function_exists( 'wc_attribute_label' ) || ! taxonomy_exists( $taxonomy ) || ! cbee_pf_is_archive() ) {
		return null;
	}
	$counts   = cbee_pf_term_counts( $taxonomy, cbee_pf_context_ids( $taxonomy ) );
	$selected = cbee_pf_selected_attributes();
	$selected = isset( $selected[ $taxonomy ] ) ? $selected[ $taxonomy ] : array();

	$orderby = wc_attribute_orderby( $taxonomy );
	$terms   = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'menu_order' === $orderby ? 'menu_order' : 'name' ) );
	if ( is_wp_error( $terms ) ) {
		return null;
	}
	if ( 'name_num' === $orderby ) {
		usort( $terms, function ( $a, $b ) {
			return strnatcmp( $a->name, $b->name );
		} );
	}
	// Sizes: single sizes ("56") first, then ranges ("56-62"), each smallest first, whatever the attribute's own order says.
	$range_start = null;
	if ( in_array( $slug, apply_filters( 'cbee_pf_size_attributes', array( 'grosse' ) ), true ) ) {
		$single = $range = $other = array();
		foreach ( $terms as $t ) {
			if ( preg_match( '/^\d+$/', $t->name ) ) {
				$single[] = $t;
			} elseif ( preg_match( '/^\d+\s*[-–]\s*\d+$/u', $t->name ) ) {
				$range[] = $t;
			} else {
				$other[] = $t;
			}
		}
		$by_name = function ( $x, $y ) {
			return strnatcmp( $x->name, $y->name );
		};
		usort( $single, $by_name );
		usort( $range, $by_name );
		$range_start = $range && $single ? $range[0]->term_id : null;
		$terms       = array_merge( $single, $range, $other );
	}

	$out = array();
	foreach ( $terms as $term ) {
		$on = in_array( $term->slug, $selected, true );
		if ( empty( $counts[ $term->slug ] ) && ! $on ) {
			continue;
		}
		$next  = $on ? array_values( array_diff( $selected, array( $term->slug ) ) ) : array_merge( $selected, array( $term->slug ) );
		$out[] = array(
			'term'  => $term,
			'sep'   => $term->term_id === $range_start,
			'name'  => $term->name,
			'count' => isset( $counts[ $term->slug ] ) ? $counts[ $term->slug ] : 0,
			'on'    => $on,
			'url'   => cbee_pf_url( array(
				'filter_' . $slug     => $next ? implode( ',', $next ) : null,
				'query_type_' . $slug => $next ? 'or' : null,
			) ),
		);
	}
	return array( 'label' => wc_attribute_label( $taxonomy ), 'terms' => $out );
}

/* ---- Filter bar: [cbee_filters] ---- */

/** Order, titles and on/off state a filter has until the settings page says otherwise. */
function cbee_pf_defaults() {
	return array(
		'sofort'             => array( 'title' => 'Sofort verfügbar', 'enabled' => 1 ),
		'sale'               => array( 'title' => 'Sale', 'enabled' => 1 ),
		'grosse'             => array( 'title' => 'Grösse', 'enabled' => 1 ),
		'kopfumfang-groesse' => array( 'title' => 'Kopfumfang', 'enabled' => 1 ),
		'schal-groesse'      => array( 'title' => 'Schal', 'enabled' => 1 ),
		'farbe'              => array( 'title' => 'Farbe', 'enabled' => 0, 'swatch' => 1 ),
		'stoffart'           => array( 'title' => 'Stoffart', 'enabled' => 1 ),
		'geschlecht'         => array( 'title' => 'Geschlecht', 'enabled' => 0 ),
		'motiv'              => array( 'title' => 'Motiv', 'enabled' => 0 ),
	);
}

/** The two toggles plus every WooCommerce attribute, saved settings over defaults, sorted by order. */
function cbee_pf_config() {
	static $config;
	if ( null !== $config ) {
		return $config;
	}
	$defaults = cbee_pf_defaults();
	$saved    = get_option( 'cbee_pf_filters', array() );
	$labels   = array( CBEE_PF_STOCK => 'Sofort verfügbar', CBEE_PF_SALE => 'Sale' );
	$types    = array( CBEE_PF_STOCK => 'toggle', CBEE_PF_SALE => 'toggle' );
	foreach ( function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array() as $attr ) {
		$labels[ $attr->attribute_name ] = $attr->attribute_label;
		$types[ $attr->attribute_name ]  = 'attr';
	}
	$known = array_flip( array_keys( $defaults ) );
	$items = array();
	foreach ( $labels as $key => $label ) {
		$d  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : array();
		$sv = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
		$items[] = array(
			'key'     => $key,
			'type'    => $types[ $key ],
			'label'   => $label,
			'title'   => isset( $sv['title'] ) && '' !== $sv['title'] ? $sv['title'] : ( isset( $d['title'] ) ? $d['title'] : $label ),
			'enabled' => (int) ( isset( $sv['enabled'] ) ? $sv['enabled'] : ( isset( $d['enabled'] ) ? $d['enabled'] : 0 ) ),
			'pill'    => (int) ( isset( $sv['pill'] ) ? $sv['pill'] : 1 ),
			'swatch'  => (int) ( isset( $sv['swatch'] ) ? $sv['swatch'] : ( isset( $d['swatch'] ) ? $d['swatch'] : 0 ) ),
			'order'   => (int) ( isset( $sv['order'] ) ? $sv['order'] : ( ( isset( $known[ $key ] ) ? $known[ $key ] : count( $known ) + count( $items ) ) * 10 ) ),
		);
	}
	usort( $items, function ( $a, $b ) {
		return $a['order'] <=> $b['order'];
	} );
	$config = $items;
	return $config;
}

/** Colour names in the terms ("Blau/Türkis"): longer names first, each match is removed so "hellgrau" is not also "grau". */
function cbee_pf_color_table() {
	return array(
		'hellgrau' => '#d4d4d4', 'dunkelgrau' => '#5a5a5a', 'hellblau' => '#9cc9ee', 'dunkelblau' => '#1f3a6b', 'marine' => '#1f3a6b',
		'dunkelgrün' => '#2f6b3e', 'dunkelgruen' => '#2f6b3e', 'hellgrün' => '#a8d8a0', 'hellgruen' => '#a8d8a0',
		'türkis' => '#2fb3b3', 'tuerkis' => '#2fb3b3', 'petrol' => '#1f6b78', 'jeans' => '#4a6f9c', 'bordeaux' => '#7a1f3d',
		'weiss' => '#f4f4f4', 'weiß' => '#f4f4f4', 'schwarz' => '#222222', 'grau' => '#9a9a9a', 'beige' => '#d8c3a5', 'creme' => '#efe6d0',
		'braun' => '#8a5a3c', 'gelb' => '#f2c230', 'senf' => '#d4a72c', 'orange' => '#f08a24', 'rot' => '#d9342b', 'rosa' => '#f2a6c4',
		'pink' => '#e8559b', 'lila' => '#8e5bb5', 'violett' => '#7b4fa3', 'mint' => '#a8dcc0', 'olive' => '#7a8040', 'grün' => '#4f9d5d',
		'gruen' => '#4f9d5d', 'blau' => '#2f7fd0', 'gold' => '#c9a227', 'silber' => '#bfc3c7',
	);
}

/** CSS background of a colour swatch: the colour set on the term, else derived from its name (several names give a split). */
function cbee_pf_term_background( $term ) {
	$set = sanitize_hex_color( (string) get_term_meta( $term->term_id, 'cbee_pf_color', true ) );
	if ( $set ) {
		return $set;
	}
	$name   = strtolower( $term->name );
	$colors = array();
	foreach ( cbee_pf_color_table() as $word => $hex ) {
		if ( false !== strpos( $name, $word ) ) {
			$colors[ strpos( $name, $word ) ] = $hex;
			$name = str_replace( $word, str_repeat( ' ', strlen( $word ) ), $name );
		}
	}
	ksort( $colors );
	$colors = array_values( $colors );
	if ( ! $colors ) {
		return '#cccccc';
	}
	if ( 1 === count( $colors ) ) {
		return $colors[0];
	}
	$stops = array();
	$step  = 100 / count( $colors );
	foreach ( $colors as $i => $hex ) {
		$stops[] = $hex . ' ' . round( $i * $step, 2 ) . '% ' . round( ( $i + 1 ) * $step, 2 ) . '%';
	}
	return 'linear-gradient(135deg,' . implode( ',', $stops ) . ')';
}

function cbee_pf_option( $t, $swatch ) {
	return sprintf(
		'<a class="pfb-opt%s%s" href="%s" rel="nofollow" role="checkbox" aria-checked="%s" data-term="%s">%s<span>%s</span><small>%d</small></a>',
		$t['on'] ? ' is-on' : '',
		$t['sep'] ? ' pfb-sep' : '',
		$t['url'],
		$t['on'] ? 'true' : 'false',
		esc_attr( $t['term']->slug ),
		$swatch ? '<span class="pfb-sw" style="background:' . esc_attr( cbee_pf_term_background( $t['term'] ) ) . '"></span>' : '',
		esc_html( $t['name'] ),
		(int) $t['count']
	);
}

/** URL without any filter parameter. */
function cbee_pf_clear_url() {
	$clear = array( CBEE_PF_STOCK => null, CBEE_PF_LEGACY => null, CBEE_PF_SALE => null );
	foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
			$clear[ $key ] = null;
		}
	}
	return cbee_pf_url( $clear );
}

/** WooCommerce's own sorting choices (so renamed or disabled options carry over): key => label, plus the current key. */
function cbee_pf_sorting() {
	$default = apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) );
	$sorts   = apply_filters( 'woocommerce_catalog_orderby', array(
		'menu_order' => __( 'Default sorting', 'woocommerce' ),
		'popularity' => __( 'Sort by popularity', 'woocommerce' ),
		'rating'     => __( 'Sort by average rating', 'woocommerce' ),
		'date'       => __( 'Sort by latest', 'woocommerce' ),
		'price'      => __( 'Sort by price: low to high', 'woocommerce' ),
		'price-desc' => __( 'Sort by price: high to low', 'woocommerce' ),
	) );
	if ( function_exists( 'wc_review_ratings_enabled' ) && ! wc_review_ratings_enabled() ) {
		unset( $sorts['rating'] );
	}
	$current = isset( $_GET['orderby'] ) ? wc_clean( wp_unslash( $_GET['orderby'] ) ) : $default; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! isset( $sorts[ $current ] ) ) {
		$current = $default;
	}
	return array( $sorts, $current, $default );
}

/** [cbee_filters]: the pill bar with a full Filter view. Printed once per page. */
function cbee_pf_render_bar() {
	static $printed = false;
	if ( $printed || ! cbee_pf_is_archive() ) {
		return '';
	}
	$printed = true;
	cbee_pf_enqueue();

	$groups = array();
	foreach ( cbee_pf_config() as $f ) {
		if ( ! $f['enabled'] ) {
			continue;
		}
		if ( 'toggle' === $f['type'] ) {
			$on       = cbee_pf_active( $f['key'] );
			$groups[] = array(
				'key' => $f['key'], 'title' => $f['title'], 'pill' => $f['pill'], 'toggle' => true, 'on' => $on,
				'url' => cbee_pf_url( array( $f['key'] => $on ? null : '1', CBEE_PF_LEGACY => null ) ),
			);
			continue;
		}
		$data = cbee_pf_attribute_terms( $f['key'] );
		if ( ! $data || ! $data['terms'] ) {
			continue;
		}
		$selected = array();
		$opts     = '';
		foreach ( $data['terms'] as $t ) {
			if ( $t['on'] ) {
				$selected[] = $t['name'];
			}
			$opts .= cbee_pf_option( $t, $f['swatch'] );
		}
		$groups[] = array(
			'key' => $f['key'], 'title' => $f['title'], 'pill' => $f['pill'], 'toggle' => false, 'on' => (bool) $selected,
			'selected' => $selected, 'opts' => $opts,
			'clear' => cbee_pf_url( array( 'filter_' . $f['key'] => null, 'query_type_' . $f['key'] => null ) ),
		);
	}
	if ( ! $groups ) {
		return '<div class="cbee-pfb" hidden></div>'; // keeps the panel count stable for the no-reload swap
	}

	$active = count( array_filter( wp_list_pluck( $groups, 'on' ) ) );
	$pills  = array_values( array_filter( $groups, function ( $g ) {
		return $g['pill'];
	} ) );
	$pills = array_merge(
		array_filter( $pills, function ( $g ) {
			return $g['on'];
		} ),
		array_filter( $pills, function ( $g ) {
			return ! $g['on'];
		} )
	);

	$icon = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true"><path d="M2 4h7M13 4h1M2 12h1M7 12h7"/><circle cx="11" cy="4" r="1.8"/><circle cx="5" cy="12" r="1.8"/></svg>';
	$caret = '<svg class="pfb-car" width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 3.5l3 3 3-3"/></svg>';

	$html = '<div class="cbee-pfb"><div class="pfb-row">';
	$html .= '<span class="pfb-pill pfb-fb"><a class="pfb-pl" href="#cbee-pf-full" data-full>' . $icon . 'Filter' . ( $active ? '<span class="pfb-bd">' . $active . '</span>' : '' ) . '</a></span>';
	foreach ( $pills as $g ) {
		if ( $g['toggle'] ) {
			$html .= '<span class="pfb-pill' . ( $g['on'] ? ' is-on has-x' : '' ) . '"><a class="pfb-pl" href="' . $g['url'] . '" rel="nofollow" aria-pressed="' . ( $g['on'] ? 'true' : 'false' ) . '"><span class="pfb-t">' . esc_html( $g['title'] ) . '</span></a>'
				. ( $g['on'] ? '<a class="pfb-x" href="' . $g['url'] . '" rel="nofollow" aria-label="' . esc_attr( $g['title'] ) . ' zurücksetzen">×</a>' : '' ) . '</span>';
			continue;
		}
		$label = $g['on'] ? $g['title'] . ': ' . $g['selected'][0] . ( count( $g['selected'] ) > 1 ? ' +' . ( count( $g['selected'] ) - 1 ) : '' ) : $g['title'];
		$html .= '<span class="pfb-pill' . ( $g['on'] ? ' is-on has-x' : '' ) . '"><a class="pfb-pl" href="#cbee-pf-full" data-group="' . esc_attr( $g['key'] ) . '" aria-haspopup="dialog"><span class="pfb-t">' . esc_html( $label ) . '</span>' . ( $g['on'] ? '' : $caret ) . '</a>'
			. ( $g['on'] ? '<a class="pfb-x" href="' . $g['clear'] . '" rel="nofollow" data-for="' . esc_attr( $g['key'] ) . '" aria-label="' . esc_attr( $g['title'] ) . ' zurücksetzen">×</a>' : '' ) . '</span>';
	}
	list( $sorts, $sort_now, $sort_default ) = cbee_pf_sorting();
	if ( count( $sorts ) > 1 ) {
		$html .= '<span class="pfb-spacer"></span><span class="pfb-pill pfb-sort"><a class="pfb-pl" href="#cbee-pf-full" data-group="sort" aria-haspopup="dialog"><span class="pfb-t">' . esc_html( $sorts[ $sort_now ] ) . '</span>' . $caret . '</a></span>';
	}
	$html .= '</div>';

	$total = (int) $GLOBALS['wp_query']->found_posts;
	$body  = '';
	foreach ( $groups as $g ) {
		if ( $g['toggle'] ) {
			$body .= '<a class="pfb-tg" href="' . $g['url'] . '" rel="nofollow" data-param="' . esc_attr( $g['key'] ) . '" role="switch" aria-checked="' . ( $g['on'] ? 'true' : 'false' ) . '">' . esc_html( $g['title'] ) . '<span class="pfb-sw2"></span></a>';
		}
	}
	foreach ( $groups as $g ) {
		if ( ! $g['toggle'] ) {
			$body .= '<details class="pfb-acc" data-group="' . esc_attr( $g['key'] ) . '" data-title="' . esc_attr( $g['title'] ) . '"' . ( $g['on'] ? ' open' : '' ) . '><summary><span>' . esc_html( $g['title'] ) . '</span><i>' . esc_html( implode( ', ', $g['selected'] ?? array() ) ) . '</i></summary><div class="pfb-opts" data-attr="' . esc_attr( $g['key'] ) . '">' . $g['opts'] . '</div></details>';
		}
	}
	if ( count( $sorts ) > 1 ) {
		$sort_opts = '';
		foreach ( $sorts as $key => $label ) {
			$sort_opts .= '<a class="pfb-opt' . ( $key === $sort_now ? ' is-on' : '' ) . '" href="' . cbee_pf_url( array( 'orderby' => $key === $sort_default ? null : $key ) ) . '" rel="nofollow" role="radio" aria-checked="' . ( $key === $sort_now ? 'true' : 'false' ) . '"><span>' . esc_html( $label ) . '</span></a>';
		}
		$body .= '<details class="pfb-acc" data-group="sort" data-title="Sortieren"><summary><span>Sortieren</span><i>' . esc_html( $sorts[ $sort_now ] ) . '</i></summary><div class="pfb-opts">' . $sort_opts . '</div></details>';
	}
	$html .= '<div class="pfb-full" id="cbee-pf-full" role="dialog" aria-label="Alle Filter"><div class="pfb-ph"><span>Alle Filter</span><a class="pfb-close" href="#" data-close aria-label="Schliessen">×</a></div><div class="pfb-pb">' . $body . '</div><div class="pfb-pf">'
		. ( $active ? '<a class="pfb-reset" href="' . cbee_pf_clear_url() . '" rel="nofollow">Zurücksetzen</a>' : '' )
		. '<a class="pfb-show" href="#" data-close data-label="' . ( 1 === $total ? '1 Ergebnis anzeigen' : $total . ' Ergebnisse anzeigen' ) . '">' . ( 1 === $total ? '1 Ergebnis anzeigen' : $total . ' Ergebnisse anzeigen' ) . '</a></div></div>';
	return $html . '</div>';
}
add_shortcode( 'cbee_filters', 'cbee_pf_render_bar' );

// Optional automatic placement above the product list, for themes where a shortcode has no good place.
add_action( 'woocommerce_before_shop_loop', function () {
	$opts = get_option( 'cbee_pf_options', array() );
	if ( ! empty( $opts['auto'] ) ) {
		echo cbee_pf_render_bar(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 40 );

if ( is_admin() ) {
	require __DIR__ . '/includes/admin.php';
}
