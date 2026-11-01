<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find a URL everywhere it is stored (post content, Elementor data incl. header/footer templates,
 * menu items, widgets) and replace it – only after the admin confirms, with an undo log.
 */
class SSAI_Linkfix {

	const LOG = 'ssai_linkfix_log';

	public static function init() {
		foreach ( array( 'scan', 'apply', 'undo' ) as $a ) {
			add_action( 'wp_ajax_ssai_linkfix_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/** Forms the URL can have in stored content: absolute, with/without trailing slash, site-relative. */
	public static function variants( $url ) {
		$url = trim( (string) $url );
		$out = array( $url );
		if ( false === strpos( $url, '?' ) && false === strpos( $url, '#' ) ) {
			$out[] = '/' === substr( $url, -1 ) ? rtrim( $url, '/' ) : $url . '/';
		}
		$home = untrailingslashit( home_url() );
		foreach ( $out as $u ) {
			foreach ( array( $home, str_replace( 'https://', 'http://', $home ), str_replace( '://', '://www.', $home ), str_replace( '://www.', '://', $home ) ) as $h ) {
				if ( 0 === stripos( $u, $h . '/' ) ) {
					$rel = substr( $u, strlen( $h ) );
					if ( strlen( $rel ) > 1 ) {
						$out[] = $rel;
					}
				}
			}
		}
		// Percent-encoded and decoded Persian slugs.
		foreach ( $out as $u ) {
			$d = rawurldecode( $u );
			if ( $d !== $u ) {
				$out[] = $d;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Regex for one variant: must start and end at a URL boundary so other URLs are not touched. */
	private static function rx( $v, $escaped ) {
		$v = $escaped ? str_replace( '/', '\\/', $v ) : $v;
		return '~(?<=["\'(=\s>])' . preg_quote( $v, '~' ) . '(?=["\'\\\\\s#<>)]|$)~u';
	}

	private static function count_in( $text, $url ) {
		$n = 0;
		foreach ( self::variants( $url ) as $v ) {
			foreach ( array( false, true ) as $esc ) {
				$n += (int) preg_match_all( self::rx( $v, $esc ), $text );
			}
		}
		return $n;
	}

	private static function replace_in( $text, $old, $new ) {
		foreach ( self::variants( $old ) as $v ) {
			foreach ( array( false, true ) as $esc ) {
				$to   = $esc ? str_replace( '/', '\\/', $new ) : $new;
				$text = preg_replace_callback(
					self::rx( $v, $esc ),
					function () use ( $to ) {
						return $to;
					},
					$text
				);
			}
		}
		return $text;
	}

	/** Short LIKE needle that every variant contains. */
	private static function needle( $url ) {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
		$p = '' !== trim( $p, '/' ) ? rtrim( $p, '/' ) : $url;
		return array_unique( array( $p, rawurldecode( $p ), str_replace( '/', '\\/', $p ), str_replace( '/', '\\/', rawurldecode( $p ) ) ) );
	}

	/**
	 * @return array of [ kind: content|elementor|menu|widget, id, title, count ]
	 */
	public static function scan( $url ) {
		global $wpdb;
		$found = array();
		foreach ( self::needle( $url ) as $nd ) {
			$like = '%' . $wpdb->esc_like( $nd ) . '%';
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future') AND post_type NOT IN ('revision','nav_menu_item','customize_changeset') AND post_content LIKE %s LIMIT 2000", $like ) ) as $r ) { // phpcs:ignore WordPress.DB
				$n = self::count_in( $r->post_content, $url );
				if ( $n ) {
					$found[ 'content:' . $r->ID ] = array( 'content', (int) $r->ID, $r->post_title, $n );
				}
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value, p.post_title, p.post_status FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_elementor_data' AND p.post_status IN ('publish','draft','private','future') AND m.meta_value LIKE %s LIMIT 2000", $like ) ) as $r ) { // phpcs:ignore WordPress.DB
				$n = self::count_in( $r->meta_value, $url );
				if ( $n ) {
					$found[ 'elementor:' . $r->post_id ] = array( 'elementor', (int) $r->post_id, $r->post_title, $n );
				}
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m WHERE m.meta_key = '_menu_item_url' AND m.meta_value LIKE %s LIMIT 500", $like ) ) as $r ) { // phpcs:ignore WordPress.DB
				$n = self::count_in( '"' . $r->meta_value . '"', $url );
				if ( $n ) {
					$found[ 'menu:' . $r->post_id ] = array( 'menu', (int) $r->post_id, get_the_title( $r->post_id ), $n );
				}
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%%' AND option_value LIKE %s", $like ) ) as $r ) { // phpcs:ignore WordPress.DB
				$n = self::count_in( $r->option_value, $url );
				if ( $n ) {
					$found[ 'widget:' . $r->option_name ] = array( 'widget', $r->option_name, $r->option_name, $n );
				}
			}
		}
		// Menu items with a page/post object (not a custom URL) cannot point to a raw URL – nothing to do there.
		return array_values( $found );
	}

	private static function walk_replace( $v, $old, $new ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::walk_replace( $x, $old, $new );
			}
			return $v;
		}
		if ( ! is_string( $v ) ) {
			return $v;
		}
		// Quotes around the value give the URL-boundary check something to anchor on.
		return substr( self::replace_in( '"' . $v . '"', $old, $new ), 1, -1 );
	}

	/** @return array { changed, locations[] } */
	public static function replace( $old, $new, $note = '' ) {
		$r = self::replace_at( $old, $new, self::scan( $old ) );
		if ( $r['changed'] ) {
			$log   = get_option( self::LOG, array() );
			$log   = is_array( $log ) ? $log : array();
			$log[] = array(
				'id'   => strtolower( wp_generate_password( 8, false ) ),
				'time' => time(),
				'user' => get_current_user_id(),
				'old'  => $old,
				'new'  => $new,
				'note' => $note,
				'locs' => $r['locations'],
			);
			update_option( self::LOG, array_slice( $log, -100 ), false );
		}
		return $r;
	}

	/** Replace only in the given locations (used by undo, so links that were already right stay untouched). */
	public static function replace_at( $old, $new, $locs ) {
		global $wpdb;
		$done = array();
		foreach ( $locs as $l ) {
			list( $kind, $id ) = $l;
			if ( 'content' === $kind ) {
				$c = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $id ) ); // phpcs:ignore WordPress.DB
				$n = self::replace_in( $c, $old, $new );
				if ( $n !== $c ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => $n ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB
					clean_post_cache( $id );
					SSAI_Applier::purge_cache( $id );
					$done[] = $l;
				}
			} elseif ( 'elementor' === $kind ) {
				$raw = SSAI_Elementor::get_raw( $id );
				$n   = self::replace_in( $raw, $old, $new );
				if ( $n !== $raw && is_array( json_decode( $n, true ) ) ) {
					SSAI_Elementor::save_raw( $id, $n );
					SSAI_Applier::purge_cache( $id );
					$done[] = $l;
				}
			} elseif ( 'menu' === $kind ) {
				$u = (string) get_post_meta( $id, '_menu_item_url', true );
				$n = substr( self::replace_in( '"' . $u . '"', $old, $new ), 1, -1 );
				if ( $n !== $u ) {
					update_post_meta( $id, '_menu_item_url', esc_url_raw( $n ) );
					$done[] = $l;
				}
			} elseif ( 'widget' === $kind ) {
				$opt = get_option( $id );
				$n   = self::walk_replace( $opt, $old, $new );
				if ( $n !== $opt ) {
					update_option( $id, $n );
					$done[] = $l;
				}
			}
		}
		if ( $done ) {
			do_action( 'ssai_sitemap_changed' );
		}
		return array(
			'changed'   => count( $done ),
			'locations' => $done,
		);
	}

	public static function log() {
		$l = get_option( self::LOG, array() );
		return is_array( $l ) ? array_reverse( $l ) : array();
	}

	/* ================================================================ AJAX */

	private static function guard() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( SSAI_Settings::get( 'apply_cap' ) ) ) {
			wp_send_json_error( array( 'message' => 'شما اجازه این کار را ندارید.' ), 403 );
		}
	}

	private static function url_param( $k ) {
		$v = isset( $_POST[ $k ] ) ? trim( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $v && '/' === $v[0] ) {
			$v = home_url( $v );
		}
		return esc_url_raw( $v );
	}

	public static function ajax_scan() {
		self::guard();
		$old = self::url_param( 'old' );
		if ( '' === $old ) {
			wp_send_json_error( array( 'message' => 'آدرس خالی است.' ) );
		}
		$kinds = array(
			'content'   => 'محتوا',
			'elementor' => 'المنتور',
			'menu'      => 'منو',
			'widget'    => 'ابزارک',
		);
		$out   = array();
		foreach ( self::scan( $old ) as $l ) {
			$pt    = 'widget' === $l[0] ? '' : get_post_type( $l[1] );
			$out[] = array(
				'kind'  => $kinds[ $l[0] ],
				'title' => ( 'elementor_library' === $pt ? 'قالب المنتور: ' : '' ) . ( $l[2] ? $l[2] : '#' . $l[1] ),
				'count' => $l[3],
				'edit'  => 'widget' === $l[0] ? admin_url( 'widgets.php' ) : ( 'menu' === $l[0] ? admin_url( 'nav-menus.php' ) : ( 'elementor' === $l[0] ? SSAI_Elementor::edit_url( $l[1] ) : get_edit_post_link( $l[1], 'raw' ) ) ),
			);
		}
		wp_send_json_success( array( 'locations' => $out ) );
	}

	public static function ajax_apply() {
		self::guard();
		$old = self::url_param( 'old' );
		$new = self::url_param( 'new' );
		if ( '' === $old || '' === $new ) {
			wp_send_json_error( array( 'message' => 'آدرس مبدأ و مقصد را وارد کنید.' ) );
		}
		if ( untrailingslashit( $old ) === untrailingslashit( $new ) ) {
			wp_send_json_error( array( 'message' => 'آدرس جدید با قبلی یکی است.' ) );
		}
		$r = self::replace( $old, $new, isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '' );
		if ( ! $r['changed'] ) {
			wp_send_json_error( array( 'message' => 'این لینک در محتوا، المنتور، منوها و ابزارک‌ها پیدا نشد. ممکن است در قالب (کد PHP) یا افزونه دیگری باشد؛ آن را دستی اصلاح کنید یا یک ریدایرکت 301 بسازید.' ) );
		}
		wp_send_json_success( array( 'message' => sprintf( 'در %d جا اصلاح شد. اگر افزونه کش دارید، کش را پاک کنید.', $r['changed'] ) ) );
	}

	public static function ajax_undo() {
		self::guard();
		$id  = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$log = get_option( self::LOG, array() );
		foreach ( (array) $log as $i => $e ) {
			if ( $e['id'] === $id ) {
				$r = self::replace_at( $e['new'], $e['old'], $e['locs'] );
				unset( $log[ $i ] );
				update_option( self::LOG, array_values( $log ), false );
				wp_send_json_success( array( 'message' => sprintf( 'بازگردانده شد (%d جا).', $r['changed'] ) ) );
			}
		}
		wp_send_json_error( array( 'message' => 'پیدا نشد.' ) );
	}
}
