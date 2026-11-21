<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Local SEO: branches (locations), KML file for Google (/locations.kml + local-sitemap.xml),
 * LocalBusiness schema on each branch page, and the [ssai_local] shortcode (address, phone,
 * hours, map). The main business stays in SEO settings › Business; branches are extra.
 */
class SSAI_Local {

	const PT    = 'ssai_location';
	const META  = '_ssai_loc';
	const SLUG  = 'edit.php?post_type=ssai_location';

	public static function fields() {
		return array(
			'subtype'     => array( 'نوع کسب‌وکار (Schema)', 'LocalBusiness', 'ltr' ),
			'phone'       => array( 'تلفن', '+98-21-1234-5678', 'ltr' ),
			'email'       => array( 'ایمیل', '', 'ltr' ),
			'street'      => array( 'آدرس (خیابان و پلاک)', '', '' ),
			'city'        => array( 'شهر', '', '' ),
			'region'      => array( 'استان', '', '' ),
			'postal'      => array( 'کد پستی', '', 'ltr' ),
			'country'     => array( 'کشور (کد دوحرفی)', 'IR', 'ltr' ),
			'lat'         => array( 'عرض جغرافیایی', '35.6892', 'ltr' ),
			'lng'         => array( 'طول جغرافیایی', '51.3890', 'ltr' ),
			'price_range' => array( 'محدوده قیمت', '$$', '' ),
			'map_url'     => array( 'لینک گوگل‌مپ / نشان / بلد', 'https://maps.google.com/?cid=…', 'ltr' ),
			'page'        => array( 'صفحه این شعبه (شناسه یا آدرس)', '123', 'ltr' ),
			'hours'       => array( 'ساعت کاری', "شنبه تا چهارشنبه 09:00-20:00\nپنجشنبه 09:00-14:00", '' ),
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'serve_kml' ), 98 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . self::PT, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_shortcode( 'ssai_local', array( __CLASS__, 'shortcode' ) );
		add_filter( 'ssai_schema_graph', array( __CLASS__, 'schema' ), 10, 2 );
		add_filter( 'manage_' . self::PT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::PT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function register() {
		register_post_type(
			self::PT,
			array(
				'labels'          => array(
					'name'          => 'شعبه‌ها',
					'singular_name' => 'شعبه',
					'add_new'       => 'افزودن شعبه',
					'add_new_item'  => 'افزودن شعبه',
					'edit_item'     => 'ویرایش شعبه',
					'all_items'     => 'شعبه‌ها',
					'not_found'     => 'شعبه‌ای ثبت نشده است.',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'supports'        => array( 'title' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'edit_post'          => 'ssai_seo_settings',
					'read_post'          => 'ssai_seo_settings',
					'delete_post'        => 'ssai_seo_settings',
					'edit_posts'         => 'ssai_seo_settings',
					'edit_others_posts'  => 'ssai_seo_settings',
					'delete_posts'       => 'ssai_seo_settings',
					'publish_posts'      => 'ssai_seo_settings',
					'read_private_posts' => 'ssai_seo_settings',
					'create_posts'       => 'ssai_seo_settings',
				),
				'map_meta_cap'    => false,
			)
		);
	}

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'سئوی محلی (شعبه‌ها)', 'سئوی محلی', 'ssai_seo_settings', self::SLUG );
	}

	public static function get( $id ) {
		$d = get_post_meta( $id, self::META, true );
		$d = is_array( $d ) ? $d : array();
		foreach ( array_keys( self::fields() ) as $k ) {
			if ( ! isset( $d[ $k ] ) ) {
				$d[ $k ] = '';
			}
		}
		return $d;
	}

	public static function boxes() {
		add_meta_box( 'ssai-loc', 'اطلاعات شعبه', array( __CLASS__, 'box' ), self::PT, 'normal', 'high' );
	}

	public static function box( $post ) {
		$d = self::get( $post->ID );
		wp_nonce_field( 'ssai_loc_' . $post->ID, 'ssai_loc_nonce' );
		echo '<table class="form-table" role="presentation" dir="rtl">';
		foreach ( self::fields() as $k => $f ) {
			echo '<tr><th><label for="ssai-loc-' . esc_attr( $k ) . '">' . esc_html( $f[0] ) . '</label></th><td>';
			if ( 'hours' === $k ) {
				echo '<textarea id="ssai-loc-hours" class="large-text" rows="4" name="ssai_loc[hours]" placeholder="' . esc_attr( $f[1] ) . '">' . esc_textarea( $d[ $k ] ) . '</textarea>';
				$h = SSAI_Schema::hours( $d['hours'] );
				if ( $h ) {
					echo '<p class="description">برداشت افزونه: ';
					foreach ( $h as $x ) {
						echo esc_html( implode( '، ', $x['dayOfWeek'] ) . ' ' . $x['opens'] . '–' . $x['closes'] ) . ' | ';
					}
					echo '</p>';
				}
			} else {
				echo '<input type="text" class="regular-text" id="ssai-loc-' . esc_attr( $k ) . '" name="ssai_loc[' . esc_attr( $k ) . ']" value="' . esc_attr( $d[ $k ] ) . '" placeholder="' . esc_attr( $f[1] ) . '"' . ( $f[2] ? ' dir="ltr"' : '' ) . '>';
			}
			if ( 'subtype' === $k ) {
				echo '<p class="description">مثلاً Dentist، MedicalClinic، Store، Restaurant، AutoRepair (نام انگلیسی از schema.org).</p>';
			} elseif ( 'lat' === $k ) {
				echo '<p class="description">در گوگل‌مپ روی محل راست‌کلیک کنید؛ عدد اول عرض و عدد دوم طول جغرافیایی است.</p>';
			} elseif ( 'page' === $k ) {
				echo '<p class="description">Schema این شعبه (LocalBusiness) در همان صفحه چاپ می‌شود. خالی = فقط در فایل KML و کد کوتاه.</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		echo '<p>کد کوتاه نمایش این شعبه در هر صفحه: <code dir="ltr">[ssai_local id="' . (int) $post->ID . '"]</code> — همه شعبه‌ها: <code dir="ltr">[ssai_local id="all"]</code> — کسب‌وکار اصلی: <code dir="ltr">[ssai_local]</code></p>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['ssai_loc_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ssai_loc_nonce'] ) ), 'ssai_loc_' . $post_id ) || ! current_user_can( 'ssai_seo_settings' ) ) {
			return;
		}
		$in  = isset( $_POST['ssai_loc'] ) && is_array( $_POST['ssai_loc'] ) ? wp_unslash( $_POST['ssai_loc'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$out = array();
		foreach ( array_keys( self::fields() ) as $k ) {
			$v         = isset( $in[ $k ] ) ? (string) $in[ $k ] : '';
			$out[ $k ] = 'hours' === $k ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
		}
		$out['subtype'] = preg_replace( '/[^A-Za-z]/', '', $out['subtype'] );
		$out['email']   = sanitize_email( $out['email'] );
		$out['map_url'] = esc_url_raw( $out['map_url'] );
		$out['country'] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $out['country'] ), 0, 2 ) );
		foreach ( array( 'lat', 'lng' ) as $k ) {
			$out[ $k ] = is_numeric( str_replace( ',', '.', $out[ $k ] ) ) ? str_replace( ',', '.', $out[ $k ] ) : '';
		}
		update_post_meta( $post_id, self::META, $out );
		SSAI_Sitemap::bump();
	}

	public static function columns( $c ) {
		return array_merge( array_slice( $c, 0, 2 ), array( 'ssai_addr' => 'آدرس', 'ssai_sc' => 'کد کوتاه' ), array_slice( $c, 2 ) );
	}

	public static function column( $col, $id ) {
		$d = self::get( $id );
		if ( 'ssai_addr' === $col ) {
			echo esc_html( trim( $d['city'] . '، ' . $d['street'], '، ' ) );
			echo ( '' === $d['lat'] ? ' <span style="color:#b32d2e">— مختصات ندارد (در KML نمی‌آید)</span>' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		} elseif ( 'ssai_sc' === $col ) {
			echo '<code dir="ltr">[ssai_local id="' . (int) $id . '"]</code>';
		}
	}

	/** All published branches as arrays (with 'name' and 'id'). */
	public static function locations() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::PT, 'post_status' => 'publish', 'numberposts' => 500, 'orderby' => 'menu_order title', 'order' => 'ASC' ) ) as $p ) {
			$out[ $p->ID ] = array_merge( self::get( $p->ID ), array( 'id' => $p->ID, 'name' => $p->post_title ) );
		}
		return $out;
	}

	/** Main business from SEO settings in the same shape. */
	public static function main() {
		$e = SSAI_SEO::get( 'entity' );
		return array_merge(
			array_fill_keys( array_keys( self::fields() ), '' ),
			$e,
			array(
				'id'      => 0,
				'name'    => '' !== $e['name'] ? $e['name'] : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'map_url' => '',
				'page'    => (string) get_option( 'page_on_front' ),
			)
		);
	}

	private static function page_url( $l ) {
		$p = trim( (string) $l['page'] );
		if ( '' === $p ) {
			return home_url( '/' );
		}
		if ( ctype_digit( $p ) ) {
			return (int) $p ? get_permalink( (int) $p ) : home_url( '/' );
		}
		return esc_url_raw( $p );
	}

	private static function addr( $l ) {
		return implode( '، ', array_filter( array( $l['street'], $l['city'], $l['region'], $l['postal'] ) ) );
	}

	/** Points with coordinates for the KML file. */
	public static function kml_points() {
		$pts = array();
		$m   = self::main();
		if ( 'Person' !== SSAI_SEO::get( 'entity' )['type'] && '' !== $m['lat'] && '' !== $m['lng'] ) {
			$pts[] = $m;
		}
		foreach ( self::locations() as $l ) {
			if ( '' !== $l['lat'] && '' !== $l['lng'] ) {
				$pts[] = $l;
			}
		}
		return $pts;
	}

	public static function kml_url() {
		return home_url( '/locations.kml' );
	}

	public static function serve_kml() {
		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( untrailingslashit( (string) wp_parse_url( self::kml_url(), PHP_URL_PATH ) ) !== untrailingslashit( $path ) ) {
			return;
		}
		$pts = self::kml_points();
		if ( ! $pts ) {
			return;
		}
		$x = function ( $s ) {
			return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		};
		$o  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<Document>\n";
		$o .= "\t<name>" . $x( get_bloginfo( 'name' ) ) . "</name>\n\t<atom:link href=\"" . $x( home_url( '/' ) ) . "\"/>\n";
		foreach ( $pts as $p ) {
			$desc = trim( self::addr( $p ) . ( '' !== $p['phone'] ? ' — ' . $p['phone'] : '' ) );
			$o   .= "\t<Placemark>\n\t\t<name>" . $x( $p['name'] ) . "</name>\n\t\t<description>" . $x( $desc ) . "</description>\n\t\t<address>" . $x( self::addr( $p ) ) . "</address>\n";
			if ( '' !== $p['phone'] ) {
				$o .= "\t\t<phoneNumber>" . $x( $p['phone'] ) . "</phoneNumber>\n";
			}
			$o .= "\t\t<atom:link href=\"" . $x( self::page_url( $p ) ) . "\"/>\n\t\t<Point><coordinates>" . (float) $p['lng'] . ',' . (float) $p['lat'] . "</coordinates></Point>\n\t</Placemark>\n";
		}
		$o .= "</Document>\n</kml>";
		status_header( 200 );
		header( 'Content-Type: application/vnd.google-earth.kml+xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $o; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		exit;
	}

	/** LocalBusiness node for a branch. */
	public static function node( $l ) {
		$url = self::page_url( $l );
		$n   = array(
			'@type'              => '' !== $l['subtype'] ? $l['subtype'] : 'LocalBusiness',
			'@id'                => $url . '#location-' . (int) $l['id'],
			'name'               => $l['name'],
			'url'                => $url,
			'parentOrganization' => array( '@id' => SSAI_Schema::entity_id() ),
		);
		foreach ( array( 'phone' => 'telephone', 'email' => 'email', 'price_range' => 'priceRange' ) as $k => $p ) {
			if ( '' !== $l[ $k ] ) {
				$n[ $p ] = $l[ $k ];
			}
		}
		$addr = array_filter(
			array(
				'streetAddress'   => $l['street'],
				'addressLocality' => $l['city'],
				'addressRegion'   => $l['region'],
				'postalCode'      => $l['postal'],
				'addressCountry'  => $l['country'],
			)
		);
		if ( $addr ) {
			$n['address'] = array_merge( array( '@type' => 'PostalAddress' ), $addr );
		}
		if ( '' !== $l['lat'] && '' !== $l['lng'] ) {
			$n['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $l['lat'],
				'longitude' => (float) $l['lng'],
			);
		}
		$h = SSAI_Schema::hours( $l['hours'] );
		if ( $h ) {
			$n['openingHoursSpecification'] = $h;
		}
		if ( '' !== $l['map_url'] ) {
			$n['hasMap'] = $l['map_url'];
		}
		$logo = SSAI_SEO::get( 'entity' )['logo'];
		if ( '' !== $logo ) {
			$n['image'] = $logo;
		}
		return $n;
	}

	public static function schema( $g, $ctx ) {
		if ( empty( $ctx['type'] ) || 'post' !== $ctx['type'] || empty( $ctx['id'] ) ) {
			return $g;
		}
		$id = (int) $ctx['id'];
		foreach ( self::locations() as $l ) {
			$p = trim( (string) $l['page'] );
			if ( '' !== $p && ( ( ctype_digit( $p ) && (int) $p === $id ) || ( ! ctype_digit( $p ) && untrailingslashit( $p ) === untrailingslashit( (string) get_permalink( $id ) ) ) ) ) {
				$g[] = self::node( $l );
			}
		}
		return $g;
	}

	/** [ssai_local id="" show="name,address,phone,email,hours,map"] */
	public static function shortcode( $atts ) {
		$a    = shortcode_atts( array( 'id' => '', 'show' => 'name,address,phone,hours,map' ), $atts, 'ssai_local' );
		$show = array_map( 'trim', explode( ',', strtolower( $a['show'] ) ) );
		if ( 'all' === $a['id'] ) {
			$list = array_values( self::locations() );
		} elseif ( '' !== $a['id'] && ctype_digit( (string) $a['id'] ) && self::PT === get_post_type( (int) $a['id'] ) ) {
			$all  = self::locations();
			$list = isset( $all[ (int) $a['id'] ] ) ? array( $all[ (int) $a['id'] ] ) : array();
		} else {
			$list = array( self::main() );
		}
		$out = '';
		foreach ( $list as $l ) {
			$out .= '<div class="ssai-local" style="margin:0 0 1.5em">';
			if ( in_array( 'name', $show, true ) ) {
				$out .= '<strong class="ssai-local-name">' . esc_html( $l['name'] ) . '</strong><br>';
			}
			if ( in_array( 'address', $show, true ) && '' !== self::addr( $l ) ) {
				$out .= '<span class="ssai-local-address">📍 ' . esc_html( self::addr( $l ) ) . '</span><br>';
			}
			if ( in_array( 'phone', $show, true ) && '' !== $l['phone'] ) {
				$out .= '<span class="ssai-local-phone">📞 <a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $l['phone'] ) ) . '" dir="ltr">' . esc_html( $l['phone'] ) . '</a></span><br>';
			}
			if ( in_array( 'email', $show, true ) && '' !== $l['email'] ) {
				$out .= '<span class="ssai-local-email">✉️ <a href="mailto:' . esc_attr( antispambot( $l['email'] ) ) . '">' . esc_html( antispambot( $l['email'] ) ) . '</a></span><br>';
			}
			if ( in_array( 'hours', $show, true ) && '' !== trim( $l['hours'] ) ) {
				$out .= '<span class="ssai-local-hours">🕘 ' . nl2br( esc_html( trim( $l['hours'] ) ) ) . '</span><br>';
			}
			if ( in_array( 'map', $show, true ) && '' !== $l['lat'] && '' !== $l['lng'] ) {
				$q    = rawurlencode( (float) $l['lat'] . ',' . (float) $l['lng'] );
				$out .= '<iframe class="ssai-local-map" title="' . esc_attr( $l['name'] ) . '" src="https://maps.google.com/maps?q=' . $q . '&z=15&output=embed" width="100%" height="300" style="border:0;margin-top:8px" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
				if ( '' !== $l['map_url'] ) {
					$out .= '<br><a href="' . esc_url( $l['map_url'] ) . '" target="_blank" rel="noopener">مسیریابی</a>';
				}
			}
			$out .= '</div>';
		}
		return $out;
	}
}
