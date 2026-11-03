<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Search Console connection (read-only).
 *
 * Two ways to connect:
 *  1. Service account (recommended): paste the JSON key; add the service-account e-mail as a user of the property.
 *  2. OAuth: your own Google Cloud OAuth client (Web application) + "Connect with Google".
 */
class SSAI_GSC {

	const OPTION = 'ssai_gsc';
	const SCOPE  = 'https://www.googleapis.com/auth/webmasters.readonly';
	const TOKEN  = 'https://oauth2.googleapis.com/token';
	const API    = 'https://www.googleapis.com/webmasters/v3';
	const INSPECT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

	public static function init() {
		add_action( 'admin_post_ssai_gsc_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ssai_gsc_oauth', array( __CLASS__, 'handle_oauth_callback' ) );
		add_action( 'admin_post_ssai_gsc_disconnect', array( __CLASS__, 'handle_disconnect' ) );
	}

	public static function get( $key = null ) {
		$o = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'mode'          => 'service', // service | oauth
				'sa_json'       => '',
				'client_id'     => '',
				'client_secret' => '',
				'refresh_token' => '',
				'site'          => '',
				'email'         => '',
			)
		);
		return null === $key ? $o : ( isset( $o[ $key ] ) ? $o[ $key ] : null );
	}

	private static function save( $o ) {
		update_option( self::OPTION, $o, false );
		delete_transient( 'ssai_gsc_token' );
	}

	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=ssai_gsc_oauth' );
	}

	public static function has_credentials() {
		$o = self::get();
		if ( 'service' === $o['mode'] ) {
			return '' !== $o['sa_json'];
		}
		return '' !== $o['refresh_token'];
	}

	public static function connected() {
		return self::has_credentials() && '' !== self::get( 'site' );
	}

	/* ------------------------------------------------------------ Auth */

	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/** Signed JWT for the service-account flow (RS256). */
	public static function service_jwt( $sa ) {
		$now     = time();
		$header  = self::b64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims  = self::b64url(
			wp_json_encode(
				array(
					'iss'   => $sa['client_email'],
					'scope' => self::SCOPE,
					'aud'   => self::TOKEN,
					'iat'   => $now,
					'exp'   => $now + 3600,
				)
			)
		);
		$key = openssl_pkey_get_private( $sa['private_key'] );
		if ( ! $key ) {
			return new WP_Error( 'ssai_gsc_key', 'کلید خصوصی فایل JSON حساب سرویس نامعتبر است.' );
		}
		$sig = '';
		if ( ! openssl_sign( $header . '.' . $claims, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'ssai_gsc_sign', 'امضای درخواست ناموفق بود (افزونه OpenSSL در PHP لازم است).' );
		}
		return $header . '.' . $claims . '.' . self::b64url( $sig );
	}

	/** @return string|WP_Error */
	public static function access_token() {
		$cached = get_transient( 'ssai_gsc_token' );
		if ( $cached ) {
			return $cached;
		}
		$o = self::get();
		if ( 'service' === $o['mode'] ) {
			$sa = json_decode( $o['sa_json'], true );
			if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
				return new WP_Error( 'ssai_gsc_json', 'فایل JSON حساب سرویس وارد نشده یا ناقص است.' );
			}
			$jwt = self::service_jwt( $sa );
			if ( is_wp_error( $jwt ) ) {
				return $jwt;
			}
			$body = array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			);
		} else {
			if ( '' === $o['refresh_token'] ) {
				return new WP_Error( 'ssai_gsc_noauth', 'هنوز به حساب گوگل وصل نشده‌اید.' );
			}
			$body = array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $o['refresh_token'],
				'client_id'     => $o['client_id'],
				'client_secret' => $o['client_secret'],
			);
		}
		$res = wp_remote_post( self::TOKEN, array( 'body' => $body, 'timeout' => 20 ) );
		$tok = self::decode( $res );
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		if ( empty( $tok['access_token'] ) ) {
			return new WP_Error( 'ssai_gsc_token', 'گوگل توکن دسترسی برنگرداند.' );
		}
		set_transient( 'ssai_gsc_token', $tok['access_token'], max( 60, (int) ( isset( $tok['expires_in'] ) ? $tok['expires_in'] : 3600 ) - 300 ) );
		return $tok['access_token'];
	}

	/** Decode a Google API response, turning errors into readable WP_Errors. */
	private static function decode( $res ) {
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'ssai_gsc_http', 'اتصال به گوگل ناموفق بود: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );
		if ( $code >= 200 && $code < 300 && is_array( $data ) ) {
			return $data;
		}
		if ( 403 === $code && ! is_array( $data ) && false !== stripos( $raw, '<html' ) ) {
			return new WP_Error( 'ssai_gsc_region', 'گوگل درخواست‌های آدرس IP سرور سایت را نمی‌پذیرد (احتمالاً سرور در منطقه‌ای است که سرویس‌های گوگل در آن ارائه نمی‌شوند).' );
		}
		$msg = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$msg = $data['error']['message'];
			} elseif ( isset( $data['error_description'] ) ) {
				$msg = $data['error_description'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$msg = $data['error'];
			}
		}
		if ( '' === $msg ) {
			$msg = mb_substr( wp_strip_all_tags( $raw ), 0, 200 );
		}
		if ( 403 === $code ) {
			$msg .= ' — مطمئن شوید ایمیل حساب سرویس (یا حساب گوگل شما) در Search Console به این ویژگی دسترسی دارد و «Google Search Console API» در پروژه Google Cloud فعال است.';
		}
		return new WP_Error( 'ssai_gsc_api', 'خطای گوگل (' . $code . '): ' . $msg );
	}

	private static function api( $method, $url, $body = null ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		return self::decode( wp_remote_request( $url, $args ) );
	}

	/* ------------------------------------------------------------ API calls */

	/** @return array|WP_Error list of { siteUrl, permissionLevel } */
	public static function sites() {
		$r = self::api( 'GET', self::API . '/sites' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return isset( $r['siteEntry'] ) ? $r['siteEntry'] : array();
	}

	/** Guess the property that matches this WordPress site. */
	public static function guess_site( $sites ) {
		$home = trailingslashit( home_url() );
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( $home, PHP_URL_HOST ) );
		foreach ( $sites as $s ) {
			if ( 'sc-domain:' . $host === $s['siteUrl'] ) {
				return $s['siteUrl'];
			}
		}
		foreach ( $sites as $s ) {
			if ( 0 === strpos( $home, $s['siteUrl'] ) || 0 === strpos( str_replace( '://', '://www.', $home ), $s['siteUrl'] ) ) {
				return $s['siteUrl'];
			}
		}
		return '';
	}

	private static function query( $body ) {
		$site = self::get( 'site' );
		if ( '' === $site ) {
			return new WP_Error( 'ssai_gsc_site', 'ویژگی (Property) سرچ کنسول انتخاب نشده است.' );
		}
		return self::api( 'POST', self::API . '/sites/' . rawurlencode( $site ) . '/searchAnalytics/query', $body );
	}

	/** Site-wide search queries of the last 90 days (cached 12 hours), for content ideas. */
	public static function site_queries() {
		$c = get_transient( 'ssai_gsc_site_queries' );
		if ( is_array( $c ) ) {
			return $c;
		}
		list( $s, $e ) = self::range( 90 );
		$r             = self::query(
			array(
				'startDate'  => $s,
				'endDate'    => $e,
				'dimensions' => array( 'query' ),
				'rowLimit'   => 1000,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$out = array();
		foreach ( isset( $r['rows'] ) ? $r['rows'] : array() as $row ) {
			$out[] = array(
				'query'       => $row['keys'][0],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => (float) $row['position'],
			);
		}
		set_transient( 'ssai_gsc_site_queries', $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	private static function page_filter( $url ) {
		return array(
			array(
				'filters' => array(
					array(
						'dimension'  => 'page',
						'operator'   => 'equals',
						'expression' => $url,
					),
				),
			),
		);
	}

	/** Search Console data is ~2-3 days behind; use full days ending 3 days ago. */
	private static function range( $days, $offset = 0 ) {
		$end   = time() - ( 3 + $offset ) * DAY_IN_SECONDS;
		$start = $end - ( $days - 1 ) * DAY_IN_SECONDS;
		return array( gmdate( 'Y-m-d', $start ), gmdate( 'Y-m-d', $end ) );
	}

	private static function totals( $rows ) {
		$c = 0;
		$i = 0;
		$p = 0;
		foreach ( $rows as $r ) {
			$c += $r['clicks'];
			$i += $r['impressions'];
			$p += $r['position'] * $r['impressions']; // Impression-weighted, like Search Console.
		}
		return array(
			'clicks'      => $c,
			'impressions' => $i,
			'ctr'         => $i ? $c / $i : 0,
			'position'    => $i ? $p / $i : 0,
		);
	}

	/** Cached report only (never calls Google) – for cheap checks. */
	public static function cached_page_report( $url ) {
		$c = get_transient( 'ssai_gsc_rep_' . md5( self::get( 'site' ) . $url ) );
		return is_array( $c ) ? $c : null;
	}

	/**
	 * Full report for one URL (cached 12 h).
	 *
	 * @return array|WP_Error
	 */
	public static function page_report( $url, $force = false ) {
		$key = 'ssai_gsc_rep_' . md5( self::get( 'site' ) . $url );
		if ( ! $force ) {
			$c = get_transient( $key );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		list( $s, $e )   = self::range( 28 );
		list( $ps, $pe ) = self::range( 28, 28 );
		$filter          = self::page_filter( $url );

		$daily = self::query(
			array(
				'startDate'             => $s,
				'endDate'               => $e,
				'dimensions'            => array( 'date' ),
				'dimensionFilterGroups' => $filter,
			)
		);
		if ( is_wp_error( $daily ) ) {
			return $daily;
		}
		$prev = self::query(
			array(
				'startDate'             => $ps,
				'endDate'               => $pe,
				'dimensions'            => array( 'date' ),
				'dimensionFilterGroups' => $filter,
			)
		);
		$queries = self::query(
			array(
				'startDate'             => $s,
				'endDate'               => $e,
				'dimensions'            => array( 'query' ),
				'dimensionFilterGroups' => $filter,
				'rowLimit'              => 100,
			)
		);
		$prev_q = self::query(
			array(
				'startDate'             => $ps,
				'endDate'               => $pe,
				'dimensions'            => array( 'query' ),
				'dimensionFilterGroups' => $filter,
				'rowLimit'              => 250,
			)
		);
		$devices = self::query(
			array(
				'startDate'             => $s,
				'endDate'               => $e,
				'dimensions'            => array( 'device' ),
				'dimensionFilterGroups' => $filter,
			)
		);

		$days = array();
		foreach ( isset( $daily['rows'] ) ? $daily['rows'] : array() as $r ) {
			$days[] = array(
				'date'        => $r['keys'][0],
				'clicks'      => (int) $r['clicks'],
				'impressions' => (int) $r['impressions'],
				'ctr'         => (float) $r['ctr'],
				'position'    => (float) $r['position'],
			);
		}
		$prev_rows = ( ! is_wp_error( $prev ) && isset( $prev['rows'] ) ) ? $prev['rows'] : array();

		$prev_pos = array();
		if ( ! is_wp_error( $prev_q ) && isset( $prev_q['rows'] ) ) {
			foreach ( $prev_q['rows'] as $r ) {
				$prev_pos[ $r['keys'][0] ] = (float) $r['position'];
			}
		}
		$q = array();
		if ( ! is_wp_error( $queries ) && isset( $queries['rows'] ) ) {
			foreach ( $queries['rows'] as $r ) {
				$q[] = array(
					'query'       => $r['keys'][0],
					'clicks'      => (int) $r['clicks'],
					'impressions' => (int) $r['impressions'],
					'ctr'         => (float) $r['ctr'],
					'position'    => (float) $r['position'],
					'prev'        => isset( $prev_pos[ $r['keys'][0] ] ) ? $prev_pos[ $r['keys'][0] ] : null,
				);
			}
		}
		$dev = array();
		if ( ! is_wp_error( $devices ) && isset( $devices['rows'] ) ) {
			foreach ( $devices['rows'] as $r ) {
				$dev[ strtolower( $r['keys'][0] ) ] = array(
					'clicks'   => (int) $r['clicks'],
					'position' => (float) $r['position'],
				);
			}
		}

		$out = array(
			'time'    => time(),
			'range'   => array( $s, $e ),
			'totals'  => self::totals( $days ),
			'prev'    => self::totals( $prev_rows ),
			'days'    => $days,
			'queries' => $q,
			'devices' => $dev,
		);
		set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		return $out;
	}

	/**
	 * URL Inspection (index status). Cached 24 h — Google allows ~2000 inspections per day per property.
	 *
	 * @return array|WP_Error
	 */
	public static function inspect( $url, $force = false ) {
		$key = 'ssai_gsc_insp_' . md5( self::get( 'site' ) . $url );
		if ( ! $force ) {
			$c = get_transient( $key );
			if ( is_array( $c ) ) {
				return $c;
			}
		}
		$r = self::api(
			'POST',
			self::INSPECT,
			array(
				'inspectionUrl' => $url,
				'siteUrl'       => self::get( 'site' ),
				'languageCode'  => 'fa-IR',
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$ir  = isset( $r['inspectionResult'] ) ? $r['inspectionResult'] : array();
		$idx = isset( $ir['indexStatusResult'] ) ? $ir['indexStatusResult'] : array();
		$out = array(
			'time'          => time(),
			'verdict'       => isset( $idx['verdict'] ) ? $idx['verdict'] : '',
			'coverage'      => isset( $idx['coverageState'] ) ? $idx['coverageState'] : '',
			'robots'        => isset( $idx['robotsTxtState'] ) ? $idx['robotsTxtState'] : '',
			'indexing'      => isset( $idx['indexingState'] ) ? $idx['indexingState'] : '',
			'fetch'         => isset( $idx['pageFetchState'] ) ? $idx['pageFetchState'] : '',
			'last_crawl'    => isset( $idx['lastCrawlTime'] ) ? $idx['lastCrawlTime'] : '',
			'crawled_as'    => isset( $idx['crawledAs'] ) ? $idx['crawledAs'] : '',
			'google_canon'  => isset( $idx['googleCanonical'] ) ? $idx['googleCanonical'] : '',
			'user_canon'    => isset( $idx['userCanonical'] ) ? $idx['userCanonical'] : '',
			'sitemaps'      => isset( $idx['sitemap'] ) ? (array) $idx['sitemap'] : array(),
			'rich'          => array(),
			'link'          => isset( $ir['inspectionResultLink'] ) ? $ir['inspectionResultLink'] : '',
		);
		if ( ! empty( $ir['richResultsResult']['detectedItems'] ) ) {
			foreach ( $ir['richResultsResult']['detectedItems'] as $it ) {
				$issues = 0;
				foreach ( isset( $it['items'] ) ? $it['items'] : array() as $sub ) {
					$issues += isset( $sub['issues'] ) ? count( $sub['issues'] ) : 0;
				}
				$out['rich'][] = array(
					'type'   => isset( $it['richResultType'] ) ? $it['richResultType'] : '',
					'issues' => $issues,
				);
			}
		}
		set_transient( $key, $out, DAY_IN_SECONDS );
		return $out;
	}

	/** Clicks/impressions/position for all pages (for the site overview table). Cached 12 h. */
	public static function all_pages() {
		if ( ! self::connected() ) {
			return array();
		}
		$key = 'ssai_gsc_pages_' . md5( self::get( 'site' ) );
		$c   = get_transient( $key );
		if ( is_array( $c ) ) {
			return $c;
		}
		list( $s, $e ) = self::range( 28 );
		$r             = self::query(
			array(
				'startDate'  => $s,
				'endDate'    => $e,
				'dimensions' => array( 'page' ),
				'rowLimit'   => 1000,
			)
		);
		$out = array();
		if ( ! is_wp_error( $r ) && isset( $r['rows'] ) ) {
			foreach ( $r['rows'] as $row ) {
				$out[ SSAI_GSC_Import::url_key( $row['keys'][0] ) ] = array(
					'clicks'      => (int) $row['clicks'],
					'impressions' => (int) $row['impressions'],
					'position'    => (float) $row['position'],
				);
			}
		}
		set_transient( $key, $out, is_wp_error( $r ) ? 10 * MINUTE_IN_SECONDS : 12 * HOUR_IN_SECONDS );
		return $out;
	}

	public static function console_links( $url ) {
		$site = self::get( 'site' );
		if ( '' === $site ) {
			$site = 'sc-domain:' . preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		$site = rawurlencode( $site );
		return array(
			'performance' => 'https://search.google.com/search-console/performance/search-analytics?resource_id=' . $site . '&page=' . rawurlencode( '!' . $url ),
			// Google no longer accepts the page address in "id" (it is now an internal token), so the property
			// is opened and the address is copied to the clipboard for its "Inspect any URL" box.
			'inspect'     => 'https://search.google.com/search-console?resource_id=' . $site,
			'url'         => $url,
		);
	}

	/* ------------------------------------------------------------ Settings handlers */

	private static function back( $msg, $type = 'success' ) {
		set_transient(
			'ssai_gsc_notice_' . get_current_user_id(),
			array(
				'msg'  => $msg,
				'type' => $type,
			),
			60
		);
		wp_safe_redirect( admin_url( 'admin.php?page=smart-seo-ai-gsc' ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'ssai_gsc_save' );
		$o    = self::get();
		$mode = isset( $_POST['mode'] ) && 'oauth' === $_POST['mode'] ? 'oauth' : 'service';
		if ( $mode !== $o['mode'] ) {
			$o['site'] = '';
		}
		$o['mode'] = $mode;

		if ( 'service' === $mode ) {
			$json = isset( $_POST['sa_json'] ) ? trim( wp_unslash( $_POST['sa_json'] ) ) : ''; // Validated below.
			if ( ! empty( $_FILES['sa_file']['tmp_name'] ) && is_uploaded_file( $_FILES['sa_file']['tmp_name'] ) ) {
				$json = trim( (string) file_get_contents( $_FILES['sa_file']['tmp_name'] ) ); // phpcs:ignore
			}
			if ( '' !== $json ) {
				$sa = json_decode( $json, true );
				if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) || ( isset( $sa['type'] ) && 'service_account' !== $sa['type'] ) ) {
					self::back( 'فایل JSON معتبر نیست؛ باید کلید «Service account» با client_email و private_key باشد.', 'error' );
				}
				$o['sa_json'] = wp_json_encode(
					array(
						'type'         => 'service_account',
						'client_email' => sanitize_email( $sa['client_email'] ),
						'private_key'  => $sa['private_key'],
					)
				);
				$o['email']   = sanitize_email( $sa['client_email'] );
			}
		} else {
			$o['client_id']     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : $o['client_id'];
			$secret             = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
			$o['client_secret'] = '' !== $secret ? $secret : $o['client_secret'];
		}
		if ( isset( $_POST['site'] ) ) {
			$o['site'] = sanitize_text_field( wp_unslash( $_POST['site'] ) );
		}
		self::save( $o );

		if ( 'oauth' === $mode && ! empty( $_POST['connect'] ) ) {
			if ( '' === $o['client_id'] || '' === $o['client_secret'] ) {
				self::back( 'ابتدا Client ID و Client Secret را وارد کنید.', 'error' );
			}
			$state = wp_generate_password( 24, false );
			set_transient( 'ssai_gsc_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
			$url = add_query_arg(
				array(
					'client_id'     => rawurlencode( $o['client_id'] ),
					'redirect_uri'  => rawurlencode( self::redirect_uri() ),
					'response_type' => 'code',
					'scope'         => rawurlencode( self::SCOPE ),
					'access_type'   => 'offline',
					'prompt'        => 'consent',
					'state'         => $state,
				),
				'https://accounts.google.com/o/oauth2/v2/auth'
			);
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Google's consent screen.
			exit;
		}

		// Pick the matching property automatically when possible.
		if ( self::has_credentials() && '' === $o['site'] ) {
			$sites = self::sites();
			if ( is_wp_error( $sites ) ) {
				self::back( 'تنظیمات ذخیره شد، اما اتصال به سرچ کنسول ناموفق بود: ' . $sites->get_error_message(), 'error' );
			}
			$o['site'] = self::guess_site( $sites );
			self::save( $o );
			if ( '' === $o['site'] ) {
				self::back( 'اتصال برقرار شد. ویژگی (Property) سایت را از فهرست انتخاب و ذخیره کنید.', 'warning' );
			}
		}
		self::back( 'تنظیمات سرچ کنسول ذخیره شد.' );
	}

	public static function handle_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$saved = get_transient( 'ssai_gsc_state_' . get_current_user_id() );
		delete_transient( 'ssai_gsc_state_' . get_current_user_id() );
		if ( ! $saved || ! hash_equals( $saved, $state ) ) {
			self::back( 'درخواست اتصال منقضی یا نامعتبر بود؛ دوباره تلاش کنید.', 'error' );
		}
		if ( ! empty( $_GET['error'] ) ) {
			self::back( 'گوگل اجازه دسترسی نداد: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ), 'error' );
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$o    = self::get();
		$res  = wp_remote_post(
			self::TOKEN,
			array(
				'timeout' => 20,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $o['client_id'],
					'client_secret' => $o['client_secret'],
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		$tok = self::decode( $res );
		if ( is_wp_error( $tok ) ) {
			self::back( $tok->get_error_message(), 'error' );
		}
		if ( empty( $tok['refresh_token'] ) ) {
			self::back( 'گوگل refresh token برنگرداند. در حساب گوگل دسترسی این برنامه را حذف و دوباره وصل شوید.', 'error' );
		}
		$o['refresh_token'] = $tok['refresh_token'];
		$o['site']          = '';
		self::save( $o );
		set_transient( 'ssai_gsc_token', $tok['access_token'], 3000 );
		$sites = self::sites();
		if ( ! is_wp_error( $sites ) ) {
			$o['site'] = self::guess_site( $sites );
			self::save( $o );
		}
		self::back( '' !== $o['site'] ? 'به سرچ کنسول وصل شدید.' : 'وصل شدید. ویژگی سایت را از فهرست انتخاب کنید.', '' !== $o['site'] ? 'success' : 'warning' );
	}

	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		check_admin_referer( 'ssai_gsc_disconnect' );
		$o = self::get();
		if ( 'oauth' === $o['mode'] && '' !== $o['refresh_token'] ) {
			wp_remote_post( 'https://oauth2.googleapis.com/revoke', array( 'body' => array( 'token' => $o['refresh_token'] ), 'timeout' => 10 ) );
		}
		$o['sa_json']       = '';
		$o['refresh_token'] = '';
		$o['site']          = '';
		$o['email']         = '';
		self::save( $o );
		self::back( 'اتصال سرچ کنسول حذف شد.' );
	}
}
