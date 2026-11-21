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
		if ( preg_match( '/^(video|news|local)-sitemap\.xml$/', $p, $m ) ) {
			$xml = self::cached( 'x_' . $m[1], 1 );
			if ( null === $xml ) {
				return;
			}
			self::send( $xml );
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
			if ( 'index' === $key ) {
				$xml = self::index();
			} elseif ( 0 === strpos( $key, 'x_' ) ) {
				$fn  = 'special_' . substr( $key, 2 );
				$xml = self::$fn();
			} else {
				$xml = self::urlset( $key, $page );
			}
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
		foreach ( array( 'video', 'news', 'local' ) as $k ) {
			if ( null !== self::cached( 'x_' . $k, 1 ) ) {
				$x .= "\t<sitemap>\n\t\t<loc>" . esc_url( self::url( $k . '-sitemap.xml' ) ) . "</loc>\n\t</sitemap>\n";
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

	/* ------------------------------------------------------------ Video / News / Local */

	/** Videos embedded in a post: list of [ player|content, url, thumb, id ]. Cached per post until it changes. */
	public static function videos( $id ) {
		$mod = (string) get_post_field( 'post_modified_gmt', $id );
		$c   = get_post_meta( $id, '_ssai_videos', true );
		if ( is_array( $c ) && isset( $c['mod'] ) && $c['mod'] === $mod ) {
			return $c['v'];
		}
		$html  = (string) get_post_field( 'post_content', $id ) . ' ' . str_replace( '\/', '/', (string) get_post_meta( $id, '_elementor_data', true ) );
		$thumb = (int) get_post_thumbnail_id( $id ) ? (string) wp_get_attachment_image_url( (int) get_post_thumbnail_id( $id ), 'full' ) : '';
		$v     = array();
		if ( preg_match_all( '#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^"\'\s<]*?&(?:amp;)?)?v=|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $html, $m ) ) {
			foreach ( array_unique( $m[1] ) as $yid ) {
				$v[ 'yt' . $yid ] = array( 'player', 'https://www.youtube.com/embed/' . $yid, 'https://i.ytimg.com/vi/' . $yid . '/hqdefault.jpg', $yid );
			}
		}
		if ( preg_match_all( '#(?:player\.)?vimeo\.com/(?:video/)?(\d{5,})#', $html, $m ) ) {
			foreach ( array_unique( $m[1] ) as $vid ) {
				$v[ 'vm' . $vid ] = array( 'player', 'https://player.vimeo.com/video/' . $vid, $thumb, $vid );
			}
		}
		if ( preg_match_all( '#aparat\.com/(?:v/|video/video/embed/videohash/)([A-Za-z0-9]{3,12})#', $html, $m ) ) {
			foreach ( array_unique( $m[1] ) as $aid ) {
				$v[ 'ap' . $aid ] = array( 'player', 'https://www.aparat.com/video/video/embed/videohash/' . $aid . '/vt/frame', $thumb, $aid );
			}
		}
		if ( preg_match_all( '#(https?://[^"\'\s<>\]]+?\.(?:mp4|webm|mov|m4v|ogv))#i', $html, $m ) ) {
			$poster = preg_match( '#poster=["\']([^"\']+)#i', $html, $pm ) ? html_entity_decode( $pm[1] ) : $thumb;
			foreach ( array_unique( $m[1] ) as $u ) {
				$v[ 'f' . md5( $u ) ] = array( 'content', html_entity_decode( $u ), $poster, '' );
			}
		}
		$v = array_slice( array_values( $v ), 0, 10 );
		update_post_meta( $id, '_ssai_videos', array( 'mod' => $mod, 'v' => $v ) );
		return $v;
	}

	private static function x( $s ) {
		return htmlspecialchars( wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ), ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	private static function special_video() {
		$s = SSAI_SEO::get( 'sitemap' );
		if ( empty( $s['video'] ) ) {
			return null;
		}
		$fallback = (string) SSAI_SEO::get( 'og_image' );
		$body     = '';
		$n        = 0;
		foreach ( self::sources() as $name => $kind ) {
			if ( 'pt' !== $kind ) {
				continue;
			}
			foreach ( self::posts( $name ) as $r ) {
				$vids = self::videos( $r[0] );
				if ( ! $vids ) {
					continue;
				}
				$title = get_the_title( $r[0] );
				$desc  = (string) SSAI_Content::get_meta_description( $r[0] );
				if ( '' === trim( $desc ) ) {
					$desc = wp_trim_words( preg_replace( '#https?://\S+#', '', wp_strip_all_tags( strip_shortcodes( (string) get_post_field( 'post_content', $r[0] ) ) ) ), 40, '…' );
				}
				$desc = '' !== trim( $desc ) ? $desc : $title;
				$body .= "\t<url>\n\t\t<loc>" . esc_url( get_permalink( $r[0] ) ) . "</loc>\n";
				foreach ( $vids as $vd ) {
					$th = '' !== $vd[2] ? $vd[2] : $fallback;
					if ( '' === $th ) {
						continue; // Google requires a thumbnail.
					}
					$body .= "\t\t<video:video>\n\t\t\t<video:thumbnail_loc>" . esc_url( $th ) . "</video:thumbnail_loc>\n\t\t\t<video:title>" . self::x( $title ) . "</video:title>\n\t\t\t<video:description>" . self::x( mb_substr( $desc, 0, 2000 ) ) . "</video:description>\n";
					$body .= 'player' === $vd[0] ? "\t\t\t<video:player_loc>" . esc_url( $vd[1] ) . "</video:player_loc>\n" : "\t\t\t<video:content_loc>" . esc_url( $vd[1] ) . "</video:content_loc>\n";
					$body .= "\t\t\t<video:publication_date>" . esc_html( get_post_time( 'c', true, $r[0] ) ) . "</video:publication_date>\n\t\t</video:video>\n";
					++$n;
				}
				$body .= "\t</url>\n";
			}
		}
		if ( ! $n ) {
			return null;
		}
		return self::head( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' ) . $body . '</urlset>';
	}

	private static function special_news() {
		$s = SSAI_SEO::get( 'sitemap' );
		if ( empty( $s['news'] ) ) {
			return null;
		}
		$types = array_values( array_intersect( (array) $s['news_pt'], SSAI_Admin::post_types() ) );
		if ( ! $types ) {
			return null;
		}
		$name = '' !== trim( (string) $s['news_name'] ) ? $s['news_name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$lang = strtolower( substr( get_locale(), 0, 2 ) );
		$q    = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'date_query'     => array( array( 'after' => '48 hours ago', 'column' => 'post_date_gmt' ) ),
				'has_password'   => false,
				'fields'         => 'ids',
				'lang'           => '',
			)
		);
		$x = self::head( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' );
		foreach ( $q as $id ) {
			$rob = SSAI_SEO::post_robots( $id );
			if ( $rob['noindex'] ) {
				continue;
			}
			$x .= "\t<url>\n\t\t<loc>" . esc_url( get_permalink( $id ) ) . "</loc>\n\t\t<news:news>\n\t\t\t<news:publication>\n\t\t\t\t<news:name>" . self::x( $name ) . "</news:name>\n\t\t\t\t<news:language>" . esc_html( $lang ) . "</news:language>\n\t\t\t</news:publication>\n\t\t\t<news:publication_date>" . esc_html( get_post_time( 'c', true, $id ) ) . "</news:publication_date>\n\t\t\t<news:title>" . self::x( get_the_title( $id ) ) . "</news:title>\n\t\t</news:news>\n\t</url>\n";
		}
		// An empty news sitemap is valid; keep it listed so Search Console does not report it missing.
		return $x . '</urlset>';
	}

	private static function special_local() {
		if ( ! class_exists( 'SSAI_Local' ) || ! SSAI_Local::kml_points() ) {
			return null;
		}
		return self::head( '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' ) . "\t<url>\n\t\t<loc>" . esc_url( SSAI_Local::kml_url() ) . "</loc>\n\t\t<lastmod>" . esc_html( gmdate( 'c', (int) get_option( self::VER, time() ) ) ) . "</lastmod>\n\t</url>\n</urlset>";
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
