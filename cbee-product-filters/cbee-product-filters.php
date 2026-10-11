<?php
/**
 * Plugin Name: CBee Product Filters
 * Description: Lean product filters for WooCommerce: "Sofort verfügbar" and "Sale", evaluated per variation in one SQL query.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cbee-product-filters
 */

defined( 'ABSPATH' ) || exit;

const CBEE_PF_VERSION = '0.1.0';

// Query parameter names. sofort_verfuegbar is the name the old snippet used, so existing links keep working.
const CBEE_PF_STOCK = 'sofort_verfuegbar';
const CBEE_PF_SALE  = 'sale';

function cbee_pf_active( $key ) {
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

add_action( 'woocommerce_product_query', function ( $q ) {
	if ( cbee_pf_active( CBEE_PF_STOCK ) || cbee_pf_active( CBEE_PF_SALE ) ) {
		$q->set( 'cbee_pf', 1 );
	}
} );

add_filter( 'posts_clauses', function ( $clauses, $q ) {
	if ( ! $q->get( 'cbee_pf' ) ) {
		return $clauses;
	}
	$clauses['where'] .= ' AND ' . cbee_pf_sql( cbee_pf_active( CBEE_PF_STOCK ), cbee_pf_active( CBEE_PF_SALE ), cbee_pf_selected_attributes() );
	return $clauses;
}, 10, 2 );

/**
 * WHERE fragment on the products table. A variable product matches when ONE variation meets every
 * condition (stock, sale, selected sizes), so "sale + sofort" means the same variation is both.
 * Simple products match on their own stock / price; size filters do not apply to them.
 */
function cbee_pf_sql( $stock, $sale, $attributes ) {
	global $wpdb;
	$p = $wpdb->posts;

	$meta = function ( $alias, $post, $key, $cond ) use ( $wpdb ) {
		return $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$wpdb->postmeta} {$alias} WHERE {$alias}.post_id = {$post} AND {$alias}.meta_key = %s AND {$cond})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$key
		);
	};

	// An active sale shows up as _price < _regular_price (WooCommerce updates _price when a scheduled sale starts or ends).
	$on_sale = function ( $post ) use ( $wpdb ) {
		return "EXISTS (SELECT 1 FROM {$wpdb->postmeta} pr JOIN {$wpdb->postmeta} rg ON rg.post_id = pr.post_id AND rg.meta_key = '_regular_price'
			WHERE pr.post_id = {$post} AND pr.meta_key = '_price' AND pr.meta_value <> '' AND rg.meta_value <> ''
			AND CAST(pr.meta_value AS DECIMAL(12,4)) < CAST(rg.meta_value AS DECIMAL(12,4)))";
	};

	$var = array( "v.post_parent = {$p}.ID", "v.post_type = 'product_variation'", "v.post_status = 'publish'" );
	if ( $stock ) {
		$var[] = $meta( 'vs', 'v.ID', '_stock', 'CAST(vs.meta_value AS DECIMAL(12,2)) > 0' );
	}
	if ( $sale ) {
		$var[] = $on_sale( 'v.ID' );
	}
	foreach ( $attributes as $taxonomy => $slugs ) {
		$in    = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
		$var[] = $wpdb->prepare(
			"EXISTS (SELECT 1 FROM {$wpdb->postmeta} va WHERE va.post_id = v.ID AND va.meta_key = %s AND (va.meta_value = '' OR va.meta_value IN ({$in})))", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			array_merge( array( 'attribute_' . $taxonomy ), $slugs )
		);
	}
	$variable = "EXISTS (SELECT 1 FROM {$p} v WHERE " . implode( ' AND ', $var ) . ')';

	$simple = array( "NOT EXISTS (SELECT 1 FROM {$p} sv WHERE sv.post_parent = {$p}.ID AND sv.post_type = 'product_variation')" );
	if ( $stock ) {
		$simple[] = $meta( 'ms', "{$p}.ID", '_manage_stock', "ms.meta_value = 'yes'" );
		$simple[] = $meta( 'ss', "{$p}.ID", '_stock', 'CAST(ss.meta_value AS DECIMAL(12,2)) > 0' );
	}
	if ( $sale ) {
		$simple[] = $on_sale( "{$p}.ID" );
	}

	return '(' . $variable . ' OR (' . implode( ' AND ', $simple ) . '))';
}

/**
 * Filtered pages depend on stock, which changes without any purge. A short TTL keeps them fresh enough
 * and still cached. Plain archive pages are left to the cache plugin's normal rules.
 */
add_action( 'template_redirect', function () {
	if ( headers_sent() || ! ( cbee_pf_active( CBEE_PF_STOCK ) || cbee_pf_active( CBEE_PF_SALE ) ) ) {
		return;
	}
	$ttl = (int) apply_filters( 'cbee_pf_cache_ttl', 300 );
	header( 'X-LiteSpeed-Cache-Control: ' . ( $ttl > 0 ? 'public,max-age=' . $ttl : 'no-cache' ) );
} );

/** Current page URL without pagination, with $key toggled on or off. */
function cbee_pf_toggle_url( $key ) {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = preg_replace( '#/page/\d+/?#', '/', strtok( $uri, '?' ) );
	$args = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
	unset( $args['paged'], $args['product-page'] );
	if ( cbee_pf_active( $key ) ) {
		unset( $args[ $key ] );
	} else {
		$args[ $key ] = '1';
	}
	return esc_url( add_query_arg( array_map( 'wp_unslash', $args ), home_url( $path ) ) );
}

function cbee_pf_render() {
	wp_enqueue_style( 'cbee-pf', plugins_url( 'assets/filters.css', __FILE__ ), array(), CBEE_PF_VERSION );

	$items = array(
		CBEE_PF_STOCK => 'Sofort verfügbar',
		CBEE_PF_SALE  => 'Sale',
	);
	$html  = '<div class="cbee-pf"><div class="cbee-pf-title">Verfügbarkeit</div>';
	foreach ( $items as $key => $label ) {
		$on    = cbee_pf_active( $key );
		$html .= sprintf(
			'<a class="cbee-pf-check%s" href="%s" rel="nofollow" role="checkbox" aria-checked="%s"><span class="cbee-pf-box"></span>%s</a>',
			$on ? ' is-on' : '',
			cbee_pf_toggle_url( $key ),
			$on ? 'true' : 'false',
			esc_html( $label )
		);
	}
	return $html . '</div>';
}

// The old shortcode name stays an alias so existing sidebar widgets keep working.
add_shortcode( 'cbee_product_filters', 'cbee_pf_render' );
add_shortcode( 'cbee_sofort_verfuegbar', 'cbee_pf_render' );
