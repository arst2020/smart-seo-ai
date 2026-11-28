<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-page schema builder: ready-made forms for common types, AI suggestion from the page text
 * (applied only after the admin saves), and checks for the fields Google needs.
 * Schemas are stored in post meta "_ssai_schema_custom" (same place as schemas copied from Rank Math)
 * and printed by SSAI_Schema inside the page's @graph.
 */
class SSAI_Schema_Builder {

	const META = '_ssai_schema_custom';

	public static function init() {
		foreach ( array( 'suggest', 'save', 'delete' ) as $a ) {
			add_action( 'wp_ajax_ssai_schema_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/* ================================================================ Type definitions */

	/**
	 * Field kinds: text, textarea, url, date, select, lines (one per line), faq (question/answer list),
	 * price (amount + currency). "req" = Google required, "rec" = recommended.
	 */
	public static function types() {
		$cur   = array(
			'IRR' => 'ریال',
			'IRT' => 'تومان (به ریال تبدیل می‌شود)',
			'USD' => 'دلار',
			'EUR' => 'یورو',
		);
		$avail = array(
			'InStock'     => 'موجود',
			'OutOfStock'  => 'ناموجود',
			'PreOrder'    => 'پیش‌سفارش',
			'BackOrder'   => 'سفارشی / با تأخیر',
		);
		return array(
			'Service'          => array(
				'label'  => 'خدمت (Service)',
				'help'   => 'برای صفحه معرفی یک خدمت: ایمپلنت، لمینت، طراحی سایت، تعمیر…',
				'fields' => array(
					'name'        => array( 'نام خدمت', 'text', 'req', '%title%' ),
					'description' => array( 'توضیح', 'textarea', 'req', '%seo_description%' ),
					'serviceType' => array( 'نوع خدمت', 'text', 'rec' ),
					'areaServed'  => array( 'منطقه ارائه خدمت', 'text', 'rec' ),
					'image'       => array( 'تصویر', 'url', 'rec', '%post_thumbnail%' ),
					'price'       => array( 'قیمت (اختیاری)', 'price', '', '', $cur ),
				),
			),
			'MedicalProcedure' => array(
				'label'  => 'روش درمانی (MedicalProcedure)',
				'help'   => 'برای صفحه یک درمان یا جراحی: ایمپلنت، عصب‌کشی، ارتودنسی…',
				'fields' => array(
					'name'          => array( 'نام روش درمان', 'text', 'req', '%title%' ),
					'description'   => array( 'توضیح', 'textarea', 'req', '%seo_description%' ),
					'procedureType' => array(
						'نوع روش',
						'select',
						'rec',
						'',
						array(
							''                      => '—',
							'SurgicalProcedure'     => 'جراحی',
							'NoninvasiveProcedure'  => 'غیرتهاجمی',
							'PercutaneousProcedure' => 'از راه پوست',
						),
					),
					'bodyLocation'  => array( 'محل درمان در بدن', 'text', 'rec' ),
					'preparation'   => array( 'آمادگی قبل از درمان', 'textarea', '' ),
					'howPerformed'  => array( 'نحوه انجام', 'textarea', 'rec' ),
					'followup'      => array( 'مراقبت بعد از درمان', 'textarea', '' ),
					'image'         => array( 'تصویر', 'url', '', '%post_thumbnail%' ),
				),
			),
			'Physician'        => array(
				'label'  => 'پزشک (Physician)',
				'help'   => 'برای صفحه معرفی یک پزشک یا دندانپزشک.',
				'fields' => array(
					'name'             => array( 'نام پزشک', 'text', 'req' ),
					'description'      => array( 'معرفی کوتاه', 'textarea', 'rec' ),
					'medicalSpecialty' => array(
						'تخصص',
						'select',
						'rec',
						'',
						array(
							''                 => '—',
							'Dentistry'        => 'دندانپزشکی',
							'PlasticSurgery'   => 'جراحی پلاستیک',
							'Dermatology'      => 'پوست',
							'Cardiovascular'   => 'قلب و عروق',
							'Pediatric'        => 'کودکان',
							'Gynecologic'      => 'زنان',
							'Musculoskeletal'  => 'ارتوپدی',
							'Otolaryngologic'  => 'گوش و حلق و بینی',
							'Optometric'       => 'چشم',
							'PrimaryCare'      => 'عمومی',
						),
					),
					'identifier'       => array( 'شماره نظام پزشکی', 'text', '' ),
					'telephone'        => array( 'تلفن', 'text', '' ),
					'image'            => array( 'عکس', 'url', 'rec', '%post_thumbnail%' ),
					'sameAs'           => array( 'پروفایل‌ها (هر خط یک آدرس)', 'lines', '' ),
				),
			),
			'FAQPage'          => array(
				'label'  => 'پرسش و پاسخ (FAQPage) — بدون نتیجه غنی در گوگل از مه ۲۰۲۶',
				'help'   => 'فقط وقتی که همین پرسش‌ها و پاسخ‌ها در خود صفحه دیده می‌شوند (مثلاً آکاردئون المنتور). برای ساختن بخش پرسش‌وپاسخ تازه، از تب AEO استفاده کنید تا متن هم در صفحه نمایش داده شود.',
				'fields' => array(
					'faq' => array( 'پرسش‌ها', 'faq', 'req' ),
				),
			),
			'VideoObject'      => array(
				'label'  => 'ویدیو (VideoObject)',
				'help'   => 'برای صفحه‌ای که ویدیوی اصلی دارد.',
				'fields' => array(
					'name'         => array( 'عنوان ویدیو', 'text', 'req', '%title%' ),
					'description'  => array( 'توضیح', 'textarea', 'req', '%seo_description%' ),
					'thumbnailUrl' => array( 'تصویر کوچک (Thumbnail)', 'url', 'req', '%post_thumbnail%' ),
					'uploadDate'   => array( 'تاریخ انتشار', 'date', 'req' ),
					'contentUrl'   => array( 'آدرس فایل ویدیو', 'url', 'rec' ),
					'embedUrl'     => array( 'آدرس پخش‌کننده (یوتیوب، آپارات…)', 'url', 'rec' ),
					'duration'     => array( 'مدت (دقیقه:ثانیه)', 'text', 'rec' ),
				),
			),
			'Product'          => array(
				'label'  => 'محصول (Product)',
				'help'   => 'برای محصولی که با ووکامرس فروخته نمی‌شود (محصولات ووکامرس خودکار Schema دارند).',
				'fields' => array(
					'name'         => array( 'نام محصول', 'text', 'req', '%title%' ),
					'description'  => array( 'توضیح', 'textarea', 'rec', '%seo_description%' ),
					'image'        => array( 'تصویر', 'url', 'rec', '%post_thumbnail%' ),
					'brand'        => array( 'برند', 'text', 'rec' ),
					'sku'          => array( 'کد کالا (SKU)', 'text', '' ),
					'price'        => array( 'قیمت', 'price', 'rec', '', $cur ),
					'availability' => array( 'موجودی', 'select', 'rec', 'InStock', $avail ),
				),
			),
			'Event'            => array(
				'label'  => 'رویداد (Event)',
				'help'   => 'همایش، کارگاه، وبینار، نمایشگاه…',
				'fields' => array(
					'name'           => array( 'نام رویداد', 'text', 'req', '%title%' ),
					'startDate'      => array( 'شروع', 'date', 'req' ),
					'endDate'        => array( 'پایان', 'date', 'rec' ),
					'attendanceMode' => array(
						'نوع برگزاری',
						'select',
						'rec',
						'OfflineEventAttendanceMode',
						array(
							'OfflineEventAttendanceMode' => 'حضوری',
							'OnlineEventAttendanceMode'  => 'آنلاین',
							'MixedEventAttendanceMode'   => 'ترکیبی',
						),
					),
					'locationName'   => array( 'محل برگزاری', 'text', 'req' ),
					'locationAddress' => array( 'آدرس محل', 'text', 'rec' ),
					'description'    => array( 'توضیح', 'textarea', 'rec', '%seo_description%' ),
					'image'          => array( 'تصویر', 'url', 'rec', '%post_thumbnail%' ),
					'price'          => array( 'قیمت بلیت (۰ = رایگان)', 'price', '', '', $cur ),
				),
			),
			'Course'           => array(
				'label'  => 'دوره آموزشی (Course)',
				'help'   => 'برای صفحه یک دوره یا کلاس.',
				'fields' => array(
					'name'        => array( 'نام دوره', 'text', 'req', '%title%' ),
					'description' => array( 'توضیح', 'textarea', 'req', '%seo_description%' ),
					'courseMode'  => array(
						'نوع برگزاری',
						'select',
						'rec',
						'Online',
						array(
							'Online'  => 'آنلاین',
							'Onsite'  => 'حضوری',
							'Blended' => 'ترکیبی',
						),
					),
					'price'       => array( 'شهریه', 'price', 'rec', '', $cur ),
				),
			),
			'OfferCatalog'     => array(
				'label'  => 'فهرست خدمات (OfferCatalog)',
				'help'   => 'فهرست خدماتی که در این صفحه یا کل کسب‌وکار ارائه می‌شود.',
				'fields' => array(
					'name'  => array( 'نام فهرست', 'text', 'req', 'خدمات' ),
					'items' => array( 'خدمات (هر خط یکی)', 'lines', 'req' ),
				),
			),
		);
	}

	/* ================================================================ Sanitise / build / check */

	public static function clean_fields( $type, $in ) {
		$def = self::types();
		if ( ! isset( $def[ $type ] ) || ! is_array( $in ) ) {
			return array();
		}
		$out = array();
		foreach ( $def[ $type ]['fields'] as $k => $f ) {
			$v = isset( $in[ $k ] ) ? $in[ $k ] : '';
			switch ( $f[1] ) {
				case 'faq':
					$list = array();
					foreach ( is_array( $v ) ? $v : array() as $row ) {
						$q = isset( $row['q'] ) ? sanitize_text_field( $row['q'] ) : '';
						$a = isset( $row['a'] ) ? trim( wp_kses( (string) $row['a'], SSAI_AEO::faq_tags() ) ) : '';
						if ( '' !== $q && '' !== $a ) {
							$list[] = array( 'q' => $q, 'a' => $a );
						}
					}
					$out[ $k ] = $list;
					break;
				case 'price':
					$amount    = is_array( $v ) && isset( $v['amount'] ) ? preg_replace( '/[^0-9.]/', '', strtr( (string) $v['amount'], array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', ',' => '', '٬' => '' ) ) ) : '';
					$c         = is_array( $v ) && isset( $v['currency'], $f[4][ $v['currency'] ] ) ? $v['currency'] : 'IRR';
					$out[ $k ] = array( 'amount' => $amount, 'currency' => $c );
					break;
				case 'textarea':
					$out[ $k ] = sanitize_textarea_field( (string) $v );
					break;
				case 'lines':
					$out[ $k ] = implode( "\n", array_filter( array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) $v ) ) ) );
					break;
				case 'url':
					$v         = trim( (string) $v );
					$out[ $k ] = 0 === strpos( $v, '%' ) ? sanitize_text_field( $v ) : esc_url_raw( $v );
					break;
				case 'select':
					$out[ $k ] = isset( $f[4][ $v ] ) ? $v : '';
					break;
				default:
					$out[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		return $out;
	}

	private static function iso_duration( $s ) {
		$s = trim( strtr( (string) $s, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) );
		if ( preg_match( '/^PT/i', $s ) ) {
			return strtoupper( $s );
		}
		$p = array_map( 'intval', explode( ':', $s ) );
		if ( ! $s || ! $p ) {
			return '';
		}
		$sec = 3 === count( $p ) ? $p[0] * 3600 + $p[1] * 60 + $p[2] : ( 2 === count( $p ) ? $p[0] * 60 + $p[1] : $p[0] * 60 );
		return 'PT' . ( $sec >= 3600 ? floor( $sec / 3600 ) . 'H' : '' ) . ( floor( $sec % 3600 / 60 ) ? floor( $sec % 3600 / 60 ) . 'M' : '' ) . ( $sec % 60 ? ( $sec % 60 ) . 'S' : '' );
	}

	private static function offer( $price, $url = '%url%' ) {
		if ( ! is_array( $price ) || '' === $price['amount'] ) {
			return null;
		}
		list( $cur, $amount ) = SSAI_Schema::currency( $price['currency'], $price['amount'] );
		return array(
			'@type'         => 'Offer',
			'price'         => rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ),
			'priceCurrency' => $cur,
			'url'           => $url,
		);
	}

	/** JSON-LD node from form fields (variables like %url% are replaced when printed). */
	public static function build( $type, $f ) {
		$entity = array( '@id' => SSAI_Schema::entity_id() );
		$n      = array( '@type' => $type );
		$copy   = function ( $keys ) use ( &$n, $f ) {
			foreach ( $keys as $k ) {
				if ( isset( $f[ $k ] ) && '' !== $f[ $k ] ) {
					$n[ $k ] = $f[ $k ];
				}
			}
		};
		switch ( $type ) {
			case 'Service':
				$copy( array( 'name', 'description', 'serviceType', 'image' ) );
				$n['provider'] = $entity;
				$n['url']      = '%url%';
				if ( ! empty( $f['areaServed'] ) ) {
					$n['areaServed'] = array( '@type' => 'City', 'name' => $f['areaServed'] );
				}
				$o = self::offer( isset( $f['price'] ) ? $f['price'] : null );
				if ( $o ) {
					$n['offers'] = $o;
				}
				break;
			case 'MedicalProcedure':
				$copy( array( 'name', 'description', 'bodyLocation', 'preparation', 'howPerformed', 'followup', 'image' ) );
				if ( ! empty( $f['procedureType'] ) ) {
					$n['procedureType'] = 'https://schema.org/' . $f['procedureType'];
				}
				$n['url'] = '%url%';
				break;
			case 'Physician':
				$copy( array( 'name', 'description', 'telephone', 'image', 'identifier' ) );
				if ( ! empty( $f['medicalSpecialty'] ) ) {
					$n['medicalSpecialty'] = 'https://schema.org/' . $f['medicalSpecialty'];
				}
				if ( ! empty( $f['sameAs'] ) ) {
					$n['sameAs'] = array_values( array_filter( preg_split( '/\n/', $f['sameAs'] ) ) );
				}
				$n['url']                = '%url%';
				$n['parentOrganization'] = $entity;
				break;
			case 'FAQPage':
				$n['mainEntity'] = array();
				foreach ( isset( $f['faq'] ) ? $f['faq'] : array() as $qa ) {
					$n['mainEntity'][] = array(
						'@type'          => 'Question',
						'name'           => $qa['q'],
						'acceptedAnswer' => array(
							'@type' => 'Answer',
							'text'  => $qa['a'],
						),
					);
				}
				break;
			case 'VideoObject':
				$copy( array( 'name', 'description', 'thumbnailUrl', 'contentUrl', 'embedUrl' ) );
				if ( ! empty( $f['uploadDate'] ) ) {
					$n['uploadDate'] = self::iso_date( $f['uploadDate'] );
				}
				$d = self::iso_duration( isset( $f['duration'] ) ? $f['duration'] : '' );
				if ( $d ) {
					$n['duration'] = $d;
				}
				break;
			case 'Product':
				$copy( array( 'name', 'description', 'image', 'sku' ) );
				if ( ! empty( $f['brand'] ) ) {
					$n['brand'] = array( '@type' => 'Brand', 'name' => $f['brand'] );
				}
				$o = self::offer( isset( $f['price'] ) ? $f['price'] : null );
				if ( $o ) {
					$o['availability'] = 'https://schema.org/' . ( ! empty( $f['availability'] ) ? $f['availability'] : 'InStock' );
					$o['seller']       = $entity;
					$n['offers']       = $o;
				}
				break;
			case 'Event':
				$copy( array( 'name', 'description', 'image' ) );
				foreach ( array( 'startDate', 'endDate' ) as $k ) {
					if ( ! empty( $f[ $k ] ) ) {
						$n[ $k ] = self::iso_date( $f[ $k ] );
					}
				}
				$mode                     = ! empty( $f['attendanceMode'] ) ? $f['attendanceMode'] : 'OfflineEventAttendanceMode';
				$n['eventAttendanceMode'] = 'https://schema.org/' . $mode;
				$n['eventStatus']         = 'https://schema.org/EventScheduled';
				if ( 'OnlineEventAttendanceMode' === $mode ) {
					$n['location'] = array( '@type' => 'VirtualLocation', 'url' => '%url%' );
				} else {
					$n['location'] = array(
						'@type'   => 'Place',
						'name'    => isset( $f['locationName'] ) ? $f['locationName'] : '',
						'address' => isset( $f['locationAddress'] ) && '' !== $f['locationAddress'] ? $f['locationAddress'] : ( isset( $f['locationName'] ) ? $f['locationName'] : '' ),
					);
				}
				$n['organizer'] = $entity;
				$o              = self::offer( isset( $f['price'] ) ? $f['price'] : null );
				if ( $o ) {
					$o['availability'] = 'https://schema.org/InStock';
					$n['offers']       = $o;
				}
				break;
			case 'Course':
				$copy( array( 'name', 'description' ) );
				$n['provider']         = $entity;
				$n['hasCourseInstance'] = array(
					'@type'      => 'CourseInstance',
					'courseMode' => ! empty( $f['courseMode'] ) ? $f['courseMode'] : 'Online',
				);
				$o                     = self::offer( isset( $f['price'] ) ? $f['price'] : null );
				if ( $o ) {
					$o['category'] = 'Paid';
					$n['offers']   = $o;
				}
				break;
			case 'OfferCatalog':
				$copy( array( 'name' ) );
				$n['itemListElement'] = array();
				foreach ( array_filter( preg_split( '/\n/', isset( $f['items'] ) ? $f['items'] : '' ) ) as $i => $name ) {
					$n['itemListElement'][] = array(
						'@type'       => 'Offer',
						'itemOffered' => array( '@type' => 'Service', 'name' => $name ),
					);
				}
				break;
		}
		$n['metadata'] = array(
			'builder' => $type,
			'fields'  => $f,
		);
		return $n;
	}

	private static function iso_date( $v ) {
		$t = strtotime( (string) $v );
		return $t ? wp_date( 'c', $t ) : (string) $v;
	}

	/** @return array { errors[], warnings[] } in Persian. */
	public static function check( $type, $f ) {
		$def = self::types();
		$out = array( 'errors' => array(), 'warnings' => array() );
		if ( ! isset( $def[ $type ] ) ) {
			return $out;
		}
		foreach ( $def[ $type ]['fields'] as $k => $fd ) {
			$v     = isset( $f[ $k ] ) ? $f[ $k ] : '';
			$empty = 'price' === $fd[1] ? ( ! is_array( $v ) || '' === $v['amount'] ) : ( 'faq' === $fd[1] ? ! $v : '' === trim( (string) $v ) );
			if ( $empty && 'req' === $fd[2] ) {
				$out['errors'][] = '«' . $fd[0] . '» لازم است.';
			} elseif ( $empty && 'rec' === $fd[2] ) {
				$out['warnings'][] = '«' . $fd[0] . '» پیشنهاد می‌شود.';
			}
		}
		if ( 'VideoObject' === $type && empty( $f['contentUrl'] ) && empty( $f['embedUrl'] ) ) {
			$out['errors'][] = 'یکی از «آدرس فایل ویدیو» یا «آدرس پخش‌کننده» لازم است.';
		}
		if ( 'FAQPage' === $type && is_array( $f['faq'] ) && count( $f['faq'] ) < 2 ) {
			$out['warnings'][] = 'دست‌کم دو پرسش پیشنهاد می‌شود.';
		}
		if ( 'Event' === $type && ! empty( $f['startDate'] ) && ! empty( $f['endDate'] ) && strtotime( $f['endDate'] ) < strtotime( $f['startDate'] ) ) {
			$out['errors'][] = 'تاریخ پایان قبل از شروع است.';
		}
		return $out;
	}

	/* ================================================================ Storage */

	/** Current list (builder items have metadata.builder; others came from Rank Math). */
	public static function items( $post_id ) {
		return array_values( SSAI_Schema::raw_custom( $post_id ) );
	}

	private static function store( $post_id, $items ) {
		update_post_meta( $post_id, self::META, array_values( $items ) );
		SSAI_Applier::purge_cache( $post_id );
	}

	/** Items prepared for the screen: editable fields, rendered JSON preview, checks. */
	public static function for_js( $post_id ) {
		$out = array();
		$m   = SSAI_SEO::meta(
			array(
				'type'  => 'post',
				'id'    => $post_id,
				'paged' => 1,
			)
		);
		$url = '' !== $m['canonical'] ? $m['canonical'] : get_permalink( $post_id );
		foreach ( self::items( $post_id ) as $i => $node ) {
			$b       = isset( $node['metadata']['builder'] ) ? $node['metadata']['builder'] : '';
			$fields  = $b && isset( $node['metadata']['fields'] ) ? $node['metadata']['fields'] : null;
			$preview = SSAI_Schema::custom_nodes_from( array( $node ), $post_id, $url, $m );
			$out[]   = array(
				'index'   => $i,
				'type'    => implode( ', ', (array) $node['@type'] ),
				'builder' => $b,
				'fields'  => $fields,
				'name'    => isset( $node['name'] ) && is_string( $node['name'] ) ? $node['name'] : '',
				'source'  => $b ? 'builder' : ( isset( $node['metadata'] ) ? 'rankmath' : 'other' ),
				'json'    => $preview ? wp_json_encode( $preview[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) : '',
				'check'   => $b ? self::check( $b, $fields ) : array( 'errors' => array(), 'warnings' => array() ),
			);
		}
		return $out;
	}

	/* ================================================================ AJAX */

	private static function guard() {
		check_ajax_referer( 'ssai', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! SSAI_Applier::can( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'شما اجازه این کار را ندارید.' ), 403 );
		}
		return $post_id;
	}

	public static function ajax_save() {
		$post_id = self::guard();
		$type    = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
		$index   = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$raw     = isset( $_POST['fields'] ) ? json_decode( wp_unslash( $_POST['fields'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- clean_fields() below.
		if ( ! isset( self::types()[ $type ] ) ) {
			wp_send_json_error( array( 'message' => 'نوع Schema نامعتبر است.' ) );
		}
		$f   = self::clean_fields( $type, $raw );
		$chk = self::check( $type, $f );
		if ( $chk['errors'] ) {
			wp_send_json_error( array( 'message' => 'ذخیره نشد: ' . implode( ' ', $chk['errors'] ) ) );
		}
		$items = self::items( $post_id );
		$node  = self::build( $type, $f );
		if ( $index >= 0 && isset( $items[ $index ] ) ) {
			$items[ $index ] = $node;
		} else {
			$items[] = $node;
		}
		self::store( $post_id, $items );
		wp_send_json_success(
			array(
				'message' => 'Schema ذخیره شد' . ( $chk['warnings'] ? ' (با ' . count( $chk['warnings'] ) . ' پیشنهاد برای کامل‌تر شدن).' : '.' ),
				'items'   => self::for_js( $post_id ),
			)
		);
	}

	public static function ajax_delete() {
		$post_id = self::guard();
		$index   = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$items   = self::items( $post_id );
		if ( ! isset( $items[ $index ] ) ) {
			wp_send_json_error( array( 'message' => 'پیدا نشد.' ) );
		}
		array_splice( $items, $index, 1 );
		self::store( $post_id, $items );
		wp_send_json_success(
			array(
				'message' => 'حذف شد.',
				'items'   => self::for_js( $post_id ),
			)
		);
	}

	/** Video addresses found in the page (YouTube, Aparat, Vimeo, mp4) – the AI cannot see them in text. */
	private static function find_videos( $post_id ) {
		$raw = (string) get_post_field( 'post_content', $post_id ) . ' ' . str_replace( '\\/', '/', (string) get_post_meta( $post_id, '_elementor_data', true ) );
		$out = array();
		if ( preg_match_all( '#https?://(?:www\.)?(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})#', $raw, $m ) ) {
			foreach ( array_unique( $m[1] ) as $id ) {
				$out[] = array(
					'embedUrl'     => 'https://www.youtube.com/embed/' . $id,
					'thumbnailUrl' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
				);
			}
		}
		if ( preg_match_all( '#https?://(?:www\.)?aparat\.com/v/([\w]+)#', $raw, $m ) ) {
			foreach ( array_unique( $m[1] ) as $id ) {
				$out[] = array( 'embedUrl' => 'https://www.aparat.com/video/video/embed/videohash/' . $id . '/vt/frame' );
			}
		}
		if ( preg_match_all( '#https?://[^\s"\'<>]+\.(?:mp4|webm|mov)#i', $raw, $m ) ) {
			foreach ( array_unique( $m[0] ) as $u ) {
				$out[] = array( 'contentUrl' => $u );
			}
		}
		return array_slice( $out, 0, 3 );
	}

	public static function ajax_suggest() {
		$post_id = self::guard();
		$d       = SSAI_Content::data( $post_id );
		if ( ! $d ) {
			wp_send_json_error( array( 'message' => 'صفحه پیدا نشد.' ) );
		}
		$types = self::types();
		$spec  = array();
		foreach ( $types as $t => $def ) {
			$fs = array();
			foreach ( $def['fields'] as $k => $f ) {
				$fs[] = $k . ( 'select' === $f[1] ? ' (one of: ' . implode( '|', array_filter( array_keys( $f[4] ) ) ) . ')' : ( 'faq' === $f[1] ? ' (array of {"q":"","a":""})' : ( 'price' === $f[1] ? ' ({"amount":"","currency":"IRR|IRT|USD|EUR"})' : ( 'date' === $f[1] ? ' (YYYY-MM-DDTHH:MM)' : '' ) ) ) );
			}
			$spec[] = $t . ': ' . implode( ', ', $fs );
		}
		$have = array();
		foreach ( self::items( $post_id ) as $n ) {
			$have = array_merge( $have, (array) $n['@type'] );
		}
		$videos = self::find_videos( $post_id );
		$e      = SSAI_SEO::get( 'entity' );
		$lang   = 'en' === SSAI_Settings::get( 'language' ) ? 'English' : 'Persian (Farsi)';
		$system = 'You are a structured-data (schema.org JSON-LD) expert. Choose the schema types that truly describe the MAIN topic of this web page and fill their fields. '
			. 'Allowed types and their fields:' . "\n- " . implode( "\n- ", $spec ) . "\n"
			. 'Rules: suggest 1 or 2 types (never more), only if they clearly fit; a page about a dental/medical treatment fits MedicalProcedure (and Service if it is offered by the business); a page about a doctor fits Physician. '
			. 'Use ONLY facts written in the page text. NEVER invent prices, dates, durations, phone numbers, ratings, reviews or medical claims – leave such fields empty when the page does not state them. '
			. 'FAQPage only when the page text itself contains questions with answers; copy them, do not write new ones. '
			. 'Do not suggest types the page already has: ' . ( $have ? implode( ', ', $have ) : 'none' ) . '. '
			. 'Write texts in the same language as the page (' . $lang . '). Respond ONLY with JSON: {"schemas":[{"type":"","reason":"one short sentence in ' . $lang . '","fields":{}}]} – an empty list if nothing fits.'
			. "\n\n" . SSAI_Guidelines::prompt();
		$user   = 'Business: ' . ( '' !== $e['name'] ? $e['name'] : get_bloginfo( 'name' ) ) . ( '' !== $e['subtype'] ? ' (' . $e['subtype'] . ')' : '' ) . ( '' !== $e['city'] ? ', ' . $e['city'] : '' )
			. "\nPage title: " . get_the_title( $post_id ) . "\nSEO description: " . $d['meta_description']
			. "\nHeadings: " . implode( ' | ', wp_list_pluck( $d['headings'], 'text' ) )
			. ( $videos ? "\nVideos found on the page: " . wp_json_encode( $videos ) : '' )
			. "\n\nPage text:\n" . mb_substr( SSAI_Content::html_to_text( $d['html'] ), 0, (int) SSAI_Settings::get( 'max_chars' ) );
		$r      = SSAI_AI_Client::chat_json( $system, $user );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$out = array();
		foreach ( isset( $r['schemas'] ) && is_array( $r['schemas'] ) ? $r['schemas'] : array() as $s ) {
			$t = isset( $s['type'] ) ? (string) $s['type'] : '';
			if ( ! isset( $types[ $t ] ) || in_array( $t, $have, true ) ) {
				continue;
			}
			$f = isset( $s['fields'] ) && is_array( $s['fields'] ) ? $s['fields'] : array();
			// Fill what we know for sure better than the AI.
			if ( 'VideoObject' === $t && $videos ) {
				$f = array_merge( $f, array_filter( $videos[0] ) );
				if ( empty( $f['uploadDate'] ) ) {
					$f['uploadDate'] = get_post_time( 'Y-m-d\TH:i', false, $post_id );
				}
			}
			foreach ( $types[ $t ]['fields'] as $k => $fd ) {
				if ( ( ! isset( $f[ $k ] ) || '' === $f[ $k ] ) && ! empty( $fd[3] ) ) {
					$f[ $k ] = $fd[3];
				}
			}
			unset( $f['rating'], $f['aggregateRating'], $f['review'] );
			$clean = self::clean_fields( $t, $f );
			$out[] = array(
				'type'   => $t,
				'reason' => isset( $s['reason'] ) ? sanitize_text_field( $s['reason'] ) : '',
				'fields' => $clean,
				'check'  => self::check( $t, $clean ),
			);
			if ( count( $out ) >= 2 ) {
				break;
			}
		}
		if ( ! $out ) {
			wp_send_json_error( array( 'message' => 'هوش مصنوعی Schema مناسب دیگری برای این صفحه پیشنهاد نداد. می‌توانید دستی یکی را انتخاب کنید.' ) );
		}
		wp_send_json_success( array( 'suggestions' => $out ) );
	}

	/* ================================================================ Screen (page report) */

	public static function render( $post_id ) {
		$data = array(
			'postId'   => $post_id,
			'canApply' => SSAI_Applier::can( $post_id ),
			'types'    => self::types(),
			'items'    => self::for_js( $post_id ),
			'test'     => 'https://search.google.com/test/rich-results?url=' . rawurlencode( get_permalink( $post_id ) ),
			'auto'     => self::auto_types( $post_id ),
		);
		wp_enqueue_script( 'ssai-schema', SSAI_URL . 'assets/schema.js', array( 'ssai-admin' ), SSAI_VERSION, true );
		?>
		<section class="ssai-card" id="ssai-schema-builder">
			<h2>Schema این صفحه</h2>
			<p class="description">Schemaهای خودکار (کسب‌وکار، سایت، صفحه، Breadcrumb<?php echo $data['auto'] ? '، ' . esc_html( implode( '، ', $data['auto'] ) ) : ''; ?>) را افزونه خودش می‌سازد. این‌جا Schemaهای مخصوص محتوای صفحه را اضافه کنید.</p>
			<div id="ssai-schema-app"></div>
			<script type="application/json" id="ssai-schema-data"><?php echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ); ?></script>
		</section>
		<?php
	}

	/** Content types printed automatically for this page (shown so admins don't add duplicates). */
	private static function auto_types( $post_id ) {
		$t = (string) get_post_meta( $post_id, SSAI_SEO::M_SCHEMA, true );
		$t = '' === $t ? SSAI_SEO::default_schema( get_post_type( $post_id ) ) : $t;
		$o = array();
		if ( 'none' !== $t ) {
			$o[] = $t;
		}
		if ( SSAI_AEO::get_faq( $post_id ) ) {
			$o[] = 'FAQPage';
		}
		return $o;
	}
}
