<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import of Search Console "Performance → Export → Download CSV" files (CSV or the ZIP that contains them).
 * Works without any connection from the web server to Google.
 *
 * - Site-wide export  → stored in an option (pages + queries of the whole site).
 * - Export filtered on one page (Filters.csv contains the URL, or uploaded from that page's report)
 *   → stored on that post (queries, dates and devices of the page).
 * The previous import is kept so changes can be shown.
 */
class SSAI_GSC_Import {

	const OPTION   = 'ssai_gsc_import_site';
	const POSTMETA = '_ssai_gsc_import';

	public static function init() {
		add_action( 'admin_post_ssai_gsc_import', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_ssai_gsc_import_clear', array( __CLASS__, 'handle_clear' ) );
	}

	/* ------------------------------------------------------------ URL helpers */

	/**
	 * Comparable form of a URL: Search Console exports Persian URLs decoded ("/تماس-با-ما/"),
	 * WordPress gives them percent-encoded ("/%d8%aa…"); scheme, "www." and trailing slash also differ.
	 */
	public static function url_key( $url ) {
		$u    = rawurldecode( trim( (string) $url ) );
		$u    = preg_replace( '#^[a-z]+://#i', '', $u );
		$u    = preg_replace( '#^www\.#i', '', $u );
		$slash = strpos( $u, '/' );
		if ( false === $slash ) {
			return strtolower( $u );
		}
		$host = strtolower( substr( $u, 0, $slash ) );
		$rest = preg_replace( '/#.*$/', '', substr( $u, $slash ) );
		return $host . rtrim( $rest, '/' );
	}

	/** Find the post for a URL from an export (handles decoded Persian slugs). */
	public static function find_post( $url ) {
		$id = url_to_postid( $url );
		if ( $id ) {
			return $id;
		}
		$parts = wp_parse_url( $url );
		if ( ! empty( $parts['path'] ) ) {
			$enc = implode( '/', array_map( 'rawurlencode', explode( '/', rawurldecode( $parts['path'] ) ) ) );
			$id  = url_to_postid( home_url( $enc . ( ! empty( $parts['query'] ) ? '?' . $parts['query'] : '' ) ) );
			if ( $id ) {
				return $id;
			}
			$slug = basename( untrailingslashit( rawurldecode( $parts['path'] ) ) );
			if ( '' !== $slug ) {
				$p = get_posts(
					array(
						'name'           => sanitize_title( $slug ),
						'post_type'      => SSAI_Admin::post_types(),
						'post_status'    => 'publish',
						'posts_per_page' => 1,
						'fields'         => 'ids',
					)
				);
				if ( $p ) {
					return (int) $p[0];
				}
			}
		}
		return 0;
	}

	/* ------------------------------------------------------------ Parsing helpers */

	/** Persian/Arabic digits and separators → plain number. */
	public static function num( $v ) {
		$v = trim( (string) $v );
		$v = strtr(
			$v,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
				'٫' => '.', '٬' => '', '٪' => '', '%' => '', ' ' => '', "\xC2\xA0" => '',
			)
		);
		// "1,234.5" → 1234.5 ; "4,5" (decimal comma) → 4.5.
		if ( false !== strpos( $v, ',' ) && false !== strpos( $v, '.' ) ) {
			$v = str_replace( ',', '', $v );
		} elseif ( preg_match( '/^\d+,\d{1,2}$/', $v ) ) {
			$v = str_replace( ',', '.', $v );
		} else {
			$v = str_replace( ',', '', $v );
		}
		return is_numeric( $v ) ? (float) $v : 0.0;
	}

	private static function digits( $s ) {
		return strtr( (string) $s, array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) );
	}

	/** Parse CSV text into rows (handles BOM, quotes, ; or , separators). */
	public static function csv_rows( $text ) {
		$text = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$first = strtok( $text, "\n" );
		$sep   = ( substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ) ? ';' : ',';
		$fh    = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $text );
		rewind( $fh );
		$rows = array();
		while ( false !== ( $r = fgetcsv( $fh, 0, $sep, '"', '\\' ) ) ) {
			if ( array( null ) !== $r && count( array_filter( $r, 'strlen' ) ) ) {
				$rows[] = array_map( 'trim', $r );
			}
		}
		fclose( $fh );
		return $rows;
	}

	/** Which Search Console table is this file? */
	public static function classify( $name, $rows ) {
		$n = mb_strtolower( (string) $name );
		$map = array(
			'filters'  => array( 'filter', 'فیلتر' ),
			'skip'     => array( 'countr', 'کشور', 'appearance', 'ظاهر' ),
			'queries'  => array( 'quer', 'درخواست', 'پرس', 'جستجو', 'جست‌وجو' ),
			'pages'    => array( 'page', 'صفحه' ),
			'dates'    => array( 'date', 'تاریخ', 'chart' ),
			'devices'  => array( 'device', 'دستگاه' ),
		);
		foreach ( $map as $type => $words ) {
			foreach ( $words as $w ) {
				if ( false !== mb_strpos( $n, $w ) ) {
					return $type;
				}
			}
		}
		// Fall back to the content of the first data column.
		$sample = array_slice( $rows, 1, 5 );
		if ( ! $sample ) {
			return 'skip';
		}
		if ( count( $rows[0] ) === 2 ) {
			return 'filters';
		}
		$first = self::digits( $sample[0][0] );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $first ) ) {
			return 'dates';
		}
		if ( preg_match( '#^https?://#i', $first ) ) {
			return 'pages';
		}
		if ( preg_match( '/^(desktop|mobile|tablet|رایانه|دسکتاپ|موبایل|تلفن|تبلت)/iu', $first ) ) {
			return 'devices';
		}
		return 'queries';
	}

	/**
	 * Data rows → list of { key, clicks, impressions, ctr, position, prev_* }.
	 * Supports normal exports (5 columns) and "Compare" exports (9 columns: current/previous pairs).
	 */
	public static function metric_rows( $rows ) {
		$out     = array();
		$compare = isset( $rows[0] ) && count( $rows[0] ) >= 9;
		foreach ( array_slice( $rows, 1 ) as $r ) {
			if ( count( $r ) < 5 || '' === $r[0] ) {
				continue;
			}
			if ( $compare ) {
				$row = array(
					'key'         => $r[0],
					'clicks'      => (int) self::num( $r[1] ),
					'prev_clicks' => (int) self::num( $r[2] ),
					'impressions' => (int) self::num( $r[3] ),
					'prev_impr'   => (int) self::num( $r[4] ),
					'ctr'         => self::num( $r[5] ) / 100,
					'prev_ctr'    => self::num( $r[6] ) / 100,
					'position'    => self::num( $r[7] ),
					'prev'        => self::num( $r[8] ) ?: null,
				);
			} else {
				$row = array(
					'key'         => $r[0],
					'clicks'      => (int) self::num( $r[1] ),
					'impressions' => (int) self::num( $r[2] ),
					'ctr'         => self::num( $r[3] ) / 100,
					'position'    => self::num( $r[4] ),
					'prev'        => null,
				);
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Read the uploaded files into one dataset.
	 *
	 * @param array $files list of [ name, path ].
	 * @return array|WP_Error
	 */
	public static function parse_files( $files ) {
		$tables = array();
		foreach ( $files as $f ) {
			list( $name, $path ) = $f;
			if ( preg_match( '/\.zip$/i', $name ) ) {
				if ( ! class_exists( 'ZipArchive' ) ) {
					return new WP_Error( 'ssai_zip', 'سرور شما از فایل ZIP پشتیبانی نمی‌کند؛ فایل ZIP را باز کنید و فایل‌های CSV داخل آن را بارگذاری کنید.' );
				}
				$zip = new ZipArchive();
				if ( true !== $zip->open( $path ) ) {
					return new WP_Error( 'ssai_zip', 'فایل ZIP باز نشد.' );
				}
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$en = $zip->getNameIndex( $i );
					if ( preg_match( '/\.csv$/i', $en ) ) {
						$tables[] = array( basename( $en ), (string) $zip->getFromIndex( $i ) );
					}
				}
				$zip->close();
			} elseif ( preg_match( '/\.csv$/i', $name ) ) {
				$tables[] = array( $name, (string) file_get_contents( $path ) ); // phpcs:ignore
			} elseif ( preg_match( '/\.xlsx?$/i', $name ) ) {
				return new WP_Error( 'ssai_xlsx', 'فایل اکسل پشتیبانی نمی‌شود. در سرچ کنسول «Export › Download CSV» را انتخاب کنید.' );
			}
		}
		if ( ! $tables ) {
			return new WP_Error( 'ssai_nofile', 'هیچ فایل CSV یا ZIP معتبری بارگذاری نشد.' );
		}

		$data = array(
			'queries'     => array(),
			'pages'       => array(),
			'dates'       => array(),
			'devices'     => array(),
			'filter_page' => '',
			'range'       => '',
			'compare'     => false,
		);
		foreach ( $tables as $t ) {
			$rows = self::csv_rows( $t[1] );
			if ( count( $rows ) < 1 ) {
				continue;
			}
			$type = self::classify( $t[0], $rows );
			if ( 'skip' === $type ) {
				continue;
			}
			if ( 'filters' === $type ) {
				foreach ( $rows as $r ) {
					$joined = implode( ' ', $r );
					if ( '' === $data['filter_page'] && preg_match( '#https?://\S+#i', $joined, $m ) ) {
						$data['filter_page'] = rtrim( $m[0], '",' );
					}
					if ( preg_match( '/date|تاریخ/iu', (string) $r[0] ) && isset( $r[1] ) ) {
						$data['range'] = sanitize_text_field( $r[1] );
					}
				}
				continue;
			}
			$m = self::metric_rows( $rows );
			if ( isset( $rows[0] ) && count( $rows[0] ) >= 9 ) {
				$data['compare'] = true;
			}
			if ( 'dates' === $type ) {
				foreach ( $m as $r ) {
					$r['key'] = self::digits( $r['key'] );
					if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $r['key'] ) ) {
						$data['dates'][] = $r;
					}
				}
				usort(
					$data['dates'],
					function ( $a, $b ) {
						return strcmp( $a['key'], $b['key'] );
					}
				);
			} elseif ( 'pages' === $type ) {
				foreach ( $m as $r ) {
					$r['url']                                  = $r['key'];
					$data['pages'][ self::url_key( $r['key'] ) ] = $r;
				}
			} elseif ( 'devices' === $type ) {
				foreach ( $m as $r ) {
					$k = mb_strtolower( $r['key'] );
					$k = preg_match( '/mobile|موبایل|تلفن/u', $k ) ? 'mobile' : ( preg_match( '/tablet|تبلت|لوحی/u', $k ) ? 'tablet' : 'desktop' );
					$data['devices'][ $k ] = array(
						'clicks'   => $r['clicks'],
						'position' => $r['position'],
					);
				}
			} else {
				foreach ( $m as $r ) {
					$data['queries'][] = $r;
				}
			}
		}
		// An export filtered on one page contains exactly one row in Pages.csv – use it when Filters.csv
		// does not reveal the page (e.g. translated filter names or "URL contains" filters).
		if ( '' === $data['filter_page'] && 1 === count( $data['pages'] ) ) {
			$only                = reset( $data['pages'] );
			$data['filter_page'] = $only['url'];
		}
		if ( ! $data['queries'] && ! $data['pages'] && ! $data['dates'] ) {
			return new WP_Error( 'ssai_empty', 'در فایل‌ها داده‌ای از گزارش Performance سرچ کنسول پیدا نشد.' );
		}
		if ( $data['dates'] ) {
			$first = reset( $data['dates'] );
			$last  = end( $data['dates'] );
			$data['range'] = $first['key'] . ' تا ' . $last['key'];
		}
		$data['time'] = time();
		return $data;
	}

	/** Keep the previous import's positions so the next report can show changes. */
	private static function with_prev( $new, $old ) {
		if ( ! is_array( $old ) || $new['compare'] ) {
			return $new;
		}
		$old_pos = array();
		foreach ( isset( $old['queries'] ) ? $old['queries'] : array() as $q ) {
			$old_pos[ $q['key'] ] = $q['position'];
		}
		foreach ( $new['queries'] as &$q ) {
			if ( null === $q['prev'] && isset( $old_pos[ $q['key'] ] ) ) {
				$q['prev'] = $old_pos[ $q['key'] ];
			}
		}
		unset( $q );
		foreach ( $new['pages'] as $url => &$p ) {
			if ( null === $p['prev'] && isset( $old['pages'][ $url ]['position'] ) ) {
				$p['prev'] = $old['pages'][ $url ]['position'];
			}
		}
		unset( $p );
		$new['prev_totals'] = isset( $old['totals'] ) ? $old['totals'] : null;
		$new['prev_time']   = isset( $old['time'] ) ? $old['time'] : 0;
		return $new;
	}

	private static function totals( $rows ) {
		$c = 0;
		$i = 0;
		$p = 0;
		foreach ( $rows as $r ) {
			$c += $r['clicks'];
			$i += $r['impressions'];
			$p += $r['position'] * $r['impressions'];
		}
		return array(
			'clicks'      => $c,
			'impressions' => $i,
			'ctr'         => $i ? $c / $i : 0,
			'position'    => $i ? $p / $i : 0,
		);
	}

	/* ------------------------------------------------------------ Upload handler */

	private static function back( $msg, $type, $post_id ) {
		set_transient(
			'ssai_gsc_notice_' . get_current_user_id(),
			array(
				'msg'  => $msg,
				'type' => $type,
			),
			60
		);
		wp_safe_redirect( $post_id ? SSAI_Report::url( $post_id ) : admin_url( 'admin.php?page=smart-seo-ai-gsc' ) );
		exit;
	}

	public static function handle_upload() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'ssai_gsc_import' );
		if ( $post_id ? ! current_user_can( 'edit_post', $post_id ) : ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$zips = array();
		$csvs = array();
		if ( ! empty( $_FILES['gsc_files']['name'] ) && is_array( $_FILES['gsc_files']['name'] ) ) {
			foreach ( $_FILES['gsc_files']['name'] as $i => $name ) {
				$tmp = $_FILES['gsc_files']['tmp_name'][ $i ];
				if ( $tmp && is_uploaded_file( $tmp ) && $_FILES['gsc_files']['size'][ $i ] < 20 * MB_IN_BYTES ) {
					$f = array( sanitize_file_name( wp_unslash( $name ) ), $tmp );
					if ( preg_match( '/\.zip$/i', $f[0] ) ) {
						$zips[] = $f;
					} else {
						$csvs[] = $f;
					}
				}
			}
		}
		// Each ZIP is one export (several page exports can be uploaded at once); loose CSV files form one export.
		$batches = array();
		foreach ( $zips as $z ) {
			$batches[] = array( $z );
		}
		if ( $csvs ) {
			$batches[] = $csvs;
		}
		if ( ! $batches ) {
			self::back( 'هیچ فایل CSV یا ZIP معتبری بارگذاری نشد.', 'error', $post_id );
		}
		if ( $post_id && count( $batches ) > 1 ) {
			self::back( 'در گزارش یک صفحه فقط خروجی همان صفحه را بارگذاری کنید (یک فایل ZIP).', 'error', $post_id );
		}

		$msgs = array();
		$errs = array();
		foreach ( $batches as $files ) {
			$r = self::import_one( $files, $post_id );
			if ( is_wp_error( $r ) ) {
				$errs[] = $files[0][0] . ': ' . $r->get_error_message();
			} else {
				$msgs[] = $r;
			}
		}
		$text = implode( ' | ', array_merge( $msgs, $errs ) );
		self::back( $text, $errs ? ( $msgs ? 'warning' : 'error' ) : 'success', $post_id );
	}

	/** Remove the imported data of one page (e.g. a wrong file). */
	public static function handle_clear() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'ssai_gsc_import_clear_' . $post_id );
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		delete_post_meta( $post_id, self::POSTMETA );
		self::back( 'داده واردشده این صفحه حذف شد؛ حالا اعداد کل سایت نمایش داده می‌شود.', 'success', $post_id );
	}

	/** @return string|WP_Error success message */
	private static function import_one( $files, $post_id ) {
		$data = self::parse_files( $files );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$target  = $post_id;
		$notepre = '';
		// A site-wide export uploaded from a page report: store it as site data, not as this page's data.
		if ( $post_id && count( $data['pages'] ) > 1 ) {
			$target  = 0;
			$post_id = 0;
			$notepre = 'این فایل خروجی کل سایت بود (نه فقط این صفحه)، پس به‌عنوان داده کل سایت ذخیره شد. ';
		}
		if ( ! $target && $data['filter_page'] ) {
			$target = self::find_post( $data['filter_page'] );
			if ( ! $target ) {
				return new WP_Error( 'ssai_nopost', 'این خروجی برای صفحه ' . rawurldecode( $data['filter_page'] ) . ' است، اما این آدرس در سایت پیدا نشد. آن را از صفحه گزارش همان نوشته بارگذاری کنید.' );
			}
		}
		if ( $post_id && $data['filter_page'] && self::url_key( $data['filter_page'] ) !== self::url_key( get_permalink( $post_id ) ) && self::find_post( $data['filter_page'] ) !== $post_id ) {
			return new WP_Error( 'ssai_other', 'این فایل خروجی صفحه دیگری است (' . rawurldecode( $data['filter_page'] ) . ').' );
		}

		if ( $target ) {
			$data['totals'] = $data['dates'] ? self::totals( $data['dates'] ) : self::totals( $data['queries'] );
			$data           = self::with_prev( $data, get_post_meta( $target, self::POSTMETA, true ) );
			unset( $data['pages'] );
			update_post_meta( $target, self::POSTMETA, wp_slash( $data ) );
			return sprintf( '«%s»: %d جستجو%s', get_the_title( $target ), count( $data['queries'] ), $data['dates'] ? '، ' . count( $data['dates'] ) . ' روز' : '' );
		}

		// Site-wide export. Never let a (mis-detected) single-page export replace a real site export.
		$old = get_option( self::OPTION );
		if ( is_array( $old ) && count( $old['pages'] ) > 1 && count( $data['pages'] ) <= 1 ) {
			return new WP_Error( 'ssai_notsite', 'این فایل خروجی کل سایت نیست (فهرست صفحه‌ها ندارد) و صفحه آن هم مشخص نشد؛ آن را از پایین گزارش همان صفحه وارد کنید.' );
		}
		$data['totals']  = $data['dates'] ? self::totals( $data['dates'] ) : self::totals( $data['pages'] ? array_values( $data['pages'] ) : $data['queries'] );
		$data['queries'] = array_slice( $data['queries'], 0, 1000 );
		$data            = self::with_prev( $data, get_option( self::OPTION ) );
		update_option( self::OPTION, $data, false );
		return $notepre . sprintf( 'خروجی کل سایت وارد شد: %d صفحه، %d جستجو.', count( $data['pages'] ), count( $data['queries'] ) );
	}

	/* ------------------------------------------------------------ Getters used by the reports */

	public static function site() {
		$s = get_option( self::OPTION );
		return is_array( $s ) ? $s : null;
	}

	public static function has_any() {
		global $wpdb;
		return (bool) self::site() || (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", self::POSTMETA ) );
	}

	/** Per-page metrics for the site overview table. */
	public static function all_pages() {
		$s = self::site();
		if ( ! $s ) {
			return array();
		}
		$out = array();
		foreach ( $s['pages'] as $k => $v ) {
			$out[ self::url_key( $k ) ] = $v;
		}
		return $out;
	}

	/**
	 * Report data in the same shape as SSAI_GSC::page_report(), built from imported files.
	 *
	 * @return array|null
	 */
	public static function page_report( $post_id, $url ) {
		$p = get_post_meta( $post_id, self::POSTMETA, true );
		if ( is_array( $p ) ) {
			$days = array();
			foreach ( $p['dates'] as $d ) {
				$days[] = array(
					'date'        => $d['key'],
					'clicks'      => $d['clicks'],
					'impressions' => $d['impressions'],
					'ctr'         => $d['ctr'],
					'position'    => $d['position'],
				);
			}
			$queries = array();
			foreach ( $p['queries'] as $q ) {
				$queries[] = array(
					'query'       => $q['key'],
					'clicks'      => $q['clicks'],
					'impressions' => $q['impressions'],
					'ctr'         => $q['ctr'],
					'position'    => $q['position'],
					'prev'        => $q['prev'],
				);
			}
			$prev = array(
				'clicks'      => 0,
				'impressions' => 0,
				'ctr'         => 0,
				'position'    => 0,
			);
			if ( ! empty( $p['compare'] ) && $p['dates'] ) {
				$pc = 0;
				$pi = 0;
				$pp = 0;
				foreach ( $p['dates'] as $d ) {
					$pc += isset( $d['prev_clicks'] ) ? $d['prev_clicks'] : 0;
					$pi += isset( $d['prev_impr'] ) ? $d['prev_impr'] : 0;
					$pp += ( isset( $d['prev'] ) ? (float) $d['prev'] : 0 ) * ( isset( $d['prev_impr'] ) ? $d['prev_impr'] : 0 );
				}
				$prev = array(
					'clicks'      => $pc,
					'impressions' => $pi,
					'ctr'         => $pi ? $pc / $pi : 0,
					'position'    => $pi ? $pp / $pi : 0,
				);
			} elseif ( ! empty( $p['prev_totals'] ) ) {
				$prev = $p['prev_totals'];
			}
			return array(
				'source'  => 'import-page',
				'time'    => $p['time'],
				'range'   => $p['range'],
				'totals'  => $p['totals'],
				'prev'    => $prev,
				'days'    => $days,
				'queries' => $queries,
				'devices' => $p['devices'],
			);
		}

		$s = self::site();
		if ( ! $s ) {
			return null;
		}
		$key = self::url_key( $url );
		$row = isset( $s['pages'][ $key ] ) ? $s['pages'][ $key ] : null;
		if ( ! $row ) {
			// Imports made before URL normalisation used raw keys.
			foreach ( $s['pages'] as $k => $v ) {
				if ( self::url_key( $k ) === $key ) {
					$row = $v;
					break;
				}
			}
		}
		// Site-wide queries: only those that match this page's keywords are relevant.
		$kws     = array_map( array( 'SSAI_Content', 'norm' ), SSAI_Content::get_focus_keywords( $post_id ) );
		$queries = array();
		foreach ( $s['queries'] as $q ) {
			$nq = SSAI_Content::norm( $q['key'] );
			foreach ( $kws as $k ) {
				if ( '' !== $k && false !== mb_strpos( $nq, $k ) ) {
					$queries[] = array(
						'query'       => $q['key'],
						'clicks'      => $q['clicks'],
						'impressions' => $q['impressions'],
						'ctr'         => $q['ctr'],
						'position'    => $q['position'],
						'prev'        => $q['prev'],
					);
					break;
				}
			}
		}
		return array(
			'source'  => 'import-site',
			'time'    => $s['time'],
			'range'   => $s['range'],
			'totals'  => $row ? array(
				'clicks'      => $row['clicks'],
				'impressions' => $row['impressions'],
				'ctr'         => $row['ctr'],
				'position'    => $row['position'],
			) : array(
				'clicks'      => 0,
				'impressions' => 0,
				'ctr'         => 0,
				'position'    => 0,
			),
			'prev'    => array(
				'clicks'      => $row && isset( $row['prev_clicks'] ) ? $row['prev_clicks'] : 0,
				'impressions' => $row && isset( $row['prev_impr'] ) ? $row['prev_impr'] : 0,
				'ctr'         => $row && isset( $row['prev_ctr'] ) ? $row['prev_ctr'] : 0,
				'position'    => $row && $row['prev'] ? $row['prev'] : 0,
			),
			'days'    => array(),
			'queries' => $queries,
			'devices' => array(),
		);
	}
}
