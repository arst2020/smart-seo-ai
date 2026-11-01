<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Redirects (301 / 302 / 307 / 410 / 451), automatic redirect when a slug changes,
 * and a 404 monitor with "one click" redirects and suggestions.
 */
class SSAI_Redirects {

	const DB_VER = '1';
	const SLUG   = 'smart-seo-ai-redirects';
	const MAP    = 'ssai_redirect_map';

	/** The request was answered with 410/451 on purpose – not a broken link. */
	private static $gone = false;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ssai_redirects';
	}

	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'ssai_404';
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 16 );
		add_action( 'admin_post_ssai_redirect', array( __CLASS__, 'handle' ) );
		add_action( 'template_redirect', array( __CLASS__, 'run' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'log_404' ), 99 );
		add_action( 'post_updated', array( __CLASS__, 'slug_changed' ), 10, 3 );
	}

	/** Tables exist (an updated plugin installs them on the next admin page load). */
	public static function ready() {
		return self::DB_VER === get_option( 'ssai_db_ver' );
	}

	public static function maybe_install() {
		if ( self::DB_VER === get_option( 'ssai_db_ver' ) ) {
			return;
		}
		self::install();
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source varchar(500) NOT NULL,
			match_type varchar(10) NOT NULL DEFAULT 'exact',
			target text NOT NULL,
			code smallint(3) NOT NULL DEFAULT 301,
			hits bigint(20) unsigned NOT NULL DEFAULT 0,
			last_hit datetime NULL DEFAULT NULL,
			created datetime NOT NULL,
			note varchar(190) NOT NULL DEFAULT '',
			active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY source (source(190))
			) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::log_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uri varchar(500) NOT NULL,
			hits bigint(20) unsigned NOT NULL DEFAULT 1,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			referer varchar(500) NOT NULL DEFAULT '',
			ua varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY uri (uri(190))
			) $c;"
		);
		update_option( 'ssai_db_ver', self::DB_VER, true );
		self::rebuild_map();
	}

	/* ================================================================ Normalising */

	/** Path relative to the site (decoded, lower case, no trailing slash, query kept when given). */
	public static function norm( $url ) {
		$url  = trim( (string) $url );
		$home = home_url();
		if ( 0 === stripos( $url, $home ) ) {
			$url = substr( $url, strlen( $home ) );
		} elseif ( preg_match( '#^https?://[^/]+(.*)$#i', $url, $m ) ) {
			$url = $m[1];
		}
		$q    = '';
		$hash = strpos( $url, '#' );
		if ( false !== $hash ) {
			$url = substr( $url, 0, $hash );
		}
		$qp = strpos( $url, '?' );
		if ( false !== $qp ) {
			$q   = substr( $url, $qp );
			$url = substr( $url, 0, $qp );
		}
		$url = '/' . ltrim( rawurldecode( $url ), '/' );
		$url = '/' === $url ? '/' : untrailingslashit( $url );
		return mb_strtolower( $url . ( '?' === $q ? '' : $q ), 'UTF-8' );
	}

	/** The current request in the same form (site-relative). */
	public static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( '' !== $base && 0 === strpos( $uri, $base ) ) {
			$uri = substr( $uri, strlen( $base ) );
		}
		return self::norm( $uri );
	}

	public static function target_url( $target ) {
		$target = trim( (string) $target );
		return preg_match( '#^https?://#i', $target ) ? $target : home_url( '/' . ltrim( $target, '/' ) );
	}

	/** Internal targets are stored site-relative so a domain change does not break them. */
	public static function store_target( $target ) {
		$target = trim( (string) $target );
		$home   = home_url();
		if ( 0 === stripos( $target, $home ) ) {
			$target = substr( $target, strlen( $home ) );
			$target = '' === $target ? '/' : $target;
		}
		return $target;
	}

	/* ================================================================ CRUD */

	public static function rebuild_map() {
		global $wpdb;
		$map  = array(
			'exact' => array(),
			'rx'    => array(),
		);
		$rows = $wpdb->get_results( 'SELECT id, source, match_type FROM ' . self::table() . ' WHERE active = 1' ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			if ( 'exact' === $r->match_type ) {
				$map['exact'][ $r->source ] = (int) $r->id;
			} else {
				$map['rx'][] = array( (int) $r->id, $r->match_type, $r->source );
			}
		}
		update_option( self::MAP, $map, true );
	}

	/** @return int|WP_Error new id */
	public static function add( $source, $target, $code = 301, $type = 'exact', $note = '' ) {
		global $wpdb;
		$type = in_array( $type, array( 'exact', 'start', 'regex' ), true ) ? $type : 'exact';
		$code = in_array( (int) $code, array( 301, 302, 307, 410, 451 ), true ) ? (int) $code : 301;
		$src  = 'regex' === $type ? trim( (string) $source ) : self::norm( $source );
		if ( '' === $src || ( 'regex' !== $type && '/' === $src ) ) {
			return new WP_Error( 'ssai_src', 'آدرس مبدأ خالی یا صفحه اصلی است.' );
		}
		if ( 'regex' === $type && false === @preg_match( '#' . str_replace( '#', '\#', $src ) . '#u', '' ) ) { // phpcs:ignore
			return new WP_Error( 'ssai_rx', 'عبارت باقاعده (Regex) نامعتبر است.' );
		}
		$tgt = $code >= 400 ? '' : self::store_target( $target );
		if ( $code < 400 && '' === $tgt ) {
			return new WP_Error( 'ssai_tgt', 'آدرس مقصد خالی است.' );
		}
		if ( $code < 400 && 'exact' === $type && self::norm( $tgt ) === $src ) {
			return new WP_Error( 'ssai_loop', 'مبدأ و مقصد یکی است (حلقه ریدایرکت).' );
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE source = %s AND match_type = %s', $src, $type ) ); // phpcs:ignore WordPress.DB
		if ( $exists ) {
			$wpdb->update( self::table(), array( 'target' => $tgt, 'code' => $code, 'active' => 1, 'note' => $note ), array( 'id' => $exists ) ); // phpcs:ignore WordPress.DB
			self::rebuild_map();
			return (int) $exists;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'source'     => $src,
				'match_type' => $type,
				'target'     => $tgt,
				'code'       => $code,
				'created'    => current_time( 'mysql', true ),
				'note'       => mb_substr( $note, 0, 190 ),
				'active'     => 1,
			)
		);
		$id = (int) $wpdb->insert_id;
		self::rebuild_map();
		return $id;
	}

	public static function count() {
		global $wpdb;
		if ( ! self::ready() ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	/* ================================================================ Front end */

	/** @return object|null matching row and the final URL */
	public static function match( $path ) {
		global $wpdb;
		$map = get_option( self::MAP );
		if ( ! is_array( $map ) ) {
			return null;
		}
		$id      = 0;
		$replace = null;
		$plain   = preg_replace( '/\?.*$/', '', $path );
		if ( isset( $map['exact'][ $path ] ) ) {
			$id = $map['exact'][ $path ];
		} elseif ( isset( $map['exact'][ $plain ] ) ) {
			$id = $map['exact'][ $plain ];
		} else {
			foreach ( $map['rx'] as $r ) {
				if ( 'start' === $r[1] && 0 === strpos( $plain, $r[2] ) ) {
					$id = $r[0];
					break;
				}
				if ( 'regex' === $r[1] && @preg_match( '#' . str_replace( '#', '\#', $r[2] ) . '#iu', $path, $mm ) ) { // phpcs:ignore
					$id      = $r[0];
					$replace = $mm;
					break;
				}
			}
		}
		if ( ! $id ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND active = 1', $id ) ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$to = $row->target;
		if ( $replace ) {
			$abs = (bool) preg_match( '#^https?://#i', trim( $to ) );
			foreach ( $replace as $i => $g ) {
				$to = str_replace( '$' . $i, $g, $to );
			}
			$to = preg_replace( '/[\r\n\t]/', '', $to );
			if ( ! $abs ) {
				// Only the admin's own target may point elsewhere; text taken from the visited address may not.
				$to = '/' . ltrim( preg_replace( '#^[a-z][a-z0-9+.-]*:/*#i', '', $to ), '/\\' );
				$row->url = (int) $row->code >= 400 ? '' : home_url( $to );
				return $row;
			}
		}
		$row->url = (int) $row->code >= 400 ? '' : self::target_url( $to );
		return $row;
	}

	public static function run() {
		if ( ! SSAI_SEO::on( 'redirects' ) || ! self::ready() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		$path = self::request_path();
		if ( '/' === $path ) {
			return;
		}
		$row = self::match( $path );
		if ( ! $row ) {
			return;
		}
		if ( $row->url && self::norm( $row->url ) === $path ) {
			return; // Would loop.
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET hits = hits + 1, last_hit = %s WHERE id = %d', current_time( 'mysql', true ), $row->id ) ); // phpcs:ignore WordPress.DB
		$code = (int) $row->code;
		if ( $code >= 400 ) {
			global $wp_query;
			self::$gone = true;
			$wp_query->set_404();
			status_header( $code );
			nocache_headers();
			return;
		}
		header( 'X-Redirect-By: Smart SEO AI' );
		wp_redirect( $row->url, $code, 'Smart SEO AI' ); // phpcs:ignore WordPress.Security.SafeRedirect -- admin-defined targets may be external.
		exit;
	}

	/** Automatic 301 from the old address when a published post's slug or parent changes. */
	public static function slug_changed( $post_id, $after, $before ) {
		if ( ! SSAI_SEO::on( 'auto_redirect' ) || ! self::ready() || 'publish' !== $before->post_status || 'publish' !== $after->post_status ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! in_array( $after->post_type, SSAI_Admin::post_types(), true ) ) {
			return;
		}
		if ( $before->post_name === $after->post_name && $before->post_parent === $after->post_parent ) {
			return;
		}
		$old = get_permalink( $before );
		$new = get_permalink( $post_id );
		if ( ! $old || ! $new || self::norm( $old ) === self::norm( $new ) ) {
			return;
		}
		global $wpdb;
		$old_n = self::norm( $old );
		$new_n = self::norm( $new );
		// A redirect from the new address would now loop – remove it.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE source = %s AND match_type = 'exact'", $new_n ) ); // phpcs:ignore WordPress.DB
		// Older redirects that pointed to the old address now point straight to the new one (no chains).
		$t = self::store_target( $new );
		foreach ( (array) $wpdb->get_results( 'SELECT id, target FROM ' . self::table() . ' WHERE code < 400' ) as $r ) { // phpcs:ignore WordPress.DB
			if ( self::norm( self::target_url( $r->target ) ) === $old_n ) {
				$wpdb->update( self::table(), array( 'target' => $t ), array( 'id' => $r->id ) ); // phpcs:ignore WordPress.DB
			}
		}
		self::add( $old, $new, 301, 'exact', 'خودکار: تغییر نامک «' . mb_substr( get_the_title( $post_id ), 0, 80 ) . '»' );
	}

	/* ================================================================ 404 monitor */

	public static function log_404() {
		if ( ! is_404() || self::$gone || ! SSAI_SEO::on( 'monitor404' ) || ! self::ready() ) {
			return;
		}
		$path = self::request_path();
		// Skip obvious bot probes (wp-login exploits, .env, .git…) to keep the list useful.
		if ( preg_match( '#\.(php\d?|env|git|sql|bak|ini|log|asp|aspx|cgi)(\W|$)|/wp-includes/|xmlrpc|/\.well-known/#i', $path ) ) {
			return;
		}
		global $wpdb;
		$now = current_time( 'mysql', true );
		$id  = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::log_table() . ' WHERE uri = %s', $path ) ); // phpcs:ignore WordPress.DB
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 500 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		if ( $id ) {
			if ( '' !== $ref ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::log_table() . ' SET hits = hits + 1, last_seen = %s, referer = %s, ua = %s WHERE id = %d', $now, $ref, $ua, $id ) ); // phpcs:ignore WordPress.DB
			} else {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::log_table() . ' SET hits = hits + 1, last_seen = %s, ua = %s WHERE id = %d', $now, $ua, $id ) ); // phpcs:ignore WordPress.DB
			}
		} else {
			$wpdb->insert( self::log_table(), array( 'uri' => mb_substr( $path, 0, 500 ), 'first_seen' => $now, 'last_seen' => $now, 'referer' => $ref, 'ua' => $ua ) ); // phpcs:ignore WordPress.DB
			if ( 1 === wp_rand( 1, 50 ) ) {
				$n = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::log_table() ); // phpcs:ignore WordPress.DB
				if ( $n > 3000 ) {
					$cut = $wpdb->get_var( $wpdb->prepare( 'SELECT last_seen FROM ' . self::log_table() . ' WHERE hits < 3 ORDER BY last_seen ASC LIMIT 1 OFFSET %d', $n - 2000 ) ); // phpcs:ignore WordPress.DB
					if ( $cut ) {
						$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . ' WHERE hits < 3 AND last_seen <= %s', $cut ) ); // phpcs:ignore WordPress.DB
					}
				}
			}
		}
	}

	/** Best existing page for a broken address (same slug, or the most similar slug). */
	public static function suggest( $uri ) {
		$seg = trim( (string) preg_replace( '/\?.*$/', '', $uri ), '/' );
		$seg = basename( $seg );
		$seg = preg_replace( '/\.(html?|php)$/i', '', $seg );
		if ( '' === $seg ) {
			return '';
		}
		$slug = sanitize_title( $seg );
		$list = get_transient( 'ssai_slug_list' );
		if ( ! is_array( $list ) ) {
			global $wpdb;
			$types = SSAI_Admin::post_types();
			$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) LIMIT 5000", $types ) ); // phpcs:ignore WordPress.DB
			$list  = array();
			foreach ( (array) $rows as $r ) {
				$list[ urldecode( $r->post_name ) ] = (int) $r->ID;
			}
			set_transient( 'ssai_slug_list', $list, HOUR_IN_SECONDS );
		}
		$dec = urldecode( $slug );
		if ( isset( $list[ $dec ] ) ) {
			return get_permalink( $list[ $dec ] );
		}
		$best = 0;
		$bid  = 0;
		foreach ( $list as $name => $id ) {
			similar_text( $dec, $name, $pct );
			if ( $pct > $best ) {
				$best = $pct;
				$bid  = $id;
			}
		}
		return $best >= 65 ? get_permalink( $bid ) : '';
	}

	public static function log_stats( $days = 7 ) {
		global $wpdb;
		if ( ! self::ready() ) {
			return array( 'urls' => 0, 'hits' => 0 );
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return array(
			'urls' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::log_table() . ' WHERE last_seen >= %s', $since ) ), // phpcs:ignore WordPress.DB
			'hits' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(hits) FROM ' . self::log_table() . ' WHERE last_seen >= %s', $since ) ), // phpcs:ignore WordPress.DB
		);
	}

	public static function top_404( $n = 5 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::log_table() . ' ORDER BY hits DESC, last_seen DESC LIMIT %d', $n ) ); // phpcs:ignore WordPress.DB
	}

	/* ================================================================ Imports */

	/** Rank Math redirections table. @return int imported */
	public static function import_rankmath() {
		global $wpdb;
		$t = $wpdb->prefix . 'rank_math_redirections';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB
			return 0;
		}
		$n = 0;
		foreach ( (array) $wpdb->get_results( "SELECT * FROM $t WHERE status = 'active'" ) as $r ) { // phpcs:ignore WordPress.DB
			$sources = maybe_unserialize( $r->sources );
			foreach ( is_array( $sources ) ? $sources : array() as $s ) {
				$pat  = isset( $s['pattern'] ) ? (string) $s['pattern'] : '';
				$cmp  = isset( $s['comparison'] ) ? $s['comparison'] : 'exact';
				$type = 'exact';
				if ( 'regex' === $cmp ) {
					$type = 'regex';
				} elseif ( 'start' === $cmp ) {
					$type = 'start';
				} elseif ( 'contains' === $cmp ) {
					$type = 'regex';
					$pat  = preg_quote( $pat, '#' );
				} elseif ( 'end' === $cmp ) {
					$type = 'regex';
					$pat  = preg_quote( $pat, '#' ) . '$';
				}
				if ( 'start' === $type ) {
					$pat = self::norm( $pat );
				}
				$r2 = self::add( $pat, $r->url_to, (int) $r->header_code, $type, 'از Rank Math' );
				if ( ! is_wp_error( $r2 ) ) {
					$n++;
				}
			}
		}
		return $n;
	}

	/** "Redirection" plugin table. */
	public static function import_redirection() {
		global $wpdb;
		$t = $wpdb->prefix . 'redirection_items';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { // phpcs:ignore WordPress.DB
			return 0;
		}
		$n = 0;
		foreach ( (array) $wpdb->get_results( "SELECT * FROM $t WHERE status = 'enabled' AND action_type = 'url'" ) as $r ) { // phpcs:ignore WordPress.DB
			$r2 = self::add( $r->url, $r->action_data, (int) $r->action_code, $r->regex ? 'regex' : 'exact', 'از افزونه Redirection' );
			if ( ! is_wp_error( $r2 ) ) {
				$n++;
			}
		}
		return $n;
	}

	/* ================================================================ Admin */

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'ریدایرکت و ۴۰۴', 'ریدایرکت و ۴۰۴', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function back( $msg, $type = 'success', $tab = '' ) {
		set_transient(
			'ssai_rd_notice_' . get_current_user_id(),
			array(
				'msg'  => $msg,
				'type' => $type,
			),
			60
		);
		wp_safe_redirect( add_query_arg( array_filter( array( 'page' => self::SLUG, 'tab' => $tab ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle() {
		check_admin_referer( 'ssai_redirect' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		global $wpdb;
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		switch ( $do ) {
			case 'add':
				$r = self::add(
					isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '',
					isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '',
					isset( $_POST['code'] ) ? absint( $_POST['code'] ) : 301,
					isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'exact'
				);
				if ( is_wp_error( $r ) ) {
					self::back( $r->get_error_message(), 'error', $tab );
				}
				if ( $id && 'log' === $tab ) {
					$wpdb->delete( self::log_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
				}
				self::back( 'ریدایرکت ذخیره شد.', 'success', $tab );
				break;
			case 'delete':
				$wpdb->delete( self::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
				self::rebuild_map();
				self::back( 'ریدایرکت حذف شد.' );
				break;
			case 'toggle':
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET active = 1 - active WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB
				self::rebuild_map();
				self::back( 'وضعیت تغییر کرد.' );
				break;
			case 'log_delete':
				$wpdb->delete( self::log_table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
				self::back( 'حذف شد.', 'success', 'log' );
				break;
			case 'log_clear':
				$wpdb->query( 'DELETE FROM ' . self::log_table() ); // phpcs:ignore WordPress.DB
				self::back( 'فهرست ۴۰۴ پاک شد.', 'success', 'log' );
				break;
			case 'import':
				$n = self::import_rankmath() + self::import_redirection();
				self::back( sprintf( '%d ریدایرکت وارد شد.', $n ) );
				break;
		}
		self::back( 'عمل نامعتبر.', 'error' );
	}

	private static function form_open( $do, $extra = array() ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ssai-inline-form">';
		echo '<input type="hidden" name="action" value="ssai_redirect"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		foreach ( $extra as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		wp_nonce_field( 'ssai_redirect' );
	}

	public static function page() {
		self::maybe_install();
		$tab    = isset( $_GET['tab'] ) && 'log' === $_GET['tab'] ? 'log' : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		$notice = get_transient( 'ssai_rd_notice_' . get_current_user_id() );
		delete_transient( 'ssai_rd_notice_' . get_current_user_id() );
		$url    = admin_url( 'admin.php?page=' . self::SLUG );
		?>
		<div class="wrap ssai ssai-report" dir="rtl">
			<h1>ریدایرکت‌ها و مانیتور ۴۰۴</h1>
			<hr class="wp-header-end">
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== SSAI_Content::seo_plugin() ) : ?>
				<div class="notice notice-info"><p>تا وقتی <?php echo esc_html( 'rankmath' === SSAI_Content::seo_plugin() ? 'Rank Math' : 'افزونه سئوی دیگری' ); ?> فعال است، ریدایرکت‌ها و ثبت ۴۰۴ این افزونه اجرا نمی‌شوند تا کار دوبار انجام نشود. می‌توانید از همین حالا ریدایرکت‌ها را وارد و آماده کنید.</p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'list' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">ریدایرکت‌ها (<?php echo esc_html( number_format_i18n( self::count() ) ); ?>)</a>
				<a class="nav-tab <?php echo 'log' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $url . '&tab=log' ); ?>">آدرس‌های ۴۰۴</a>
			</nav>
			<?php 'log' === $tab ? self::render_log() : self::render_list(); ?>
		</div>
		<?php
	}

	private static function render_list() {
		global $wpdb;
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$s     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$where = '' !== $s ? $wpdb->prepare( 'WHERE source LIKE %s OR target LIKE %s', '%' . $wpdb->esc_like( $s ) . '%', '%' . $wpdb->esc_like( $s ) . '%' ) : '';
		$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " $where" ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " $where ORDER BY id DESC LIMIT 50 OFFSET " . ( ( $paged - 1 ) * 50 ) ); // phpcs:ignore WordPress.DB
		?>
		<section class="ssai-card">
			<h2>افزودن ریدایرکت</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssai-rd-form">
				<input type="hidden" name="action" value="ssai_redirect"><input type="hidden" name="do" value="add">
				<?php wp_nonce_field( 'ssai_redirect' ); ?>
				<label>از آدرس <input type="text" name="source" dir="ltr" required placeholder="/old-page/"></label>
				<label>به آدرس <input type="text" name="target" dir="ltr" placeholder="/new-page/ یا https://…"></label>
				<label>نوع <select name="code"><option value="301">301 دائمی</option><option value="302">302 موقت</option><option value="307">307 موقت</option><option value="410">410 حذف‌شده</option><option value="451">451 حذف به دلیل قانونی</option></select></label>
				<label>تطبیق <select name="type"><option value="exact">دقیق</option><option value="start">شروع با</option><option value="regex">Regex</option></select></label>
				<button class="button button-primary">ذخیره</button>
			</form>
			<p class="description">آدرس‌ها نسبت به سایت ذخیره می‌شوند و حروف بزرگ و کوچک و / آخر مهم نیست. در حالت Regex می‌توانید در مقصد از $1، $2 استفاده کنید. برای 410 مقصد لازم نیست.</p>
			<?php
			self::form_open( 'import' );
			echo '<button class="button">وارد کردن از Rank Math / Redirection</button></form>';
			?>
		</section>
		<section class="ssai-card">
			<form method="get" class="ssai-inline-form"><input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"><input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="جستجو"> <button class="button">جستجو</button></form>
			<div class="ssai-table-wrap"><table class="widefat striped">
				<thead><tr><th>از</th><th>به</th><th>کد</th><th>بازدید</th><th>آخرین بازدید</th><th>توضیح</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7">ریدایرکتی ثبت نشده است.</td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $rows as $r ) : ?>
					<tr class="<?php echo $r->active ? '' : 'ssai-muted'; ?>">
						<td dir="ltr" style="text-align:right"><?php echo esc_html( ( 'exact' !== $r->match_type ? '[' . $r->match_type . '] ' : '' ) . $r->source ); ?></td>
						<td dir="ltr" style="text-align:right"><?php echo $r->code >= 400 ? '—' : '<a href="' . esc_url( self::target_url( $r->target ) ) . '" target="_blank" rel="noopener">' . esc_html( rawurldecode( $r->target ) ) . '</a>'; ?></td>
						<td><?php echo esc_html( $r->code ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r->hits ) ); ?></td>
						<td><?php echo $r->last_hit ? esc_html( wp_date( 'Y/m/d', strtotime( $r->last_hit . ' UTC' ) ) ) : '—'; ?></td>
						<td class="description"><?php echo esc_html( $r->note ); ?></td>
						<td>
							<?php
							self::form_open( 'toggle', array( 'id' => $r->id ) );
							echo '<button class="button-link">' . ( $r->active ? 'غیرفعال' : 'فعال' ) . '</button></form> ';
							self::form_open( 'delete', array( 'id' => $r->id ) );
							echo '<button class="button-link ssai-del">حذف</button></form>';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php
			if ( $total > 50 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'current' => $paged, 'total' => ceil( $total / 50 ) ) ) ) . '</div></div>';
			}
			?>
		</section>
		<?php
	}

	private static function render_log() {
		global $wpdb;
		$rows  = $wpdb->get_results( 'SELECT * FROM ' . self::log_table() . ' ORDER BY hits DESC, last_seen DESC LIMIT 200' ); // phpcs:ignore WordPress.DB
		$stats = self::log_stats( 7 );
		?>
		<section class="ssai-card">
			<p>در ۷ روز اخیر <strong><?php echo esc_html( number_format_i18n( $stats['urls'] ) ); ?></strong> آدرس خراب، <strong><?php echo esc_html( number_format_i18n( $stats['hits'] ) ); ?></strong> بار باز شده‌اند. آدرس‌هایی که بیشتر باز می‌شوند یا از سایت‌های دیگر لینک دارند را به مرتبط‌ترین صفحه ریدایرکت کنید. درخواست‌های ربات‌های مخرب (مثل wp-login.php، ‎.env) ثبت نمی‌شوند.</p>
			<?php
			self::form_open( 'log_clear', array( 'tab' => 'log' ) );
			echo '<button class="button">پاک کردن کل فهرست</button></form>';
			?>
			<div class="ssai-table-wrap"><table class="widefat striped">
				<thead><tr><th>آدرس</th><th>تعداد</th><th>آخرین بار</th><th>از کجا آمده</th><th>ریدایرکت به</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6">آدرس ۴۰۴ ثبت نشده است.</td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $rows as $r ) : ?>
					<?php $sug = self::suggest( $r->uri ); ?>
					<tr>
						<td dir="ltr" style="text-align:right;word-break:break-all"><a href="<?php echo esc_url( home_url( $r->uri ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r->uri ); ?></a></td>
						<td><?php echo esc_html( number_format_i18n( $r->hits ) ); ?></td>
						<td><?php echo esc_html( wp_date( 'Y/m/d H:i', strtotime( $r->last_seen . ' UTC' ) ) ); ?></td>
						<td dir="ltr" class="description" style="text-align:right;word-break:break-all"><?php echo esc_html( $r->referer ? rawurldecode( $r->referer ) : '—' ); ?></td>
						<td>
							<?php self::form_open( 'add', array( 'id' => $r->id, 'tab' => 'log', 'source' => $r->uri, 'code' => 301, 'type' => 'exact' ) ); ?>
							<input type="text" name="target" dir="ltr" value="<?php echo esc_attr( $sug ? rawurldecode( self::store_target( $sug ) ) : '' ); ?>" placeholder="/صفحه-مقصد/" aria-label="مقصد ریدایرکت" required>
							<button class="button button-small">ریدایرکت ۳۰۱</button></form>
							<?php if ( $sug ) : ?><div class="description">پیشنهاد: صفحه با نامک مشابه</div><?php endif; ?>
						</td>
						<td><?php self::form_open( 'log_delete', array( 'id' => $r->id, 'tab' => 'log' ) ); ?><button class="button-link ssai-del">حذف</button></form></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</section>
		<?php
	}
}
