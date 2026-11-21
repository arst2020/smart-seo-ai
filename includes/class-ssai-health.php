<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site health tools:
 *  - PageSpeed Insights (speed, Core Web Vitals, Lighthouse scores). Called from the admin's BROWSER
 *    (the web server may not be able to reach Google); the summary is then saved on the post.
 *  - MDN HTTP Observatory (security headers grade) – server side, cached one day.
 *  - IndexNow – notifies Bing, Yandex and other participating engines when a page changes.
 */
class SSAI_Health {

	const META_PSI   = '_ssai_psi';
	const OPT_OBS    = 'ssai_observatory';
	const OPT_INX    = 'ssai_indexnow_log';
	const OPT_QUEUE  = 'ssai_indexnow_queue';
	const CRON_INX   = 'ssai_indexnow_send';
	const OBS_API    = 'https://observatory-api.mdn.mozilla.net/api/v2/scan';
	const INX_API    = 'https://api.indexnow.org/indexnow';

	public static function init() {
		add_action( 'wp_ajax_ssai_save_psi', array( __CLASS__, 'ajax_save_psi' ) );
		add_action( 'wp_ajax_ssai_observatory', array( __CLASS__, 'ajax_observatory' ) );
		add_action( 'wp_ajax_ssai_indexnow_test', array( __CLASS__, 'ajax_indexnow_test' ) );
		add_action( 'init', array( __CLASS__, 'serve_key' ), 0 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( 'ssai_after_change', array( __CLASS__, 'queue_post' ) );
		add_action( self::CRON_INX, array( __CLASS__, 'send_queue' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_key_file' ) );
	}

	/**
	 * Besides serving /<key>.txt through WordPress, also write a real file in the site root when
	 * possible – some servers answer *.txt requests themselves and never reach WordPress.
	 */
	public static function ensure_key_file() {
		if ( ! self::indexnow_on() ) {
			return;
		}
		$key  = (string) SSAI_Settings::get( 'indexnow_key' );
		$file = trailingslashit( ABSPATH ) . $key . '.txt';
		if ( ! file_exists( $file ) && is_writable( ABSPATH ) ) {
			@file_put_contents( $file, $key ); // phpcs:ignore
		}
	}

	/* ------------------------------------------------------------ PageSpeed (results come from the browser) */

	private static function clean_metric( $m ) {
		return array(
			'value' => isset( $m['value'] ) ? (float) $m['value'] : null,
			'cat'   => isset( $m['cat'] ) ? sanitize_key( $m['cat'] ) : '',
			'text'  => isset( $m['text'] ) ? sanitize_text_field( (string) $m['text'] ) : '',
		);
	}

	public static function ajax_save_psi() {
		check_ajax_referer( 'ssai', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$strategy = isset( $_POST['strategy'] ) && 'desktop' === $_POST['strategy'] ? 'desktop' : 'mobile';
		$raw      = isset( $_POST['summary'] ) ? json_decode( wp_unslash( $_POST['summary'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised field by field below.
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'message' => 'داده نامعتبر.' ) );
		}
		$sum = array(
			'time'   => time(),
			'scores' => array(),
			'field'  => array(),
			'lab'    => array(),
			'opps'   => array(),
			'origin' => ! empty( $raw['origin'] ),
		);
		foreach ( array( 'performance', 'seo', 'accessibility', 'best-practices' ) as $c ) {
			if ( isset( $raw['scores'][ $c ] ) && null !== $raw['scores'][ $c ] ) {
				$sum['scores'][ $c ] = max( 0, min( 100, (int) $raw['scores'][ $c ] ) );
			}
		}
		foreach ( array( 'lcp', 'inp', 'cls', 'fcp', 'ttfb' ) as $k ) {
			if ( isset( $raw['field'][ $k ] ) && is_array( $raw['field'][ $k ] ) ) {
				$sum['field'][ $k ] = self::clean_metric( $raw['field'][ $k ] );
			}
		}
		foreach ( array( 'lcp', 'tbt', 'cls', 'fcp', 'si' ) as $k ) {
			if ( isset( $raw['lab'][ $k ] ) && is_array( $raw['lab'][ $k ] ) ) {
				$sum['lab'][ $k ] = self::clean_metric( $raw['lab'][ $k ] );
			}
		}
		if ( ! empty( $raw['opps'] ) && is_array( $raw['opps'] ) ) {
			foreach ( array_slice( $raw['opps'], 0, 8 ) as $o ) {
				if ( is_array( $o ) && ! empty( $o['title'] ) ) {
					$sum['opps'][] = array(
						'title' => sanitize_text_field( (string) $o['title'] ),
						'save'  => isset( $o['save'] ) ? sanitize_text_field( (string) $o['save'] ) : '',
					);
				}
			}
		}
		$all              = get_post_meta( $post_id, self::META_PSI, true );
		$all              = is_array( $all ) ? $all : array();
		$all[ $strategy ] = $sum;
		update_post_meta( $post_id, self::META_PSI, wp_slash( $all ) );
		wp_send_json_success( array( 'saved' => true ) );
	}

	public static function psi( $post_id ) {
		$p = get_post_meta( $post_id, self::META_PSI, true );
		return is_array( $p ) ? $p : array();
	}

	/* ------------------------------------------------------------ MDN HTTP Observatory */

	/** @return array|WP_Error { grade, score, passed, failed, details_url, time } */
	public static function observatory( $force = false ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$c    = get_option( self::OPT_OBS );
		if ( ! $force && is_array( $c ) && isset( $c['host'] ) && $c['host'] === $host && time() - $c['time'] < DAY_IN_SECONDS ) {
			return $c;
		}
		// Try the site's own host first, then the www / non-www variant (the scanner may reach only one).
		$alt   = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		$data  = null;
		$error = '';
		foreach ( array( $host, $alt ) as $h ) {
			$res = wp_remote_post( add_query_arg( 'host', rawurlencode( $h ), self::OBS_API ), array( 'timeout' => 45 ) );
			if ( is_wp_error( $res ) ) {
				return new WP_Error( 'ssai_obs', 'اتصال سرور سایت به Observatory ناموفق بود: ' . $res->get_error_message() );
			}
			$d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( ! is_array( $d ) ) {
				return new WP_Error( 'ssai_obs', 'پاسخ نامعتبر از Observatory (کد ' . wp_remote_retrieve_response_code( $res ) . ').' );
			}
			if ( empty( $d['error'] ) ) {
				$data = $d;
				$host = $h;
				break;
			}
			$error = ( is_string( $d['error'] ) ? $d['error'] : wp_json_encode( $d['error'] ) ) . ( ! empty( $d['message'] ) ? ' — ' . $d['message'] : '' );
		}
		if ( null === $data ) {
			if ( false !== stripos( $error, 'down' ) || false !== stripos( $error, 'scan-failed' ) ) {
				return new WP_Error(
					'ssai_obs',
					'Observatory نتوانست سایت را از سرورهای خودش (خارج از ایران) باز کند، پس نمره امنیت در دسترس نیست. علت‌های رایج: '
					. '۱) فایروال هاست یا CDN (مثل ابر آروان یا کلودفلر) ربات‌ها یا ترافیک خارجی را مسدود می‌کند؛ '
					. '۲) دامنه رکورد IPv6 (AAAA) دارد ولی سایت روی IPv6 جواب نمی‌دهد؛ '
					. '۳) گواهی SSL یا ریدایرکت http→https مشکل دارد. (' . $error . ')'
				);
			}
			return new WP_Error( 'ssai_obs', 'Observatory: ' . $error );
		}
		$out = array(
			'host'        => $host,
			'time'        => time(),
			'grade'       => isset( $data['grade'] ) ? sanitize_text_field( $data['grade'] ) : '',
			'score'       => isset( $data['score'] ) ? (int) $data['score'] : null,
			'passed'      => isset( $data['tests_passed'] ) ? (int) $data['tests_passed'] : null,
			'failed'      => isset( $data['tests_failed'] ) ? (int) $data['tests_failed'] : null,
			'details_url' => isset( $data['details_url'] ) ? esc_url_raw( $data['details_url'] ) : 'https://developer.mozilla.org/en-US/observatory/analyze?host=' . rawurlencode( $host ),
		);
		update_option( self::OPT_OBS, $out, false );
		return $out;
	}

	public static function ajax_observatory() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$r = self::observatory( ! empty( $_POST['force'] ) );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( $r );
	}

	/* ------------------------------------------------------------ IndexNow */

	public static function indexnow_on() {
		return 'on' === SSAI_Settings::get( 'indexnow' ) && '' !== (string) SSAI_Settings::get( 'indexnow_key' );
	}

	public static function key_url() {
		return home_url( '/' . SSAI_Settings::get( 'indexnow_key' ) . '.txt' );
	}

	/** Serve the IndexNow key file (/<key>.txt) without writing files to the server. */
	public static function serve_key() {
		$key = (string) SSAI_Settings::get( 'indexnow_key' );
		if ( '' === $key || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( untrailingslashit( $base ) . '/' . $key . '.txt' === $path ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $key );
			exit;
		}
	}

	public static function on_status( $new, $old, $post ) {
		if ( 'publish' === $new && in_array( $post->post_type, SSAI_Admin::post_types(), true ) ) {
			self::queue_post( $post->ID );
		}
	}

	public static function queue_post( $post_id ) {
		if ( ! self::indexnow_on() || 'publish' !== get_post_status( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$q   = (array) get_option( self::OPT_QUEUE, array() );
		$q[] = get_permalink( $post_id );
		update_option( self::OPT_QUEUE, array_values( array_unique( $q ) ), false );
		// Batch the changes of the next minute into one request.
		if ( ! wp_next_scheduled( self::CRON_INX ) ) {
			wp_schedule_single_event( time() + 60, self::CRON_INX );
		}
	}

	public static function send_queue() {
		$q = (array) get_option( self::OPT_QUEUE, array() );
		delete_option( self::OPT_QUEUE );
		if ( $q ) {
			self::submit( $q );
		}
	}

	/** @return true|WP_Error */
	public static function submit( $urls ) {
		$key  = (string) SSAI_Settings::get( 'indexnow_key' );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array_values( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) );
		if ( '' === $key || ! $urls ) {
			return new WP_Error( 'ssai_inx', 'کلید IndexNow یا آدرسی برای ارسال وجود ندارد.' );
		}
		$res  = wp_remote_post(
			self::INX_API,
			array(
				'timeout' => 20,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode(
					array(
						'host'        => $host,
						'key'         => $key,
						'keyLocation' => self::key_url(),
						'urlList'     => array_slice( $urls, 0, 1000 ),
					)
				),
			)
		);
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$msgs = array(
			200 => 'ارسال شد',
			202 => 'دریافت شد (کلید در حال تأیید است)',
			400 => 'درخواست نامعتبر',
			403 => 'کلید تأیید نشد (فایل کلید در دسترس نیست)',
			422 => 'آدرس‌ها متعلق به این دامنه نیستند',
			429 => 'ارسال بیش از حد؛ بعداً دوباره',
		);
		$ok   = in_array( $code, array( 200, 202 ), true );
		$text = is_wp_error( $res ) ? 'خطای اتصال: ' . $res->get_error_message() : ( isset( $msgs[ $code ] ) ? $msgs[ $code ] : 'کد ' . $code );
		$log  = (array) get_option( self::OPT_INX, array() );
		array_unshift(
			$log,
			array(
				'time'  => time(),
				'count' => count( $urls ),
				'first' => $urls[0],
				'ok'    => $ok,
				'text'  => $text,
			)
		);
		update_option( self::OPT_INX, array_slice( $log, 0, 10 ), false );
		return $ok ? true : new WP_Error( 'ssai_inx', 'IndexNow: ' . $text );
	}

	public static function ajax_indexnow_test() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'ssai_settings' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$r = self::submit( array( home_url( '/' ) ) );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'صفحه اصلی به IndexNow ارسال شد ✓' ) );
	}

	public static function log() {
		return (array) get_option( self::OPT_INX, array() );
	}
}
