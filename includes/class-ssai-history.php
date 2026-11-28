<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO history: one row per day with the site's SEO state (average score, Google clicks /
 * impressions / position, keywords in the top 3 / top 10, AEO, speed, 404s, backlinks…), so
 * progress and drops are visible over months. Filled by a daily snapshot and back-filled from
 * Search Console (API or imported export), the rank tracker and the Ahrefs import.
 * Each page also keeps its own score history.
 */
class SSAI_History {

	const SLUG      = 'smart-seo-ai-history';
	const CRON      = 'ssai_history_daily';
	const DBV       = '1';
	const PAGE_META = '_ssai_score_hist';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ssai_history';
	}

	/** metric => [ label, unit/format, lower is better, chart color ] */
	public static function metrics() {
		return array(
			'score'    => array( 'میانگین امتیاز سئو', 'int', false, '#6366f1' ),
			'bad'      => array( 'صفحه‌های ضعیف (امتیاز زیر ۵۰)', 'int', true, '#dc2626' ),
			'clicks'   => array( 'کلیک روزانه از گوگل', 'int', false, '#4f46e5' ),
			'impr'     => array( 'نمایش روزانه در گوگل', 'int', false, '#a78bfa' ),
			'pos'      => array( 'میانگین جایگاه در گوگل', 'dec', true, '#0284c7' ),
			'kw_top3'  => array( 'کلمه در سه رتبه اول', 'int', false, '#16a34a' ),
			'kw_top10' => array( 'کلمه در صفحه اول گوگل', 'int', false, '#22c55e' ),
			'kw_pos'   => array( 'میانگین جایگاه کلمه‌های ردیابی‌شده', 'dec', true, '#0ea5e9' ),
			'aeo'      => array( 'امتیاز AEO', 'int', false, '#7c3aed' ),
			'psi'      => array( 'سرعت موبایل صفحه اصلی', 'int', false, '#d97706' ),
			'e404'     => array( 'آدرس‌های ۴۰۴ (۷ روز)', 'int', true, '#b45309' ),
			'rd'       => array( 'دامنه‌های لینک‌دهنده (Ahrefs)', 'int', false, '#64748b' ),
			'opr'      => array( 'Open PageRank', 'dec', false, '#475569' ),
			'changes'  => array( 'تغییرات اعمال‌شده با افزونه', 'int', false, '#94a3b8' ),
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'install' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 47 );
		add_action( 'admin_post_ssai_history', array( __CLASS__, 'handle' ) );
		add_action( 'updated_post_meta', array( __CLASS__, 'page_score' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'page_score' ), 10, 4 );
	}

	public static function install() {
		if ( self::DBV === get_option( 'ssai_history_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE $t (\n\t\t\tday date NOT NULL,\n\t\t\tdata longtext NOT NULL,\n\t\t\tPRIMARY KEY  (day)\n\t\t) " . $wpdb->get_charset_collate() . ';' );
		update_option( 'ssai_history_db', self::DBV, true );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 600, 'daily', self::CRON );
		}
	}

	public static function cron() {
		self::install();
		self::snapshot();
		if ( SSAI_GSC::connected() ) {
			self::backfill_gsc_api( 10 ); // Search Console data arrives 2–3 days late; refresh the last days.
		}
	}

	/* ------------------------------------------------------------ Storage */

	/** Merge metric values into a day (existing keys not given stay). */
	public static function put( $day, $vals ) {
		global $wpdb;
		$vals = array_filter(
			$vals,
			function ( $v ) {
				return null !== $v && '' !== $v;
			}
		);
		if ( ! $vals ) {
			return;
		}
		$t   = self::table();
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM $t WHERE day = %s", $day ) ); // phpcs:ignore WordPress.DB
		$cur = $old ? (array) json_decode( $old, true ) : array();
		$wpdb->replace( $t, array( 'day' => $day, 'data' => wp_json_encode( array_merge( $cur, $vals ) ) ), array( '%s', '%s' ) ); // phpcs:ignore WordPress.DB
	}

	/** day => metrics, ascending. */
	public static function rows( $days ) {
		global $wpdb;
		$t    = self::table();
		$from = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$out  = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT day, data FROM $t WHERE day >= %s ORDER BY day ASC", $from ) ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r->day ] = (array) json_decode( $r->data, true );
		}
		return $out;
	}

	/* ------------------------------------------------------------ Snapshot (today) */

	public static function snapshot() {
		global $wpdb;
		$v     = array();
		$types = SSAI_Admin::post_types();
		if ( $types ) {
			$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT AVG(CAST(m.meta_value AS DECIMAL(5,1))) AS a, COUNT(*) AS n, SUM(CAST(m.meta_value AS DECIMAL(5,1)) < 50) AS bad FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ($in)", array_merge( array( SSAI_Analyzer::META_SCORE ), $types ) ) ); // phpcs:ignore WordPress.DB
			if ( $row && $row->n ) {
				$v['score'] = (int) round( $row->a );
				$v['bad']   = (int) $row->bad;
			}
		}
		$aeo = SSAI_AEO::cached();
		if ( $aeo && ! empty( $aeo['pages'] ) ) {
			$v['aeo'] = (int) round( array_sum( wp_list_pluck( $aeo['pages'], 'score' ) ) / count( $aeo['pages'] ) );
		}
		$front = (int) get_option( 'page_on_front' );
		$psi   = $front ? SSAI_Health::psi( $front ) : array();
		if ( isset( $psi['mobile']['scores']['performance'] ) ) {
			$v['psi'] = (int) $psi['mobile']['scores']['performance'];
		}
		if ( SSAI_SEO::on( 'monitor404' ) ) {
			$st        = SSAI_Redirects::log_stats( 7 );
			$v['e404'] = (int) $st['urls'];
		}
		$auth = SSAI_Site::authority();
		if ( isset( $auth['opr'] ) && null !== $auth['opr'] ) {
			$v['opr'] = round( (float) $auth['opr'], 2 );
		}
		$v['changes'] = self::changes_on( gmdate( 'Y-m-d' ) );
		self::put( gmdate( 'Y-m-d' ), $v );
		self::from_rank( 3 );
		self::from_ahrefs();
		self::from_import();
		update_option( 'ssai_history_last', time(), false );
	}

	/** Number of changes applied with the plugin on a day. */
	private static function changes_on( $day ) {
		global $wpdb;
		$a = strtotime( $day . ' 00:00:00 UTC' );
		$b = $a + DAY_IN_SECONDS;
		$n = 0;
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", SSAI_Applier::META_HISTORY ) ) as $h ) { // phpcs:ignore WordPress.DB
			foreach ( (array) maybe_unserialize( $h ) as $e ) {
				if ( is_array( $e ) && isset( $e['time'] ) && $e['time'] >= $a && $e['time'] < $b ) {
					++$n;
				}
			}
		}
		return $n;
	}

	/* ------------------------------------------------------------ Back-fill */

	/** Daily clicks / impressions / position from the Search Console API. */
	public static function backfill_gsc_api( $days ) {
		$r = SSAI_GSC::query(
			array(
				'startDate'  => gmdate( 'Y-m-d', time() - ( $days + 2 ) * DAY_IN_SECONDS ),
				'endDate'    => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
				'dimensions' => array( 'date' ),
				'rowLimit'   => 1000,
			)
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$n = 0;
		foreach ( isset( $r['rows'] ) ? $r['rows'] : array() as $x ) {
			self::put( $x['keys'][0], array( 'clicks' => (int) $x['clicks'], 'impr' => (int) $x['impressions'], 'pos' => round( (float) $x['position'], 1 ) ) );
			++$n;
		}
		return $n;
	}

	/** Daily rows of the imported Search Console export. */
	public static function from_import() {
		$g = SSAI_GSC_Import::site();
		if ( ! $g || empty( $g['dates'] ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $g['dates'] as $d ) {
			if ( empty( $d['key'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d['key'] ) ) {
				continue;
			}
			self::put(
				$d['key'],
				array(
					'clicks' => isset( $d['clicks'] ) ? (int) $d['clicks'] : null,
					'impr'   => isset( $d['impressions'] ) ? (int) $d['impressions'] : null,
					'pos'    => isset( $d['position'] ) && $d['position'] ? round( (float) $d['position'], 1 ) : null,
				)
			);
			++$n;
		}
		return $n;
	}

	/** Keyword counts per day from the rank tracker table. */
	public static function from_rank( $days ) {
		global $wpdb;
		if ( ! class_exists( 'SSAI_Rank' ) || SSAI_Rank::DBV !== get_option( 'ssai_rank_db' ) ) {
			return 0;
		}
		$t    = SSAI_Rank::table();
		$from = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(pos <= 3.5) AS t3, SUM(pos <= 10.5) AS t10, AVG(pos) AS ap FROM $t WHERE day >= %s GROUP BY day", $from ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $rows as $r ) {
			self::put( $r->day, array( 'kw_top3' => (int) $r->t3, 'kw_top10' => (int) $r->t10, 'kw_pos' => round( (float) $r->ap, 1 ) ) );
		}
		return count( (array) $rows );
	}

	/** Referring-domain history of the Ahrefs import. */
	public static function from_ahrefs() {
		$d = SSAI_Ahrefs::data();
		if ( ! $d || empty( $d['history'] ) ) {
			return 0;
		}
		$n = 0;
		foreach ( $d['history'] as $day => $val ) {
			$k = SSAI_GSC_Import::norm_date( $day );
			if ( '' !== $k && isset( $val[0] ) ) {
				self::put( $k, array( 'rd' => (int) $val[0] ) );
				++$n;
			}
		}
		return $n;
	}

	/* ------------------------------------------------------------ Per-page score history */

	public static function page_score( $meta_id, $post_id, $key, $value ) {
		if ( SSAI_Analyzer::META_SCORE !== $key ) {
			return;
		}
		$h = get_post_meta( $post_id, self::PAGE_META, true );
		$h = is_array( $h ) ? $h : array();
		$h[ gmdate( 'Y-m-d' ) ] = (int) $value;
		ksort( $h );
		update_post_meta( $post_id, self::PAGE_META, array_slice( $h, -180, null, true ) );
	}

	/** Small "score over time" line for the page report. */
	public static function page_box( $post_id ) {
		$h = get_post_meta( $post_id, self::PAGE_META, true );
		if ( ! is_array( $h ) || count( $h ) < 2 ) {
			return '';
		}
		$pts = array();
		foreach ( $h as $d => $s ) {
			$pts[] = array( $d, $s );
		}
		$first = reset( $h );
		$last  = end( $h );
		$diff  = $last - $first;
		return '<section class="ssai-card"><h2>تاریخچه امتیاز این صفحه</h2><p class="description">از ' . esc_html( array_key_first( $h ) ) . ': ' . esc_html( $first ) . ' ← امروز: ' . esc_html( $last ) . ' (' . ( $diff >= 0 ? '▲ ' : '▼ ' ) . esc_html( abs( $diff ) ) . ')</p>' . SSAI_Report::chart( $pts, 'امتیاز سئو', '#6366f1' ) . '</section>';
	}

	/* ------------------------------------------------------------ Admin page */

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'تاریخچه وضعیت سئو و رتبه‌ها', 'تاریخچه سئو', 'ssai_site', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function handle() {
		check_admin_referer( 'ssai_history' );
		if ( ! current_user_can( 'ssai_site' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		self::install();
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$msg = array();
		self::snapshot();
		$msg[] = 'وضعیت امروز ثبت شد';
		if ( SSAI_GSC::connected() ) {
			$n     = self::backfill_gsc_api( 480 );
			$msg[] = is_wp_error( $n ) ? 'سرچ کنسول: ' . $n->get_error_message() : 'سرچ کنسول: ' . number_format_i18n( $n ) . ' روز';
		}
		$n = self::from_import();
		if ( $n ) {
			$msg[] = 'فایل سرچ کنسول: ' . number_format_i18n( $n ) . ' روز';
		}
		$n = self::from_rank( 480 );
		if ( $n ) {
			$msg[] = 'رتبه کلمات: ' . number_format_i18n( $n ) . ' روز';
		}
		$n = self::from_ahrefs();
		if ( $n ) {
			$msg[] = 'Ahrefs: ' . number_format_i18n( $n ) . ' نقطه';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'msg' => rawurlencode( implode( ' — ', $msg ) ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function fmt( $v, $type ) {
		if ( null === $v ) {
			return '—';
		}
		return 'dec' === $type ? number_format_i18n( $v, 1 ) : number_format_i18n( $v );
	}

	public static function page() {
		if ( ! current_user_can( 'ssai_site' ) ) {
			return;
		}
		self::install();
		// phpcs:disable WordPress.Security.NonceVerification
		$range = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 90;
		$range = in_array( $range, array( 30, 90, 180, 365, 480 ), true ) ? $range : 90;
		if ( ! get_option( 'ssai_history_last' ) ) {
			self::snapshot(); // First visit: record today.
		}
		$rows  = self::rows( $range );
		$defs  = self::metrics();
		$base  = admin_url( 'admin.php?page=' . self::SLUG );
		$ups   = (array) SSAI_Guidelines::updates()['items'];
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>تاریخچه وضعیت سئو و رتبه‌ها</h1>
			<?php if ( ! empty( $_GET['msg'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<p class="description">هر روز وضعیت سئوی سایت خودکار ثبت می‌شود تا پیشرفت یا افت را در طول زمان ببینید. داده‌های گذشته از سرچ کنسول (اتصال یا فایل واردشده)، ردیابی رتبه کلمات و Ahrefs پر می‌شوند؛ بقیه موارد (امتیاز سئو، AEO، سرعت، ۴۰۴) از روز فعال شدن این بخش ثبت می‌شوند.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0 16px">
				<input type="hidden" name="action" value="ssai_history">
				<?php wp_nonce_field( 'ssai_history' ); ?>
				<button class="button button-primary">ثبت وضعیت امروز و پر کردن گذشته</button>
				<span class="description">بازه:</span>
				<?php foreach ( array( 30 => '۳۰ روز', 90 => '۹۰ روز', 180 => '۶ ماه', 365 => '۱ سال', 480 => '۱۶ ماه' ) as $d => $l ) : ?>
					<a class="button<?php echo $range === $d ? ' button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'range', $d, $base ) ); ?>"><?php echo esc_html( $l ); ?></a>
				<?php endforeach; ?>
			</form>

			<?php if ( ! $rows ) : ?>
				<div class="ssai-card"><p>هنوز داده‌ای ثبت نشده است. دکمه بالا را بزنید.</p></div>
			<?php else : ?>
				<div class="ssai-tiles" style="margin-bottom:16px">
					<?php
					foreach ( $defs as $k => $m ) {
						$series = array();
						foreach ( $rows as $day => $r ) {
							if ( isset( $r[ $k ] ) ) {
								$series[ $day ] = $r[ $k ];
							}
						}
						if ( ! $series || 'changes' === $k ) {
							continue;
						}
						// Daily Search Console values are noisy: compare 7-day averages.
						$smooth = in_array( $k, array( 'clicks', 'impr', 'pos' ), true );
						$vals   = array_values( $series );
						$now    = $smooth ? array_sum( array_slice( $vals, -7 ) ) / max( 1, count( array_slice( $vals, -7 ) ) ) : end( $vals );
						$then   = $smooth ? array_sum( array_slice( $vals, 0, 7 ) ) / max( 1, count( array_slice( $vals, 0, 7 ) ) ) : reset( $vals );
						$diff   = $now - $then;
						$good   = $m[2] ? $diff < 0 : $diff > 0;
						$chg    = count( $vals ) > 1 && abs( $diff ) >= ( 'dec' === $m[1] ? 0.1 : 1 ) ? '<span style="color:' . ( $good ? 'var(--s-good,#16a34a)' : 'var(--s-bad,#dc2626)' ) . ';font-weight:700">' . ( $diff > 0 ? '▲ ' : '▼ ' ) . esc_html( self::fmt( abs( $diff ), 'dec' === $m[1] || $smooth ? 'dec' : 'int' ) ) . '</span> از ' . esc_html( array_key_first( $series ) ) : 'بدون تغییر';
						echo '<div class="ssai-tile"><span class="ssai-tile-label">' . esc_html( $m[0] ) . ( $smooth ? ' (میانگین ۷ روز)' : '' ) . '</span><strong>' . esc_html( self::fmt( $smooth && 'pos' !== $k ? round( $now ) : $now, $m[1] ) ) . '</strong><span class="description">' . wp_kses_post( $chg ) . '</span></div>';
					}
					?>
				</div>

				<div class="ssai-dash-cols">
					<?php
					foreach ( $defs as $k => $m ) {
						$pts = array();
						foreach ( $rows as $day => $r ) {
							if ( isset( $r[ $k ] ) ) {
								$pts[] = array( $day, $r[ $k ] );
							}
						}
						if ( count( $pts ) < 2 ) {
							continue;
						}
						echo '<section class="ssai-card"><h2>' . esc_html( $m[0] ) . '</h2>' . SSAI_Report::chart( $pts, $m[0], $m[3], $m[2] && in_array( $k, array( 'pos', 'kw_pos' ), true ), 'dec' === $m[1] ? 1 : 0 ) . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput
					}
					?>
				</div>

				<section class="ssai-card">
					<details<?php echo $range <= 30 ? ' open' : ''; ?>><summary style="cursor:pointer"><h2 style="display:inline">روزبه‌روز</h2> <span class="description">(<?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?> روز — برای باز و بسته کردن کلیک کنید)</span></summary>
					<p class="description">جدیدترین روز بالا. ردیف‌های نارنجی روزهای به‌روزرسانی رسمی رتبه‌بندی گوگل‌اند.</p>
					<div class="ssai-table-wrap"><table class="widefat striped">
						<thead><tr><th>روز</th><th>امتیاز سئو</th><th>کلیک</th><th>نمایش</th><th>جایگاه</th><th>۳ رتبه اول</th><th>صفحه اول</th><th>AEO</th><th>سرعت</th><th>۴۰۴</th><th>تغییرات</th><th>رویداد گوگل</th></tr></thead>
						<tbody>
						<?php
						foreach ( array_reverse( $rows, true ) as $day => $r ) {
							$ev = array();
							foreach ( $ups as $u ) {
								if ( ! empty( $u['date'] ) && $day >= $u['date'] && $day <= ( ! empty( $u['end'] ) ? $u['end'] : gmdate( 'Y-m-d' ) ) ) {
									$ev[] = $u['title'];
								}
							}
							$c = function ( $k ) use ( $r, $defs ) {
								return esc_html( isset( $r[ $k ] ) ? self::fmt( $r[ $k ], $defs[ $k ][1] ) : '' );
							};
							echo '<tr' . ( $ev ? ' style="background:rgba(245,158,11,.10)"' : '' ) . '><td dir="ltr" style="text-align:right">' . esc_html( $day ) . '</td><td>' . $c( 'score' ) . '</td><td>' . $c( 'clicks' ) . '</td><td>' . $c( 'impr' ) . '</td><td>' . $c( 'pos' ) . '</td><td>' . $c( 'kw_top3' ) . '</td><td>' . $c( 'kw_top10' ) . '</td><td>' . $c( 'aeo' ) . '</td><td>' . $c( 'psi' ) . '</td><td>' . $c( 'e404' ) . '</td><td>' . $c( 'changes' ) . '</td><td>' . esc_html( implode( '، ', $ev ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
						</tbody>
					</table></div>
					</details>
				</section>
			<?php endif; ?>
		</div>
		<?php
		// phpcs:enable
	}
}
