<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional "remove /category/ from category URLs" (same as Rank Math's "Strip Category Base"),
 * so category addresses do not change when Rank Math is deactivated.
 */
class SSAI_Permalinks {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 999 );
		if ( ! SSAI_SEO::get( 'strip_category' ) || '' !== SSAI_Content::seo_plugin() ) {
			return;
		}
		add_filter( 'category_link', array( __CLASS__, 'link' ), 10, 2 );
		add_filter( 'category_rewrite_rules', array( __CLASS__, 'rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'vars' ) );
		add_filter( 'request', array( __CLASS__, 'request' ) );
		foreach ( array( 'created_category', 'edited_category', 'delete_category' ) as $h ) {
			add_action( $h, array( __CLASS__, 'schedule_flush' ) );
		}
	}

	public static function schedule_flush() {
		update_option( 'ssai_flush_rules', 1, true );
	}

	public static function maybe_flush() {
		if ( get_option( 'ssai_flush_rules' ) ) {
			delete_option( 'ssai_flush_rules' );
			flush_rewrite_rules( false );
		}
	}

	private static function base() {
		$b = trim( (string) get_option( 'category_base' ), '/' );
		return '' === $b ? 'category' : $b;
	}

	public static function link( $link, $term_id ) {
		return preg_replace( '#/' . preg_quote( self::base(), '#' ) . '/#', '/', $link, 1 );
	}

	public static function rules( $rules ) {
		$new = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
			$nice = urldecode( $c->slug );
			if ( $c->parent && $c->parent !== $c->term_id ) {
				$parents = get_category_parents( $c->parent, false, '/', true );
				if ( ! is_wp_error( $parents ) ) {
					$nice = urldecode( $parents ) . $nice;
				}
			}
			$q                                                  = preg_quote( $nice, '#' );
			$new[ '(' . $q . ')/(?:feed/)?(feed|rdf|rss|rss2|atom)/?$' ] = 'index.php?category_name=$matches[1]&feed=$matches[2]';
			$new[ '(' . $q . ')/page/?([0-9]{1,})/?$' ]       = 'index.php?category_name=$matches[1]&paged=$matches[2]';
			$new[ '(' . $q . ')/?$' ]                          = 'index.php?category_name=$matches[1]';
		}
		$new[ self::base() . '/(.+)$' ] = 'index.php?ssai_cat_redirect=$matches[1]';
		return $new;
	}

	public static function vars( $vars ) {
		$vars[] = 'ssai_cat_redirect';
		return $vars;
	}

	public static function request( $qv ) {
		if ( ! empty( $qv['ssai_cat_redirect'] ) ) {
			wp_safe_redirect( home_url( user_trailingslashit( trim( $qv['ssai_cat_redirect'], '/' ) ) ), 301 );
			exit;
		}
		return $qv;
	}
}
