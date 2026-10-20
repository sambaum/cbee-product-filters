<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_menu_page( 'CBee Product Filters', 'CBee Filters', 'manage_options', 'cbee-product-filters', 'cbee_pf_render_settings', 'dashicons-filter', 58 );
} );

add_filter( 'plugin_action_links_cbee-product-filters/cbee-product-filters.php', function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=cbee-product-filters' ) ) . '">Settings</a>' );
	return $links;
} );

function cbee_pf_render_settings() {
	$opts = get_option( 'cbee_pf_options', array() );
	echo '<div class="wrap"><h1>CBee Product Filters</h1>';
	if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
	}
	echo '<p>The filter bar is one shortcode: <code>[cbee_filters]</code>. Put it above the product list (a Shortcode block in a Blocksy hook element on <code>woocommerce_before_shop_loop</code>), or tick the box below. Filters appear in the order set here; filters without values in the current list are skipped.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cbee_pf_save">';
	wp_nonce_field( 'cbee_pf_save' );
	echo '<p><label><input type="checkbox" name="auto" value="1"' . checked( ! empty( $opts['auto'] ), true, false ) . '> Show the bar automatically above the product list (leave off when the shortcode is placed manually)</label></p>';
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Filter</th><th>Enabled</th><th>Title</th><th>Order</th><th>In bar</th><th>Colours</th></tr></thead><tbody>';
	foreach ( cbee_pf_config() as $f ) {
		$k = esc_attr( $f['key'] );
		printf(
			'<tr><td>%1$s<br><code>%2$s</code></td><td><input type="checkbox" name="f[%3$s][enabled]" value="1"%4$s></td><td><input type="text" name="f[%3$s][title]" value="%5$s" class="regular-text" style="width:160px"></td><td><input type="number" name="f[%3$s][order]" value="%6$d" style="width:70px"></td><td><input type="checkbox" name="f[%3$s][pill]" value="1"%7$s></td><td>%8$s</td></tr>',
			esc_html( $f['label'] ),
			esc_html( 'toggle' === $f['type'] ? $f['key'] : 'pa_' . $f['key'] ),
			$k,
			checked( $f['enabled'], 1, false ),
			esc_attr( $f['title'] ),
			(int) $f['order'],
			checked( $f['pill'], 1, false ),
			'attr' === $f['type'] ? '<input type="checkbox" name="f[' . $k . '][swatch]" value="1"' . checked( $f['swatch'], 1, false ) . '>' : ''
		);
	}
	echo '</tbody></table>';
	echo '<p class="description">Enabled: shown at all. In bar: also as a pill above the products (otherwise only in the full Filter view). Colours: show a colour dot per value; set a colour on each value under Products &rarr; Attributes &rarr; Configure terms, or leave it automatic (derived from the name).</p>';
	submit_button();
	echo '</form>';
	echo '<h2>Deprecated shortcodes</h2><p><code>[cbee_availability_filter]</code>, <code>[cbee_attribute_filter]</code> and <code>[cbee_reset_filter]</code> still work. Administrators see a notice under each one. Remove them from the sidebar once <code>[cbee_filters]</code> is in place.</p></div>';
}

add_action( 'admin_post_cbee_pf_save', function () {
	check_admin_referer( 'cbee_pf_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	$posted = isset( $_POST['f'] ) ? (array) wp_unslash( $_POST['f'] ) : array();
	$saved  = array();
	foreach ( cbee_pf_config() as $f ) {
		$row = isset( $posted[ $f['key'] ] ) && is_array( $posted[ $f['key'] ] ) ? $posted[ $f['key'] ] : array();
		$saved[ $f['key'] ] = array(
			'enabled' => empty( $row['enabled'] ) ? 0 : 1,
			'title'   => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
			'order'   => isset( $row['order'] ) ? (int) $row['order'] : 0,
			'pill'    => empty( $row['pill'] ) ? 0 : 1,
			'swatch'  => empty( $row['swatch'] ) ? 0 : 1,
		);
	}
	update_option( 'cbee_pf_filters', $saved );
	update_option( 'cbee_pf_options', array( 'auto' => empty( $_POST['auto'] ) ? 0 : 1 ) );
	wp_safe_redirect( admin_url( 'admin.php?page=cbee-product-filters&saved=1' ) );
	exit;
} );

// Colour field on the terms of every attribute that shows colour dots.
add_action( 'admin_init', function () {
	foreach ( cbee_pf_config() as $f ) {
		if ( 'attr' !== $f['type'] || ! $f['swatch'] ) {
			continue;
		}
		$tax = 'pa_' . $f['key'];
		add_action( $tax . '_add_form_fields', function () {
			echo '<div class="form-field"><label for="cbee-pf-color">Filter colour</label>';
			cbee_pf_color_field( null );
			echo '</div>';
		} );
		add_action( $tax . '_edit_form_fields', function ( $term ) {
			echo '<tr class="form-field"><th scope="row"><label for="cbee-pf-color">Filter colour</label></th><td>';
			cbee_pf_color_field( $term );
			echo '</td></tr>';
		} );
		foreach ( array( 'created_', 'edited_' ) as $prefix ) {
			add_action( $prefix . $tax, function ( $term_id ) {
				if ( ! current_user_can( 'manage_product_terms' ) || ! isset( $_POST['cbee_pf_color_set'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					return;
				}
				$color = ! empty( $_POST['cbee_pf_auto'] ) ? '' : sanitize_hex_color( wp_unslash( $_POST['cbee_pf_color'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( $color ) {
					update_term_meta( $term_id, 'cbee_pf_color', $color );
				} else {
					delete_term_meta( $term_id, 'cbee_pf_color' );
				}
			} );
		}
	}
} );

function cbee_pf_color_field( $term ) {
	$set   = $term ? sanitize_hex_color( (string) get_term_meta( $term->term_id, 'cbee_pf_color', true ) ) : '';
	$auto  = $term ? cbee_pf_term_background( (object) array( 'term_id' => 0, 'name' => $term->name ) ) : '';
	echo '<input type="hidden" name="cbee_pf_color_set" value="1">';
	echo '<input type="color" id="cbee-pf-color" name="cbee_pf_color" value="' . esc_attr( $set ? $set : '#cccccc' ) . '"> ';
	echo '<label><input type="checkbox" name="cbee_pf_auto" value="1"' . checked( '' === $set, true, false ) . '> Automatic';
	if ( $auto ) {
		echo ' <span style="display:inline-block;vertical-align:middle;width:16px;height:16px;border-radius:50%;border:1px solid #888;background:' . esc_attr( $auto ) . '"></span>';
	}
	echo '</label><p class="description">Colour dot in the filter. Automatic derives it from the name (Blau, Grün, Rosa/Lila ...).</p>';
}
