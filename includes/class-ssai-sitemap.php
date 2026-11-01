<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XML sitemaps with the same addresses Rank Math uses (/sitemap_index.xml, /page-sitemap.xml,
 * /category-sitemap.xml …), so nothing has to be re-submitted in Search Console.
 * Served without rewrite rules; noindex and password-protected content is left out.
 */
class SSAI_Sitemap {

	const VER = 'ssai_sitemap_ver';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 99 );
		add_filter( 'wp_sitemaps_enabled', array( __CLASS__, 'core_enabled' ) );
		foreach ( array( 'save_post', 'deleted_post', 'trashed_post', 'edited_term', 'created_term', 'delete_term', 'ssai_sitemap_changed' ) as $h ) {
			add_action( $h, array( __CLASS__, 'bump' ) );
		}
	}

	public static function core_enabled( $on ) {
		return SSAI_SEO::on( 'sitemap' ) ? false : $on;
	}

	public static function bump() {
		update_option( self::VER, time(), true );
	}

	public static function url( $file = 'sitemap_index.xml' ) {
		return home_url( '/' . $file );
	}

	private static function path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return ltrim( $path, '/' );
	}

	public static function serve() {
		if ( is_admin() || ! SSAI_SEO::on( 'sitemap' ) ) {
			return;
		}
		$p = self::path();
		if ( '' === $p || ! preg_match( '/\.(xml|xsl)$/', $p ) ) {
			return;
		}
		if ( 'wp-sitemap.xml' === $p ) {
			wp_safe_redirect( self::url(), 301 );
			exit;
		}
		if ( 'sitemap.xsl' === $p ) {
			self::xsl();
		}
		if ( 'sitemap_index.xml' === $p || 'sitemap.xml' === $p ) {
			self::send( self::cached( 'index', 1 ) );
		}
		if ( preg_match( '/^([a-z0-9_\-]+?)-sitemap(\d*)\.xml$/', $p, $m ) ) {
			$xml = self::cached( $m[1], max( 1, (int) $m[2] ) );
			if ( null === $xml ) {
				return; // Unknown: let WordPress show its 404.
			}
			self::send( $xml );
		}
	}

	private static function send( $xml ) {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- XML built with esc_* below.
		exit;
	}

	private static function cached( $key, $page ) {
		$tk  = 'ssai_sm_' . md5( $key . '|' . $page . '|' . get_option( self::VER ) . '|' . home_url() );
		$xml = get_transient( $tk );
		if ( false === $xml ) {
			$xml = 'index' === $key ? self::index() : self::urlset( $key, $page );
			if ( null !== $xml ) {
				set_transient( $tk, $xml, 12 * HOUR_IN_SECONDS );
			}
		}
		return $xml;
	}

	/** Enabled post types and taxonomies: [ name => 'pt'|'tax' ]. */
	public static function sources() {
		$o   = SSAI_SEO::get( 'sitemap' );
		$off = (array) $o['off'];
		$out = array();
		foreach ( SSAI_Admin::post_types() as $pt ) {
			if ( ! in_array( 'pt_' . $pt, $off, true ) ) {
				$tpl = SSAI_SEO::tpl( 'pt_' . $pt );
				if ( empty( $tpl['noindex'] ) ) {
					$out[ $pt ] = 'pt';
				}
			}
		}
		foreach ( array_keys( SSAI_SEO::taxonomies() ) as $tax ) {
			if ( ! in_array( 'tax_' . $tax, $off, true ) && ! isset( $out[ $tax ] ) ) {
				$tpl = SSAI_SEO::tpl( 'tax_' . $tax );
				if ( empty( $tpl['noindex'] ) ) {
					$out[ $tax ] = 'tax';
				}
			}
		}
		return $out;
	}

	/** @return array of [ id, modified_gmt ] for a post type, noindex removed. */
	public static function posts( $pt ) {
		global $wpdb;
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' ORDER BY post_modified_gmt DESC", $pt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exclude = array_map( 'absint', preg_split( '/[\s,،]+/', (string) SSAI_SEO::get( 'sitemap' )['exclude'], -1, PREG_SPLIT_NO_EMPTY ) );
		$ids     = wp_list_pluck( $rows, 'ID' );
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}
		$out = array();
		foreach ( $rows as $r ) {
			$id = (int) $r->ID;
			if ( in_array( $id, $exclude, true ) ) {
				continue;
			}
			$rob = SSAI_SEO::post_robots( $id );
			if ( $rob['noindex'] ) {
				continue;
			}
			// A custom canonical pointing elsewhere means this URL is not the one to index.
			$c = SSAI_SEO::post_canonical( $id );
			if ( $c && untrailingslashit( $c ) !== untrailingslashit( get_permalink( $id ) ) && (int) get_option( 'page_on_front' ) !== $id ) {
				continue;
			}
			// A published page that is redirected elsewhere does not belong in the sitemap.
			if ( SSAI_Redirects::ready() && SSAI_SEO::on( 'redirects' ) && SSAI_Redirects::match( SSAI_Redirects::norm( get_permalink( $id ) ) ) ) {
				continue;
			}
			$out[] = array( $id, $r->post_modified_gmt );
		}
		return $out;
	}

	public static function terms( $tax ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => true,
				'lang'       => '',
			)
		);
		$out   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			if ( ! SSAI_SEO::term_noindex( $t ) ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	private static function head( $root ) {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<?xml-stylesheet type="text/xsl" href="' . esc_url( self::url( 'sitemap.xsl' ) ) . '"?>' . "\n" . $root . "\n";
	}

	private static function per() {
		return max( 50, min( 5000, (int) SSAI_SEO::get( 'sitemap' )['per_page'] ) );
	}

	public static function index() {
		$x   = self::head( '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' );
		$per = self::per();
		foreach ( self::sources() as $name => $kind ) {
			if ( 'pt' === $kind ) {
				$rows = self::posts( $name );
				if ( 'page' === $name && 'posts' === get_option( 'show_on_front' ) ) {
					array_unshift( $rows, array( 0, gmdate( 'Y-m-d H:i:s' ) ) );
				}
				foreach ( array_chunk( $rows, $per ) as $i => $chunk ) {
					$last = max( wp_list_pluck( $chunk, 1 ) );
					$x   .= "\t<sitemap>\n\t\t<loc>" . esc_url( self::url( $name . '-sitemap' . ( $i ? $i + 1 : '' ) . '.xml' ) ) . "</loc>\n\t\t<lastmod>" . esc_html( mysql2date( 'c', $last, false ) ) . "</lastmod>\n\t</sitemap>\n";
				}
			} else {
				$terms = self::terms( $name );
				foreach ( array_chunk( $terms, $per ) as $i => $chunk ) {
					$x .= "\t<sitemap>\n\t\t<loc>" . esc_url( self::url( $name . '-sitemap' . ( $i ? $i + 1 : '' ) . '.xml' ) ) . "</loc>\n\t</sitemap>\n";
				}
			}
		}
		return $x . '</sitemapindex>';
	}

	private static function images( $id ) {
		if ( ! SSAI_SEO::get( 'sitemap' )['images'] ) {
			return array();
		}
		$urls = array();
		$tid  = (int) get_post_thumbnail_id( $id );
		if ( $tid ) {
			$u = wp_get_attachment_image_url( $tid, 'full' );
			if ( $u ) {
				$urls[] = $u;
			}
		}
		$html = (string) get_post_field( 'post_content', $id );
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}
		$raw = (string) get_post_meta( $id, '_elementor_data', true );
		if ( '' !== $raw && preg_match_all( '/"url":"(https?:[^"]+?\.(?:jpe?g|png|webp|gif|avif))"/i', $raw, $m ) ) {
			$urls = array_merge( $urls, array_map( 'stripslashes', $m[1] ) );
		}
		if ( 'product' === get_post_type( $id ) ) {
			foreach ( array_filter( explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) as $aid ) {
				$u = wp_get_attachment_image_url( (int) $aid, 'full' );
				if ( $u ) {
					$urls[] = $u;
				}
			}
		}
		$out = array();
		foreach ( $urls as $u ) {
			$u = html_entity_decode( trim( $u ) );
			if ( 0 === strpos( $u, '//' ) ) {
				$u = ( is_ssl() ? 'https:' : 'http:' ) . $u;
			} elseif ( '/' === substr( $u, 0, 1 ) ) {
				$u = home_url( $u );
			}
			if ( preg_match( '#^https?://#', $u ) && false === strpos( $u, 'data:' ) && ! in_array( $u, $out, true ) ) {
				$out[] = $u;
			}
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}

	public static function urlset( $name, $page ) {
		$src = self::sources();
		if ( ! isset( $src[ $name ] ) ) {
			return null;
		}
		$per  = self::per();
		$x    = self::head( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' );
		if ( 'pt' === $src[ $name ] ) {
			$rows = self::posts( $name );
			if ( 'page' === $name && 'posts' === get_option( 'show_on_front' ) ) {
				array_unshift( $rows, array( 0, gmdate( 'Y-m-d H:i:s' ) ) );
			}
			$rows = array_slice( $rows, ( $page - 1 ) * $per, $per );
			if ( ! $rows ) {
				return null;
			}
			_prime_post_caches( array_filter( wp_list_pluck( $rows, 0 ) ), false, false );
			foreach ( $rows as $r ) {
				$loc = $r[0] ? get_permalink( $r[0] ) : home_url( '/' );
				if ( (int) get_option( 'page_on_front' ) === (int) $r[0] ) {
					$loc = home_url( '/' );
				}
				$x .= "\t<url>\n\t\t<loc>" . esc_url( $loc ) . "</loc>\n\t\t<lastmod>" . esc_html( mysql2date( 'c', $r[1], false ) ) . "</lastmod>\n";
				if ( $r[0] ) {
					foreach ( self::images( $r[0] ) as $img ) {
						$x .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_url( $img ) . "</image:loc>\n\t\t</image:image>\n";
					}
				}
				$x .= "\t</url>\n";
			}
		} else {
			$terms = array_slice( self::terms( $name ), ( $page - 1 ) * $per, $per );
			if ( ! $terms ) {
				return null;
			}
			foreach ( $terms as $t ) {
				$link = get_term_link( $t );
				if ( ! is_wp_error( $link ) ) {
					$x .= "\t<url>\n\t\t<loc>" . esc_url( $link ) . "</loc>\n\t</url>\n";
				}
			}
		}
		return $x . '</urlset>';
	}

	private static function xsl() {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/xsl; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}
		echo '<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html><head><meta charset="UTF-8"/><title>XML Sitemap</title>
<style>body{font:14px/1.6 Tahoma,Arial,sans-serif;margin:24px;color:#1d2327;background:#fff}h1{font-size:20px}p{color:#50575e}table{border-collapse:collapse;width:100%}th,td{padding:6px 10px;border-bottom:1px solid #dcdcde;text-align:left;direction:ltr}th{background:#f6f7f7}a{color:#2271b1;text-decoration:none;word-break:break-all}</style></head>
<body><h1>XML Sitemap – Smart SEO AI</h1>
<p>This sitemap is for search engines. Number of entries: <xsl:value-of select="count(s:sitemapindex/s:sitemap) + count(s:urlset/s:url)"/></p>
<table><tr><th>URL</th><th>Images</th><th>Last modified</th></tr>
<xsl:for-each select="s:sitemapindex/s:sitemap"><tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td></td><td><xsl:value-of select="substring(s:lastmod,1,10)"/></td></tr></xsl:for-each>
<xsl:for-each select="s:urlset/s:url"><tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td><xsl:value-of select="count(image:image)"/></td><td><xsl:value-of select="substring(s:lastmod,1,10)"/></td></tr></xsl:for-each>
</table></body></html>
</xsl:template>
</xsl:stylesheet>';
		exit;
	}
}
