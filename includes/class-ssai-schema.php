<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One JSON-LD @graph per page: Organization / LocalBusiness / Person, WebSite, WebPage,
 * BreadcrumbList, Article / BlogPosting / Service, and WooCommerce Product.
 */
class SSAI_Schema {

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'hooks' ) );
	}

	public static function hooks() {
		if ( is_admin() || is_feed() || ! SSAI_SEO::on( 'schema' ) ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'output' ), 2 );
		// WooCommerce prints its own product/breadcrumb/website markup – avoid duplicates.
		add_filter( 'woocommerce_structured_data_product', '__return_empty_array' );
		add_filter( 'woocommerce_structured_data_breadcrumblist', '__return_empty_array' );
		add_filter( 'woocommerce_structured_data_website', '__return_empty_array' );
	}

	public static function output() {
		$ctx = SSAI_SEO::context();
		if ( in_array( $ctx['type'], array( '404', 'search' ), true ) ) {
			return;
		}
		$g = self::graph( $ctx );
		if ( ! $g ) {
			return;
		}
		echo "\n" . '<script type="application/ld+json" class="ssai-schema">' . wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $g,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON.
	}

	/* ================================================================ Entity */

	public static function entity_id() {
		$e = SSAI_SEO::get( 'entity' );
		return home_url( '/' ) . ( 'Person' === $e['type'] ? '#person' : '#organization' );
	}

	public static function entity() {
		$e    = SSAI_SEO::get( 'entity' );
		$name = '' !== $e['name'] ? SSAI_SEO::t( 'entity_name', $e['name'] ) : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$type = 'Person' === $e['type'] ? 'Person' : ( 'LocalBusiness' === $e['type'] ? ( '' !== $e['subtype'] ? $e['subtype'] : 'LocalBusiness' ) : ( '' !== $e['subtype'] && 'Organization' === $e['type'] ? $e['subtype'] : 'Organization' ) );
		$n    = array(
			// Like Rank Math: a business subtype is also marked as Organization (publisher / logo).
			'@type' => in_array( $type, array( 'Person', 'Organization' ), true ) ? $type : array( $type, 'Organization' ),
			'@id'   => self::entity_id(),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);
		$logo = '' !== $e['logo'] ? SSAI_SEO::image_arr( attachment_url_to_postid( $e['logo'] ), $e['logo'] ) : ( get_theme_mod( 'custom_logo' ) ? SSAI_SEO::image_arr( (int) get_theme_mod( 'custom_logo' ) ) : null );
		if ( $logo ) {
			$img = array(
				'@type'   => 'ImageObject',
				'@id'     => home_url( '/' ) . '#logo',
				'url'     => $logo['url'],
				'caption' => $name,
			);
			if ( $logo['width'] ) {
				$img['width']  = $logo['width'];
				$img['height'] = $logo['height'];
			}
			if ( 'Person' === $type ) {
				$n['image'] = $img;
			} else {
				$n['logo']  = $img;
				$n['image'] = array( '@id' => home_url( '/' ) . '#logo' );
			}
		}
		foreach ( array( 'phone' => 'telephone', 'email' => 'email', 'price_range' => 'priceRange' ) as $k => $prop ) {
			if ( '' !== trim( (string) $e[ $k ] ) ) {
				$n[ $prop ] = trim( $e[ $k ] );
			}
		}
		$addr = array_filter(
			array(
				'streetAddress'   => $e['street'],
				'addressLocality' => $e['city'],
				'addressRegion'   => $e['region'],
				'postalCode'      => $e['postal'],
				'addressCountry'  => $e['country'],
			)
		);
		if ( $addr ) {
			$n['address'] = array_merge( array( '@type' => 'PostalAddress' ), $addr );
		}
		if ( 'Person' !== $type && 'Organization' !== $e['type'] ) {
			if ( '' !== $e['lat'] && '' !== $e['lng'] ) {
				$n['geo'] = array(
					'@type'     => 'GeoCoordinates',
					'latitude'  => (float) $e['lat'],
					'longitude' => (float) $e['lng'],
				);
			}
			$h = self::hours( $e['hours'] );
			if ( $h ) {
				$n['openingHoursSpecification'] = $h;
			}
		}
		$same = array();
		foreach ( preg_split( '/\s+/', (string) SSAI_SEO::get( 'same_as' ) . ' ' . SSAI_SEO::get( 'facebook' ) ) as $u ) {
			if ( preg_match( '#^https?://#', $u ) ) {
				$same[] = esc_url_raw( $u );
			}
		}
		$tw = trim( (string) SSAI_SEO::get( 'twitter' ), " @\t" );
		if ( '' !== $tw ) {
			$same[] = 'https://x.com/' . $tw;
		}
		if ( $same ) {
			$n['sameAs'] = array_values( array_unique( $same ) );
		}
		return $n;
	}

	/** "Saturday-Wednesday 09:00-20:00" / "شنبه تا چهارشنبه ۹:۰۰-۲۰:۰۰" / "Mo,Tu 10:00-14:00" per line. */
	public static function hours( $text ) {
		$week = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$alias = array(
			'Saturday'  => array( 'saturday', 'sat', 'sa', 'شنبه' ),
			'Sunday'    => array( 'sunday', 'sun', 'su', 'یکشنبه' ),
			'Monday'    => array( 'monday', 'mon', 'mo', 'دوشنبه' ),
			'Tuesday'   => array( 'tuesday', 'tue', 'tu', 'سهشنبه' ),
			'Wednesday' => array( 'wednesday', 'wed', 'we', 'چهارشنبه' ),
			'Thursday'  => array( 'thursday', 'thu', 'th', 'پنجشنبه' ),
			'Friday'    => array( 'friday', 'fri', 'fr', 'جمعه' ),
		);
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = strtr( $line, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
			$line = preg_replace( '/(یک|دو|سه|چهار|پنج)[\s\x{200C}]*شنبه/u', '$1شنبه', $line );
			if ( ! preg_match( '/(\d{1,2})[:.](\d{2})\s*(?:-|–|تا|to)\s*(\d{1,2})[:.](\d{2})/u', $line, $tm ) ) {
				continue;
			}
			$days_txt = trim( str_replace( $tm[0], ' ', $line ) );
			$tokens   = preg_split( '/[\s,،]+/u', mb_strtolower( $days_txt ), -1, PREG_SPLIT_NO_EMPTY );
			$days     = array();
			$range    = false;
			foreach ( $tokens as $tok ) {
				if ( in_array( $tok, array( 'تا', '-', '–', 'to' ), true ) ) {
					$range = true;
					continue;
				}
				foreach ( explode( '-', $tok ) as $i => $part ) {
					if ( $i > 0 ) {
						$range = true;
					}
					foreach ( $alias as $day => $names ) {
						if ( in_array( $part, $names, true ) ) {
							if ( $range && $days ) {
								$from = array_search( end( $days ), $week, true );
								$to   = array_search( $day, $week, true );
								for ( $k = 1; $k <= 7; $k++ ) {
									$d      = $week[ ( $from + $k ) % 7 ];
									$days[] = $d;
									if ( $d === $day || $k > 6 ) {
										break;
									}
								}
								unset( $to );
							} else {
								$days[] = $day;
							}
							$range = false;
						}
					}
				}
			}
			if ( ! $days ) {
				continue;
			}
			$out[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => array_values( array_unique( $days ) ),
				'opens'     => sprintf( '%02d:%s', $tm[1], $tm[2] ),
				'closes'    => sprintf( '%02d:%s', $tm[3], $tm[4] ),
			);
		}
		return $out;
	}

	/* ================================================================ Graph */

	public static function graph( $ctx ) {
		$m     = SSAI_SEO::meta( $ctx );
		$home  = home_url( '/' );
		$url   = '' !== $m['canonical'] ? $m['canonical'] : self::ctx_url( $ctx );
		$lang  = str_replace( '_', '-', $m['locale'] );
		$g     = array();
		$g[]   = self::entity();
		$g[]   = array(
			'@type'           => 'WebSite',
			'@id'             => $home . '#website',
			'url'             => $home,
			'name'            => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'publisher'       => array( '@id' => self::entity_id() ),
			'inLanguage'      => $lang,
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => home_url( '/?s={search_term_string}' ),
				'query-input' => 'required name=search_term_string',
			),
		);
		if ( '' === $url ) {
			return $g;
		}
		$page = array(
			'@type'      => in_array( $ctx['type'], array( 'term', 'home', 'author', 'date', 'pta' ), true ) || ! empty( $ctx['blog'] ) || ! empty( $ctx['shop'] ) ? 'CollectionPage' : 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $m['title'],
			'isPartOf'   => array( '@id' => $home . '#website' ),
			'inLanguage' => $lang,
		);
		if ( '' !== $m['desc'] ) {
			$page['description'] = $m['desc'];
		}
		if ( ! empty( $ctx['front'] ) || 'home' === $ctx['type'] ) {
			$page['about'] = array( '@id' => self::entity_id() );
		}
		if ( 'post' === $ctx['type'] && '' !== $m['published'] ) {
			$page['datePublished'] = $m['published'];
			$page['dateModified']  = $m['modified'];
		}
		if ( $m['image'] ) {
			$img = array(
				'@type' => 'ImageObject',
				'@id'   => $url . '#primaryimage',
				'url'   => $m['image']['url'],
			);
			if ( $m['image']['width'] ) {
				$img['width']  = $m['image']['width'];
				$img['height'] = $m['image']['height'];
			}
			if ( '' !== $m['image']['alt'] ) {
				$img['caption'] = $m['image']['alt'];
			}
			$g[]                         = $img;
			$page['primaryImageOfPage'] = array( '@id' => $url . '#primaryimage' );
		}
		$crumbs = SSAI_SEO::crumbs( $ctx );
		if ( count( $crumbs ) > 1 ) {
			$list = array();
			foreach ( $crumbs as $i => $c ) {
				$li = array(
					'@type'    => 'ListItem',
					'position' => $i + 1,
					'name'     => wp_specialchars_decode( $c[0], ENT_QUOTES ),
				);
				if ( '' !== $c[1] && ! is_wp_error( $c[1] ) ) {
					$li['item'] = $c[1];
				}
				$list[] = $li;
			}
			$g[]                = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $list,
			);
			$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		}
		$g[] = $page;

		if ( 'post' === $ctx['type'] && empty( $ctx['blog'] ) && empty( $ctx['shop'] ) ) {
			$id   = (int) $ctx['id'];
			$pt   = get_post_type( $id );
			$type = (string) get_post_meta( $id, SSAI_SEO::M_SCHEMA, true );
			$type = '' === $type ? SSAI_SEO::default_schema( $pt ) : $type;
			if ( 'Product' === $type && function_exists( 'wc_get_product' ) ) {
				$prod = wc_get_product( $id );
				if ( $prod ) {
					$g[] = self::product( $prod, $url, $m );
				}
			} elseif ( in_array( $type, array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) ) {
				$p      = get_post( $id );
				$aid    = $home . '#/schema/person/' . md5( (string) $p->post_author );
				$g[]    = array(
					'@type' => 'Person',
					'@id'   => $aid,
					'name'  => get_the_author_meta( 'display_name', $p->post_author ),
					'url'   => get_author_posts_url( $p->post_author ),
				);
				$art    = array(
					'@type'            => $type,
					'@id'              => $url . '#article',
					'headline'         => mb_substr( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), 0, 110 ),
					'datePublished'    => $m['published'],
					'dateModified'     => $m['modified'],
					'author'           => array( '@id' => $aid ),
					'publisher'        => array( '@id' => self::entity_id() ),
					'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
					'isPartOf'         => array( '@id' => $url . '#webpage' ),
					'inLanguage'       => $lang,
				);
				if ( '' !== $m['desc'] ) {
					$art['description'] = $m['desc'];
				}
				if ( $m['image'] ) {
					$art['image'] = array( '@id' => $url . '#primaryimage' );
				}
				$cat = SSAI_SEO::primary_term( $id );
				if ( $cat ) {
					$art['articleSection'] = $cat->name;
				}
				$kw = SSAI_Content::get_focus_keywords( $id );
				if ( $kw ) {
					$art['keywords'] = implode( ', ', $kw );
				}
				$g[] = $art;
			} elseif ( 'Service' === $type ) {
				$e   = SSAI_SEO::get( 'entity' );
				$svc = array(
					'@type'            => 'Service',
					'@id'              => $url . '#service',
					'name'             => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
					'url'              => $url,
					'provider'         => array( '@id' => self::entity_id() ),
					'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
				);
				if ( '' !== $m['desc'] ) {
					$svc['description'] = $m['desc'];
				}
				if ( '' !== $e['city'] ) {
					$svc['areaServed'] = $e['city'];
				}
				if ( $m['image'] ) {
					$svc['image'] = array( '@id' => $url . '#primaryimage' );
				}
				$g[] = $svc;
			}
		}
		if ( 'post' === $ctx['type'] && empty( $ctx['blog'] ) && empty( $ctx['shop'] ) ) {
			$have = array();
			foreach ( $g as $node ) {
				foreach ( (array) ( isset( $node['@type'] ) ? $node['@type'] : array() ) as $ty ) {
					$have[ $ty ] = true;
				}
			}
			foreach ( self::custom_nodes( (int) $ctx['id'], $url, $m, $ctx ) as $node ) {
				$t = (array) $node['@type'];
				if ( array_intersect( $t, array_keys( $have ) ) && array_intersect( $t, array( 'Article', 'BlogPosting', 'NewsArticle', 'Product', 'WebPage', 'BreadcrumbList', 'Organization', 'WebSite', 'Person' ) ) ) {
					continue; // Already generated above.
				}
				$g[] = $node;
			}
		}
		return apply_filters( 'ssai_schema_graph', $g, $ctx );
	}

	/**
	 * Custom schemas made in Rank Math's Schema Generator (MedicalProcedure, Service, FAQPage, VideoObject…).
	 * They live in post meta "rank_math_schema_{Type}"; a copy is kept in our own meta by the migration.
	 */
	public static function raw_custom( $post_id ) {
		// Once our own list exists (even empty, after deleting), it replaces Rank Math's.
		if ( metadata_exists( 'post', $post_id, '_ssai_schema_custom' ) ) {
			$own = get_post_meta( $post_id, '_ssai_schema_custom', true );
			return is_array( $own ) ? $own : array();
		}
		$out = array();
		foreach ( get_post_meta( $post_id ) as $k => $vals ) {
			if ( 0 === strpos( $k, 'rank_math_schema_' ) ) {
				$v = maybe_unserialize( $vals[0] );
				if ( is_array( $v ) && ! empty( $v['@type'] ) ) {
					$out[] = $v;
				}
			}
		}
		return $out;
	}

	/**
	 * Rank Math Pro "Schema Templates": posts of type rank_math_schema whose schema is applied to pages
	 * by display conditions. A copy is kept in an option by the migration (survives Rank Math removal).
	 *
	 * @return array of [ id, title, schema, conditions ]
	 */
	public static function rm_templates() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$saved = get_option( 'ssai_schema_templates' );
		if ( is_array( $saved ) && $saved ) {
			$cache = $saved;
			return $cache;
		}
		$cache = array();
		$ids   = get_posts(
			array(
				'post_type'        => 'rank_math_schema',
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		foreach ( $ids as $tid ) {
			foreach ( get_post_meta( $tid ) as $k => $vals ) {
				if ( 0 !== strpos( $k, 'rank_math_schema_' ) ) {
					continue;
				}
				$v = maybe_unserialize( $vals[0] );
				if ( ! is_array( $v ) || empty( $v['@type'] ) ) {
					continue;
				}
				$cond    = isset( $v['metadata']['displayConditions'] ) ? (array) $v['metadata']['displayConditions'] : array();
				$cache[] = array(
					'id'         => (int) $tid,
					'title'      => get_the_title( $tid ),
					'schema'     => $v,
					'conditions' => $cond,
				);
			}
		}
		return $cache;
	}

	/** Does a template's display condition apply to this post? (include / exclude, general / singular). */
	public static function template_matches( $tpl, $post_id ) {
		$pt       = get_post_type( $post_id );
		$included = false;
		foreach ( (array) $tpl['conditions'] as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$cat  = isset( $c['category'] ) ? (string) $c['category'] : 'general';
			$type = isset( $c['type'] ) ? (string) $c['type'] : 'all';
			$val  = isset( $c['value'] ) ? $c['value'] : '';
			$hit  = false;
			if ( 'general' === $cat ) {
				$hit = true;
			} elseif ( 'singular' === $cat ) {
				$type_ok = in_array( $type, array( '', 'all' ), true ) || $type === $pt;
				$ids     = array_filter( array_map( 'absint', (array) ( is_string( $val ) ? explode( ',', $val ) : $val ) ) );
				$hit     = $type_ok && ( ! $ids || in_array( (int) $post_id, $ids, true ) );
			}
			if ( $hit ) {
				if ( isset( $c['condition'] ) && 'exclude' === $c['condition'] ) {
					return false;
				}
				$included = true;
			}
		}
		return $included;
	}

	/** Render given raw nodes for a post (variables replaced, Rank Math metadata removed). */
	public static function custom_nodes_from( $raw, $post_id, $url, $m ) {
		return self::render_nodes( $raw, $post_id, $url, $m, array( 'type' => 'post', 'id' => $post_id, 'paged' => 1 ), false );
	}

	public static function custom_nodes( $post_id, $url, $m, $ctx ) {
		$raw   = self::raw_custom( $post_id );
		$types = array();
		foreach ( $raw as $r ) {
			$types = array_merge( $types, (array) $r['@type'] );
		}
		foreach ( self::rm_templates() as $tpl ) {
			if ( self::template_matches( $tpl, $post_id ) && ! array_intersect( (array) $tpl['schema']['@type'], $types ) ) {
				$raw[] = $tpl['schema'];
			}
		}
		return self::render_nodes( $raw, $post_id, $url, $m, $ctx, true );
	}

	private static function render_nodes( $raw, $post_id, $url, $m, $ctx, $skip_faq ) {
		if ( ! $raw ) {
			return array();
		}
		$has_faq = $skip_faq && (bool) SSAI_AEO::get_faq( $post_id );
		$p       = get_post( $post_id );
		$vars    = array(
			'%seo_title%'       => $m['title'],
			'%seo_description%' => $m['desc'],
			'%url%'             => $url,
			'%post_thumbnail%'  => $m['image'] ? $m['image']['url'] : '',
			'%featured_image%'  => $m['image'] ? $m['image']['url'] : '',
			'%name%'            => $p ? get_the_author_meta( 'display_name', $p->post_author ) : '',
			'%sitename%'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'%org_name%'        => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'%org_url%'         => home_url( '/' ),
		);
		$fix     = function ( $v ) use ( &$fix, $vars, $ctx, $p ) {
			if ( is_array( $v ) ) {
				$o = array();
				foreach ( $v as $k => $x ) {
					if ( 'metadata' === $k ) {
						continue;
					}
					$o[ $k ] = $fix( $x );
				}
				return $o;
			}
			if ( ! is_string( $v ) || false === strpos( $v, '%' ) ) {
				return $v;
			}
			$v = strtr( $v, $vars );
			$v = preg_replace_callback(
				'/%(date|modified)\(([^)]*)\)%/',
				function ( $mm ) use ( $p ) {
					if ( ! $p ) {
						return '';
					}
					$f = '' !== $mm[2] ? $mm[2] : 'c';
					return 'date' === $mm[1] ? get_post_time( $f, false, $p ) : get_post_modified_time( $f, false, $p );
				},
				$v
			);
			return SSAI_SEO::replace_vars( $v, $ctx );
		};
		$out = array();
		foreach ( $raw as $i => $node ) {
			$t = (array) $node['@type'];
			if ( $has_faq && in_array( 'FAQPage', $t, true ) ) {
				continue; // Our own FAQ already prints FAQPage.
			}
			$n = $fix( $node );
			if ( empty( $n['@id'] ) ) {
				$n['@id'] = $url . '#schema-' . ( $i + 1 );
			}
			$out[] = $n;
		}
		return $out;
	}

	private static function ctx_url( $ctx ) {
		if ( 'post' === $ctx['type'] ) {
			return get_permalink( $ctx['id'] );
		}
		if ( 'term' === $ctx['type'] ) {
			$l = get_term_link( (int) $ctx['id'] );
			return is_wp_error( $l ) ? '' : $l;
		}
		if ( 'home' === $ctx['type'] ) {
			return home_url( '/' );
		}
		return '';
	}

	/** Google only accepts ISO 4217 currencies; Iranian WooCommerce shops often use Toman. */
	public static function currency( $code, $price ) {
		$mult = array(
			'IRT'  => 10,
			'IRHT' => 10000,
			'IRHR' => 1000,
		);
		if ( isset( $mult[ $code ] ) ) {
			return array( 'IRR', (float) $price * $mult[ $code ] );
		}
		return array( $code, (float) $price );
	}

	private static function price_str( $p ) {
		return rtrim( rtrim( number_format( (float) $p, 2, '.', '' ), '0' ), '.' );
	}

	public static function product( $prod, $url, $m ) {
		$id   = $prod->get_id();
		$cur  = get_woocommerce_currency();
		$desc = wp_strip_all_tags( $prod->get_short_description() ? $prod->get_short_description() : $prod->get_description() );
		$n    = array(
			'@type'            => 'Product',
			'@id'              => $url . '#product',
			'name'             => $prod->get_name(),
			'url'              => $url,
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
		);
		$desc = trim( preg_replace( '/\s+/u', ' ', $desc ) );
		if ( '' !== $desc ) {
			$n['description'] = mb_substr( $desc, 0, 5000 );
		} elseif ( '' !== $m['desc'] ) {
			$n['description'] = $m['desc'];
		}
		$imgs = array();
		foreach ( array_merge( array( $prod->get_image_id() ), $prod->get_gallery_image_ids() ) as $aid ) {
			if ( $aid && count( $imgs ) < 5 ) {
				$u = wp_get_attachment_image_url( $aid, 'full' );
				if ( $u ) {
					$imgs[] = $u;
				}
			}
		}
		if ( $imgs ) {
			$n['image'] = $imgs;
		}
		if ( $prod->get_sku() ) {
			$n['sku'] = $prod->get_sku();
		}
		if ( method_exists( $prod, 'get_global_unique_id' ) && $prod->get_global_unique_id() ) {
			$n['gtin'] = $prod->get_global_unique_id();
		}
		$brand = '';
		foreach ( array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) as $bt ) {
			if ( taxonomy_exists( $bt ) ) {
				$b = get_the_terms( $id, $bt );
				if ( $b && ! is_wp_error( $b ) ) {
					$brand = $b[0]->name;
					break;
				}
			}
		}
		if ( '' === $brand ) {
			$brand = (string) $prod->get_attribute( 'pa_brand' );
			$brand = '' === $brand ? (string) $prod->get_attribute( 'brand' ) : $brand;
		}
		if ( '' !== $brand ) {
			$n['brand'] = array(
				'@type' => 'Brand',
				'name'  => trim( explode( ',', $brand )[0] ),
			);
		}
		$stock = $prod->get_stock_status();
		$avail = 'outofstock' === $stock ? 'OutOfStock' : ( 'onbackorder' === $stock ? 'BackOrder' : 'InStock' );
		if ( $prod->is_type( 'variable' ) ) {
			$prices = $prod->get_variation_prices( true );
			$list   = array_filter( array_map( 'floatval', isset( $prices['price'] ) ? $prices['price'] : array() ) );
			if ( $list ) {
				list( $c, $low )  = self::currency( $cur, min( $list ) );
				list( , $high )   = self::currency( $cur, max( $list ) );
				$n['offers']      = array(
					'@type'         => 'AggregateOffer',
					'lowPrice'      => self::price_str( $low ),
					'highPrice'     => self::price_str( $high ),
					'offerCount'    => count( $list ),
					'priceCurrency' => $c,
					'availability'  => 'https://schema.org/' . $avail,
					'url'           => $url,
					'seller'        => array( '@id' => self::entity_id() ),
				);
			}
		} elseif ( '' !== (string) $prod->get_price() ) {
			list( $c, $price ) = self::currency( $cur, $prod->get_price() );
			$until             = $prod->is_on_sale() && $prod->get_date_on_sale_to() ? $prod->get_date_on_sale_to()->date( 'Y-m-d' ) : gmdate( 'Y-12-31', strtotime( '+1 year' ) );
			$n['offers']       = array(
				'@type'           => 'Offer',
				'price'           => self::price_str( $price ),
				'priceCurrency'   => $c,
				'priceValidUntil' => $until,
				'availability'    => 'https://schema.org/' . $avail,
				'itemCondition'   => 'https://schema.org/NewCondition',
				'url'             => $url,
				'seller'          => array( '@id' => self::entity_id() ),
			);
		}
		if ( function_exists( 'wc_review_ratings_enabled' ) && wc_review_ratings_enabled() && $prod->get_review_count() > 0 ) {
			$n['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) round( (float) $prod->get_average_rating(), 2 ),
				'reviewCount' => (int) $prod->get_review_count(),
			);
			$reviews              = get_comments(
				array(
					'post_id' => $id,
					'status'  => 'approve',
					'type'    => 'review',
					'number'  => 3,
				)
			);
			foreach ( $reviews as $r ) {
				$rating = (int) get_comment_meta( $r->comment_ID, 'rating', true );
				if ( $rating ) {
					$n['review'][] = array(
						'@type'         => 'Review',
						'reviewRating'  => array(
							'@type'       => 'Rating',
							'ratingValue' => $rating,
							'bestRating'  => 5,
						),
						'author'        => array(
							'@type' => 'Person',
							'name'  => $r->comment_author,
						),
						'datePublished' => mysql2date( 'c', $r->comment_date_gmt ),
						'reviewBody'    => wp_strip_all_tags( $r->comment_content ),
					);
				}
			}
		}
		return $n;
	}
}
