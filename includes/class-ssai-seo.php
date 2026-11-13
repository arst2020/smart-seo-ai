<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO core that replaces Rank Math / Yoast: titles, meta description, canonical, robots,
 * Open Graph / Twitter, verification codes, breadcrumbs, robots.txt, attachment redirects,
 * per-post and per-term settings, and multilingual (WPML / Polylang) helpers.
 *
 * Nothing is printed while another SEO plugin is active – the modules switch on by
 * themselves when Rank Math / Yoast is deactivated.
 */
class SSAI_SEO {

	const OPT = 'ssai_seo';

	const M_ROBOTS  = '_ssai_robots';      // array( 'noindex', 'nofollow' ).
	const M_CANON   = '_ssai_canonical';
	const M_FORCE_CANON = '_ssai_force_canonical'; // Page printed no canonical: this plugin prints a self-referencing one.
	const M_OGTITLE = '_ssai_og_title';
	const M_OGDESC  = '_ssai_og_desc';
	const M_OGIMG   = '_ssai_og_image';
	const M_PRIMARY = '_ssai_primary_term';
	const M_SCHEMA  = '_ssai_schema';      // auto | none | Article | BlogPosting | NewsArticle | Service | WebPage.

	const T_TITLE   = '_ssai_title';       // Term meta.
	const T_DESC    = '_ssai_desc';
	const T_NOINDEX = '_ssai_noindex';

	private static $cache = array();

	/* ================================================================ Settings */

	public static function defaults() {
		return array(
			'modules'      => array(
				'head'          => 1,
				'schema'        => 1,
				'sitemap'       => 1,
				'redirects'     => 1,
				'monitor404'    => 1,
				'auto_redirect' => 1,
				'breadcrumbs'   => 1,
				'robots_txt'    => 1,
			),
			'sep'          => '-',
			'home_title'   => '%sitename% %page% %sep% %sitedesc%',
			'home_desc'    => '%sitedesc%',
			'tpl'          => array(), // key => array( title, desc, noindex ) – post types "pt_x", taxonomies "tax_x", author, date, search, 404.
			'noindex_empty'  => 1,
			'attachment_redirect' => 1,
			'strip_category' => 0,
			'og_image'     => '',
			'facebook'     => '',
			'twitter'      => '',
			'same_as'      => '',
			'entity'       => array(
				'type'        => 'Organization', // Organization | LocalBusiness | Person.
				'subtype'     => '',             // e.g. Dentist, Store, MedicalClinic.
				'name'        => '',
				'logo'        => '',
				'phone'       => '',
				'email'       => '',
				'street'      => '',
				'city'        => '',
				'region'      => '',
				'postal'      => '',
				'country'     => '',
				'lat'         => '',
				'lng'         => '',
				'hours'       => '',
				'price_range' => '',
			),
			'verify'       => array(
				'google'    => '',
				'bing'      => '',
				'yandex'    => '',
				'baidu'     => '',
				'pinterest' => '',
			),
			'robots_txt'   => '',
			'crumb'        => array(
				'sep'       => '»',
				'home'      => '',
				'show_home' => 1,
			),
			'sitemap'      => array(
				'per_page' => 1000,
				'images'   => 1,
				'off'      => array( 'tax_post_tag', 'tax_product_tag' ), // Disabled post types / taxonomies ("pt_x", "tax_x").
				'exclude'  => '',      // Post IDs, comma separated.
			),
			'schema_pt'    => array(), // Default schema per post type.
			'migrated'     => 0,
		);
	}

	public static function get( $key = null ) {
		if ( ! isset( self::$cache['opt'] ) ) {
			$o = get_option( self::OPT );
			$d = self::defaults();
			$o = is_array( $o ) ? $o : array();
			foreach ( $d as $k => $v ) {
				if ( ! isset( $o[ $k ] ) ) {
					$o[ $k ] = $v;
				} elseif ( is_array( $v ) && is_array( $o[ $k ] ) && $v && array_keys( $v ) !== range( 0, count( $v ) - 1 ) ) {
					$o[ $k ] = array_merge( $v, $o[ $k ] );
				}
			}
			self::$cache['opt'] = $o;
		}
		$o = self::$cache['opt'];
		return null === $key ? $o : ( isset( $o[ $key ] ) ? $o[ $key ] : null );
	}

	public static function save( $o ) {
		update_option( self::OPT, $o, true );
		self::$cache = array();
	}

	/** Template for a key (pt_post, tax_category, author, date, search, 404). */
	public static function tpl( $key ) {
		$all = self::get( 'tpl' );
		$def = self::default_tpl( $key );
		return isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? array_merge( $def, $all[ $key ] ) : $def;
	}

	public static function default_tpl( $key ) {
		if ( 0 === strpos( $key, 'pt_' ) ) {
			return array(
				'title'   => '%title% %page% %sep% %sitename%',
				'desc'    => '%excerpt%',
				'noindex' => 0,
			);
		}
		if ( 0 === strpos( $key, 'tax_' ) ) {
			return array(
				'title'   => '%term% %page% %sep% %sitename%',
				'desc'    => '%term_description%',
				'noindex' => 'tax_post_format' === $key ? 1 : 0,
			);
		}
		$map = array(
			'author' => array( '%name% %page% %sep% %sitename%', '', 0 ),
			'date'   => array( '%title% %page% %sep% %sitename%', '', 1 ),
			'search' => array( '%search_query% %page% %sep% %sitename%', '', 1 ),
			'404'    => array( '404 %sep% %sitename%', '', 1 ),
			'pta'    => array( '%pt_plural% %page% %sep% %sitename%', '', 0 ),
		);
		$m   = isset( $map[ $key ] ) ? $map[ $key ] : array( '%title% %sep% %sitename%', '', 0 );
		return array(
			'title'   => $m[0],
			'desc'    => $m[1],
			'noindex' => $m[2],
		);
	}

	/** A module is active only when it is switched on and no other SEO plugin is running. */
	public static function on( $module ) {
		$m = self::get( 'modules' );
		return ! empty( $m[ $module ] ) && '' === SSAI_Content::seo_plugin();
	}

	/** Public taxonomies we handle. */
	public static function taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $t ) {
			if ( in_array( $t->name, array( 'post_format', 'elementor_library_type', 'elementor_library_category', 'nav_menu', 'link_category' ), true ) || 0 === strpos( $t->name, 'elementor' ) ) {
				continue;
			}
			$out[ $t->name ] = $t;
		}
		return $out;
	}

	/* ================================================================ Init */

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'term_fields_hooks' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_post_ssai_adv_save', array( __CLASS__, 'handle_adv_save' ) );
		add_shortcode( 'ssai_breadcrumbs', array( __CLASS__, 'breadcrumb_shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_strings' ), 20 );
		add_action( 'wp', array( __CLASS__, 'front_hooks' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 99, 2 );
		add_action( 'template_redirect', array( __CLASS__, 'attachment_redirect' ), 1 );
		// Keep Rank Math / Yoast breadcrumb shortcodes and theme calls working after they are deactivated.
		add_action( 'plugins_loaded', array( __CLASS__, 'compat' ), 20 );
	}

	public static function compat() {
		if ( '' !== SSAI_Content::seo_plugin() ) {
			return;
		}
		require_once SSAI_DIR . 'includes/compat-functions.php';
		if ( ! shortcode_exists( 'rank_math_breadcrumb' ) ) {
			add_shortcode( 'rank_math_breadcrumb', array( __CLASS__, 'breadcrumb_shortcode' ) );
		}
		if ( ! shortcode_exists( 'rank_math_rich_snippet' ) ) {
			add_shortcode( 'rank_math_rich_snippet', '__return_empty_string' );
		}
		if ( ! shortcode_exists( 'wpseo_breadcrumb' ) ) {
			add_shortcode( 'wpseo_breadcrumb', array( __CLASS__, 'breadcrumb_shortcode' ) );
		}
	}

	public static function front_hooks() {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'fallback_tags' ), 1 );
		if ( ! self::on( 'head' ) ) {
			return;
		}
		remove_action( 'wp_head', 'rel_canonical' );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 15 );
		add_filter( 'wp_robots', array( __CLASS__, 'wp_robots' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 1 );
	}

	/* ================================================================ Multilingual */

	/** Language code (2 letters) of a post: WPML / Polylang, otherwise the site language. */
	public static function post_lang( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$l = pll_get_post_language( $post_id, 'slug' );
			if ( $l ) {
				return $l;
			}
		}
		$w = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $w ) && ! empty( $w['language_code'] ) ) {
			return $w['language_code'];
		}
		return substr( get_locale(), 0, 2 );
	}

	public static function post_locale( $post_id ) {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$l = pll_get_post_language( $post_id, 'locale' );
			if ( $l ) {
				return $l;
			}
		}
		$w = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $w ) && ! empty( $w['locale'] ) ) {
			return $w['locale'];
		}
		return get_locale();
	}

	public static function multilingual() {
		if ( function_exists( 'pll_languages_list' ) ) {
			return 'Polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return 'WPML';
		}
		return '';
	}

	/** Strings an admin types once (home title, business name…) become translatable in WPML / Polylang. */
	public static function register_strings() {
		$o = self::get();
		$s = array(
			'home_title'  => $o['home_title'],
			'home_desc'   => $o['home_desc'],
			'entity_name' => $o['entity']['name'],
			'crumb_home'  => $o['crumb']['home'],
		);
		foreach ( $s as $k => $v ) {
			if ( '' === (string) $v ) {
				continue;
			}
			if ( function_exists( 'pll_register_string' ) ) {
				pll_register_string( 'ssai_' . $k, $v, 'Smart SEO AI' );
			}
			do_action( 'wpml_register_single_string', 'Smart SEO AI', $k, $v );
		}
	}

	public static function t( $name, $value ) {
		if ( '' === (string) $value ) {
			return $value;
		}
		if ( function_exists( 'pll__' ) ) {
			return pll__( $value );
		}
		return apply_filters( 'wpml_translate_single_string', $value, 'Smart SEO AI', $name );
	}

	/* ================================================================ Context */

	/** What the current request shows. */
	public static function context() {
		if ( isset( self::$cache['ctx'] ) ) {
			return self::$cache['ctx'];
		}
		$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$ctx   = array(
			'type'  => '404',
			'paged' => $paged,
			'main'  => true,
		);
		if ( is_front_page() && 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
			$ctx = array_merge( $ctx, array( 'type' => 'post', 'id' => (int) get_option( 'page_on_front' ), 'front' => true ) );
		} elseif ( is_home() ) {
			$pf = (int) get_option( 'page_for_posts' );
			$ctx = $pf ? array_merge( $ctx, array( 'type' => 'post', 'id' => $pf, 'blog' => true ) ) : array_merge( $ctx, array( 'type' => 'home' ) );
		} elseif ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
			$ctx = array_merge( $ctx, array( 'type' => 'post', 'id' => (int) wc_get_page_id( 'shop' ), 'shop' => true ) );
		} elseif ( is_singular() ) {
			$ctx = array_merge( $ctx, array( 'type' => 'post', 'id' => (int) get_queried_object_id() ) );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$t   = get_queried_object();
			$ctx = array_merge( $ctx, array( 'type' => 'term', 'id' => (int) $t->term_id, 'tax' => $t->taxonomy ) );
		} elseif ( is_author() ) {
			$ctx = array_merge( $ctx, array( 'type' => 'author', 'id' => (int) get_queried_object_id() ) );
		} elseif ( is_search() ) {
			$ctx = array_merge( $ctx, array( 'type' => 'search' ) );
		} elseif ( is_date() ) {
			$ctx = array_merge( $ctx, array( 'type' => 'date' ) );
		} elseif ( is_post_type_archive() ) {
			$pt  = get_query_var( 'post_type' );
			$ctx = array_merge( $ctx, array( 'type' => 'pta', 'pt' => is_array( $pt ) ? reset( $pt ) : $pt ) );
		}
		self::$cache['ctx'] = $ctx;
		return $ctx;
	}

	/* ================================================================ Variables */

	public static function replace_vars( $tpl, $ctx ) {
		$tpl = (string) $tpl;
		if ( false === strpos( $tpl, '%' ) ) {
			return trim( $tpl );
		}
		$tpl = preg_replace( '/%%([a-z_]+)%%/i', '%$1%', $tpl ); // Yoast style.
		$sep = (string) self::get( 'sep' );
		$id  = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
		$v   = array(
			'sep'         => $sep,
			'sitename'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'sitedesc'    => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'currentyear' => wp_date( 'Y' ),
			'page'        => ! empty( $ctx['paged'] ) && $ctx['paged'] > 1 ? $sep . ' ' . sprintf( __( 'Page %s' ), number_format_i18n( $ctx['paged'] ) ) : '', // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- WordPress core string.
			'title'       => '',
			'excerpt'     => '',
			'term'        => '',
			'term_description' => '',
			'name'        => '',
			'date'        => '',
			'modified'    => '',
			'category'    => '',
			'search_query' => 'search' === $ctx['type'] ? get_search_query( false ) : '',
			'focuskw'     => '',
			'pt_plural'   => '',
			'pt_single'   => '',
		);
		if ( 'post' === $ctx['type'] && $id ) {
			$p              = get_post( $id );
			$v['title']     = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			$v['excerpt']   = false !== strpos( $tpl, '%excerpt%' ) ? self::auto_excerpt( $id ) : '';
			$v['date']      = get_the_date( '', $id );
			$v['modified']  = get_the_modified_date( '', $id );
			$v['name']      = $p ? get_the_author_meta( 'display_name', $p->post_author ) : '';
			$v['focuskw']   = SSAI_Content::get_focus_keyword( $id );
			$cat            = false !== strpos( $tpl, '%category%' ) ? self::primary_term( $id ) : null;
			$v['category']  = $cat ? $cat->name : '';
			$pto            = $p ? get_post_type_object( $p->post_type ) : null;
			$v['pt_plural'] = $pto ? $pto->labels->name : '';
			$v['pt_single'] = $pto ? $pto->labels->singular_name : '';
		} elseif ( 'term' === $ctx['type'] && $id ) {
			$t                     = get_term( $id );
			$v['term']             = $t && ! is_wp_error( $t ) ? $t->name : '';
			$v['title']            = $v['term'];
			$v['term_description'] = $t && ! is_wp_error( $t ) ? wp_strip_all_tags( term_description( $t ) ) : '';
		} elseif ( 'author' === $ctx['type'] && $id ) {
			$v['name']  = get_the_author_meta( 'display_name', $id );
			$v['title'] = $v['name'];
		} elseif ( 'date' === $ctx['type'] ) {
			$v['title'] = wp_strip_all_tags( get_the_archive_title() );
			$v['date']  = $v['title'];
		} elseif ( 'pta' === $ctx['type'] && ! empty( $ctx['pt'] ) ) {
			$pto            = get_post_type_object( $ctx['pt'] );
			$v['pt_plural'] = $pto ? $pto->labels->name : '';
			$v['title']     = $v['pt_plural'];
		} elseif ( 'search' === $ctx['type'] ) {
			$v['title'] = $v['search_query'];
		}
		$out = preg_replace_callback(
			'/%([a-z_]+)%/i',
			function ( $m ) use ( $v ) {
				$k = strtolower( $m[1] );
				return isset( $v[ $k ] ) ? $v[ $k ] : '';
			},
			$tpl
		);
		$out = trim( preg_replace( '/\s+/u', ' ', $out ) );
		if ( '' !== $sep ) {
			$q   = preg_quote( $sep, '/' );
			$out = preg_replace( '/(\s*' . $q . '\s*){2,}/u', ' ' . $sep . ' ', $out );
			$out = preg_replace( '/^\s*' . $q . '\s*|\s*' . $q . '\s*$/u', '', $out );
		}
		return trim( $out );
	}

	public static function auto_excerpt( $post_id, $len = 155 ) {
		$p = get_post( $post_id );
		if ( ! $p ) {
			return '';
		}
		$t = has_excerpt( $p ) ? $p->post_excerpt : '';
		if ( '' === trim( $t ) ) {
			$html = SSAI_Elementor::is_built( $post_id ) ? SSAI_Elementor::html( $post_id ) : $p->post_content;
			$t    = SSAI_Content::html_to_text( $html );
		}
		$t = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $t ) ) ) );
		if ( mb_strlen( $t ) > $len ) {
			$t   = mb_substr( $t, 0, $len );
			$cut = mb_strrpos( $t, ' ' );
			$t   = ( $cut > $len * 0.6 ? mb_substr( $t, 0, $cut ) : $t ) . '…';
		}
		return $t;
	}

	/* ================================================================ Meta resolver */

	public static function post_robots( $post_id ) {
		$r = get_post_meta( $post_id, self::M_ROBOTS, true );
		if ( ! is_array( $r ) || ! $r ) {
			$rm = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $rm ) && $rm ) {
				$r = array_values( array_intersect( $rm, array( 'noindex', 'nofollow' ) ) );
				if ( ! $r && in_array( 'index', $rm, true ) ) {
					$r = array( 'index' );
				}
			} elseif ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
				$r = array( 'noindex' );
			}
		}
		$r       = is_array( $r ) ? $r : array();
		$noindex = in_array( 'noindex', $r, true );
		if ( ! $noindex && ! in_array( 'index', $r, true ) ) {
			$tpl     = self::tpl( 'pt_' . get_post_type( $post_id ) );
			$noindex = ! empty( $tpl['noindex'] );
		}
		if ( ! $noindex && function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wp ) {
				if ( (int) wc_get_page_id( $wp ) === (int) $post_id ) {
					$noindex = true;
				}
			}
		}
		return array(
			'noindex'  => $noindex,
			'nofollow' => in_array( 'nofollow', $r, true ),
		);
	}

	public static function post_noindex( $post_id ) {
		$r = self::post_robots( $post_id );
		return $r['noindex'] || 'publish' !== get_post_status( $post_id ) || post_password_required( $post_id );
	}

	public static function term_noindex( $term ) {
		$term = is_object( $term ) ? $term : get_term( $term );
		if ( ! $term || is_wp_error( $term ) ) {
			return true;
		}
		$own = get_term_meta( $term->term_id, self::T_NOINDEX, true );
		if ( '' !== (string) $own ) {
			return '1' === (string) $own;
		}
		$rm = get_term_meta( $term->term_id, 'rank_math_robots', true );
		if ( is_array( $rm ) && $rm ) {
			return in_array( 'noindex', $rm, true );
		}
		$tpl = self::tpl( 'tax_' . $term->taxonomy );
		if ( ! empty( $tpl['noindex'] ) ) {
			return true;
		}
		return self::get( 'noindex_empty' ) && 0 === (int) $term->count;
	}

	public static function post_canonical( $post_id ) {
		$c = (string) get_post_meta( $post_id, self::M_CANON, true );
		if ( '' === $c ) {
			$c = (string) get_post_meta( $post_id, 'rank_math_canonical_url', true );
		}
		if ( '' === $c ) {
			$c = (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		}
		if ( '' !== $c ) {
			return $c;
		}
		$u = wp_get_canonical_url( $post_id );
		return $u ? $u : get_permalink( $post_id );
	}

	/** Primary term of a post in its main hierarchical taxonomy (category, product_cat…). */
	public static function primary_term( $post_id, $tax = '' ) {
		if ( '' === $tax ) {
			$pt  = get_post_type( $post_id );
			$tax = 'product' === $pt ? 'product_cat' : ( 'post' === $pt ? 'category' : '' );
			if ( '' === $tax ) {
				foreach ( get_object_taxonomies( $pt, 'objects' ) as $t ) {
					if ( $t->hierarchical && $t->public ) {
						$tax = $t->name;
						break;
					}
				}
			}
		}
		if ( '' === $tax ) {
			return null;
		}
		$terms = get_the_terms( $post_id, $tax );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return null;
		}
		foreach ( array( (int) get_post_meta( $post_id, self::M_PRIMARY, true ), (int) get_post_meta( $post_id, 'rank_math_primary_' . $tax, true ), (int) get_post_meta( $post_id, '_yoast_wpseo_primary_' . $tax, true ) ) as $want ) {
			if ( $want ) {
				foreach ( $terms as $t ) {
					if ( (int) $t->term_id === $want ) {
						return $t;
					}
				}
			}
		}
		// Prefer the deepest term (most specific).
		usort(
			$terms,
			function ( $a, $b ) {
				return count( get_ancestors( $b->term_id, $b->taxonomy ) ) - count( get_ancestors( $a->term_id, $a->taxonomy ) );
			}
		);
		return $terms[0];
	}

	private static function first_meta( $post_id, $keys ) {
		foreach ( $keys as $k ) {
			$v = trim( (string) get_post_meta( $post_id, $k, true ) );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	private static function first_term_meta( $term_id, $keys ) {
		foreach ( $keys as $k ) {
			$v = trim( (string) get_term_meta( $term_id, $k, true ) );
			if ( '' !== $v ) {
				return $v;
			}
		}
		return '';
	}

	/** @return array { url, width, height, alt } or null */
	public static function image_of_post( $post_id ) {
		$url = self::first_meta( $post_id, array( self::M_OGIMG, 'rank_math_facebook_image', '_yoast_wpseo_opengraph-image' ) );
		if ( '' !== $url ) {
			$aid = attachment_url_to_postid( $url );
			return self::image_arr( $aid, $url );
		}
		$tid = (int) get_post_thumbnail_id( $post_id );
		if ( $tid ) {
			return self::image_arr( $tid );
		}
		$p = get_post( $post_id );
		if ( $p && preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $p->post_content, $m ) ) {
			return self::image_arr( 0, $m[1] );
		}
		if ( $p && SSAI_Elementor::is_built( $post_id ) ) {
			$raw = (string) get_post_meta( $post_id, '_elementor_data', true );
			if ( preg_match( '/"url":"(https?:[^"]+\.(?:jpe?g|png|webp))"/i', $raw, $m ) ) {
				return self::image_arr( 0, stripslashes( $m[1] ) );
			}
		}
		return null;
	}

	public static function image_arr( $aid, $url = '' ) {
		if ( $aid ) {
			$src = wp_get_attachment_image_src( $aid, 'full' );
			if ( $src ) {
				return array(
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
					'alt'    => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
					'id'     => (int) $aid,
				);
			}
		}
		return '' !== $url ? array(
			'url'    => $url,
			'width'  => 0,
			'height' => 0,
			'alt'    => '',
			'id'     => 0,
		) : null;
	}

	public static function default_image() {
		$u = (string) self::get( 'og_image' );
		if ( '' !== $u ) {
			return self::image_arr( attachment_url_to_postid( $u ), $u );
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		return $logo ? self::image_arr( $logo ) : null;
	}

	/**
	 * Everything the <head> needs for a context.
	 *
	 * @return array { title, desc, noindex, nofollow, canonical, og_type, og_title, og_desc, image, locale, published, modified, author, price… }
	 */
	public static function meta( $ctx ) {
		// Only the current request is cached; admin screens may change data between two calls.
		$key = empty( $ctx['main'] ) ? '' : md5( wp_json_encode( $ctx ) );
		if ( '' !== $key && isset( self::$cache[ 'meta' . $key ] ) ) {
			return self::$cache[ 'meta' . $key ];
		}
		$o   = self::get();
		$id  = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
		$m   = array(
			'title'     => '',
			'desc'      => '',
			'noindex'   => false,
			'nofollow'  => false,
			'canonical' => '',
			'og_type'   => 'website',
			'og_title'  => '',
			'og_desc'   => '',
			'image'     => null,
			'locale'    => get_locale(),
			'published' => '',
			'modified'  => '',
		);
		$paged = isset( $ctx['paged'] ) ? (int) $ctx['paged'] : 1;
		switch ( $ctx['type'] ) {
			case 'post':
				$p = get_post( $id );
				if ( ! $p ) {
					break;
				}
				$tpl          = self::tpl( 'pt_' . $p->post_type );
				$raw          = SSAI_Content::get_seo_title_raw( $id );
				$m['title']   = self::replace_vars( '' !== trim( $raw ) ? $raw : $tpl['title'], $ctx );
				$desc         = SSAI_Content::get_meta_description( $id );
				$m['desc']    = self::replace_vars( '' !== trim( $desc ) ? $desc : $tpl['desc'], $ctx );
				$r            = self::post_robots( $id );
				$m['noindex'] = $r['noindex'];
				$m['nofollow'] = $r['nofollow'];
				if ( ! empty( $ctx['blog'] ) || ! empty( $ctx['shop'] ) ) {
					$m['canonical'] = $paged > 1 && ! empty( $ctx['main'] ) ? get_pagenum_link( $paged ) : get_permalink( $id );
				} elseif ( ! empty( $ctx['front'] ) ) {
					$m['canonical'] = home_url( '/' );
					$own            = self::first_meta( $id, array( self::M_CANON, 'rank_math_canonical_url' ) );
					if ( '' !== $own ) {
						$m['canonical'] = $own;
					}
				} else {
					$m['canonical'] = self::post_canonical( $id );
				}
				$m['og_type']   = ! empty( $ctx['front'] ) || ! empty( $ctx['blog'] ) || ! empty( $ctx['shop'] ) ? 'website' : ( 'product' === $p->post_type ? 'product' : 'article' );
				$m['og_title']  = self::first_meta( $id, array( self::M_OGTITLE, 'rank_math_facebook_title', '_yoast_wpseo_opengraph-title' ) );
				$m['og_title']  = '' !== $m['og_title'] ? self::replace_vars( $m['og_title'], $ctx ) : $m['title'];
				$m['og_desc']   = self::first_meta( $id, array( self::M_OGDESC, 'rank_math_facebook_description', '_yoast_wpseo_opengraph-description' ) );
				$m['og_desc']   = '' !== $m['og_desc'] ? self::replace_vars( $m['og_desc'], $ctx ) : $m['desc'];
				$m['image']     = self::image_of_post( $id );
				$m['locale']    = self::post_locale( $id );
				$m['published'] = get_post_time( 'c', true, $p );
				$m['modified']  = get_post_modified_time( 'c', true, $p );
				$m['author']    = get_the_author_meta( 'display_name', $p->post_author );
				if ( 'product' === $p->post_type && function_exists( 'wc_get_product' ) ) {
					$prod = wc_get_product( $id );
					if ( $prod && '' !== (string) $prod->get_price() ) {
						$m['price']    = (float) $prod->get_price();
						$m['currency'] = get_woocommerce_currency();
					}
				}
				break;

			case 'term':
				$t = get_term( $id );
				if ( ! $t || is_wp_error( $t ) ) {
					break;
				}
				$tpl            = self::tpl( 'tax_' . $t->taxonomy );
				$raw            = self::first_term_meta( $id, array( self::T_TITLE, 'rank_math_title' ) );
				$m['title']     = self::replace_vars( '' !== $raw ? $raw : $tpl['title'], $ctx );
				$d              = self::first_term_meta( $id, array( self::T_DESC, 'rank_math_description' ) );
				$m['desc']      = self::replace_vars( '' !== $d ? $d : $tpl['desc'], $ctx );
				$m['noindex']   = self::term_noindex( $t );
				$link           = get_term_link( $t );
				$m['canonical'] = $paged > 1 && ! empty( $ctx['main'] ) ? get_pagenum_link( $paged ) : ( is_wp_error( $link ) ? '' : $link );
				$thumb          = (int) get_term_meta( $id, 'thumbnail_id', true );
				$m['image']     = $thumb ? self::image_arr( $thumb ) : null;
				break;

			case 'home':
				$m['title']     = self::replace_vars( self::t( 'home_title', $o['home_title'] ), $ctx );
				$m['desc']      = self::replace_vars( self::t( 'home_desc', $o['home_desc'] ), $ctx );
				$m['canonical'] = $paged > 1 && ! empty( $ctx['main'] ) ? get_pagenum_link( $paged ) : home_url( '/' );
				break;

			case 'author':
				$tpl            = self::tpl( 'author' );
				$m['title']     = self::replace_vars( $tpl['title'], $ctx );
				$m['desc']      = self::replace_vars( '' !== $tpl['desc'] ? $tpl['desc'] : wp_strip_all_tags( get_the_author_meta( 'description', $id ) ), $ctx );
				$m['noindex']   = ! empty( $tpl['noindex'] );
				$m['canonical'] = get_author_posts_url( $id );
				$m['og_type']   = 'profile';
				break;

			case 'pta':
				$tpl            = self::tpl( 'pta' );
				$m['title']     = self::replace_vars( $tpl['title'], $ctx );
				$m['noindex']   = ! empty( $tpl['noindex'] );
				$link           = ! empty( $ctx['pt'] ) ? get_post_type_archive_link( $ctx['pt'] ) : '';
				$m['canonical'] = $paged > 1 && ! empty( $ctx['main'] ) ? get_pagenum_link( $paged ) : (string) $link;
				break;

			default: // search, date, 404.
				$tpl          = self::tpl( $ctx['type'] );
				$m['title']   = self::replace_vars( $tpl['title'], $ctx );
				$m['noindex'] = ! empty( $tpl['noindex'] );
		}
		if ( '' === $m['og_title'] ) {
			$m['og_title'] = $m['title'];
		}
		if ( '' === $m['og_desc'] ) {
			$m['og_desc'] = $m['desc'];
		}
		if ( ! $m['image'] ) {
			$m['image'] = self::default_image();
		}
		if ( $m['noindex'] ) {
			$m['canonical'] = '';
		}
		$m = apply_filters( 'ssai_meta', $m, $ctx );
		if ( '' !== $key ) {
			self::$cache[ 'meta' . $key ] = $m;
		}
		return $m;
	}

	/* ================================================================ Output */

	public static function document_title( $title ) {
		$m = self::meta( self::context() );
		return '' !== $m['title'] ? $m['title'] : $title;
	}

	public static function wp_robots( $robots ) {
		$m = self::meta( self::context() );
		if ( $m['noindex'] ) {
			unset( $robots['index'], $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
			$robots['noindex'] = true;
		} elseif ( empty( $robots['noindex'] ) ) {
			$robots['index']             = true;
			$robots['max-snippet']       = '-1';
			$robots['max-video-preview'] = '-1';
			$robots['max-image-preview'] = 'large';
		}
		if ( $m['nofollow'] ) {
			unset( $robots['follow'] );
			$robots['nofollow'] = true;
		} elseif ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}
		return $robots;
	}

	/**
	 * Fixes chosen in the SEO check: a mobile viewport tag for themes without one (site-wide option) and a
	 * self-referencing canonical on pages where nothing else prints one (when this plugin's head output is off).
	 */
	public static function fallback_tags() {
		if ( get_option( 'ssai_viewport' ) ) {
			echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
		}
		if ( is_singular() && ! self::on( 'head' ) && get_post_meta( get_queried_object_id(), self::M_FORCE_CANON, true ) && ! has_action( 'wp_head', 'rel_canonical' ) ) {
			echo '<link rel="canonical" href="' . esc_url( get_permalink( get_queried_object_id() ) ) . '" />' . "\n";
		}
	}

	public static function head() {
		$ctx = self::context();
		$m   = self::meta( $ctx );
		$o   = self::get();
		$out = array( '<!-- Smart SEO AI -->' );
		if ( '' !== $m['desc'] ) {
			$out[] = '<meta name="description" content="' . esc_attr( $m['desc'] ) . '" />';
		}
		if ( '' !== $m['canonical'] ) {
			$out[] = '<link rel="canonical" href="' . esc_url( $m['canonical'] ) . '" />';
		}
		$ogp = array(
			'og:locale'      => $m['locale'],
			'og:type'        => $m['og_type'],
			'og:title'       => $m['og_title'],
			'og:description' => $m['og_desc'],
			'og:url'         => '' !== $m['canonical'] ? $m['canonical'] : '',
			'og:site_name'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		);
		if ( function_exists( 'pll_languages_list' ) && 'post' === $ctx['type'] && function_exists( 'pll_get_post_translations' ) ) {
			foreach ( pll_get_post_translations( $ctx['id'] ) as $lang => $tid ) {
				if ( (int) $tid !== (int) $ctx['id'] ) {
					$out_alt[] = pll_get_post_language( $tid, 'locale' );
				}
			}
		}
		foreach ( $ogp as $k => $v ) {
			if ( '' !== (string) $v ) {
				$out[] = '<meta property="' . esc_attr( $k ) . '" content="' . esc_attr( $v ) . '" />';
			}
		}
		if ( ! empty( $out_alt ) ) {
			foreach ( array_filter( $out_alt ) as $loc ) {
				$out[] = '<meta property="og:locale:alternate" content="' . esc_attr( $loc ) . '" />';
			}
		}
		if ( 'article' === $m['og_type'] && '' !== $m['published'] ) {
			$out[] = '<meta property="article:published_time" content="' . esc_attr( $m['published'] ) . '" />';
			$out[] = '<meta property="article:modified_time" content="' . esc_attr( $m['modified'] ) . '" />';
			if ( '' !== $o['facebook'] ) {
				$out[] = '<meta property="article:publisher" content="' . esc_url( $o['facebook'] ) . '" />';
			}
		}
		if ( isset( $m['price'] ) ) {
			$out[] = '<meta property="product:price:amount" content="' . esc_attr( $m['price'] ) . '" />';
			$out[] = '<meta property="product:price:currency" content="' . esc_attr( $m['currency'] ) . '" />';
		}
		if ( $m['image'] ) {
			$out[] = '<meta property="og:image" content="' . esc_url( $m['image']['url'] ) . '" />';
			if ( 0 === strpos( $m['image']['url'], 'https://' ) ) {
				$out[] = '<meta property="og:image:secure_url" content="' . esc_url( $m['image']['url'] ) . '" />';
			}
			if ( $m['image']['width'] ) {
				$out[] = '<meta property="og:image:width" content="' . (int) $m['image']['width'] . '" />';
				$out[] = '<meta property="og:image:height" content="' . (int) $m['image']['height'] . '" />';
			}
			if ( '' !== $m['image']['alt'] ) {
				$out[] = '<meta property="og:image:alt" content="' . esc_attr( $m['image']['alt'] ) . '" />';
			}
		}
		$out[] = '<meta name="twitter:card" content="' . ( $m['image'] ? 'summary_large_image' : 'summary' ) . '" />';
		$out[] = '<meta name="twitter:title" content="' . esc_attr( $m['og_title'] ) . '" />';
		if ( '' !== $m['og_desc'] ) {
			$out[] = '<meta name="twitter:description" content="' . esc_attr( $m['og_desc'] ) . '" />';
		}
		if ( $m['image'] ) {
			$out[] = '<meta name="twitter:image" content="' . esc_url( $m['image']['url'] ) . '" />';
		}
		if ( '' !== $o['twitter'] ) {
			$out[] = '<meta name="twitter:site" content="@' . esc_attr( ltrim( $o['twitter'], '@' ) ) . '" />';
		}
		if ( is_front_page() ) {
			$names = array(
				'google'    => 'google-site-verification',
				'bing'      => 'msvalidate.01',
				'yandex'    => 'yandex-verification',
				'baidu'     => 'baidu-site-verification',
				'pinterest' => 'p:domain_verify',
			);
			foreach ( $names as $k => $n ) {
				if ( ! empty( $o['verify'][ $k ] ) ) {
					$out[] = '<meta name="' . esc_attr( $n ) . '" content="' . esc_attr( $o['verify'][ $k ] ) . '" />';
				}
			}
		}
		$out[] = '<!-- /Smart SEO AI -->';
		echo "\n" . implode( "\n", $out ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/* ================================================================ Breadcrumbs */

	/** @return array of [ name, url ] */
	public static function crumbs( $ctx ) {
		$o    = self::get();
		$home = self::t( 'crumb_home', '' !== $o['crumb']['home'] ? $o['crumb']['home'] : __( 'Home' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
		$out  = array( array( $home, home_url( '/' ) ) );
		$id   = isset( $ctx['id'] ) ? (int) $ctx['id'] : 0;
		$tchain = function ( $t ) {
			$c = array();
			foreach ( array_reverse( get_ancestors( $t->term_id, $t->taxonomy, 'taxonomy' ) ) as $aid ) {
				$a   = get_term( $aid, $t->taxonomy );
				$c[] = array( $a->name, get_term_link( $a ) );
			}
			$c[] = array( $t->name, get_term_link( $t ) );
			return $c;
		};
		$shop = function () {
			if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 && (int) get_option( 'page_on_front' ) !== (int) wc_get_page_id( 'shop' ) ) {
				return array( array( get_the_title( wc_get_page_id( 'shop' ) ), get_permalink( wc_get_page_id( 'shop' ) ) ) );
			}
			return array();
		};
		switch ( $ctx['type'] ) {
			case 'post':
				if ( ! empty( $ctx['front'] ) ) {
					return $out;
				}
				$p = get_post( $id );
				if ( ! $p ) {
					return $out;
				}
				if ( 'product' === $p->post_type ) {
					$out = array_merge( $out, $shop() );
				} elseif ( 'post' === $p->post_type && (int) get_option( 'page_for_posts' ) && 'page' === get_option( 'show_on_front' ) ) {
					$out[] = array( get_the_title( get_option( 'page_for_posts' ) ), get_permalink( get_option( 'page_for_posts' ) ) );
				} elseif ( ! in_array( $p->post_type, array( 'post', 'page' ), true ) && get_post_type_archive_link( $p->post_type ) ) {
					$pto   = get_post_type_object( $p->post_type );
					$out[] = array( $pto->labels->name, get_post_type_archive_link( $p->post_type ) );
				}
				if ( is_post_type_hierarchical( $p->post_type ) ) {
					foreach ( array_reverse( get_post_ancestors( $p ) ) as $aid ) {
						$out[] = array( get_the_title( $aid ), get_permalink( $aid ) );
					}
				} elseif ( empty( $ctx['shop'] ) && empty( $ctx['blog'] ) ) {
					$t = self::primary_term( $id );
					if ( $t ) {
						$out = array_merge( $out, $tchain( $t ) );
					}
				}
				$out[] = array( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), get_permalink( $id ) );
				break;
			case 'term':
				$t = get_term( $id );
				if ( $t && ! is_wp_error( $t ) ) {
					if ( 'product_cat' === $t->taxonomy || 'product_tag' === $t->taxonomy ) {
						$out = array_merge( $out, $shop() );
					}
					$out = array_merge( $out, $tchain( $t ) );
				}
				break;
			case 'author':
				$out[] = array( get_the_author_meta( 'display_name', $id ), get_author_posts_url( $id ) );
				break;
			case 'search':
				$out[] = array( sprintf( __( 'Search Results for &#8220;%s&#8221;' ), get_search_query( false ) ), '' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
				break;
			case '404':
				$out[] = array( __( 'Page not found' ), '' ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain
				break;
			case 'pta':
				$pto = get_post_type_object( $ctx['pt'] );
				if ( $pto ) {
					$out[] = array( $pto->labels->name, get_post_type_archive_link( $ctx['pt'] ) );
				}
				break;
			case 'date':
				$out[] = array( wp_strip_all_tags( get_the_archive_title() ), '' );
				break;
		}
		return apply_filters( 'ssai_breadcrumbs', $out, $ctx );
	}

	public static function breadcrumb_html() {
		$ctx   = self::context();
		$items = self::crumbs( $ctx );
		$o     = self::get();
		if ( 'post' === $ctx['type'] && ! empty( $ctx['front'] ) ) {
			return '';
		}
		if ( empty( $o['crumb']['show_home'] ) ) {
			array_shift( $items );
		}
		$parts = array();
		$last  = count( $items ) - 1;
		foreach ( $items as $i => $c ) {
			$name    = esc_html( wp_specialchars_decode( $c[0] ) );
			$parts[] = $i === $last || '' === $c[1] ? '<span class="ssai-bc-last" aria-current="page">' . $name . '</span>' : '<a href="' . esc_url( $c[1] ) . '">' . $name . '</a>';
		}
		return '<nav class="ssai-breadcrumbs rank-math-breadcrumb" aria-label="breadcrumbs"><p>' . implode( ' <span class="separator">' . esc_html( $o['crumb']['sep'] ) . '</span> ', $parts ) . '</p></nav>';
	}

	public static function breadcrumb_shortcode() {
		return self::breadcrumb_html();
	}

	/* ================================================================ robots.txt, attachments */

	public static function robots_txt( $output, $public ) {
		if ( ! self::on( 'robots_txt' ) ) {
			return $output;
		}
		$custom = trim( (string) self::get( 'robots_txt' ) );
		if ( '' !== $custom && $public ) {
			$output = $custom . "\n";
		}
		if ( self::on( 'sitemap' ) && $public && false === stripos( $output, 'sitemap_index.xml' ) ) {
			$output = rtrim( $output ) . "\n\nSitemap: " . home_url( '/sitemap_index.xml' ) . "\n";
		}
		return $output;
	}

	public static function attachment_redirect() {
		if ( ! is_attachment() || ! self::get( 'attachment_redirect' ) || '' !== SSAI_Content::seo_plugin() ) {
			return;
		}
		$p      = get_queried_object();
		$target = ( $p && $p->post_parent && 'publish' === get_post_status( $p->post_parent ) ) ? get_permalink( $p->post_parent ) : wp_get_attachment_url( $p ? $p->ID : 0 );
		if ( $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/* ================================================================ Per-post settings */

	public static function meta_box() {
		foreach ( SSAI_Admin::post_types() as $pt ) {
			add_meta_box( 'ssai-advanced', 'Smart SEO AI – تنظیمات پیشرفته سئو', array( __CLASS__, 'render_meta_box' ), $pt, 'normal', 'default' );
		}
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'ssai_adv_' . $post->ID, 'ssai_adv_nonce' );
		echo '<div class="ssai" dir="rtl">';
		self::adv_fields( $post->ID );
		echo '</div>';
	}

	/** Fields shared by the editor meta box and the page report. */
	public static function adv_fields( $post_id ) {
		$r      = get_post_meta( $post_id, self::M_ROBOTS, true );
		$r      = is_array( $r ) ? $r : array();
		$eff    = self::post_robots( $post_id );
		$schema = (string) get_post_meta( $post_id, self::M_SCHEMA, true );
		$pt     = get_post_type( $post_id );
		$def    = self::default_schema( $pt );
		$terms  = array();
		$tax    = 'product' === $pt ? 'product_cat' : ( 'post' === $pt ? 'category' : '' );
		if ( $tax ) {
			$terms = get_the_terms( $post_id, $tax );
			$terms = $terms && ! is_wp_error( $terms ) ? $terms : array();
		}
		$prim = self::primary_term( $post_id );
		?>
		<table class="form-table ssai-adv" role="presentation">
			<tr><th scope="row">نمایش در گوگل</th><td>
				<label><input type="checkbox" name="ssai_adv[noindex]" value="1" <?php checked( in_array( 'noindex', $r, true ) || ( ! in_array( 'index', $r, true ) && $eff['noindex'] && ! $r ) ); ?>> noindex – این صفحه در نتایج گوگل نیاید</label><br>
				<label><input type="checkbox" name="ssai_adv[nofollow]" value="1" <?php checked( in_array( 'nofollow', $r, true ) ); ?>> nofollow – گوگل لینک‌های این صفحه را دنبال نکند</label>
			</td></tr>
			<tr><th scope="row"><label for="ssai-canon">آدرس Canonical</label></th><td><input type="url" id="ssai-canon" class="large-text" dir="ltr" name="ssai_adv[canonical]" value="<?php echo esc_attr( get_post_meta( $post_id, self::M_CANON, true ) ); ?>" placeholder="<?php echo esc_attr( get_permalink( $post_id ) ); ?>"><p class="description">فقط وقتی پر کنید که نسخه اصلی این محتوا آدرس دیگری است.</p></td></tr>
			<?php if ( count( $terms ) > 1 ) : ?>
			<tr><th scope="row"><label for="ssai-prim">دسته اصلی</label></th><td><select id="ssai-prim" name="ssai_adv[primary]">
				<?php foreach ( $terms as $t ) : ?>
					<option value="<?php echo esc_attr( $t->term_id ); ?>" <?php selected( $prim && $prim->term_id === $t->term_id ); ?>><?php echo esc_html( $t->name ); ?></option>
				<?php endforeach; ?>
			</select><p class="description">در Breadcrumb و آدرس‌ها استفاده می‌شود.</p></td></tr>
			<?php endif; ?>
			<tr><th scope="row"><label for="ssai-schema">نوع Schema</label></th><td><select id="ssai-schema" name="ssai_adv[schema]">
				<?php foreach ( self::schema_types( $pt ) as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $schema, 'auto' === $k ? '' : $k ); ?>><?php echo esc_html( 'auto' === $k ? 'پیش‌فرض (' . self::schema_types( $pt )[ $def ] . ')' : $l ); ?></option>
				<?php endforeach; ?>
			</select></td></tr>
			<tr><th scope="row"><label for="ssai-ogt">عنوان شبکه‌های اجتماعی</label></th><td><input type="text" id="ssai-ogt" class="large-text" name="ssai_adv[og_title]" value="<?php echo esc_attr( get_post_meta( $post_id, self::M_OGTITLE, true ) ); ?>" placeholder="همان عنوان سئو"></td></tr>
			<tr><th scope="row"><label for="ssai-ogd">توضیح شبکه‌های اجتماعی</label></th><td><textarea id="ssai-ogd" class="large-text" rows="2" name="ssai_adv[og_desc]" placeholder="همان توضیحات متا"><?php echo esc_textarea( get_post_meta( $post_id, self::M_OGDESC, true ) ); ?></textarea></td></tr>
			<tr><th scope="row"><label for="ssai-ogi">تصویر شبکه‌های اجتماعی</label></th><td><input type="url" id="ssai-ogi" class="large-text" dir="ltr" name="ssai_adv[og_image]" value="<?php echo esc_attr( get_post_meta( $post_id, self::M_OGIMG, true ) ); ?>" placeholder="تصویر شاخص"><p class="description">برای پیش‌نمایش لینک در تلگرام، واتس‌اپ، لینکدین و… (پیشنهاد: ۱۲۰۰×۶۳۰).</p></td></tr>
		</table>
		<?php
	}

	public static function schema_types( $pt = '' ) {
		$t = array(
			'auto'        => 'پیش‌فرض',
			'none'        => 'بدون Schema اضافه (فقط WebPage)',
			'Article'     => 'مقاله (Article)',
			'BlogPosting' => 'نوشته وبلاگ (BlogPosting)',
			'NewsArticle' => 'خبر (NewsArticle)',
			'Service'     => 'خدمت (Service)',
		);
		if ( 'product' === $pt ) {
			$t = array(
				'auto' => 'پیش‌فرض',
				'Product' => 'محصول (Product)',
				'none' => 'بدون Schema محصول',
			);
		}
		return $t;
	}

	public static function default_schema( $pt ) {
		$d = self::get( 'schema_pt' );
		if ( isset( $d[ $pt ] ) ) {
			return $d[ $pt ];
		}
		if ( 'post' === $pt ) {
			return 'BlogPosting';
		}
		if ( 'product' === $pt ) {
			return 'Product';
		}
		return 'none';
	}

	public static function save_adv( $post_id, $in ) {
		$in = is_array( $in ) ? $in : array();
		$r   = array();
		$old = self::post_robots( $post_id );
		if ( ! empty( $in['noindex'] ) ) {
			$r[] = 'noindex';
		} else {
			// Store "index" only when it overrides something: a noindex-by-default post type or old Rank Math data.
			$tpl = self::tpl( 'pt_' . get_post_type( $post_id ) );
			$rm  = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( ! empty( $tpl['noindex'] ) || ( is_array( $rm ) && in_array( 'noindex', $rm, true ) ) ) {
				$r[] = 'index';
			}
		}
		if ( ! empty( $in['nofollow'] ) ) {
			$r[] = 'nofollow';
		}
		if ( $r ) {
			update_post_meta( $post_id, self::M_ROBOTS, $r );
		} else {
			delete_post_meta( $post_id, self::M_ROBOTS );
		}
		foreach ( array( 'canonical' => self::M_CANON, 'og_image' => self::M_OGIMG ) as $k => $meta ) {
			$v = isset( $in[ $k ] ) ? esc_url_raw( trim( $in[ $k ] ) ) : '';
			'' === $v ? delete_post_meta( $post_id, $meta ) : update_post_meta( $post_id, $meta, $v );
		}
		$v = isset( $in['og_title'] ) ? sanitize_text_field( $in['og_title'] ) : '';
		'' === $v ? delete_post_meta( $post_id, self::M_OGTITLE ) : update_post_meta( $post_id, self::M_OGTITLE, wp_slash( $v ) );
		$v = isset( $in['og_desc'] ) ? sanitize_textarea_field( $in['og_desc'] ) : '';
		'' === $v ? delete_post_meta( $post_id, self::M_OGDESC ) : update_post_meta( $post_id, self::M_OGDESC, wp_slash( $v ) );
		if ( isset( $in['primary'] ) ) {
			update_post_meta( $post_id, self::M_PRIMARY, absint( $in['primary'] ) );
		}
		$s = isset( $in['schema'] ) ? sanitize_text_field( $in['schema'] ) : 'auto';
		'auto' === $s || ! array_key_exists( $s, self::schema_types( get_post_type( $post_id ) ) ) ? delete_post_meta( $post_id, self::M_SCHEMA ) : update_post_meta( $post_id, self::M_SCHEMA, $s );
		$new = self::post_robots( $post_id );
		if ( $old['noindex'] !== $new['noindex'] ) {
			do_action( 'ssai_sitemap_changed' );
		}
		SSAI_Applier::purge_cache( $post_id );
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['ssai_adv_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ssai_adv_nonce'] ) ), 'ssai_adv_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		remove_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10 );
		self::save_adv( $post_id, isset( $_POST['ssai_adv'] ) ? wp_unslash( $_POST['ssai_adv'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field by field.
		add_action( 'save_post', array( __CLASS__, 'save_meta_box' ), 10, 2 );
	}

	/** Same fields saved from the page report (handy for Elementor pages). */
	public static function handle_adv_save() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'ssai_adv_' . $post_id, 'ssai_adv_nonce' );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		self::save_adv( $post_id, isset( $_POST['ssai_adv'] ) ? wp_unslash( $_POST['ssai_adv'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		wp_safe_redirect( add_query_arg( 'saved', 'adv', SSAI_Report::url( $post_id ) ) . '#ssai-adv' );
		exit;
	}

	/* ================================================================ Per-term settings */

	public static function term_fields_hooks() {
		foreach ( array_keys( self::taxonomies() ) as $tax ) {
			add_action( $tax . '_edit_form_fields', array( __CLASS__, 'term_fields' ), 20 );
			add_action( 'edited_' . $tax, array( __CLASS__, 'save_term' ) );
		}
	}

	public static function term_fields( $term ) {
		wp_nonce_field( 'ssai_term_' . $term->term_id, 'ssai_term_nonce' );
		$title = self::first_term_meta( $term->term_id, array( self::T_TITLE, 'rank_math_title' ) );
		$desc  = self::first_term_meta( $term->term_id, array( self::T_DESC, 'rank_math_description' ) );
		$tpl   = self::tpl( 'tax_' . $term->taxonomy );
		?>
		<tr class="form-field"><th scope="row" colspan="2"><h2 style="margin:0">Smart SEO AI</h2></th></tr>
		<tr class="form-field"><th scope="row"><label for="ssai-tt">عنوان سئو</label></th><td><input type="text" id="ssai-tt" name="ssai_term[title]" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( $tpl['title'] ); ?>"></td></tr>
		<tr class="form-field"><th scope="row"><label for="ssai-td">توضیحات متا</label></th><td><textarea id="ssai-td" name="ssai_term[desc]" rows="3"><?php echo esc_textarea( $desc ); ?></textarea></td></tr>
		<tr class="form-field"><th scope="row">نمایش در گوگل</th><td><label><input type="checkbox" name="ssai_term[noindex]" value="1" <?php checked( self::term_noindex( $term ) ); ?>> noindex</label></td></tr>
		<?php
	}

	public static function save_term( $term_id ) {
		if ( ! isset( $_POST['ssai_term_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ssai_term_nonce'] ) ), 'ssai_term_' . $term_id ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$in = isset( $_POST['ssai_term'] ) ? wp_unslash( $_POST['ssai_term'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_term_meta( $term_id, self::T_TITLE, sanitize_text_field( isset( $in['title'] ) ? $in['title'] : '' ) );
		update_term_meta( $term_id, self::T_DESC, sanitize_textarea_field( isset( $in['desc'] ) ? $in['desc'] : '' ) );
		update_term_meta( $term_id, self::T_NOINDEX, empty( $in['noindex'] ) ? '0' : '1' );
		do_action( 'ssai_sitemap_changed' );
	}
}
