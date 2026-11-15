<?php
/**
 * "Rival prices" box on the product edit screen.
 *
 * @package RivalPriceWatch
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product meta box.
 */
class RPW_Metabox {

	const MAX_LINKS = 10;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register the box.
	 */
	public static function add() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		add_meta_box( 'rpw-links', __( 'Rival prices', 'rival-price-watch' ), array( __CLASS__, 'render' ), 'product', 'normal', 'default' );
	}

	/**
	 * Box content.
	 *
	 * @param WP_Post $post Product post.
	 */
	public static function render( $post ) {
		$links = RPW_Settings::links( $post->ID );
		wp_nonce_field( 'rpw_save_links', 'rpw_nonce' );
		?>
		<p class="description"><?php esc_html_e( 'Paste the link of this same product on competitor sites. The price is read from the page\'s product data (the same data Google uses), so most shops work without any setup.', 'rival-price-watch' ); ?></p>

		<table class="rpw-links widefat">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Competitor product link', 'rival-price-watch' ); ?></th>
					<th><?php esc_html_e( 'Name (optional)', 'rival-price-watch' ); ?></th>
					<th title="<?php esc_attr_e( 'Only needed when the competitor page lists several versions of the product. Type the SKU or barcode of the right one, or words that describe it, for example: 256GB black. Leave empty to use the first product on the page.', 'rival-price-watch' ); ?>"><?php esc_html_e( 'Variant (optional)', 'rival-price-watch' ); ?> <span class="dashicons dashicons-editor-help"></span></th>
					<th title="<?php esc_attr_e( 'Which currency this site sells in. Auto-detect reads it from the page; if the page does not say, the link is not compared until you choose one here.', 'rival-price-watch' ); ?>"><?php esc_html_e( 'Currency', 'rival-price-watch' ); ?> <span class="dashicons dashicons-editor-help"></span></th>
					<th title="<?php esc_attr_e( 'The competitor price is multiplied by this number before comparing, for example 1.1 if the site shows prices without a 10% tax. Leave it at 1 in most cases.', 'rival-price-watch' ); ?>"><?php esc_html_e( 'Price × ', 'rival-price-watch' ); ?> <span class="dashicons dashicons-editor-help"></span></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				$rows = $links ? $links : array( self::blank() );
				foreach ( $rows as $l ) {
					self::row( $l );
				}
				?>
			</tbody>
		</table>
		<template id="rpw-row-template"><?php self::row( self::blank() ); ?></template>
		<div class="rpw-preview" hidden aria-live="polite"></div>
		<p>
			<button type="button" class="button rpw-add"><?php esc_html_e( '+ Add competitor', 'rival-price-watch' ); ?></button>
			<span class="rpw-muted"><?php esc_html_e( 'Use "Test" to see what is read from a link before you save.', 'rival-price-watch' ); ?></span>
		</p>

		<?php if ( $links ) : ?>
			<div class="rpw-latest">
				<h4><?php esc_html_e( 'Prices by market', 'rival-price-watch' ); ?></h4>
				<p class="description"><?php esc_html_e( 'Rivals are grouped by the currency they sell in and compared in that currency. Set your own price for each foreign market; without it, your shop price is converted with your exchange rates (≈).', 'rival-price-watch' ); ?></p>
				<?php foreach ( RPW_Checker::markets( $post->ID ) as $cur => $m ) : ?>
					<?php self::market_section( $cur, $m ); ?>
				<?php endforeach; ?>
				<?php self::history_chart( $post->ID, $links ); ?>
				<p><a class="button" href="<?php echo esc_url( RPW_Admin::check_url( $post->ID ) ); ?>"><?php esc_html_e( 'Check now', 'rival-price-watch' ); ?></a>
				<span class="rpw-muted"><?php esc_html_e( 'Save the product first if you changed the links.', 'rival-price-watch' ); ?></span></p>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * One market (currency) section: your price in that currency, each rival's own price, the difference.
	 *
	 * @param string $cur Currency code.
	 * @param array  $m   Market data from RPW_Checker::markets().
	 */
	private static function market_section( $cur, array $m ) {
		$shop = RPW_Currency::shop();
		?>
		<div class="rpw-market">
			<div class="rpw-market-head">
				<strong><?php echo esc_html( $cur . ' · ' . RPW_Currency::name( $cur ) ); ?></strong>
				<span class="rpw-market-own">
					<?php if ( $cur === $shop ) : ?>
						<?php esc_html_e( 'Your price:', 'rival-price-watch' ); ?>
						<?php echo $m['own'] ? wp_kses_post( RPW_Checker::money( $m['own'], $cur ) ) : '&mdash;'; ?>
						<span class="rpw-muted">(<?php esc_html_e( 'shop price', 'rival-price-watch' ); ?>)</span>
					<?php else : ?>
						<label>
							<?php
							/* translators: %s: currency code. */
							echo esc_html( sprintf( __( 'Your price in %s:', 'rival-price-watch' ), $cur ) );
							?>
							<input type="text" inputmode="decimal" class="small-text rpw-market-input" name="rpw_market_price[<?php echo esc_attr( $cur ); ?>]"
								value="<?php echo 'set' === $m['own_source'] ? esc_attr( RPW_Currency::format_rate( $m['own'] ) ) : ''; ?>"
								placeholder="<?php echo 'converted' === $m['own_source'] ? esc_attr( '≈ ' . number_format_i18n( $m['own'], 2 ) ) : ''; ?>">
						</label>
						<?php if ( 'converted' === $m['own_source'] ) : ?>
							<span class="rpw-muted"><?php esc_html_e( '(empty: converted from your shop price)', 'rival-price-watch' ); ?></span>
						<?php elseif ( 'none' === $m['own_source'] ) : ?>
							<span class="rpw-muted"><?php esc_html_e( '(set a price or an exchange rate to compare)', 'rival-price-watch' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</span>
			</div>
			<ul>
				<?php foreach ( $m['rows'] as $r ) : ?>
					<?php $last = $r['last']; ?>
					<li>
						<a href="<?php echo esc_url( $r['link']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( RPW_Settings::link_name( $r['link'] ) ); ?></a>:
						<?php
						if ( ! $last ) {
							echo '<span class="rpw-muted">' . esc_html__( 'not checked yet', 'rival-price-watch' ) . '</span>';
						} elseif ( in_array( $last->status, array( 'ok', 'norate' ), true ) && ( null !== $last->raw_price || null !== $last->price ) ) {
							$native = null !== $last->raw_price ? (float) $last->raw_price : (float) $last->price;
							echo '<span title="' . esc_attr( 'ok' === $last->status ? $last->message : '' ) . '">' . wp_kses_post( RPW_Checker::money( $native, $cur ) ) . '</span>';
							if ( '0' === (string) $last->in_stock ) {
								echo ' <span class="rpw-muted">(' . esc_html__( 'out of stock', 'rival-price-watch' ) . ')</span>';
							}
						} else {
							echo '<span class="rpw-error" title="' . esc_attr( $last->message ) . '">' . esc_html( RPW_Admin::status_label( $last->status ) ) . '</span>';
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				<?php echo ( 'converted' === $m['own_source'] && null !== $m['diff'] ? '<span class="rpw-muted">≈</span> ' : '' ) . RPW_Admin::diff_badge( $m['diff'], $m['gap'], $cur ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
				<?php if ( $m['checked'] ) : ?>
					<span class="rpw-muted"><?php echo esc_html( RPW_Admin::ago( $m['checked'] ) ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Empty link.
	 *
	 * @return array
	 */
	private static function blank() {
		return array(
			'url'      => '',
			'label'    => '',
			'factor'   => 1,
			'currency' => '',
			'match'    => '',
		);
	}

	/**
	 * One input row.
	 *
	 * @param array $l Link.
	 */
	private static function row( array $l ) {
		$cur = isset( $l['currency'] ) ? $l['currency'] : '';
		?>
		<tr>
			<td><input type="url" class="widefat" name="rpw_url[]" value="<?php echo esc_attr( $l['url'] ); ?>" placeholder="https://"></td>
			<td><input type="text" class="widefat" name="rpw_label[]" value="<?php echo esc_attr( $l['label'] ); ?>"></td>
			<td><input type="text" class="widefat" name="rpw_match[]" maxlength="100" value="<?php echo esc_attr( isset( $l['match'] ) ? $l['match'] : '' ); ?>"></td>
			<td>
				<select name="rpw_currency[]">
					<option value=""><?php esc_html_e( 'Auto-detect', 'rival-price-watch' ); ?></option>
					<?php foreach ( array_keys( RPW_Currency::codes() ) as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $cur, $code ); ?>><?php echo esc_html( $code . ' · ' . RPW_Currency::name( $code ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="number" class="small-text" step="any" min="0" name="rpw_factor[]" value="<?php echo esc_attr( (string) $l['factor'] ); ?>"></td>
			<td class="rpw-row-actions"><button type="button" class="button button-small rpw-test"><?php esc_html_e( 'Test', 'rival-price-watch' ); ?></button> <button type="button" class="button-link rpw-remove" aria-label="<?php esc_attr_e( 'Remove', 'rival-price-watch' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Small SVG line chart of each rival's price over time, plus your current price.
	 *
	 * @param int   $pid   Product ID.
	 * @param array $links Links.
	 */
	private static function history_chart( $pid, array $links ) {
		$series = array();
		$min    = null;
		$max    = null;
		$t0     = null;
		$t1     = null;
		foreach ( $links as $i => $l ) {
			$points = array_reverse( RPW_DB::history( $pid, $l['url'], 60 ) );
			if ( count( $points ) < 2 ) {
				continue;
			}
			$pts = array();
			foreach ( $points as $p ) {
				$t     = strtotime( $p->checked_at . ' UTC' );
				$v     = (float) $p->price;
				$pts[] = array( $t, $v );
				$min   = null === $min ? $v : min( $min, $v );
				$max   = null === $max ? $v : max( $max, $v );
				$t0    = null === $t0 ? $t : min( $t0, $t );
				$t1    = null === $t1 ? $t : max( $t1, $t );
			}
			$series[ $i ] = $pts;
		}
		if ( ! $series || $t1 === $t0 ) {
			return;
		}
		$product = wc_get_product( $pid );
		$own     = $product ? (float) $product->get_price() : 0;
		if ( $own > 0 ) {
			$min = min( $min, $own );
			$max = max( $max, $own );
		}
		if ( $max === $min ) {
			$max += 1;
			$min -= 1;
		}
		$w      = 600;
		$h      = 160;
		$pad    = 8;
		$colors = array( '#2271b1', '#d63638', '#00a32a', '#dba617', '#8c5ccf', '#e26f1f', '#3c434a', '#1e8cbe', '#b32d2e', '#008a20' );
		$x      = static function ( $t ) use ( $t0, $t1, $w, $pad ) {
			return round( $pad + ( $t - $t0 ) / ( $t1 - $t0 ) * ( $w - 2 * $pad ), 1 );
		};
		$y      = static function ( $v ) use ( $min, $max, $h, $pad ) {
			return round( $h - $pad - ( $v - $min ) / ( $max - $min ) * ( $h - 2 * $pad ), 1 );
		};
		echo '<div class="rpw-chart"><svg viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" role="img" aria-label="' . esc_attr__( 'Price history', 'rival-price-watch' ) . '">';
		if ( $own > 0 ) {
			$yy = $y( $own );
			echo '<line x1="' . (int) $pad . '" x2="' . (int) ( $w - $pad ) . '" y1="' . esc_attr( $yy ) . '" y2="' . esc_attr( $yy ) . '" stroke="#1d2327" stroke-dasharray="4 4" stroke-width="1.5"/>';
		}
		foreach ( $series as $i => $pts ) {
			$d = array();
			foreach ( $pts as $p ) {
				$d[] = $x( $p[0] ) . ',' . $y( $p[1] );
			}
			echo '<polyline fill="none" stroke-width="2" stroke="' . esc_attr( $colors[ $i % count( $colors ) ] ) . '" points="' . esc_attr( implode( ' ', $d ) ) . '"/>';
		}
		echo '</svg><ul class="rpw-legend">';
		if ( $own > 0 ) {
			echo '<li><span class="rpw-dash"></span>' . esc_html__( 'Your price', 'rival-price-watch' ) . '</li>';
		}
		foreach ( array_keys( $series ) as $i ) {
			echo '<li><span style="background:' . esc_attr( $colors[ $i % count( $colors ) ] ) . '"></span>' . esc_html( RPW_Settings::link_name( $links[ $i ] ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Save links.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rpw_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rpw_nonce'] ) ), 'rpw_save_links' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$urls    = isset( $_POST['rpw_url'] ) ? (array) wp_unslash( $_POST['rpw_url'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$labels  = isset( $_POST['rpw_label'] ) ? (array) wp_unslash( $_POST['rpw_label'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$factors = isset( $_POST['rpw_factor'] ) ? (array) wp_unslash( $_POST['rpw_factor'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$matches = isset( $_POST['rpw_match'] ) ? (array) wp_unslash( $_POST['rpw_match'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$curs    = isset( $_POST['rpw_currency'] ) ? (array) wp_unslash( $_POST['rpw_currency'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$valid   = RPW_Currency::codes();

		$old_hashes = array_map(
			static function ( $l ) {
				return RPW_DB::hash( $l['url'] );
			},
			RPW_Settings::links( $post_id )
		);

		$links = array();
		$seen  = array();
		foreach ( $urls as $i => $raw ) {
			$url = esc_url_raw( trim( (string) $raw ), array( 'http', 'https' ) );
			if ( '' === $url || ! wp_http_validate_url( $url ) ) {
				continue;
			}
			$hash = RPW_DB::hash( $url );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$seen[ $hash ] = true;
			$factor        = isset( $factors[ $i ] ) ? (float) str_replace( ',', '.', (string) $factors[ $i ] ) : 1.0;
			$cur           = isset( $curs[ $i ] ) ? strtoupper( sanitize_key( (string) $curs[ $i ] ) ) : '';
			$links[]       = array(
				'url'      => $url,
				'label'    => isset( $labels[ $i ] ) ? sanitize_text_field( (string) $labels[ $i ] ) : '',
				'factor'   => $factor > 0 ? $factor : 1.0,
				'currency' => isset( $valid[ $cur ] ) ? $cur : '',
				'match'    => isset( $matches[ $i ] ) ? substr( sanitize_text_field( (string) $matches[ $i ] ), 0, 100 ) : '',
			);
			if ( count( $links ) >= self::MAX_LINKS ) {
				break;
			}
		}

		// Forget history of links that were removed.
		foreach ( array_diff( $old_hashes, array_keys( $seen ) ) as $gone ) {
			RPW_DB::delete_product( $post_id, $gone );
		}

		// Your own price per foreign market (currency).
		$market = array();
		foreach ( (array) ( isset( $_POST['rpw_market_price'] ) ? wp_unslash( $_POST['rpw_market_price'] ) : array() ) as $cur => $val ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed below.
			$cur   = strtoupper( sanitize_key( (string) $cur ) );
			$price = RPW_Currency::parse_rate( sanitize_text_field( (string) $val ) );
			if ( isset( RPW_Currency::codes()[ $cur ] ) && $price > 0 ) {
				$market[ $cur ] = $price;
			}
		}
		if ( $market ) {
			update_post_meta( $post_id, '_rpw_market_prices', $market );
		} else {
			delete_post_meta( $post_id, '_rpw_market_prices' );
		}

		if ( $links ) {
			update_post_meta( $post_id, '_rpw_links', $links );
		} else {
			delete_post_meta( $post_id, '_rpw_links' );
			delete_post_meta( $post_id, '_rpw_alerted' );
			delete_post_meta( $post_id, '_rpw_pending' );
		}
	}
}
