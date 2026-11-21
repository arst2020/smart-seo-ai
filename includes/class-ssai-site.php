<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Site overview" page: domain authority, backlinks (Search Console Links export) with a
 * heuristic risk check, and a whole-site technical/content audit.
 */
class SSAI_Site {

	const SLUG      = 'smart-seo-ai-site';
	const OPT_AUTH  = 'ssai_authority';
	const OPT_LINKS = 'ssai_backlinks';
	const OPT_OPR   = 'ssai_backlink_opr';
	const T_AUDIT   = 'ssai_site_audit';
	const OPR_API   = 'https://openpagerank.keywordseverywhere.com/v1/domains/bulk';
	const MOZ_API   = 'https://api.moz.com/jsonrpc';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_post_ssai_links_import', array( __CLASS__, 'handle_links_import' ) );
		add_action( 'admin_post_ssai_site_action', array( __CLASS__, 'handle_action' ) );
	}

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'نمای کلی سایت', 'نمای کلی سایت', 'ssai_site', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function back( $msg, $type = 'success', $tab = '' ) {
		set_transient(
			'ssai_site_notice_' . get_current_user_id(),
			array(
				'msg'  => $msg,
				'type' => $type,
			),
			60
		);
		wp_safe_redirect( self::url( $tab ? array( 'tab' => $tab ) : array() ) );
		exit;
	}

	public static function domain() {
		return preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/* ================================================================ Authority */

	/** @return array { opr, opr_rank, opr_refdomains, moz_da, moz_spam, moz_rd, time_opr, time_moz, errors[] } */
	public static function authority() {
		$a = get_option( self::OPT_AUTH );
		return is_array( $a ) ? $a : array( 'errors' => array() );
	}

	/** Open PageRank for a list of domains (max 100 per request). @return array|WP_Error domain => row */
	public static function opr_bulk( $domains ) {
		$key = trim( (string) SSAI_Settings::get( 'opr_key' ) );
		if ( '' === $key ) {
			return new WP_Error( 'ssai_opr', 'کلید Open PageRank در تنظیمات وارد نشده است.' );
		}
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $domains ) ), 100 ) as $chunk ) {
			$res = wp_remote_post(
				self::OPR_API,
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'domains'         => $chunk,
							'include_history' => false,
						)
					),
				)
			);
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'ssai_opr', 'اتصال به Open PageRank ناموفق بود: ' . $res->get_error_message() );
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( 200 !== $code || ! is_array( $data ) ) {
				$msg = is_array( $data ) && isset( $data['error'] ) ? ( is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] ) ) : 'کد ' . $code;
				if ( 403 === $code ) {
					$msg .= ' — کلید رایگان فقط از یک آدرس IP کار می‌کند؛ IP سرور سایت را در حساب Open PageRank ثبت کنید.';
				}
				return new WP_Error( 'ssai_opr', 'Open PageRank: ' . $msg );
			}
			foreach ( isset( $data['results'] ) ? $data['results'] : array() as $r ) {
				if ( ! empty( $r['domain'] ) ) {
					$out[ strtolower( $r['domain'] ) ] = array(
						'opr'  => isset( $r['open_page_rank'] ) && null !== $r['open_page_rank'] ? (float) $r['open_page_rank'] : null,
						'rank' => isset( $r['rank'] ) ? (int) $r['rank'] : null,
						'rd'   => isset( $r['referring_domains'] ) ? (int) $r['referring_domains'] : null,
					);
				}
			}
		}
		return $out;
	}

	/** Moz Domain Authority + Spam Score for this site (costs 1 of the 50 free rows per month). */
	public static function moz_fetch() {
		$token = trim( (string) SSAI_Settings::get( 'moz_token' ) );
		if ( '' === $token ) {
			return new WP_Error( 'ssai_moz', 'توکن Moz در تنظیمات وارد نشده است.' );
		}
		$res = wp_remote_post(
			self::MOZ_API,
			array(
				'timeout' => 30,
				'headers' => array(
					'x-moz-token'  => $token,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => bin2hex( random_bytes( 16 ) ),
						'method'  => 'data.site.metrics.fetch',
						'params'  => array(
							'data' => array(
								'site_query' => array(
									'query' => self::domain(),
									'scope' => 'domain',
								),
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'ssai_moz', 'اتصال به Moz ناموفق بود: ' . $res->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || isset( $data['error'] ) || empty( $data['result']['site_metrics'] ) ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'کد ' . wp_remote_retrieve_response_code( $res );
			return new WP_Error( 'ssai_moz', 'Moz: ' . $msg );
		}
		return $data['result']['site_metrics'];
	}

	/* ================================================================ Backlinks (Search Console "Links" export) */

	private static function is_url( $s ) {
		return (bool) preg_match( '#^https?://#i', trim( (string) $s ) );
	}

	private static function is_domain( $s ) {
		return (bool) preg_match( '/^(?!-)[a-z0-9\-\.\x{0600}-\x{06FF}]+\.[a-z\x{0600}-\x{06FF}]{2,}$/iu', trim( (string) $s ) );
	}

	/** Decide which Links table a CSV is (by content first, then by file name). */
	private static function classify_links( $name, $rows ) {
		$n    = mb_strtolower( (string) $name );
		$data = array_slice( $rows, 1, 8 );
		if ( ! $data ) {
			return 'skip';
		}
		$c0 = array_column( $data, 0 );
		$c1 = array_column( $data, 1 );
		$urls    = count( array_filter( $c0, array( __CLASS__, 'is_url' ) ) );
		$doms    = count( array_filter( $c0, array( __CLASS__, 'is_domain' ) ) );
		$dates   = count( array_filter( $c1, function ( $v ) { return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}|^[۰-۹]{4}/u', trim( (string) $v ) ); } ) );
		$own     = count( array_filter( $c0, function ( $v ) { return self::is_url( $v ) && false !== stripos( $v, SSAI_Site::domain() ); } ) );
		if ( $urls && $dates ) {
			return 'latest';
		}
		if ( $own === count( $data ) ) {
			return ( false !== strpos( $n, 'intern' ) || false !== strpos( $n, 'داخلی' ) ) ? 'skip' : 'pages';
		}
		if ( $urls && ! $own ) {
			return 'latest';
		}
		if ( $doms === count( $data ) ) {
			return 'sites';
		}
		if ( false !== strpos( $n, 'text' ) || false !== strpos( $n, 'متن' ) || false !== strpos( $n, 'anchor' ) ) {
			return 'anchors';
		}
		if ( false !== strpos( $n, 'site' ) || false !== strpos( $n, 'سایت' ) ) {
			return 'sites';
		}
		return 'anchors';
	}

	public static function handle_links_import() {
		check_admin_referer( 'ssai_links_import' );
		if ( ! current_user_can( 'ssai_site_actions' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$tables = array();
		if ( ! empty( $_FILES['link_files']['name'] ) && is_array( $_FILES['link_files']['name'] ) ) {
			foreach ( $_FILES['link_files']['name'] as $i => $name ) {
				$tmp  = $_FILES['link_files']['tmp_name'][ $i ];
				$name = sanitize_file_name( wp_unslash( $name ) );
				if ( ! $tmp || ! is_uploaded_file( $tmp ) || $_FILES['link_files']['size'][ $i ] > 30 * MB_IN_BYTES ) {
					continue;
				}
				if ( preg_match( '/\.zip$/i', $name ) && class_exists( 'ZipArchive' ) ) {
					$zip = new ZipArchive();
					if ( true === $zip->open( $tmp ) ) {
						for ( $j = 0; $j < $zip->numFiles; $j++ ) {
							$en = $zip->getNameIndex( $j );
							if ( preg_match( '/\.csv$/i', $en ) ) {
								$tables[] = array( basename( $en ), (string) $zip->getFromIndex( $j ) );
							}
						}
						$zip->close();
					}
				} elseif ( preg_match( '/\.csv$/i', $name ) ) {
					$tables[] = array( $name, (string) file_get_contents( $tmp ) ); // phpcs:ignore
				}
			}
		}
		if ( ! $tables ) {
			self::back( 'فایل CSV یا ZIP معتبری بارگذاری نشد.', 'error', 'links' );
		}

		$old  = get_option( self::OPT_LINKS );
		$data = is_array( $old ) ? $old : array();
		$got  = array();
		foreach ( $tables as $t ) {
			$rows = SSAI_GSC_Import::csv_rows( $t[1] );
			if ( count( $rows ) < 2 ) {
				continue;
			}
			$type = self::classify_links( $t[0], $rows );
			$list = array();
			foreach ( array_slice( $rows, 1 ) as $r ) {
				if ( '' === trim( (string) $r[0] ) && 'anchors' !== $type ) {
					continue;
				}
				if ( 'sites' === $type ) {
					$list[] = array(
						'domain' => strtolower( preg_replace( '/^www\./i', '', trim( $r[0] ) ) ),
						'links'  => isset( $r[1] ) ? (int) SSAI_GSC_Import::num( $r[1] ) : 0,
						'pages'  => isset( $r[2] ) ? (int) SSAI_GSC_Import::num( $r[2] ) : 0,
					);
				} elseif ( 'pages' === $type ) {
					$list[] = array(
						'url'   => trim( $r[0] ),
						'links' => isset( $r[1] ) ? (int) SSAI_GSC_Import::num( $r[1] ) : 0,
						'sites' => isset( $r[2] ) ? (int) SSAI_GSC_Import::num( $r[2] ) : 0,
					);
				} elseif ( 'anchors' === $type ) {
					$list[] = array(
						'text'  => trim( (string) $r[0] ),
						'count' => isset( $r[1] ) ? (int) SSAI_GSC_Import::num( $r[1] ) : 0,
					);
				} elseif ( 'latest' === $type ) {
					$d      = isset( $r[1] ) ? strtr( trim( $r[1] ), array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) : '';
					$list[] = array(
						'url'  => trim( $r[0] ),
						'date' => preg_match( '/^\d{4}-\d{2}-\d{2}/', $d ) ? substr( $d, 0, 10 ) : '',
					);
				}
			}
			if ( 'skip' !== $type && $list ) {
				$data[ $type ] = array_slice( $list, 0, 'latest' === $type ? 100000 : 1000 );
				$got[]         = $type;
			}
		}
		if ( ! $got ) {
			self::back( 'در فایل‌ها جدول قابل شناسایی از گزارش Links سرچ کنسول پیدا نشد.', 'error', 'links' );
		}
		// Linking sites can also be derived from the latest-links list.
		if ( empty( $data['sites'] ) && ! empty( $data['latest'] ) ) {
			$agg = array();
			foreach ( $data['latest'] as $l ) {
				$h = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $l['url'], PHP_URL_HOST ) ) );
				if ( $h ) {
					$agg[ $h ] = isset( $agg[ $h ] ) ? $agg[ $h ] + 1 : 1;
				}
			}
			arsort( $agg );
			foreach ( $agg as $h => $c ) {
				$data['sites'][] = array(
					'domain' => $h,
					'links'  => $c,
					'pages'  => 0,
				);
			}
		}
		$data['time'] = time();
		update_option( self::OPT_LINKS, $data, false );
		$names = array(
			'sites'   => 'سایت‌های لینک‌دهنده',
			'pages'   => 'صفحه‌های لینک‌گرفته',
			'anchors' => 'متن لینک‌ها',
			'latest'  => 'آخرین لینک‌ها',
		);
		self::back( 'وارد شد: ' . implode( '، ', array_map( function ( $g ) use ( $names ) { return $names[ $g ]; }, array_unique( $got ) ) ), 'success', 'links' );
	}

	public static function links() {
		$l = get_option( self::OPT_LINKS );
		return is_array( $l ) ? $l : array();
	}

	/**
	 * Heuristic risk of a linking domain. This is NOT Moz's Spam Score – only common warning signs.
	 *
	 * @return array { score 0-100, flags[] }
	 */
	public static function risk( $site, $opr = null, $latest_paths = array() ) {
		$d     = $site['domain'];
		$flags = array();
		$s     = 0;
		$tld   = substr( strrchr( $d, '.' ), 1 );
		if ( in_array( $tld, array( 'xyz', 'top', 'icu', 'buzz', 'click', 'gq', 'tk', 'ml', 'cf', 'ga', 'work', 'loan', 'win', 'bid', 'kim', 'rest', 'cyou', 'monster', 'quest', 'sbs', 'cfd', 'su' ), true ) ) {
			$s      += 25;
			$flags[] = 'پسوند دامنه پرریسک (.' . $tld . ')';
		}
		if ( preg_match( '/casino|bet(?!ter)|poker|slot|porn|sex|xxx|viagra|cialis|pharma|loan|payday|crypto|forex|escort|dating|backlink|seo-?link|linkbuild|rank|directory|bookmark|apk|crack|torrent|kazino|شرط|کازینو|بت|بک.?لینک/iu', $d ) ) {
			$s      += 35;
			$flags[] = 'کلمات مشکوک در نام دامنه';
		}
		if ( preg_match( '/^\d+\.\d+\.\d+\.\d+$/', $d ) ) {
			$s      += 30;
			$flags[] = 'آدرس IP به جای دامنه';
		}
		if ( substr_count( $d, '-' ) >= 3 || preg_match( '/\d{4,}/', $d ) ) {
			$s      += 10;
			$flags[] = 'نام دامنه شبیه دامنه‌های ساختگی';
		}
		if ( ! empty( $site['links'] ) && $site['links'] >= 200 && ( empty( $site['pages'] ) || $site['pages'] <= 2 ) ) {
			$s      += 10;
			$flags[] = 'لینک سراسری (فوتر/سایدبار) با تعداد زیاد';
		}
		foreach ( $latest_paths as $p ) {
			if ( preg_match( '/casino|bet|porn|sex|viagra|loan|links?\.html|link-?exchange|directory|backlinks?|guest-?post|seo-?service|خرید-?بک/iu', $p ) ) {
				$s      += 20;
				$flags[] = 'صفحه لینک‌دهنده شبیه صفحه فروش/تبادل لینک';
				break;
			}
		}
		if ( null !== $opr ) {
			if ( $opr <= 0 ) {
				$s      += 15;
				$flags[] = 'بدون اعتبار در Open PageRank';
			} elseif ( $opr >= 4 ) {
				$s -= 15;
			}
		}
		return array(
			'score' => max( 0, min( 100, $s ) ),
			'flags' => $flags,
		);
	}

	/**
	 * Site-level backlink spam estimate from the imported Search Console links (no API, no key).
	 *
	 * @return array|null { domains, high, mid, pct (share of risky domains), score (link-weighted 0-100) }
	 */
	public static function risk_summary() {
		$l = self::links();
		if ( empty( $l['sites'] ) ) {
			return null;
		}
		$oprd  = get_option( self::OPT_OPR );
		$opr   = is_array( $oprd ) && isset( $oprd['data'] ) ? $oprd['data'] : array();
		$paths = array();
		foreach ( isset( $l['latest'] ) ? $l['latest'] : array() as $x ) {
			$h = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $x['url'], PHP_URL_HOST ) ) );
			if ( $h && ( ! isset( $paths[ $h ] ) || count( $paths[ $h ] ) < 20 ) ) {
				$paths[ $h ][] = rawurldecode( (string) wp_parse_url( $x['url'], PHP_URL_PATH ) );
			}
		}
		$high = 0;
		$mid  = 0;
		$sum  = 0;
		$w    = 0;
		foreach ( $l['sites'] as $s ) {
			$o  = isset( $opr[ $s['domain'] ] ) ? $opr[ $s['domain'] ]['opr'] : null;
			$r  = self::risk( $s, $o, isset( $paths[ $s['domain'] ] ) ? $paths[ $s['domain'] ] : array() );
			$lw = max( 1, (int) $s['links'] );
			// Sitewide links are counted at most 10 times so one footer link doesn't dominate.
			$lw   = min( $lw, 10 );
			$sum += $r['score'] * $lw;
			$w   += $lw;
			if ( $r['score'] >= 50 ) {
				$high++;
			} elseif ( $r['score'] >= 25 ) {
				$mid++;
			}
		}
		$n = count( $l['sites'] );
		return array(
			'domains' => $n,
			'high'    => $high,
			'mid'     => $mid,
			'pct'     => $n ? round( 100 * $high / $n ) : 0,
			'score'   => $w ? (int) round( $sum / $w ) : 0,
		);
	}

	/* ================================================================ Site audit (local, cached) */

	/** Cached audit or null (the audit is started with a button – it can take a while on big sites). */
	public static function cached_audit() {
		$c = get_option( self::T_AUDIT );
		return is_array( $c ) ? $c : null;
	}

	public static function audit( $force = false ) {
		if ( ! $force ) {
			$c = self::cached_audit();
			if ( $c ) {
				return $c;
			}
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore
		}
		$ids = get_posts(
			array(
				'post_type'      => SSAI_Admin::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$home_id = (int) get_option( 'page_on_front' );
		$map     = array(); // url_key => post id.
		foreach ( $ids as $id ) {
			$map[ SSAI_GSC_Import::url_key( get_permalink( $id ) ) ] = $id;
		}
		$host      = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		$inbound   = array_fill_keys( $ids, 0 );
		$titles    = array();
		$metas     = array();
		$thin      = array();
		$no_meta   = array();
		$no_kw     = array();
		$noindex   = array();
		$img_noalt = 0;
		$scores    = array();
		$errors    = 0;
		$unknown   = array(); // Internal URLs that are not a known post – checked for 404 below.
		foreach ( $ids as $id ) {
			$d = SSAI_Content::data( $id );
			if ( ! $d ) {
				continue;
			}
			$row      = SSAI_Admin::scan_row( get_post( $id ) );
			$scores[] = $row['score'];
			$errors  += $row['err'] ? 1 : 0;
			$t        = SSAI_Content::norm( $d['seo_title'] );
			$m        = SSAI_Content::norm( $d['meta_description'] );
			if ( '' !== $t ) {
				$titles[ $t ][] = $id;
			}
			if ( '' === $m ) {
				$no_meta[] = $id;
			} else {
				$metas[ $m ][] = $id;
			}
			if ( $d['words'] < 300 && $id !== $home_id ) {
				$thin[] = $id;
			}
			if ( ! $d['keywords'] ) {
				$no_kw[] = $id;
			}
			foreach ( $d['images'] as $img ) {
				if ( '' === $img['alt'] ) {
					$img_noalt++;
				}
			}
			if ( SSAI_SEO::post_noindex( $id ) ) {
				$noindex[] = $id;
			}
			if ( preg_match_all( '/<a\b[^>]*(?<=\s)href=(["\'])(.*?)\1/i', $d['html'], $mm ) ) {
				foreach ( array_unique( $mm[2] ) as $href ) {
					$href = html_entity_decode( trim( $href ) );
					if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript):/i', $href ) ) {
						continue;
					}
					$abs = preg_match( '#^https?://#i', $href ) ? $href : ( '/' === $href[0] ? home_url( $href ) : '' );
					if ( '' === $abs ) {
						continue;
					}
					$h = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $abs, PHP_URL_HOST ) ) );
					if ( $h !== $host ) {
						continue;
					}
					$k = SSAI_GSC_Import::url_key( preg_replace( '/[?#].*$/', '', $abs ) );
					if ( isset( $map[ $k ] ) ) {
						if ( $map[ $k ] !== $id ) {
							$inbound[ $map[ $k ] ]++;
						}
					} elseif ( ! preg_match( '#/wp-(content|admin|includes)/|\.(jpe?g|png|gif|webp|svg|pdf|zip|css|js|xml|txt|mp4)$#i', $abs ) ) {
						$unknown[ $k ] = array(
							'url'  => preg_replace( '/[?#].*$/', '', $abs ),
							'from' => $id,
						);
					}
				}
			}
		}
		// Check up to 40 unknown internal URLs (categories, tags… are fine; 404s are broken links).
		$broken = array();
		foreach ( array_slice( $unknown, 0, 25 ) as $u ) {
			$r = wp_remote_head(
				$u['url'],
				array(
					'timeout'     => 5,
					'redirection' => 3,
					'sslverify'   => false,
				)
			);
			if ( ! is_wp_error( $r ) && in_array( (int) wp_remote_retrieve_response_code( $r ), array( 404, 410 ), true ) ) {
				$broken[] = $u;
			}
		}
		$orphans = array();
		foreach ( $inbound as $id => $n ) {
			if ( 0 === $n && $id !== $home_id ) {
				$orphans[] = $id;
			}
		}
		$dup_titles = array_values( array_filter( $titles, function ( $g ) { return count( $g ) > 1; } ) );
		$dup_metas  = array_values( array_filter( $metas, function ( $g ) { return count( $g ) > 1; } ) );

		$out = array(
			'time'       => time(),
			'pages'      => count( $ids ),
			'avg_score'  => $scores ? (int) round( array_sum( $scores ) / count( $scores ) ) : 0,
			'with_err'   => $errors,
			'no_meta'    => $no_meta,
			'no_kw'      => $no_kw,
			'thin'       => $thin,
			'dup_titles' => $dup_titles,
			'dup_metas'  => $dup_metas,
			'orphans'    => $orphans,
			'broken'     => $broken,
			'noindex'    => $noindex,
			'img_noalt'  => $img_noalt,
			'tech'       => self::tech_checks(),
		);
		update_option( self::T_AUDIT, $out, false );
		return $out;
	}

	/** Whole-site technical checks: indexing allowed, robots.txt, sitemap, HTTPS/SSL. */
	private static function tech_checks() {
		$t = array();
		$t['blog_public'] = (bool) get_option( 'blog_public' );
		$robots           = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 8, 'sslverify' => false ) );
		$body             = is_wp_error( $robots ) ? '' : (string) wp_remote_retrieve_body( $robots );
		$t['robots_ok']   = ! is_wp_error( $robots ) && 200 === (int) wp_remote_retrieve_response_code( $robots );
		$t['robots_all']  = (bool) preg_match( '/User-agent:\s*\*\s*(?:\r?\n(?!User-agent)[^\n]*)*?\r?\nDisallow:\s*\/\s*(\r?\n|$)/i', $body );
		$t['robots_map']  = (bool) preg_match( '/^Sitemap:/mi', $body );
		$t['sitemap']     = '';
		foreach ( array( '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml' ) as $p ) {
			$r = wp_remote_get( home_url( $p ), array( 'timeout' => 8, 'sslverify' => false, 'limit_response_size' => 4096 ) );
			if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) && false !== stripos( (string) wp_remote_retrieve_body( $r ), '<' ) ) {
				$t['sitemap'] = home_url( $p );
				break;
			}
		}
		$t['https']    = 0 === strpos( home_url(), 'https://' );
		$t['ssl_days'] = null;
		if ( $t['https'] && function_exists( 'stream_socket_client' ) ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$ctx  = stream_context_create(
				array(
					'ssl' => array(
						'capture_peer_cert' => true,
						'verify_peer'       => false,
						'verify_peer_name'  => false,
						'SNI_enabled'       => true,
						'peer_name'         => $host,
					),
				)
			);
			$fp   = @stream_socket_client( 'ssl://' . $host . ':443', $en, $es, 8, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore
			if ( $fp ) {
				$p = stream_context_get_params( $fp );
				if ( ! empty( $p['options']['ssl']['peer_certificate'] ) ) {
					$c = openssl_x509_parse( $p['options']['ssl']['peer_certificate'] );
					if ( ! empty( $c['validTo_time_t'] ) ) {
						$t['ssl_days'] = (int) floor( ( $c['validTo_time_t'] - time() ) / DAY_IN_SECONDS );
					}
				}
				fclose( $fp );
			}
		}
		return $t;
	}

	/* ================================================================ Actions */

	public static function handle_action() {
		check_admin_referer( 'ssai_site_action' );
		if ( ! current_user_can( 'ssai_site_actions' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		if ( 'audit' === $do ) {
			self::audit( true );
			self::back( 'بررسی کل سایت دوباره انجام شد.' );
		}
		if ( 'opr' === $do ) {
			$r = self::opr_bulk( array( self::domain() ) );
			$a = self::authority();
			if ( is_wp_error( $r ) ) {
				self::back( $r->get_error_message(), 'error' );
			}
			$me                  = isset( $r[ self::domain() ] ) ? $r[ self::domain() ] : reset( $r );
			$a['opr']            = $me ? $me['opr'] : null;
			$a['opr_rank']       = $me ? $me['rank'] : null;
			$a['opr_rd']         = $me ? $me['rd'] : null;
			$a['time_opr']       = time();
			update_option( self::OPT_AUTH, $a, false );
			self::back( 'Open PageRank به‌روز شد.' );
		}
		if ( 'moz' === $do ) {
			$m = self::moz_fetch();
			if ( is_wp_error( $m ) ) {
				self::back( $m->get_error_message(), 'error' );
			}
			$a             = self::authority();
			$a['moz_da']   = isset( $m['domain_authority'] ) ? (int) $m['domain_authority'] : null;
			$a['moz_spam'] = isset( $m['spam_score'] ) ? (int) $m['spam_score'] : null;
			$a['moz_rd']   = isset( $m['root_domains_to_root_domain'] ) ? (int) $m['root_domains_to_root_domain'] : null;
			$a['time_moz'] = time();
			update_option( self::OPT_AUTH, $a, false );
			self::back( 'Moz به‌روز شد (۱ ردیف از سهمیه ماهانه مصرف شد).' );
		}
		if ( 'opr_links' === $do ) {
			$l = self::links();
			if ( empty( $l['sites'] ) ) {
				self::back( 'ابتدا خروجی لینک‌ها را وارد کنید.', 'error', 'links' );
			}
			$doms = array_slice( wp_list_pluck( $l['sites'], 'domain' ), 0, 300 );
			$r    = self::opr_bulk( $doms );
			if ( is_wp_error( $r ) ) {
				self::back( $r->get_error_message(), 'error', 'links' );
			}
			update_option( self::OPT_OPR, array( 'time' => time(), 'data' => $r ), false );
			self::back( sprintf( 'اعتبار %d دامنه لینک‌دهنده از Open PageRank گرفته شد.', count( $r ) ), 'success', 'links' );
		}
		self::back( 'عمل نامعتبر.', 'error' );
	}

	/* ================================================================ Page */

	private static function action_button( $do, $label, $primary = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ssai-inline-form">';
		echo '<input type="hidden" name="action" value="ssai_site_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'ssai_site_action' );
		echo '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function tile( $label, $value, $sub = '', $cls = '' ) {
		echo '<div class="ssai-tile' . ( $cls ? ' ssai-tile-' . esc_attr( $cls ) : '' ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong>' . ( $sub ? '<span class="description">' . wp_kses_post( $sub ) . '</span>' : '' ) . '</div>';
	}

	private static function post_list( $ids, $max = 15 ) {
		if ( ! $ids ) {
			return '';
		}
		$h = '<ul class="ssai-plist">';
		foreach ( array_slice( $ids, 0, $max ) as $id ) {
			$h .= '<li><a href="' . esc_url( SSAI_Report::url( $id ) ) . '">' . esc_html( get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ) . '</a></li>';
		}
		if ( count( $ids ) > $max ) {
			$h .= '<li class="description">و ' . number_format_i18n( count( $ids ) - $max ) . ' مورد دیگر…</li>';
		}
		return $h . '</ul>';
	}

	private static function check_row( $ok, $text, $detail = '', $level = 'error' ) {
		$cls = true === $ok ? 'good' : ( null === $ok ? 'warning' : $level );
		echo '<li class="ssai-' . esc_attr( $cls ) . '"><span class="ssai-dot"></span><div class="ssai-li-body"><div>' . esc_html( $text ) . '</div>' . ( $detail ? '<div class="ssai-hint">' . wp_kses_post( $detail ) . '</div>' : '' ) . '</div></li>';
	}

	public static function page() {
		$tab    = isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'links', 'ai', 'ahrefs', 'tech' ), true ) ? sanitize_key( $_GET['tab'] ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$notice = get_transient( 'ssai_site_notice_' . get_current_user_id() );
		delete_transient( 'ssai_site_notice_' . get_current_user_id() );
		?>
		<div class="wrap ssai ssai-report ssai-site" dir="rtl">
			<h1>نمای کلی سایت <span class="description" dir="ltr"><?php echo esc_html( self::domain() ); ?></span></h1>
			<hr class="wp-header-end">
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>">سلامت و اعتبار سایت</a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'links' ) ) ); ?>" class="nav-tab <?php echo 'links' === $tab ? 'nav-tab-active' : ''; ?>">بک‌لینک‌ها</a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'ai' ) ) ); ?>" class="nav-tab <?php echo 'ai' === $tab ? 'nav-tab-active' : ''; ?>">AEO / GEO / SXO</a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'ahrefs' ) ) ); ?>" class="nav-tab <?php echo 'ahrefs' === $tab ? 'nav-tab-active' : ''; ?>">Ahrefs</a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'tech' ) ) ); ?>" class="nav-tab <?php echo 'tech' === $tab ? 'nav-tab-active' : ''; ?>">بررسی فنی</a>
			</nav>
			<?php
			if ( 'links' === $tab ) {
				self::render_links();
			} elseif ( 'ahrefs' === $tab ) {
				SSAI_Ahrefs::render();
			} elseif ( 'tech' === $tab ) {
				SSAI_Tech::render();
			} elseif ( 'ai' === $tab ) {
				wp_enqueue_script( 'ssai-aeo', SSAI_URL . 'assets/aeo.js', array( 'ssai-admin' ), SSAI_VERSION, true );
				SSAI_AEO::render();
			} else {
				self::render_overview();
			}
			?>
		</div>
		<?php
	}

	private static function render_overview() {
		$a     = self::authority();
		$audit = self::cached_audit();
		if ( ! $audit ) {
			echo '<section class="ssai-card"><h2>بررسی کل سایت</h2><p>بررسی فنی و محتوایی همه صفحه‌ها هنوز انجام نشده است (برای سایت‌های بزرگ ممکن است یک تا دو دقیقه طول بکشد).</p>';
			if ( current_user_can( 'ssai_site_actions' ) ) {
				self::action_button( 'audit', 'شروع بررسی کل سایت', true );
			}
			echo '</section>';
			$audit = array(
				'pages'      => 0,
				'avg_score'  => 0,
				'with_err'   => 0,
				'none'       => true,
			);
		}
		$t     = isset( $audit['tech'] ) ? $audit['tech'] : null;
		$links = self::links();
		$gsc   = SSAI_GSC_Import::site();
		$obs   = get_option( SSAI_Health::OPT_OBS );
		$front = (int) get_option( 'page_on_front' );
		$psi   = $front ? SSAI_Health::psi( $front ) : array();
		$admin = current_user_can( 'ssai_site_actions' );
		?>
		<section class="ssai-card">
			<h2>اعتبار دامنه</h2>
			<div class="ssai-tiles">
				<?php
				self::tile( 'Open PageRank (۰ تا ۱۰)', isset( $a['opr'] ) && null !== $a['opr'] ? number_format_i18n( $a['opr'], 2 ) : '—', isset( $a['opr_rd'] ) && $a['opr_rd'] ? 'دامنه‌های ارجاع‌دهنده: ' . number_format_i18n( $a['opr_rd'] ) : 'رایگان؛ بر اساس داده Common Crawl' );
				$rs = self::risk_summary();
				if ( $rs ) {
					$lvl = $rs['pct'] <= 10 ? 'good' : ( $rs['pct'] <= 30 ? 'warning' : 'error' );
					self::tile(
						'ریسک اسپم بک‌لینک‌ها (تخمین افزونه)',
						number_format_i18n( $rs['pct'] ) . '٪',
						number_format_i18n( $rs['high'] ) . ' دامنه پرخطر و ' . number_format_i18n( $rs['mid'] ) . ' مشکوک از ' . number_format_i18n( $rs['domains'] ) . ' — <a href="' . esc_url( self::url( array( 'tab' => 'links' ) ) ) . '">بررسی</a>',
						$lvl
					);
				} else {
					self::tile( 'ریسک اسپم بک‌لینک‌ها (تخمین افزونه)', '—', 'رایگان و بدون کلید؛ بعد از <a href="' . esc_url( self::url( array( 'tab' => 'links' ) ) ) . '">وارد کردن خروجی Links</a> محاسبه می‌شود' );
				}
				$has_moz = SSAI_Settings::get( 'moz_token' ) || ( isset( $a['moz_da'] ) && null !== $a['moz_da'] );
				if ( $has_moz ) :
				self::tile( 'Moz Domain Authority (۱ تا ۱۰۰)', isset( $a['moz_da'] ) && null !== $a['moz_da'] ? number_format_i18n( $a['moz_da'] ) : '—', isset( $a['moz_rd'] ) && $a['moz_rd'] ? 'دامنه‌های لینک‌دهنده (Moz): ' . number_format_i18n( $a['moz_rd'] ) : 'اختیاری؛ ۵۰ استعلام رایگان در ماه' );
				self::tile( 'Moz Spam Score', isset( $a['moz_spam'] ) && null !== $a['moz_spam'] ? number_format_i18n( $a['moz_spam'] ) . '٪' : '—', isset( $a['moz_spam'] ) && null !== $a['moz_spam'] ? ( $a['moz_spam'] <= 30 ? 'پایین (خوب)' : ( $a['moz_spam'] <= 60 ? 'متوسط' : 'بالا' ) ) : 'احتمال جریمه بر اساس الگوی سایت‌های جریمه‌شده', isset( $a['moz_spam'] ) && null !== $a['moz_spam'] ? ( $a['moz_spam'] <= 30 ? 'good' : ( $a['moz_spam'] <= 60 ? 'warning' : 'error' ) ) : '' );
				endif;
				self::tile( 'بک‌لینک‌ها (سرچ کنسول)', ! empty( $links['sites'] ) ? number_format_i18n( array_sum( wp_list_pluck( $links['sites'], 'links' ) ) ) : '—', ! empty( $links['sites'] ) ? 'از ' . number_format_i18n( count( $links['sites'] ) ) . ' دامنه — <a href="' . esc_url( self::url( array( 'tab' => 'links' ) ) ) . '">جزئیات</a>' : '<a href="' . esc_url( self::url( array( 'tab' => 'links' ) ) ) . '">وارد کردن خروجی Links</a>' );
				?>
			</div>
			<?php if ( $admin ) : ?>
				<p>
					<?php
					if ( SSAI_Settings::get( 'opr_key' ) ) {
						self::action_button( 'opr', 'به‌روزرسانی Open PageRank' );
					}
					if ( SSAI_Settings::get( 'moz_token' ) ) {
						self::action_button( 'moz', 'استعلام Moz (۱ ردیف)' );
					}
					?>
				</p>
				<?php if ( ! SSAI_Settings::get( 'opr_key' ) ) : ?>
					<p class="description">برای امتیاز Open PageRank، کلید رایگان آن (بدون کارت بانکی) را از <a href="https://keywordseverywhere.com/openpagerank/" target="_blank" rel="noopener">این‌جا</a> بگیرید و در <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-settings' ) ); ?>">تنظیمات</a> وارد کنید.</p>
				<?php endif; ?>
			<?php endif; ?>
			<p class="description">گوگل از این امتیازها (DA، Spam Score، Open PageRank) استفاده نمی‌کند؛ این‌ها تخمین شرکت‌های دیگر از اعتبار سایت‌اند و فقط برای مقایسه و پیگیری روند مفیدند.</p>
		</section>

		<section class="ssai-card">
			<h2>خلاصه سایت</h2>
			<div class="ssai-tiles">
				<?php
				if ( empty( $audit['none'] ) ) {
					self::tile( 'صفحه‌های منتشرشده', number_format_i18n( $audit['pages'] ) );
					self::tile( 'میانگین امتیاز سئو', number_format_i18n( $audit['avg_score'] ), number_format_i18n( $audit['with_err'] ) . ' صفحه دارای خطا', $audit['avg_score'] >= 80 ? 'good' : ( $audit['avg_score'] >= 50 ? 'warning' : 'error' ) );
				}
				if ( $gsc ) {
					self::tile( 'کلیک از گوگل', number_format_i18n( $gsc['totals']['clicks'] ), esc_html( $gsc['range'] ) );
					self::tile( 'صفحه‌های دیده‌شده در گوگل', number_format_i18n( count( $gsc['pages'] ) ), $audit['pages'] ? 'از ' . number_format_i18n( $audit['pages'] ) . ' صفحه سایت' : '' );
				}
				if ( ! empty( $psi['mobile']['scores']['performance'] ) ) {
					$sp = $psi['mobile']['scores']['performance'];
					self::tile( 'سرعت موبایل صفحه اصلی', number_format_i18n( $sp ), 'PageSpeed', $sp >= 90 ? 'good' : ( $sp >= 50 ? 'warning' : 'error' ) );
				}
				if ( is_array( $obs ) && ! empty( $obs['grade'] ) ) {
					self::tile( 'امنیت (Observatory)', $obs['grade'], '', 0 === strpos( $obs['grade'], 'A' ) ? 'good' : 'warning' );
				}
				?>
			</div>
		</section>

		<?php if ( ! empty( $audit['none'] ) ) { return; } ?>
		<div class="ssai-cards">
			<section class="ssai-card">
				<h2>فنی و ایندکس</h2>
				<ul class="ssai-list">
					<?php
					self::check_row( $t['blog_public'], $t['blog_public'] ? 'سایت برای موتورهای جستجو قابل ایندکس است.' : 'ایندکس کل سایت خاموش است! (تنظیمات › خواندن › «از موتورهای جستجو بخواه…»)' );
					self::check_row( $t['robots_ok'] && ! $t['robots_all'], ! $t['robots_ok'] ? 'فایل robots.txt در دسترس نیست.' : ( $t['robots_all'] ? 'robots.txt کل سایت را برای همه ربات‌ها مسدود کرده است (Disallow: /).' : 'robots.txt مشکلی ندارد.' ), '<a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener">مشاهده robots.txt</a>' );
					self::check_row( '' !== $t['sitemap'] ? true : false, '' !== $t['sitemap'] ? 'نقشه سایت (sitemap) پیدا شد.' : 'نقشه سایت پیدا نشد.', '' !== $t['sitemap'] ? '<a href="' . esc_url( $t['sitemap'] ) . '" target="_blank" rel="noopener" dir="ltr">' . esc_html( $t['sitemap'] ) . '</a>' . ( $t['robots_map'] ? '' : ' — بهتر است آدرسش در robots.txt هم با «Sitemap:» بیاید.' ) : 'Rank Math یا Yoast را فعال کنید یا نقشه سایت وردپرس را بررسی کنید، و آدرسش را در سرچ کنسول ثبت کنید.' );
					self::check_row( $t['https'], $t['https'] ? 'سایت روی HTTPS است.' : 'آدرس سایت در تنظیمات وردپرس HTTPS نیست.' );
					if ( null !== $t['ssl_days'] ) {
						self::check_row( $t['ssl_days'] > 14 ? true : ( $t['ssl_days'] > 0 ? null : false ), $t['ssl_days'] > 0 ? sprintf( 'گواهی SSL تا %s روز دیگر معتبر است.', number_format_i18n( $t['ssl_days'] ) ) : 'گواهی SSL منقضی شده است!' );
					}
					self::check_row( ! $audit['noindex'] ? true : null, $audit['noindex'] ? sprintf( '%s صفحه noindex است (در گوگل نمی‌آید) — مطمئن شوید عمدی است.', number_format_i18n( count( $audit['noindex'] ) ) ) : 'هیچ صفحه منتشرشده‌ای noindex نیست.', self::post_list( $audit['noindex'] ) );
					?>
				</ul>
			</section>
			<section class="ssai-card">
				<h2>محتوا و لینک‌سازی داخلی</h2>
				<ul class="ssai-list">
					<?php
					$dt = count( $audit['dup_titles'] );
					$dm = count( $audit['dup_metas'] );
					self::check_row( ! $dt, $dt ? sprintf( '%s گروه صفحه عنوان سئوی یکسان دارند.', number_format_i18n( $dt ) ) : 'عنوان‌های سئو تکراری نیستند.', $dt ? self::post_list( array_merge( ...$audit['dup_titles'] ) ) : '' );
					self::check_row( ! $dm ? true : null, $dm ? sprintf( '%s گروه صفحه توضیحات متای یکسان دارند.', number_format_i18n( $dm ) ) : 'توضیحات متا تکراری نیستند.', $dm ? self::post_list( array_merge( ...$audit['dup_metas'] ) ) : '' );
					self::check_row( ! $audit['no_meta'] ? true : null, $audit['no_meta'] ? sprintf( '%s صفحه توضیحات متا ندارند.', number_format_i18n( count( $audit['no_meta'] ) ) ) : 'همه صفحه‌ها توضیحات متا دارند.', self::post_list( $audit['no_meta'] ) );
					self::check_row( ! $audit['no_kw'] ? true : null, $audit['no_kw'] ? sprintf( '%s صفحه کلمه کلیدی ندارند.', number_format_i18n( count( $audit['no_kw'] ) ) ) : 'همه صفحه‌ها کلمه کلیدی دارند.', self::post_list( $audit['no_kw'] ) );
					self::check_row( ! $audit['thin'] ? true : null, $audit['thin'] ? sprintf( '%s صفحه کمتر از ۳۰۰ کلمه دارند (محتوای کم).', number_format_i18n( count( $audit['thin'] ) ) ) : 'صفحه کم‌محتوا نیست.', self::post_list( $audit['thin'] ) );
					self::check_row( ! $audit['orphans'] ? true : null, $audit['orphans'] ? sprintf( '%s صفحه «یتیم» هستند: هیچ صفحه دیگری از سایت به آن‌ها لینک نداده (گوگل دیرتر پیدا و کمتر مهم حسابشان می‌کند).', number_format_i18n( count( $audit['orphans'] ) ) ) : 'همه صفحه‌ها حداقل یک لینک داخلی دریافت می‌کنند.', self::post_list( $audit['orphans'] ) . ( $audit['orphans'] ? '<span class="description">لینک‌های منو و فوتر قالب این‌جا حساب نمی‌شوند؛ فقط لینک‌های داخل محتوا.</span>' : '' ) );
					$bl = '';
					foreach ( array_slice( $audit['broken'], 0, 15 ) as $b ) {
						$bl .= '<li><span dir="ltr">' . esc_html( rawurldecode( $b['url'] ) ) . '</span> ← در <a href="' . esc_url( SSAI_Report::url( $b['from'] ) ) . '">' . esc_html( get_the_title( $b['from'] ) ) . '</a></li>';
					}
					self::check_row( ! $audit['broken'], $audit['broken'] ? sprintf( '%s لینک داخلی خراب (۴۰۴) پیدا شد.', number_format_i18n( count( $audit['broken'] ) ) ) : 'لینک داخلی خراب پیدا نشد.', $bl ? '<ul class="ssai-plist">' . $bl . '</ul>' : '' );
					self::check_row( ! $audit['img_noalt'] ? true : null, $audit['img_noalt'] ? sprintf( '%s تصویر در محتوا متن alt ندارند.', number_format_i18n( $audit['img_noalt'] ) ) : 'همه تصاویر محتوا alt دارند.' );
					?>
				</ul>
			</section>
		</div>
		<p class="description">بررسی کل سایت: <?php echo esc_html( wp_date( 'Y/m/d H:i', $audit['time'] ) ); ?>
			<?php
			if ( current_user_can( 'ssai_site_actions' ) ) {
				self::action_button( 'audit', 'بررسی دوباره الان' );
			}
			?>
		</p>
		<?php
	}

	private static function render_links() {
		$l     = self::links();
		$oprd  = get_option( self::OPT_OPR );
		$opr   = is_array( $oprd ) && isset( $oprd['data'] ) ? $oprd['data'] : array();
		$admin = current_user_can( 'ssai_site_actions' );
		?>
		<section class="ssai-card">
			<h2>وارد کردن بک‌لینک‌ها از سرچ کنسول</h2>
			<ol class="ssai-steps">
				<li>در سرچ کنسول به <strong>Links</strong> (پیوندها) بروید.</li>
				<li>بالای صفحه <strong>Export external links › Latest links</strong> را بزنید و CSV را دانلود کنید (مهم‌ترین فایل: آدرس صفحه‌های لینک‌دهنده و تاریخ).</li>
				<li>اختیاری: در همان صفحه روی «More» کنار <em>Top linking sites</em> و <em>Top linking text</em> بزنید و از هر کدام Export بگیرید.</li>
				<li>همه فایل‌ها را با هم این‌جا بارگذاری کنید.</li>
			</ol>
			<?php if ( $admin ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="ssai_links_import">
					<?php wp_nonce_field( 'ssai_links_import' ); ?>
					<input type="file" name="link_files[]" multiple accept=".csv,.zip" required>
					<button class="button button-primary">وارد کردن</button>
				</form>
			<?php endif; ?>
			<?php if ( ! empty( $l['time'] ) ) : ?>
				<p class="description">آخرین ورود: <?php echo esc_html( wp_date( 'Y/m/d H:i', $l['time'] ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
		if ( empty( $l['sites'] ) && empty( $l['anchors'] ) ) {
			return;
		}

		// Paths of latest links per domain (for the risk check).
		$paths = array();
		foreach ( isset( $l['latest'] ) ? $l['latest'] : array() as $x ) {
			$h = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $x['url'], PHP_URL_HOST ) ) );
			if ( $h && ( ! isset( $paths[ $h ] ) || count( $paths[ $h ] ) < 20 ) ) {
				$paths[ $h ][] = rawurldecode( (string) wp_parse_url( $x['url'], PHP_URL_PATH ) );
			}
		}
		$sites = isset( $l['sites'] ) ? $l['sites'] : array();
		$high  = array();
		$rows  = array();
		foreach ( $sites as $s ) {
			$o      = isset( $opr[ $s['domain'] ] ) ? $opr[ $s['domain'] ]['opr'] : null;
			$r      = self::risk( $s, $o, isset( $paths[ $s['domain'] ] ) ? $paths[ $s['domain'] ] : array() );
			$rows[] = array( $s, $o, $r );
			if ( $r['score'] >= 50 ) {
				$high[] = $s['domain'];
			}
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b[2]['score'] - $a[2]['score'] ?: $b[0]['links'] - $a[0]['links'];
			}
		);
		$total_links = array_sum( wp_list_pluck( $sites, 'links' ) );
		$new30       = 0;
		$months      = array();
		foreach ( isset( $l['latest'] ) ? $l['latest'] : array() as $x ) {
			if ( $x['date'] ) {
				if ( strtotime( $x['date'] ) >= time() - 30 * DAY_IN_SECONDS ) {
					$new30++;
				}
				$m            = substr( $x['date'], 0, 7 );
				$months[ $m ] = isset( $months[ $m ] ) ? $months[ $m ] + 1 : 1;
			}
		}
		ksort( $months );
		$months = array_slice( $months, -12, 12, true );
		?>
		<section class="ssai-card">
			<h2>خلاصه بک‌لینک‌ها</h2>
			<div class="ssai-tiles">
				<?php
				self::tile( 'دامنه‌های لینک‌دهنده', number_format_i18n( count( $sites ) ) );
				self::tile( 'تعداد لینک‌ها', number_format_i18n( $total_links ) );
				if ( ! empty( $l['latest'] ) ) {
					self::tile( 'لینک‌های جدید (۳۰ روز)', number_format_i18n( $new30 ), 'طبق تاریخ کشف گوگل' );
				}
				self::tile( 'دامنه‌های مشکوک', number_format_i18n( count( $high ) ), 'ریسک ۵۰ به بالا (تخمینی)', count( $high ) ? 'warning' : 'good' );
				?>
			</div>
			<?php if ( $months ) : ?>
				<h3>لینک‌های کشف‌شده در هر ماه</h3>
				<div class="ssai-bars" role="img" aria-label="تعداد لینک‌های جدید در هر ماه">
					<?php
					$mx = max( $months );
					foreach ( $months as $m => $c ) {
						echo '<div class="ssai-bar-col" title="' . esc_attr( $m . ': ' . $c ) . '"><span class="ssai-bar-val">' . esc_html( number_format_i18n( $c ) ) . '</span><span class="ssai-bar" style="height:' . esc_attr( max( 2, round( $c / $mx * 120 ) ) ) . 'px"></span><span class="ssai-bar-lbl" dir="ltr">' . esc_html( $m ) . '</span></div>';
					}
					?>
				</div>
			<?php endif; ?>
		</section>

		<section class="ssai-card">
			<h2>سایت‌های لینک‌دهنده و ریسک اسپم <span class="description">(<?php echo esc_html( number_format_i18n( count( $sites ) ) ); ?>)</span></h2>
			<p class="description">«ریسک» این‌جا یک تخمین ساده از نشانه‌های رایج اسپم است (پسوند دامنه، کلمات مشکوک، لینک سراسری، نبود اعتبار)، نه Spam Score شرکت Moz. گوگل بیشتر لینک‌های اسپم را خودش نادیده می‌گیرد؛ <strong>Disavow فقط وقتی لازم است</strong> که در سرچ کنسول «Manual action» گرفته باشید یا خودتان لینک خریده باشید.</p>
			<?php
			if ( $admin && SSAI_Settings::get( 'opr_key' ) ) {
				self::action_button( 'opr_links', 'گرفتن اعتبار دامنه‌ها از Open PageRank' );
				if ( $opr ) {
					echo ' <span class="description">آخرین: ' . esc_html( wp_date( 'Y/m/d', $oprd['time'] ) ) . '</span>';
				}
			}
			?>
			<table class="widefat striped ssai-kwtable ssai-links-table">
				<thead><tr><th><input type="checkbox" id="ssai-dis-all" aria-label="انتخاب همه پرریسک‌ها"></th><th>دامنه</th><th>لینک</th><th>صفحه‌های هدف</th><th>Open PageRank</th><th>ریسک</th><th>نشانه‌ها</th></tr></thead>
				<tbody>
				<?php
				foreach ( array_slice( $rows, 0, 300 ) as $i => $row ) {
					list( $s, $o, $r ) = $row;
					$cls = $r['score'] >= 50 ? 'error' : ( $r['score'] >= 25 ? 'warning' : 'good' );
					$lbl = $r['score'] >= 50 ? 'بالا' : ( $r['score'] >= 25 ? 'متوسط' : 'پایین' );
					echo '<tr' . ( $i >= 50 ? ' class="ssai-more" hidden' : '' ) . '><td><input type="checkbox" class="ssai-dis" value="' . esc_attr( $s['domain'] ) . '"' . ( $r['score'] >= 50 ? ' data-high="1"' : '' ) . '></td>';
					echo '<td dir="ltr"><a href="https://' . esc_attr( $s['domain'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $s['domain'] ) . '</a></td>';
					echo '<td>' . esc_html( number_format_i18n( $s['links'] ) ) . '</td><td>' . ( $s['pages'] ? esc_html( number_format_i18n( $s['pages'] ) ) : '—' ) . '</td>';
					echo '<td>' . ( null !== $o ? esc_html( number_format_i18n( $o, 2 ) ) : '—' ) . '</td>';
					echo '<td><span class="ssai-pos ssai-risk-' . esc_attr( $cls ) . '">' . esc_html( $lbl . ' ' . number_format_i18n( $r['score'] ) ) . '</span></td>';
					echo '<td class="description">' . esc_html( implode( '، ', $r['flags'] ) ) . '</td></tr>';
				}
				?>
				</tbody>
			</table>
			<?php if ( count( $rows ) > 50 ) : ?>
				<p><button type="button" class="button" onclick="document.querySelectorAll('.ssai-links-table .ssai-more').forEach(function(r){r.hidden=false});this.remove();">نمایش همه</button></p>
			<?php endif; ?>
			<details class="ssai-import">
				<summary>ساخت فایل Disavow از دامنه‌های انتخاب‌شده</summary>
				<p class="ssai-msg ssai-msg-error">هشدار: Disavow اشتباه می‌تواند لینک‌های خوب را بی‌اثر کند و رتبه را پایین بیاورد. فقط دامنه‌هایی را انتخاب کنید که مطمئنید اسپم یا خریداری‌شده‌اند. فایل را در <a href="https://search.google.com/search-console/disavow-links" target="_blank" rel="noopener">ابزار Disavow گوگل</a> بارگذاری کنید.</p>
				<textarea id="ssai-dis-out" class="large-text code" rows="6" dir="ltr" readonly></textarea>
				<p><button type="button" class="button" id="ssai-dis-dl">دانلود disavow.txt</button></p>
			</details>
			<script>
			(function () {
				var out = document.getElementById('ssai-dis-out');
				function upd() {
					var d = [].slice.call(document.querySelectorAll('.ssai-dis:checked')).map(function (c) { return 'domain:' + c.value; });
					out.value = d.length ? '# Disavow – <?php echo esc_js( self::domain() ); ?> – ' + new Date().toISOString().slice(0, 10) + '\n' + d.join('\n') : '';
				}
				document.addEventListener('change', function (e) { if (e.target.classList.contains('ssai-dis')) upd(); });
				var all = document.getElementById('ssai-dis-all');
				if (all) all.addEventListener('change', function () { document.querySelectorAll('.ssai-dis[data-high]').forEach(function (c) { c.checked = all.checked; }); upd(); });
				document.getElementById('ssai-dis-dl').addEventListener('click', function () {
					if (!out.value) return;
					var a = document.createElement('a');
					a.href = URL.createObjectURL(new Blob([out.value + '\n'], { type: 'text/plain' }));
					a.download = 'disavow.txt'; a.click();
				});
			})();
			</script>
		</section>

		<div class="ssai-cards">
			<?php if ( ! empty( $l['anchors'] ) ) : ?>
				<section class="ssai-card">
					<h2>متن لینک‌ها (Anchor text)</h2>
					<?php
					$tot = max( 1, array_sum( wp_list_pluck( $l['anchors'], 'count' ) ) );
					$kws = array();
					foreach ( get_posts( array( 'post_type' => SSAI_Admin::post_types(), 'posts_per_page' => 300, 'fields' => 'ids', 'post_status' => 'publish' ) ) as $pid ) {
						foreach ( SSAI_Content::get_focus_keywords( $pid ) as $k ) {
							$kws[] = SSAI_Content::norm( $k );
						}
					}
					$exact = 0;
					echo '<table class="widefat ssai-kwtable"><tbody>';
					foreach ( array_slice( $l['anchors'], 0, 15 ) as $an ) {
						$pct  = $an['count'] / $tot * 100;
						$isk  = '' !== $an['text'] && in_array( SSAI_Content::norm( $an['text'] ), $kws, true );
						$spam = (bool) preg_match( '/casino|bet|porn|sex|viagra|loan|crypto|[\x{4E00}-\x{9FFF}]|[\x{0400}-\x{04FF}]/u', $an['text'] );
						if ( $isk ) {
							$exact += $an['count'];
						}
						echo '<tr><td>' . esc_html( '' !== $an['text'] ? $an['text'] : '(بدون متن / تصویر)' ) . ( $isk ? ' <span class="ssai-chip">کلمه کلیدی</span>' : '' ) . ( $spam ? ' <span class="ssai-chip ssai-chip-main">مشکوک</span>' : '' ) . '</td><td style="width:45%"><span class="ssai-hbar" style="width:' . esc_attr( max( 1, round( $pct ) ) ) . '%"></span> ' . esc_html( number_format_i18n( $pct, 1 ) ) . '٪</td></tr>';
					}
					echo '</tbody></table>';
					$ep = $exact / $tot * 100;
					if ( $ep > 30 ) {
						echo '<p class="ssai-msg ssai-msg-error">' . esc_html( sprintf( '%s٪ لینک‌ها دقیقاً با کلمه کلیدی هستند؛ این الگو برای گوگل غیرطبیعی است (نشانه لینک‌سازی). لینک‌های طبیعی بیشتر با نام برند یا آدرس سایت‌اند.', number_format_i18n( $ep, 0 ) ) ) . '</p>';
					} else {
						echo '<p class="description">' . esc_html( sprintf( 'سهم متن‌های دقیقاً برابر کلمه کلیدی: %s٪ — تا حدود ۲۰-۳۰٪ طبیعی است.', number_format_i18n( $ep, 0 ) ) ) . '</p>';
					}
					?>
				</section>
			<?php endif; ?>
			<?php if ( ! empty( $l['pages'] ) ) : ?>
				<section class="ssai-card">
					<h2>صفحه‌هایی که بیشترین بک‌لینک را دارند</h2>
					<table class="widefat striped ssai-kwtable"><thead><tr><th>صفحه</th><th>لینک</th><th>دامنه</th></tr></thead><tbody>
					<?php
					foreach ( array_slice( $l['pages'], 0, 15 ) as $p ) {
						$pid = SSAI_GSC_Import::find_post( $p['url'] );
						echo '<tr><td>' . ( $pid ? '<a href="' . esc_url( SSAI_Report::url( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>' : '<span dir="ltr">' . esc_html( rawurldecode( $p['url'] ) ) . '</span>' ) . '</td><td>' . esc_html( number_format_i18n( $p['links'] ) ) . '</td><td>' . ( $p['sites'] ? esc_html( number_format_i18n( $p['sites'] ) ) : '—' ) . '</td></tr>';
					}
					?>
					</tbody></table>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
