<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moving from Rank Math: import settings, per-post / per-term data, redirects and FAQ blocks,
 * compare the live output with ours, then deactivate Rank Math (and re-activate if needed).
 * Rank Math's own data is never deleted.
 */
class SSAI_Migrate {

	const OPT_REPORT  = 'ssai_migrate_report';
	const OPT_COMPARE = 'ssai_migrate_compare';

	public static function init() {
		add_action( 'admin_post_ssai_migrate', array( __CLASS__, 'handle' ) );
	}

	public static function rm_active() {
		return defined( 'RANK_MATH_VERSION' );
	}

	public static function rm_has_data() {
		global $wpdb;
		return (bool) get_option( 'rank-math-options-titles' ) || (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%' LIMIT 1" ); // phpcs:ignore WordPress.DB
	}

	/** Installed Rank Math plugin files. */
	public static function rm_files() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( array_keys( get_plugins() ) as $f ) {
			if ( 0 === strpos( $f, 'seo-by-rank-math' ) ) {
				$out[] = $f;
			}
		}
		// Pro first when deactivating (it depends on the free plugin).
		usort(
			$out,
			function ( $a, $b ) {
				return ( false !== strpos( $b, 'pro' ) ) - ( false !== strpos( $a, 'pro' ) );
			}
		);
		return $out;
	}

	/** Rank Math options that change URLs and are not reproduced here – deactivating would break links. */
	public static function blockers() {
		$g   = (array) get_option( 'rank-math-options-general', array() );
		$out = array();
		$on  = function ( $k ) use ( $g ) {
			return isset( $g[ $k ] ) && 'on' === $g[ $k ];
		};
		if ( $on( 'wc_remove_product_base' ) ) {
			$out[] = 'حذف پایه آدرس محصولات ووکامرس (Remove base از Rank Math › WooCommerce) – با غیرفعال کردن، آدرس همه محصولات عوض می‌شود.';
		}
		if ( $on( 'wc_remove_category_base' ) ) {
			$out[] = 'حذف پایه آدرس دسته‌های محصول (Remove category base در WooCommerce) – آدرس دسته‌های محصول عوض می‌شود.';
		}
		if ( $on( 'wc_remove_category_parent_slugs' ) ) {
			$out[] = 'حذف نامک دسته والد از آدرس دسته‌های محصول – آدرس‌ها عوض می‌شوند.';
		}
		return $out;
	}

	/* ================================================================ Imports */

	public static function import_settings() {
		$t   = (array) get_option( 'rank-math-options-titles', array() );
		$g   = (array) get_option( 'rank-math-options-general', array() );
		$sm  = (array) get_option( 'rank-math-options-sitemap', array() );
		$o   = SSAI_SEO::get();
		$log = array();
		$get = function ( $arr, $k, $def = '' ) {
			return isset( $arr[ $k ] ) && '' !== $arr[ $k ] ? $arr[ $k ] : $def;
		};
		$on  = function ( $arr, $k ) {
			return isset( $arr[ $k ] ) && 'on' === $arr[ $k ];
		};
		if ( $t ) {
			$o['sep']        = $get( $t, 'title_separator', $o['sep'] );
			$o['home_title'] = $get( $t, 'homepage_title', $o['home_title'] );
			$o['home_desc']  = $get( $t, 'homepage_description', $o['home_desc'] );
			$tpl             = (array) $o['tpl'];
			foreach ( SSAI_Admin::post_types() as $pt ) {
				$row = SSAI_SEO::tpl( 'pt_' . $pt );
				$row['title'] = $get( $t, "pt_{$pt}_title", $row['title'] );
				$row['desc']  = $get( $t, "pt_{$pt}_description", $row['desc'] );
				if ( $on( $t, "pt_{$pt}_custom_robots" ) && isset( $t[ "pt_{$pt}_robots" ] ) ) {
					$row['noindex'] = in_array( 'noindex', (array) $t[ "pt_{$pt}_robots" ], true ) ? 1 : 0;
				}
				$tpl[ 'pt_' . $pt ] = $row;
				$rs                 = $get( $t, "pt_{$pt}_default_rich_snippet" );
				if ( 'off' === $rs ) {
					$o['schema_pt'][ $pt ] = 'none';
				} elseif ( 'article' === $rs ) {
					$o['schema_pt'][ $pt ] = $get( $t, "pt_{$pt}_default_article_type", 'Article' );
				} elseif ( 'product' === $rs || 'woocommerce_product' === $rs ) {
					$o['schema_pt'][ $pt ] = 'Product';
				} elseif ( 'service' === $rs ) {
					$o['schema_pt'][ $pt ] = 'Service';
				}
			}
			foreach ( array_keys( SSAI_SEO::taxonomies() ) as $tax ) {
				$row          = SSAI_SEO::tpl( 'tax_' . $tax );
				$row['title'] = $get( $t, "tax_{$tax}_title", $row['title'] );
				$row['desc']  = $get( $t, "tax_{$tax}_description", $row['desc'] );
				if ( $on( $t, "tax_{$tax}_custom_robots" ) && isset( $t[ "tax_{$tax}_robots" ] ) ) {
					$row['noindex'] = in_array( 'noindex', (array) $t[ "tax_{$tax}_robots" ], true ) ? 1 : 0;
				}
				$tpl[ 'tax_' . $tax ] = $row;
			}
			$a                = SSAI_SEO::tpl( 'author' );
			$a['title']       = $get( $t, 'author_archive_title', $a['title'] );
			$a['noindex']     = $on( $t, 'disable_author_archives' ) || ( $on( $t, 'author_custom_robots' ) && in_array( 'noindex', (array) $get( $t, 'author_robots', array() ), true ) ) ? 1 : $a['noindex'];
			$tpl['author']    = $a;
			$d                = SSAI_SEO::tpl( 'date' );
			$d['title']       = $get( $t, 'date_archive_title', $d['title'] );
			$tpl['date']      = $d;
			$s                = SSAI_SEO::tpl( 'search' );
			$s['title']       = $get( $t, 'search_title', $s['title'] );
			$tpl['search']    = $s;
			$f                = SSAI_SEO::tpl( '404' );
			$f['title']       = $get( $t, '404_title', $f['title'] );
			$tpl['404']       = $f;
			$o['tpl']         = $tpl;
			$o['noindex_empty'] = $on( $t, 'noindex_empty_taxonomies' ) ? 1 : 0;
			$log[]            = 'الگوهای عنوان و توضیحات';

			// Business / Knowledge Graph.
			$e         = $o['entity'];
			$kg        = $get( $t, 'knowledgegraph_type', 'company' );
			$e['type'] = 'person' === $kg ? 'Person' : ( $get( $t, 'local_business_type' ) && 'Organization' !== $get( $t, 'local_business_type' ) ? 'LocalBusiness' : 'Organization' );
			$e['subtype'] = 'LocalBusiness' === $e['type'] ? $get( $t, 'local_business_type' ) : '';
			$e['name']    = $get( $t, 'knowledgegraph_name', $e['name'] );
			$e['logo']    = $get( $t, 'knowledgegraph_logo', $e['logo'] );
			$e['email']   = $get( $t, 'email', $e['email'] );
			$e['price_range'] = $get( $t, 'price_range', $e['price_range'] );
			$phones       = $get( $t, 'phone_numbers', array() );
			if ( is_array( $phones ) && $phones ) {
				$first      = reset( $phones );
				$e['phone'] = is_array( $first ) && isset( $first['number'] ) ? $first['number'] : (string) $first;
			} elseif ( $get( $t, 'phone' ) ) {
				$e['phone'] = $get( $t, 'phone' );
			}
			$addr = $get( $t, 'local_address', array() );
			if ( is_array( $addr ) ) {
				$e['street']  = isset( $addr['streetAddress'] ) ? $addr['streetAddress'] : $e['street'];
				$e['city']    = isset( $addr['addressLocality'] ) ? $addr['addressLocality'] : $e['city'];
				$e['region']  = isset( $addr['addressRegion'] ) ? $addr['addressRegion'] : $e['region'];
				$e['postal']  = isset( $addr['postalCode'] ) ? $addr['postalCode'] : $e['postal'];
				$e['country'] = isset( $addr['addressCountry'] ) ? $addr['addressCountry'] : $e['country'];
			}
			$geo = $get( $t, 'geo' );
			if ( is_string( $geo ) && false !== strpos( $geo, ',' ) ) {
				list( $e['lat'], $e['lng'] ) = array_map( 'trim', explode( ',', $geo, 2 ) );
			}
			$hours = $get( $t, 'opening_hours', array() );
			if ( is_array( $hours ) && $hours ) {
				$lines = array();
				foreach ( $hours as $h ) {
					if ( ! empty( $h['day'] ) && ! empty( $h['time'] ) ) {
						$lines[] = $h['day'] . ' ' . $h['time'];
					}
				}
				$e['hours'] = implode( "\n", $lines );
			}
			$o['entity'] = $e;
			$log[]       = 'اطلاعات کسب‌وکار / Knowledge Graph';

			$o['facebook'] = $get( $t, 'social_url_facebook', $o['facebook'] );
			$o['twitter']  = $get( $t, 'twitter_author_names', $o['twitter'] );
			$o['same_as']  = trim( $get( $t, 'social_additional_profiles', $o['same_as'] ) );
			$o['og_image'] = $get( $t, 'open_graph_image', $o['og_image'] );
			$log[]         = 'شبکه‌های اجتماعی و تصویر پیش‌فرض';
		}
		if ( $g ) {
			foreach ( array( 'google', 'bing', 'yandex', 'baidu', 'pinterest' ) as $k ) {
				$o['verify'][ $k ] = $get( $g, $k . '_verify', $o['verify'][ $k ] );
			}
			$o['crumb']['sep']  = $get( $g, 'breadcrumbs_separator', $o['crumb']['sep'] );
			$o['crumb']['home'] = $get( $g, 'breadcrumbs_home_label', $o['crumb']['home'] );
			$o['attachment_redirect'] = $on( $g, 'attachment_redirect_urls' ) ? 1 : 0;
			$o['strip_category']      = $on( $g, 'strip_category_base' ) ? 1 : 0;
			$robots = $get( $g, 'robots_txt_content' );
			if ( is_string( $robots ) && '' !== trim( $robots ) ) {
				$o['robots_txt'] = $robots;
			}
			$o['modules']['auto_redirect'] = ! isset( $g['redirections_post_redirect'] ) || $on( $g, 'redirections_post_redirect' ) ? 1 : 0;
			$log[] = 'کدهای تأیید، Breadcrumb، robots.txt و تنظیمات عمومی';
		}
		if ( $sm ) {
			$o['sitemap']['per_page'] = (int) $get( $sm, 'items_per_page', $o['sitemap']['per_page'] );
			$o['sitemap']['images']   = $on( $sm, 'include_images' ) ? 1 : 0;
			$o['sitemap']['exclude']  = (string) $get( $sm, 'exclude_posts', '' );
			$off = array();
			foreach ( SSAI_Admin::post_types() as $pt ) {
				if ( isset( $sm[ "pt_{$pt}_sitemap" ] ) && 'on' !== $sm[ "pt_{$pt}_sitemap" ] ) {
					$off[] = 'pt_' . $pt;
				}
			}
			foreach ( array_keys( SSAI_SEO::taxonomies() ) as $tax ) {
				if ( isset( $sm[ "tax_{$tax}_sitemap" ] ) ? 'on' !== $sm[ "tax_{$tax}_sitemap" ] : in_array( 'tax_' . $tax, (array) $o['sitemap']['off'], true ) ) {
					$off[] = 'tax_' . $tax;
				}
			}
			$o['sitemap']['off'] = $off;
			$log[]               = 'تنظیمات نقشه سایت';
		}
		$tpls = SSAI_Schema::rm_templates();
		if ( $tpls ) {
			update_option( 'ssai_schema_templates', $tpls, false );
			$log[] = sprintf( '%d قالب Schema (Schema Templates)', count( $tpls ) );
		}
		$o['migrated'] = time();
		SSAI_SEO::save( $o );
		if ( ! empty( $o['strip_category'] ) ) {
			SSAI_Permalinks::schedule_flush();
		}
		return $log;
	}

	/** Copy Rank Math post meta into ours where ours is empty. */
	public static function import_posts() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" ); // phpcs:ignore WordPress.DB
		$map = array(
			'rank_math_title'                => SSAI_Content::META_TITLE,
			'rank_math_description'          => SSAI_Content::META_DESC,
			'rank_math_focus_keyword'        => SSAI_Content::META_KW,
			'rank_math_canonical_url'        => SSAI_SEO::M_CANON,
			'rank_math_facebook_title'       => SSAI_SEO::M_OGTITLE,
			'rank_math_facebook_description' => SSAI_SEO::M_OGDESC,
			'rank_math_facebook_image'       => SSAI_SEO::M_OGIMG,
		);
		$n   = 0;
		foreach ( $ids as $id ) {
			$id      = (int) $id;
			$changed = false;
			foreach ( $map as $from => $to ) {
				$v = get_post_meta( $id, $from, true );
				if ( '' !== (string) $v && '' === (string) get_post_meta( $id, $to, true ) ) {
					update_post_meta( $id, $to, wp_slash( $v ) );
					$changed = true;
				}
			}
			$rob = get_post_meta( $id, 'rank_math_robots', true );
			if ( is_array( $rob ) && ! get_post_meta( $id, SSAI_SEO::M_ROBOTS, true ) ) {
				$r = array_values( array_intersect( $rob, array( 'noindex', 'nofollow' ) ) );
				if ( ! in_array( 'noindex', $r, true ) && in_array( 'index', $rob, true ) ) {
					$tpl = SSAI_SEO::tpl( 'pt_' . get_post_type( $id ) );
					if ( ! empty( $tpl['noindex'] ) ) {
						$r[] = 'index';
					}
				}
				if ( $r ) {
					update_post_meta( $id, SSAI_SEO::M_ROBOTS, $r );
					$changed = true;
				}
			}
			foreach ( array( 'category', 'product_cat' ) as $tax ) {
				$p = (int) get_post_meta( $id, 'rank_math_primary_' . $tax, true );
				if ( $p && ! get_post_meta( $id, SSAI_SEO::M_PRIMARY, true ) ) {
					update_post_meta( $id, SSAI_SEO::M_PRIMARY, $p );
					$changed = true;
				}
			}
			$rs = (string) get_post_meta( $id, 'rank_math_rich_snippet', true );
			if ( '' !== $rs && ! get_post_meta( $id, SSAI_SEO::M_SCHEMA, true ) ) {
				$type = 'off' === $rs ? 'none' : ( 'article' === $rs ? ( get_post_meta( $id, 'rank_math_snippet_article_type', true ) ? get_post_meta( $id, 'rank_math_snippet_article_type', true ) : 'Article' ) : ( 'service' === $rs ? 'Service' : '' ) );
				if ( $type ) {
					update_post_meta( $id, SSAI_SEO::M_SCHEMA, $type );
					$changed = true;
				}
			}
			if ( ! get_post_meta( $id, '_ssai_schema_custom', true ) ) {
				$custom = SSAI_Schema::raw_custom( $id );
				if ( $custom ) {
					update_post_meta( $id, '_ssai_schema_custom', $custom );
					$changed = true;
				}
			}
			if ( $changed ) {
				$n++;
				delete_transient( 'ssai_row_' . $id );
			}
		}
		return $n;
	}

	public static function import_terms() {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT DISTINCT term_id FROM {$wpdb->termmeta} WHERE meta_key LIKE 'rank\\_math\\_%'" ); // phpcs:ignore WordPress.DB
		$n   = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			$c  = false;
			foreach ( array( 'rank_math_title' => SSAI_SEO::T_TITLE, 'rank_math_description' => SSAI_SEO::T_DESC ) as $from => $to ) {
				$v = (string) get_term_meta( $id, $from, true );
				if ( '' !== $v && '' === (string) get_term_meta( $id, $to, true ) ) {
					update_term_meta( $id, $to, $v );
					$c = true;
				}
			}
			$rob = get_term_meta( $id, 'rank_math_robots', true );
			if ( is_array( $rob ) && '' === (string) get_term_meta( $id, SSAI_SEO::T_NOINDEX, true ) && in_array( 'noindex', $rob, true ) ) {
				update_term_meta( $id, SSAI_SEO::T_NOINDEX, '1' );
				$c = true;
			}
			$n += $c ? 1 : 0;
		}
		return $n;
	}

	/* ================================================================ Rank Math blocks */

	/** Posts whose content uses Rank Math blocks / shortcodes (they stop rendering when it is off). */
	public static function block_posts() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future') AND ( post_content LIKE '%wp:rank-math/%' OR post_content LIKE '%[rank_math_%' ) LIMIT 500" ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			preg_match_all( '/wp:rank-math\/([a-z\-]+)|\[(rank_math_[a-z_]+)/', $r->post_content, $m );
			$out[ (int) $r->ID ] = array_values( array_unique( array_filter( array_merge( $m[1], $m[2] ) ) ) );
		}
		return $out;
	}

	/** Turn Rank Math FAQ blocks into our FAQ (visible text + FAQPage schema). @return int converted posts */
	public static function convert_faq_blocks() {
		$n = 0;
		foreach ( self::block_posts() as $id => $kinds ) {
			if ( ! in_array( 'faq-block', $kinds, true ) ) {
				continue;
			}
			$post   = get_post( $id );
			$blocks = parse_blocks( $post->post_content );
			$faq    = SSAI_AEO::get_faq( $id );
			$done   = false;
			$walk   = function ( &$list ) use ( &$walk, &$faq, &$done ) {
				foreach ( $list as &$b ) {
					if ( 'rank-math/faq-block' === $b['blockName'] ) {
						foreach ( isset( $b['attrs']['questions'] ) ? (array) $b['attrs']['questions'] : array() as $q ) {
							if ( isset( $q['visible'] ) && ! $q['visible'] ) {
								continue;
							}
							$qq = isset( $q['title'] ) ? wp_strip_all_tags( $q['title'] ) : '';
							$aa = isset( $q['content'] ) ? wp_kses( $q['content'], SSAI_AEO::faq_tags() ) : '';
							if ( '' !== $qq && '' !== $aa ) {
								$faq[] = array(
									'q' => $qq,
									'a' => $aa,
								);
							}
						}
						$b    = array(
							'blockName'    => 'core/shortcode',
							'attrs'        => array(),
							'innerBlocks'  => array(),
							'innerHTML'    => $done ? '' : '[ssai_faq title=""]',
							'innerContent' => array( $done ? '' : '[ssai_faq title=""]' ),
						);
						$done = true;
					} elseif ( ! empty( $b['innerBlocks'] ) ) {
						$walk( $b['innerBlocks'] );
					}
				}
			};
			$walk( $blocks );
			if ( ! $done ) {
				continue;
			}
			update_post_meta( $id, SSAI_AEO::META_FAQ, wp_slash( $faq ) );
			$r = SSAI_Applier::apply( $id, 'content', serialize_blocks( $blocks ) );
			if ( ! is_wp_error( $r ) ) {
				$n++;
			}
		}
		return $n;
	}

	/* ================================================================ Compare live output with ours */

	private static function samples() {
		$s     = array();
		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front ) {
			$s[] = array( 'type' => 'post', 'id' => $front, 'front' => true, 'paged' => 1 );
		} else {
			$s[] = array( 'type' => 'home', 'paged' => 1 );
		}
		foreach ( array( 'page' => 3, 'post' => 3, 'product' => 2 ) as $pt => $n ) {
			if ( ! post_type_exists( $pt ) ) {
				continue;
			}
			foreach ( get_posts( array( 'post_type' => $pt, 'post_status' => 'publish', 'numberposts' => $n, 'exclude' => array( $front ), 'fields' => 'ids', 'has_password' => false ) ) as $id ) {
				$s[] = array( 'type' => 'post', 'id' => (int) $id, 'paged' => 1 );
			}
		}
		foreach ( array( 'category', 'product_cat' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$t = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 1 ) );
				if ( $t && ! is_wp_error( $t ) ) {
					$s[] = array( 'type' => 'term', 'id' => (int) $t[0]->term_id, 'tax' => $tax, 'paged' => 1 );
				}
			}
		}
		return $s;
	}

	private static function parse_head( $html ) {
		$x = array(
			'title'     => '',
			'desc'      => '',
			'canonical' => '',
			'noindex'   => false,
			'og_image'  => '',
			'schema'    => array(),
		);
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
			$x['title'] = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m ) || preg_match( '/<meta[^>]+content=["\']([^"\']*)["\'][^>]*name=["\']description["\']/i', $html, $m ) ) {
			$x['desc'] = trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
		}
		if ( preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]*href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$x['canonical'] = html_entity_decode( $m[1] );
		}
		if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m ) ) {
			$x['noindex'] = false !== stripos( $m[1], 'noindex' );
		}
		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m ) ) {
			$x['og_image'] = $m[1];
		}
		$x['schema'] = array();
		$x['other']  = array();
		if ( preg_match_all( '#<script([^>]*type=["\']application/ld\+json["\'][^>]*)>(.*?)</script>#is', $html, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $sc ) {
				$types = SSAI_AEO::schema( '<script type="application/ld+json">' . $sc[2] . '</script>' )['types'];
				// Rank Math / Yoast / this plugin vs. JSON-LD placed in the page itself (stays after switching).
				if ( preg_match( '/rank-math|yoast|ssai-schema|aioseo/i', $sc[1] ) ) {
					$x['schema'] = array_merge( $x['schema'], $types );
				} else {
					$x['other'] = array_merge( $x['other'], $types );
				}
			}
		}
		$x['schema'] = array_values( array_unique( array_merge( $x['schema'], $x['other'] ) ) );
		return $x;
	}

	private static function types_of( $graph ) {
		// Same recursive walk as for the live page, so nested types (City, Offer…) are counted too.
		return SSAI_AEO::schema( '<script type="application/ld+json">' . wp_json_encode( array( '@graph' => $graph ) ) . '</script>' )['types'];
	}

	private static function top_types_of( $graph ) {
		$t = array();
		foreach ( $graph as $n ) {
			foreach ( (array) ( isset( $n['@type'] ) ? $n['@type'] : array() ) as $ty ) {
				$t[] = $ty;
			}
		}
		return array_values( array_unique( $t ) );
	}

	public static function compare() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore
		}
		$rows = array();
		foreach ( self::samples() as $ctx ) {
			$url = 'post' === $ctx['type'] ? ( ! empty( $ctx['front'] ) ? home_url( '/' ) : get_permalink( $ctx['id'] ) ) : ( 'term' === $ctx['type'] ? get_term_link( $ctx['id'] ) : home_url( '/' ) );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$res  = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => false, 'limit_response_size' => 800000 ) );
			$live = is_wp_error( $res ) ? null : self::parse_head( (string) wp_remote_retrieve_body( $res ) );
			$m    = SSAI_SEO::meta( $ctx );
			$ours = array(
				'title'     => $m['title'],
				'desc'      => $m['desc'],
				'canonical' => $m['canonical'],
				'noindex'   => $m['noindex'],
				'og_image'  => $m['image'] ? $m['image']['url'] : '',
				'schema'    => self::types_of( SSAI_Schema::graph( $ctx ) ),
			);
			if ( $live && ! empty( $live['other'] ) ) {
				// JSON-LD inside the page content (e.g. an Elementor HTML widget) stays after the switch.
				$ours['schema'] = array_values( array_unique( array_merge( $ours['schema'], $live['other'] ) ) );
				$ours['in_page'] = $live['other'];
			}
			$rows[] = array(
				'url'  => $url,
				'live' => $live,
				'ours' => $ours,
				'err'  => is_wp_error( $res ) ? $res->get_error_message() : '',
			);
		}
		$out = array(
			'time'   => time(),
			'rm'     => self::rm_active(),
			'rows'   => $rows,
		);
		update_option( self::OPT_COMPARE, $out, false );
		return $out;
	}

	public static function same( $field, $a, $b ) {
		if ( 'canonical' === $field ) {
			return untrailingslashit( rawurldecode( (string) $a ) ) === untrailingslashit( rawurldecode( (string) $b ) );
		}
		if ( 'noindex' === $field ) {
			return (bool) $a === (bool) $b;
		}
		if ( 'og_image' === $field ) {
			return ( '' === (string) $a ) === ( '' === (string) $b );
		}
		$n = function ( $s ) {
			return SSAI_Content::norm( str_replace( array( '–', '—', '|', '»', '·' ), '-', (string) $s ) );
		};
		return $n( $a ) === $n( $b );
	}

	/* ================================================================ Schema diagnostics */

	/** Where every schema type of one page comes from. */
	public static function diagnose( $post_id ) {
		$out  = array(
			'id'        => $post_id,
			'title'     => get_the_title( $post_id ),
			'meta'      => array(),
			'own'       => array(),
			'templates' => array(),
			'content'   => false,
			'scripts'   => array(),
		);
		foreach ( get_post_meta( $post_id ) as $k => $vals ) {
			if ( 0 === strpos( $k, 'rank_math_schema_' ) ) {
				$v               = maybe_unserialize( $vals[0] );
				$out['meta'][ $k ] = is_array( $v ) && isset( $v['@type'] ) ? implode( ',', (array) $v['@type'] ) : '?';
			}
		}
		foreach ( (array) get_post_meta( $post_id, '_ssai_schema_custom', true ) as $v ) {
			if ( is_array( $v ) && isset( $v['@type'] ) ) {
				$out['own'][] = implode( ',', (array) $v['@type'] );
			}
		}
		foreach ( SSAI_Schema::rm_templates() as $t ) {
			$out['templates'][] = array(
				'title' => $t['title'],
				'type'  => implode( ',', (array) $t['schema']['@type'] ),
				'match' => SSAI_Schema::template_matches( $t, $post_id ),
				'cond'  => wp_json_encode( $t['conditions'], JSON_UNESCAPED_UNICODE ),
			);
		}
		$raw            = (string) get_post_field( 'post_content', $post_id ) . (string) get_post_meta( $post_id, '_elementor_data', true );
		$out['content'] = false !== stripos( $raw, 'ld+json' ) || false !== stripos( $raw, 'ld\\+json' );
		$res            = wp_remote_get( get_permalink( $post_id ), array( 'timeout' => 15, 'sslverify' => false, 'limit_response_size' => 800000 ) );
		if ( ! is_wp_error( $res ) && preg_match_all( '#<script([^>]*type=["\']application/ld\+json["\'][^>]*)>(.*?)</script>#is', (string) wp_remote_retrieve_body( $res ), $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $sc ) {
				$out['scripts'][] = array(
					'attrs' => trim( preg_replace( '/\s+/', ' ', $sc[1] ) ),
					'types' => implode( ', ', SSAI_AEO::schema( '<script type="application/ld+json">' . $sc[2] . '</script>' )['types'] ),
				);
			}
		}
		update_option( 'ssai_schema_diag', $out, false );
		return $out;
	}

	/* ================================================================ Actions */

	public static function handle() {
		check_admin_referer( 'ssai_migrate' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = '';
		$typ = 'success';
		switch ( $do ) {
			case 'import':
				$log   = self::import_settings();
				$posts = self::import_posts();
				$terms = self::import_terms();
				SSAI_Redirects::install();
				$red   = SSAI_Redirects::import_rankmath() + SSAI_Redirects::import_redirection();
				update_option(
					self::OPT_REPORT,
					array(
						'time'  => time(),
						'items' => $log,
						'posts' => $posts,
						'terms' => $terms,
						'redir' => $red,
					),
					false
				);
				SSAI_Sitemap::bump();
				$msg = sprintf( 'انتقال انجام شد: %d صفحه، %d دسته و %d ریدایرکت. حالا «مقایسه خروجی» را بزنید.', $posts, $terms, $red );
				break;
			case 'faq':
				$msg = sprintf( '%d صفحه با بلوک FAQ رنک‌مث تبدیل شد.', self::convert_faq_blocks() );
				break;
			case 'diagnose':
				$ref = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
				$pid = ctype_digit( $ref ) ? (int) $ref : url_to_postid( $ref );
				if ( ! $pid && $ref ) {
					$pid = SSAI_GSC_Import::find_post( $ref );
				}
				if ( ! $pid || ! get_post( $pid ) ) {
					$msg = 'صفحه پیدا نشد؛ آدرس کامل یا شناسه صفحه را وارد کنید.';
					$typ = 'error';
					break;
				}
				self::diagnose( $pid );
				$msg = 'تشخیص Schema انجام شد؛ نتیجه پایین صفحه است.';
				break;
			case 'compare':
				self::compare();
				$msg = 'مقایسه انجام شد؛ نتیجه پایین صفحه است.';
				break;
			case 'deactivate':
				if ( self::blockers() ) {
					$msg = 'Rank Math غیرفعال نشد: تنظیمی دارد که آدرس‌ها را تغییر می‌دهد (پایین صفحه را ببینید).';
					$typ = 'error';
					break;
				}
				if ( ! get_option( self::OPT_REPORT ) ) {
					$msg = 'اول «انتقال اطلاعات» را انجام دهید.';
					$typ = 'error';
					break;
				}
				deactivate_plugins( self::rm_files() );
				update_option( 'ssai_flush_rules', 1, true );
				SSAI_Sitemap::bump();
				$msg = 'Rank Math غیرفعال شد و Smart SEO AI همه کارها را بر عهده گرفت. اطلاعات Rank Math پاک نشده؛ هر وقت لازم شد می‌توانید دوباره فعالش کنید.';
				break;
			case 'activate':
				foreach ( array_reverse( self::rm_files() ) as $f ) {
					activate_plugin( $f );
				}
				update_option( 'ssai_flush_rules', 1, true );
				$msg = 'Rank Math دوباره فعال شد؛ خروجی‌های سئوی این افزونه خودکار متوقف شدند.';
				break;
		}
		set_transient(
			'ssai_seo_notice_' . get_current_user_id(),
			array(
				'msg'  => $msg ? $msg : 'عمل نامعتبر.',
				'type' => $msg ? $typ : 'error',
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=smart-seo-ai-seo&tab=migrate' ) );
		exit;
	}
}
