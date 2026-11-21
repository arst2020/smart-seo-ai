<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instant indexing: send URLs to the Google Indexing API (service account) and to IndexNow
 * (Bing, Yandex, Seznam, Naver…). Manual page, row action and bulk action in the post lists,
 * optional automatic sending on publish, and a log.
 *
 * Note: Google officially supports the Indexing API only for JobPosting and BroadcastEvent
 * pages; for other pages it may be ignored. This is shown to the user in the settings.
 */
class SSAI_Indexing {

	const OPTION = 'ssai_indexing';
	const LOG    = 'ssai_indexing_log';
	const QUOTA  = 'ssai_indexing_quota';
	const SLUG   = 'smart-seo-ai-indexing';
	const SCOPE  = 'https://www.googleapis.com/auth/indexing';
	const API    = 'https://indexing.googleapis.com/v3/urlNotifications';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 45 );
		add_action( 'admin_post_ssai_index_submit', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_ssai_index_settings', array( __CLASS__, 'handle_settings' ) );
		add_action( 'admin_post_ssai_index_one', array( __CLASS__, 'handle_one' ) );
		add_action( 'admin_init', array( __CLASS__, 'list_hooks' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 20, 3 );
		add_action( 'ssai_index_auto', array( __CLASS__, 'auto_send' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function get( $key = null ) {
		$o = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'source'   => 'gsc',   // gsc = Search Console service account | own = separate JSON.
				'sa_json'  => '',
				'auto'     => 0,       // Send to Google on publish / update.
				'auto_pt'  => array( 'post', 'page', 'product' ),
			)
		);
		return null === $key ? $o : ( isset( $o[ $key ] ) ? $o[ $key ] : null );
	}

	/** Service account array or WP_Error. */
	private static function account() {
		$o = self::get();
		if ( 'own' === $o['source'] ) {
			$json = $o['sa_json'];
		} else {
			$json = 'service' === SSAI_GSC::get( 'mode' ) ? SSAI_GSC::get( 'sa_json' ) : '';
		}
		$sa = json_decode( (string) $json, true );
		if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
			return new WP_Error( 'ssai_idx_sa', 'حساب سرویس گوگل تنظیم نشده است (در «ارسال فوری ایندکس › تنظیمات» یا اتصال سرچ کنسول با حساب سرویس).' );
		}
		return $sa;
	}

	public static function google_ready() {
		return ! is_wp_error( self::account() );
	}

	private static function token() {
		$c = get_transient( 'ssai_idx_token' );
		if ( $c ) {
			return $c;
		}
		$sa = self::account();
		if ( is_wp_error( $sa ) ) {
			return $sa;
		}
		$jwt = SSAI_GSC::service_jwt( $sa, self::SCOPE );
		if ( is_wp_error( $jwt ) ) {
			return $jwt;
		}
		$tok = SSAI_GSC::decode(
			wp_remote_post(
				SSAI_GSC::TOKEN,
				array(
					'timeout' => 20,
					'body'    => array(
						'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
						'assertion'  => $jwt,
					),
				)
			)
		);
		if ( is_wp_error( $tok ) ) {
			return $tok;
		}
		if ( empty( $tok['access_token'] ) ) {
			return new WP_Error( 'ssai_idx_tok', 'گوگل توکن دسترسی نداد.' );
		}
		set_transient( 'ssai_idx_token', $tok['access_token'], max( 60, (int) ( isset( $tok['expires_in'] ) ? $tok['expires_in'] : 3600 ) - 120 ) );
		return $tok['access_token'];
	}

	private static function quota_used() {
		$q = get_option( self::QUOTA );
		return is_array( $q ) && isset( $q['day'] ) && gmdate( 'Y-m-d' ) === $q['day'] ? (int) $q['n'] : 0;
	}

	private static function quota_add( $n ) {
		update_option( self::QUOTA, array( 'day' => gmdate( 'Y-m-d' ), 'n' => self::quota_used() + $n ), false );
	}

	/**
	 * @param string $type URL_UPDATED | URL_DELETED | status
	 * @return array [ url => [ ok, text ] ]
	 */
	public static function google( $urls, $type = 'URL_UPDATED' ) {
		$out   = array();
		$token = self::token();
		foreach ( $urls as $u ) {
			if ( is_wp_error( $token ) ) {
				$out[ $u ] = array( false, $token->get_error_message() );
				continue;
			}
			$args = array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			);
			if ( 'status' === $type ) {
				$r = SSAI_GSC::decode( wp_remote_get( self::API . '/metadata?url=' . rawurlencode( $u ), $args ) );
				if ( is_wp_error( $r ) ) {
					$msg       = $r->get_error_message();
					$out[ $u ] = array( false, false !== strpos( $msg, '404' ) ? 'تا حالا از طریق Indexing API ارسال نشده است' : $msg );
				} else {
					$last      = isset( $r['latestUpdate']['notifyTime'] ) ? $r['latestUpdate']['notifyTime'] : '';
					$out[ $u ] = array( true, 'آخرین ارسال: ' . ( $last ? wp_date( 'Y/m/d H:i', strtotime( $last ) ) : '—' ) );
				}
				continue;
			}
			$args['body'] = wp_json_encode( array( 'url' => $u, 'type' => $type ) );
			$r            = SSAI_GSC::decode( wp_remote_post( self::API . ':publish', $args ) );
			if ( is_wp_error( $r ) ) {
				$msg = $r->get_error_message();
				if ( false !== strpos( $msg, '(403)' ) ) {
					$msg = 'گوگل اجازه نداد: ایمیل حساب سرویس باید در سرچ کنسول «Owner» (مالک) باشد و «Web Search Indexing API» در Google Cloud فعال باشد.';
				} elseif ( false !== strpos( $msg, '(429)' ) ) {
					$msg = 'سهمیه روزانه گوگل تمام شد (معمولاً ۲۰۰ آدرس در روز).';
				}
				$out[ $u ] = array( false, $msg );
			} else {
				self::quota_add( 1 );
				$out[ $u ] = array( true, 'URL_DELETED' === $type ? 'درخواست حذف ارسال شد' : 'ارسال شد' );
			}
		}
		return $out;
	}

	/** Send to the chosen engines and log. @return array of log rows added */
	public static function send( $urls, $engines, $type = 'URL_UPDATED', $by = 'manual' ) {
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) ) );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array_values(
			array_filter(
				$urls,
				function ( $u ) use ( $host ) {
					return (string) wp_parse_url( $u, PHP_URL_HOST ) === $host;
				}
			)
		);
		$rows = array();
		if ( ! $urls ) {
			return $rows;
		}
		if ( in_array( 'google', $engines, true ) ) {
			foreach ( self::google( array_slice( $urls, 0, 200 ), $type ) as $u => $r ) {
				$rows[] = array( time(), 'Google', $u, $r[0], $r[1], $by );
			}
		}
		if ( in_array( 'indexnow', $engines, true ) && 'status' !== $type ) {
			if ( ! SSAI_Health::indexnow_on() ) {
				$rows[] = array( time(), 'IndexNow', $urls[0] . ( count( $urls ) > 1 ? ' (+' . ( count( $urls ) - 1 ) . ')' : '' ), false, 'IndexNow در «هوش مصنوعی و سرویس‌ها» روشن نیست.', $by );
			} else {
				$r      = SSAI_Health::submit( $urls );
				$rows[] = array( time(), 'IndexNow', $urls[0] . ( count( $urls ) > 1 ? ' (+' . ( count( $urls ) - 1 ) . ')' : '' ), ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : 'ارسال شد (' . count( $urls ) . ' آدرس)', $by );
			}
		}
		$log = (array) get_option( self::LOG, array() );
		update_option( self::LOG, array_slice( array_merge( array_reverse( $rows ), $log ), 0, 200 ), false );
		return $rows;
	}

	/* ------------------------------------------------------------ Automatic on publish */

	public static function on_status( $new, $old, $post ) {
		if ( 'publish' !== $new || ! self::get( 'auto' ) || ! in_array( $post->post_type, (array) self::get( 'auto_pt' ), true ) || wp_is_post_revision( $post ) ) {
			return;
		}
		$q   = (array) get_option( 'ssai_index_queue', array() );
		$q[] = (int) $post->ID;
		update_option( 'ssai_index_queue', array_values( array_unique( $q ) ), false );
		if ( ! wp_next_scheduled( 'ssai_index_auto' ) ) {
			wp_schedule_single_event( time() + 60, 'ssai_index_auto' );
		}
	}

	public static function auto_send() {
		$ids = (array) get_option( 'ssai_index_queue', array() );
		delete_option( 'ssai_index_queue' );
		$urls = array();
		foreach ( $ids as $id ) {
			if ( 'publish' === get_post_status( $id ) && ! SSAI_SEO::post_robots( $id )['noindex'] ) {
				$urls[] = get_permalink( $id );
			}
		}
		if ( $urls && self::google_ready() ) {
			self::send( $urls, array( 'google' ), 'URL_UPDATED', 'auto' );
		}
	}

	/* ------------------------------------------------------------ Post lists */

	public static function list_hooks() {
		if ( ! current_user_can( 'ssai_index' ) ) {
			return;
		}
		foreach ( SSAI_Admin::post_types() as $pt ) {
			add_filter( 'bulk_actions-edit-' . $pt, array( __CLASS__, 'bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $pt, array( __CLASS__, 'bulk_handle' ), 10, 3 );
		}
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 20, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_action' ), 20, 2 );
	}

	private static function engines() {
		$e = array();
		if ( self::google_ready() ) {
			$e[] = 'google';
		}
		if ( SSAI_Health::indexnow_on() ) {
			$e[] = 'indexnow';
		}
		return $e;
	}

	public static function row_action( $actions, $post ) {
		if ( 'publish' === $post->post_status && in_array( $post->post_type, SSAI_Admin::post_types(), true ) ) {
			$url                  = wp_nonce_url( admin_url( 'admin-post.php?action=ssai_index_one&post=' . $post->ID ), 'ssai_index_one_' . $post->ID );
			$actions['ssai_index'] = '<a href="' . esc_url( $url ) . '" title="ارسال فوری به گوگل / IndexNow">ارسال برای ایندکس</a>';
		}
		return $actions;
	}

	public static function bulk_action( $a ) {
		$a['ssai_index'] = 'ارسال فوری برای ایندکس';
		return $a;
	}

	public static function bulk_handle( $redirect, $action, $ids ) {
		if ( 'ssai_index' !== $action ) {
			return $redirect;
		}
		$urls = array();
		foreach ( array_map( 'absint', (array) $ids ) as $id ) {
			if ( 'publish' === get_post_status( $id ) && current_user_can( 'edit_post', $id ) ) {
				$urls[] = get_permalink( $id );
			}
		}
		$rows = self::send( $urls, self::engines() );
		return add_query_arg( self::result_args( $rows ), $redirect );
	}

	public static function handle_one() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( 'ssai_index_one_' . $id );
		if ( ! current_user_can( 'ssai_index' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$rows = self::send( array( get_permalink( $id ) ), self::engines() );
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=' . get_post_type( $id ) );
		wp_safe_redirect( add_query_arg( self::result_args( $rows ), remove_query_arg( array( 'ssai_idx_ok', 'ssai_idx_err', 'ssai_idx_none' ), $back ) ) );
		exit;
	}

	private static function result_args( $rows ) {
		if ( ! $rows ) {
			return array( 'ssai_idx_none' => 1 );
		}
		$ok  = count( array_filter( wp_list_pluck( $rows, 3 ) ) );
		$err = count( $rows ) - $ok;
		return array( 'ssai_idx_ok' => $ok, 'ssai_idx_err' => $err );
	}

	public static function notice() {
		// phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_GET['ssai_idx_none'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>ارسال نشد: نه حساب سرویس گوگل تنظیم شده و نه IndexNow روشن است (یا صفحه منتشر نشده). <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">تنظیمات ارسال فوری</a></p></div>';
		} elseif ( isset( $_GET['ssai_idx_ok'] ) ) {
			$ok  = absint( $_GET['ssai_idx_ok'] );
			$err = isset( $_GET['ssai_idx_err'] ) ? absint( $_GET['ssai_idx_err'] ) : 0;
			echo '<div class="notice notice-' . ( $err ? 'warning' : 'success' ) . ' is-dismissible"><p>ارسال برای ایندکس: ' . esc_html( $ok ) . ' موفق' . ( $err ? '، ' . esc_html( $err ) . ' ناموفق' : '' ) . '. <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">جزئیات در گزارش</a></p></div>';
		}
		// phpcs:enable
	}

	/* ------------------------------------------------------------ Admin page */

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'ارسال فوری برای ایندکس', 'ارسال فوری ایندکس', 'ssai_index', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function handle_submit() {
		check_admin_referer( 'ssai_index_submit' );
		if ( ! current_user_can( 'ssai_index' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$raw     = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
		$type    = isset( $_POST['type'] ) && in_array( $_POST['type'], array( 'URL_UPDATED', 'URL_DELETED', 'status' ), true ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'URL_UPDATED';
		$type    = 'url_updated' === $type ? 'URL_UPDATED' : ( 'url_deleted' === $type ? 'URL_DELETED' : $type );
		$engines = isset( $_POST['engines'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['engines'] ) ) : array();
		$urls    = preg_split( '/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$rows    = self::send( $urls, $engines, $type );
		set_transient( 'ssai_idx_last_' . get_current_user_id(), $rows, 300 );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'done' => count( $rows ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_settings() {
		check_admin_referer( 'ssai_index_settings' );
		if ( ! current_user_can( 'ssai_settings' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$o           = self::get();
		$o['source'] = isset( $_POST['source'] ) && 'own' === $_POST['source'] ? 'own' : 'gsc';
		$o['auto']   = empty( $_POST['auto'] ) ? 0 : 1;
		$o['auto_pt'] = isset( $_POST['auto_pt'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['auto_pt'] ) ), SSAI_Admin::post_types() ) ) : array();
		if ( ! empty( $_FILES['sa_file']['tmp_name'] ) && is_uploaded_file( $_FILES['sa_file']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$json = (string) file_get_contents( $_FILES['sa_file']['tmp_name'] ); // phpcs:ignore
		} else {
			$json = isset( $_POST['sa_json'] ) ? trim( (string) wp_unslash( $_POST['sa_json'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- JSON validated below.
		}
		if ( '' !== $json ) {
			$sa = json_decode( $json, true );
			if ( is_array( $sa ) && ! empty( $sa['client_email'] ) && ! empty( $sa['private_key'] ) ) {
				$o['sa_json'] = wp_json_encode( $sa );
			}
		}
		if ( ! empty( $_POST['clear_json'] ) ) {
			$o['sa_json'] = '';
		}
		update_option( self::OPTION, $o, false );
		delete_transient( 'ssai_idx_token' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'ssai_index' ) ) {
			return;
		}
		$o    = self::get();
		$sa   = self::account();
		$log  = (array) get_option( self::LOG, array() );
		$last = get_transient( 'ssai_idx_last_' . get_current_user_id() );
		// phpcs:disable WordPress.Security.NonceVerification
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>ارسال فوری برای ایندکس</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>تنظیمات ذخیره شد.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['done'] ) && is_array( $last ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><strong>نتیجه:</strong></p><ul>
					<?php foreach ( $last as $r ) : ?>
						<li><?php echo $r[3] ? '✅' : '❌'; ?> <?php echo esc_html( $r[1] ); ?> — <code dir="ltr"><?php echo esc_html( urldecode( $r[2] ) ); ?></code> — <?php echo esc_html( $r[4] ); ?></li>
					<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<div class="ssai-card">
				<h2>وضعیت</h2>
				<p>Google Indexing API: <?php echo is_wp_error( $sa ) ? '<span style="color:#b32d2e">✗ ' . esc_html( $sa->get_error_message() ) . '</span>' : '<span style="color:#008a20">✓ آماده</span> — <code dir="ltr">' . esc_html( $sa['client_email'] ) . '</code> — امروز ' . esc_html( self::quota_used() ) . ' از حدود ۲۰۰ ارسال'; ?></p>
				<p>IndexNow (Bing، Yandex، Seznam، Naver): <?php echo SSAI_Health::indexnow_on() ? '<span style="color:#008a20">✓ روشن</span> (صفحه‌های منتشرشده خودکار ارسال می‌شوند)' : '<span style="color:#b32d2e">✗ خاموش</span> — در <a href="' . esc_url( admin_url( 'admin.php?page=smart-seo-ai-settings' ) ) . '">هوش مصنوعی و سرویس‌ها</a> روشن کنید.'; ?></p>
				<p class="description">ارسال سریع از فهرست نوشته‌ها و برگه‌ها هم ممکن است: لینک «ارسال برای ایندکس» زیر عنوان هر صفحه، یا انتخاب چند صفحه و «کارهای دسته‌جمعی › ارسال فوری برای ایندکس».</p>
			</div>

			<div class="ssai-card">
				<h2>ارسال آدرس‌ها</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ssai_index_submit">
					<?php wp_nonce_field( 'ssai_index_submit' ); ?>
					<textarea name="urls" class="large-text code" rows="6" dir="ltr" placeholder="<?php echo esc_attr( home_url( '/sample-page/' ) ); ?>&#10;…"></textarea>
					<p class="description">هر خط یک آدرس؛ فقط آدرس‌های همین دامنه ارسال می‌شوند. حداکثر ۲۰۰ آدرس برای گوگل در هر بار.</p>
					<p>
						<label><input type="radio" name="type" value="URL_UPDATED" checked> صفحه جدید / به‌روز شده</label>&nbsp;
						<label><input type="radio" name="type" value="URL_DELETED"> صفحه حذف شده</label>&nbsp;
						<label><input type="radio" name="type" value="status"> فقط وضعیت آخرین ارسال (گوگل)</label>
					</p>
					<p>
						<label><input type="checkbox" name="engines[]" value="google" <?php checked( ! is_wp_error( $sa ) ); ?>> Google</label>&nbsp;
						<label><input type="checkbox" name="engines[]" value="indexnow" <?php checked( SSAI_Health::indexnow_on() ); ?>> IndexNow</label>
					</p>
					<p><button class="button button-primary">ارسال</button></p>
				</form>
			</div>

			<?php if ( current_user_can( 'ssai_settings' ) ) : ?>
			<div class="ssai-card">
				<h2>تنظیمات Google Indexing API</h2>
				<div class="notice notice-warning inline"><p><strong>توجه:</strong> گوگل رسماً Indexing API را فقط برای صفحه‌های آگهی شغلی (JobPosting) و پخش زنده (BroadcastEvent) پشتیبانی می‌کند. برای صفحه‌های دیگر ممکن است درخواست را بپذیرد ولی در عمل اثری نداشته باشد. برای بقیه صفحه‌ها، نقشه سایت و «URL Inspection» سرچ کنسول راه رسمی است.</p></div>
				<ol class="description">
					<li>در Google Cloud یک پروژه و «Service account» بسازید و کلید JSON آن را دانلود کنید (اگر برای سرچ کنسول ساخته‌اید، همان کافی است).</li>
					<li>در همان پروژه «Web Search Indexing API» را فعال کنید.</li>
					<li>در سرچ کنسول › Settings › Users and permissions ایمیل حساب سرویس را با دسترسی <strong>Owner</strong> اضافه کنید.</li>
				</ol>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ssai_index_settings">
					<?php wp_nonce_field( 'ssai_index_settings' ); ?>
					<p>
						<label><input type="radio" name="source" value="gsc" <?php checked( 'gsc', $o['source'] ); ?>> همان حساب سرویس اتصال سرچ کنسول</label><br>
						<label><input type="radio" name="source" value="own" <?php checked( 'own', $o['source'] ); ?>> یک حساب سرویس جداگانه:</label>
					</p>
					<p><input type="file" name="sa_file" accept=".json,application/json"> یا JSON را بچسبانید:</p>
					<textarea name="sa_json" class="large-text code" rows="3" dir="ltr" placeholder="<?php echo '' !== $o['sa_json'] ? esc_attr( '(ذخیره شده — برای تغییر، فایل جدید بدهید)' ) : '{ "type": "service_account", … }'; ?>"></textarea>
					<?php if ( '' !== $o['sa_json'] ) : ?><label><input type="checkbox" name="clear_json" value="1"> حذف JSON جداگانه</label><?php endif; ?>
					<p><label><input type="checkbox" name="auto" value="1" <?php checked( $o['auto'] ); ?>> هر بار صفحه‌ای از این انواع منتشر یا به‌روز شد، خودکار به گوگل ارسال شود:</label><br>
						<?php
						foreach ( SSAI_Admin::post_types() as $pt ) {
							$obj = get_post_type_object( $pt );
							echo '<label style="margin-left:12px"><input type="checkbox" name="auto_pt[]" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, (array) $o['auto_pt'], true ), true, false ) . '> ' . esc_html( $obj ? $obj->labels->name : $pt ) . '</label>';
						}
						?>
					</p>
					<p><button class="button button-primary">ذخیره تنظیمات</button></p>
				</form>
			</div>
			<?php endif; ?>

			<div class="ssai-card">
				<h2>گزارش ارسال‌ها</h2>
				<?php if ( ! $log ) : ?>
					<p>هنوز چیزی ارسال نشده است.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead><tr><th>زمان</th><th>موتور</th><th>آدرس</th><th>نتیجه</th><th>نوع</th></tr></thead>
						<tbody>
						<?php foreach ( array_slice( $log, 0, 100 ) as $r ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( 'Y/m/d H:i', $r[0] ) ); ?></td>
								<td><?php echo esc_html( $r[1] ); ?></td>
								<td dir="ltr" style="text-align:left"><?php echo esc_html( urldecode( $r[2] ) ); ?></td>
								<td><?php echo $r[3] ? '✅ ' : '❌ '; ?><?php echo esc_html( $r[4] ); ?></td>
								<td><?php echo 'auto' === $r[5] ? 'خودکار' : 'دستی'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
		// phpcs:enable
	}
}
