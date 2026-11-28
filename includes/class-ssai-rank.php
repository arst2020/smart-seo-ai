<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rank tracker: daily average position, clicks and impressions of chosen keywords from
 * Search Console, kept in a small table, so the history goes beyond Search Console's view and
 * changes are visible at a glance. Keywords: the pages' focus keywords (optional) + a manual list.
 */
class SSAI_Rank {

	const SLUG   = 'smart-seo-ai-rank';
	const OPTION = 'ssai_rank';
	const CRON   = 'ssai_rank_daily';
	const DBV    = '1';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'ssai_rank';
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 46 );
		add_action( 'admin_post_ssai_rank', array( __CLASS__, 'handle' ) );
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function get( $key = null ) {
		$o = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'manual' => array(),  // list of keywords.
				'auto'   => 1,        // include focus keywords of pages.
				'last'   => 0,
				'error'  => '',
			)
		);
		return null === $key ? $o : ( isset( $o[ $key ] ) ? $o[ $key ] : null );
	}

	private static function set( $changes ) {
		update_option( self::OPTION, array_merge( self::get(), $changes ), false );
	}

	public static function install() {
		if ( self::DBV === get_option( 'ssai_rank_db' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE $t (
				kw varchar(190) NOT NULL,
				day date NOT NULL,
				pos float NOT NULL DEFAULT 0,
				clicks int NOT NULL DEFAULT 0,
				impr int NOT NULL DEFAULT 0,
				PRIMARY KEY  (kw,day),
				KEY day (day)
			) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'ssai_rank_db', self::DBV, true );
	}

	public static function schedule() {
		if ( SSAI_GSC::connected() && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function norm( $kw ) {
		$kw = str_replace( array( 'ي', 'ك', "\xE2\x80\x8C" ), array( 'ی', 'ک', ' ' ), (string) $kw );
		return trim( preg_replace( '/\s+/u', ' ', mb_strtolower( $kw ) ) );
	}

	/** keyword => post id (0 = manual). */
	public static function keywords() {
		$o   = self::get();
		$out = array();
		if ( $o['auto'] ) {
			global $wpdb;
			$rows = $wpdb->get_results( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_status = 'publish' AND m.meta_key IN ('" . esc_sql( SSAI_Content::META_KW ) . "','rank_math_focus_keyword','_yoast_wpseo_focuskw') AND m.meta_value <> '' GROUP BY p.ID LIMIT 1000" ); // phpcs:ignore WordPress.DB
			foreach ( $rows as $r ) {
				$k = self::norm( SSAI_Content::get_focus_keyword( (int) $r->ID ) );
				if ( '' !== $k && ! isset( $out[ $k ] ) ) {
					$out[ $k ] = (int) $r->ID;
				}
			}
		}
		foreach ( (array) $o['manual'] as $k ) {
			$k = self::norm( $k );
			if ( '' !== $k && ! isset( $out[ $k ] ) ) {
				$out[ $k ] = 0;
			}
		}
		return $out;
	}

	/** Regex groups under Search Console's filter length limit. */
	private static function groups( $kws ) {
		$groups = array();
		$cur    = array();
		$len    = 0;
		foreach ( $kws as $k ) {
			$q = str_replace( array( 'ی', 'ک' ), array( '[یي]', '[کك]' ), preg_quote( $k, '/' ) );
			if ( $cur && $len + strlen( $q ) > 3500 ) {
				$groups[] = $cur;
				$cur      = array();
				$len      = 0;
			}
			$cur[] = $q;
			$len  += strlen( $q ) + 1;
		}
		if ( $cur ) {
			$groups[] = $cur;
		}
		return $groups;
	}

	/** Fetch and store a date range. @return int|WP_Error rows stored */
	public static function fetch( $days ) {
		if ( ! SSAI_GSC::connected() ) {
			return new WP_Error( 'ssai_rank_gsc', 'سرچ کنسول وصل نیست؛ اول در «Smart SEO AI › سرچ کنسول» وصل شوید.' );
		}
		$kws = array_keys( self::keywords() );
		if ( ! $kws ) {
			return new WP_Error( 'ssai_rank_kw', 'هیچ کلمه‌ای برای ردیابی نیست؛ کلمه اضافه کنید یا برای صفحه‌ها کلمه کلیدی اصلی تعیین کنید.' );
		}
		$end   = gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS );
		$start = gmdate( 'Y-m-d', time() - ( 1 + $days ) * DAY_IN_SECONDS );
		$want  = array_flip( $kws );
		$data  = array();
		foreach ( self::groups( $kws ) as $g ) {
			$row = 0;
			do {
				$r = SSAI_GSC::query(
					array(
						'startDate'             => $start,
						'endDate'               => $end,
						'dimensions'            => array( 'date', 'query' ),
						'rowLimit'              => 25000,
						'startRow'              => $row,
						'dimensionFilterGroups' => array(
							array(
								'filters' => array(
									array(
										'dimension'  => 'query',
										'operator'   => 'includingRegex',
										'expression' => '^(' . implode( '|', $g ) . ')$',
									),
								),
							),
						),
					)
				);
				if ( is_wp_error( $r ) ) {
					self::set( array( 'error' => $r->get_error_message() ) );
					return $r;
				}
				$rows = isset( $r['rows'] ) ? $r['rows'] : array();
				foreach ( $rows as $x ) {
					$k = self::norm( $x['keys'][1] );
					if ( ! isset( $want[ $k ] ) ) {
						continue;
					}
					$key = $k . '|' . $x['keys'][0];
					if ( isset( $data[ $key ] ) ) { // Variants (ي/ی) that normalise to the same keyword.
						$a              = $data[ $key ];
						$i              = $a[3] + (int) $x['impressions'];
						$data[ $key ]   = array( $k, $x['keys'][0], $i ? ( $a[2] * $a[3] + $x['position'] * $x['impressions'] ) / $i : $a[2], $i, $a[4] + (int) $x['clicks'] );
					} else {
						$data[ $key ] = array( $k, $x['keys'][0], (float) $x['position'], (int) $x['impressions'], (int) $x['clicks'] );
					}
				}
				$row += 25000;
			} while ( count( $rows ) >= 25000 && $row < 100000 );
		}
		global $wpdb;
		$t = self::table();
		foreach ( $data as $d ) {
			$wpdb->replace( $t, array( 'kw' => $d[0], 'day' => $d[1], 'pos' => round( $d[2], 2 ), 'impr' => $d[3], 'clicks' => $d[4] ), array( '%s', '%s', '%f', '%d', '%d' ) ); // phpcs:ignore WordPress.DB
		}
		self::set( array( 'last' => time(), 'error' => '' ) );
		return count( $data );
	}

	public static function cron() {
		self::install();
		self::fetch( 6 );
	}

	/* ------------------------------------------------------------ Stats */

	/** kw => list of [ day, pos, clicks, impr ] (ascending), last $days days. */
	private static function history( $days ) {
		global $wpdb;
		$t    = self::table();
		$from = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT kw, day, pos, clicks, impr FROM $t WHERE day >= %s ORDER BY day ASC", $from ) ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r->kw ][] = array( $r->day, (float) $r->pos, (int) $r->clicks, (int) $r->impr );
		}
		return $out;
	}

	/** Impression-weighted position of rows between two day offsets (from today). */
	private static function avg( $rows, $from, $to ) {
		$a = gmdate( 'Y-m-d', time() - $from * DAY_IN_SECONDS );
		$b = gmdate( 'Y-m-d', time() - $to * DAY_IN_SECONDS );
		$p = 0;
		$i = 0;
		$c = 0;
		foreach ( $rows as $r ) {
			if ( $r[0] >= $a && $r[0] < $b ) {
				$w  = max( 1, $r[3] );
				$p += $r[1] * $w;
				$i += $w;
				$c += $r[2];
			}
		}
		return $i ? array( $p / $i, $c, $i ) : array( null, 0, 0 );
	}

	private static function spark( $rows, $w = 140, $h = 32, $days = 90 ) {
		if ( count( $rows ) < 2 ) {
			return '<span class="description">—</span>';
		}
		$max  = max( 10, ceil( max( wp_list_pluck( $rows, 1 ) ) ) );
		$t0   = time() - $days * DAY_IN_SECONDS;
		$pts  = array();
		foreach ( $rows as $r ) {
			$x     = round( ( strtotime( $r[0] ) - $t0 ) / ( $days * DAY_IN_SECONDS ) * $w, 1 );
			$y     = round( ( $r[1] - 1 ) / ( $max - 1 ) * ( $h - 4 ) + 2, 1 );
			$pts[] = $x . ',' . $y;
		}
		return '<svg width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" style="direction:ltr;background:#f6f7f7;border-radius:3px" aria-hidden="true"><polyline fill="none" stroke="#2271b1" stroke-width="1.5" points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg>';
	}

	/** Larger chart with axis labels for one keyword. */
	private static function chart( $rows, $days ) {
		$w   = 900;
		$h   = 260;
		$pad = 34;
		if ( count( $rows ) < 2 ) {
			return '<p>داده کافی برای نمودار نیست.</p>';
		}
		$max = max( 10, ceil( max( wp_list_pluck( $rows, 1 ) ) / 5 ) * 5 );
		$t0  = time() - $days * DAY_IN_SECONDS;
		$sx  = function ( $d ) use ( $t0, $days, $w, $pad ) {
			return round( $pad + ( strtotime( $d ) - $t0 ) / ( $days * DAY_IN_SECONDS ) * ( $w - 2 * $pad ), 1 );
		};
		$sy  = function ( $p ) use ( $max, $h, $pad ) {
			return round( $pad / 2 + ( $p - 1 ) / ( $max - 1 ) * ( $h - 1.5 * $pad ), 1 );
		};
		$o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" style="width:100%;max-width:' . $w . 'px;direction:ltr;background:#fff" role="img" aria-label="نمودار جایگاه">';
		foreach ( array_unique( array( 1, 3, 10, 20, $max ) ) as $g ) {
			if ( $g > $max ) {
				continue;
			}
			$y  = $sy( $g );
			$o .= '<line x1="' . $pad . '" x2="' . ( $w - $pad ) . '" y1="' . $y . '" y2="' . $y . '" stroke="#dcdcde" stroke-dasharray="3 3"/><text x="4" y="' . ( $y + 4 ) . '" font-size="11" fill="#646970">' . $g . '</text>';
		}
		// Google ranking updates as shaded bands (from the Search Status Dashboard feed).
		$from = gmdate( 'Y-m-d', $t0 );
		foreach ( (array) SSAI_Guidelines::updates()['items'] as $u ) {
			if ( empty( $u['date'] ) ) {
				continue;
			}
			$end = ! empty( $u['end'] ) ? $u['end'] : gmdate( 'Y-m-d' );
			if ( $end < $from ) {
				continue;
			}
			$x1 = max( $pad, $sx( max( $u['date'], $from ) ) );
			$x2 = min( $w - $pad, max( $x1 + 2, $sx( $end ) ) );
			$o .= '<rect x="' . $x1 . '" y="' . ( $pad / 2 ) . '" width="' . round( $x2 - $x1, 1 ) . '" height="' . ( $h - 1.5 * $pad ) . '" fill="#f59e0b" fill-opacity=".12"><title>' . esc_html( $u['title'] . ' — ' . $u['date'] . ( ! empty( $u['end'] ) ? ' تا ' . $u['end'] : '' ) ) . '</title></rect>';
		}
		$pts = array();
		foreach ( $rows as $r ) {
			$pts[] = $sx( $r[0] ) . ',' . $sy( $r[1] );
		}
		$o .= '<polyline fill="none" stroke="#2271b1" stroke-width="2" points="' . esc_attr( implode( ' ', $pts ) ) . '"/>';
		foreach ( $rows as $r ) {
			$o .= '<circle cx="' . $sx( $r[0] ) . '" cy="' . $sy( $r[1] ) . '" r="2.5" fill="#2271b1"><title>' . esc_html( $r[0] . ' — جایگاه ' . round( $r[1], 1 ) . ' — ' . $r[2] . ' کلیک / ' . $r[3] . ' نمایش' ) . '</title></circle>';
		}
		$first = reset( $rows );
		$last  = end( $rows );
		$o    .= '<text x="' . $pad . '" y="' . ( $h - 4 ) . '" font-size="11" fill="#646970">' . esc_html( $first[0] ) . '</text><text x="' . ( $w - $pad ) . '" y="' . ( $h - 4 ) . '" font-size="11" fill="#646970" text-anchor="end">' . esc_html( $last[0] ) . '</text></svg>';
		return $o;
	}

	/* ------------------------------------------------------------ Admin */

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'ردیابی رتبه کلمات کلیدی', 'رتبه کلمات', 'ssai_reports', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function handle() {
		check_admin_referer( 'ssai_rank' );
		if ( ! current_user_can( 'ssai_reports' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		self::install();
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = '';
		if ( 'add' === $do ) {
			$list = preg_split( '/[\r\n,،]+/u', isset( $_POST['kws'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kws'] ) ) : '' );
			$cur  = (array) self::get( 'manual' );
			foreach ( $list as $k ) {
				$k = self::norm( $k );
				if ( '' !== $k && mb_strlen( $k ) <= 190 && ! in_array( $k, $cur, true ) ) {
					$cur[] = $k;
				}
			}
			self::set( array( 'manual' => array_values( $cur ), 'auto' => empty( $_POST['auto'] ) ? 0 : 1 ) );
			$msg = 'ذخیره شد. برای دیدن رتبه‌ها «دریافت از سرچ کنسول» را بزنید.';
		} elseif ( 'remove' === $do ) {
			$k = self::norm( isset( $_POST['kw'] ) ? sanitize_text_field( wp_unslash( $_POST['kw'] ) ) : '' );
			self::set( array( 'manual' => array_values( array_diff( (array) self::get( 'manual' ), array( $k ) ) ) ) );
			$msg = 'حذف شد.';
		} elseif ( 'fetch' === $do ) {
			$days = isset( $_POST['days'] ) ? min( 480, max( 3, absint( $_POST['days'] ) ) ) : 7;
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore
			}
			$n   = self::fetch( $days );
			$msg = is_wp_error( $n ) ? '✗ ' . $n->get_error_message() : '✓ ' . $n . ' رکورد روزانه دریافت شد.';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'msg' => rawurlencode( $msg ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'ssai_reports' ) ) {
			return;
		}
		self::install();
		// phpcs:disable WordPress.Security.NonceVerification
		$o    = self::get();
		$kws  = self::keywords();
		$hist = self::history( 90 );
		$sel  = isset( $_GET['kw'] ) ? self::norm( sanitize_text_field( wp_unslash( $_GET['kw'] ) ) ) : '';
		$sort = isset( $_GET['sort'] ) ? sanitize_key( wp_unslash( $_GET['sort'] ) ) : 'pos';
		$rows = array();
		$sum  = array( 'top3' => 0, 'top10' => 0, 'up' => 0, 'down' => 0 );
		foreach ( $kws as $k => $pid ) {
			$h          = isset( $hist[ $k ] ) ? $hist[ $k ] : array();
			$now        = self::avg( $h, 7, -1 );
			$prev       = self::avg( $h, 14, 7 );
			$m28        = self::avg( $h, 28, -1 );
			$p28        = self::avg( $h, 56, 28 );
			$best       = $h ? min( wp_list_pluck( $h, 1 ) ) : null;
			$chg        = null !== $now[0] && null !== $prev[0] ? $prev[0] - $now[0] : null;
			$rows[ $k ] = array(
				'pid'   => $pid,
				'pos'   => $now[0],
				'chg'   => $chg,
				'chg28' => null !== $m28[0] && null !== $p28[0] ? $p28[0] - $m28[0] : null,
				'best'  => $best,
				'clk'   => $m28[1],
				'imp'   => $m28[2],
				'h'     => $h,
			);
			if ( null !== $now[0] ) {
				$sum['top3']  += $now[0] <= 3.5 ? 1 : 0;
				$sum['top10'] += $now[0] <= 10.5 ? 1 : 0;
			}
			if ( null !== $chg ) {
				$sum['up']   += $chg >= 1 ? 1 : 0;
				$sum['down'] += $chg <= -1 ? 1 : 0;
			}
		}
		uasort(
			$rows,
			function ( $a, $b ) use ( $sort ) {
				if ( 'chg' === $sort ) {
					return ( null === $b['chg'] ? -999 : $b['chg'] ) <=> ( null === $a['chg'] ? -999 : $a['chg'] );
				}
				if ( 'clk' === $sort || 'imp' === $sort ) {
					return $b[ $sort ] <=> $a[ $sort ];
				}
				return ( null === $a['pos'] ? 999 : $a['pos'] ) <=> ( null === $b['pos'] ? 999 : $b['pos'] );
			}
		);
		$base = admin_url( 'admin.php?page=' . self::SLUG );
		$fmt  = function ( $c ) {
			if ( null === $c ) {
				return '<span class="description">—</span>';
			}
			if ( abs( $c ) < 0.5 ) {
				return '<span style="color:#646970">=</span>';
			}
			return $c > 0 ? '<span style="color:#008a20">▲ ' . esc_html( number_format_i18n( $c, 1 ) ) . '</span>' : '<span style="color:#b32d2e">▼ ' . esc_html( number_format_i18n( -$c, 1 ) ) . '</span>';
		};
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>ردیابی رتبه کلمات کلیدی</h1>
			<?php if ( ! empty( $_GET['msg'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! SSAI_GSC::connected() ) : ?>
				<div class="notice notice-warning"><p>برای ردیابی رتبه، سرچ کنسول باید وصل باشد: <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-gsc' ) ); ?>">اتصال سرچ کنسول</a></p></div>
			<?php endif; ?>
			<p class="description">جایگاه میانگین هر کلمه در گوگل، هر روز از سرچ کنسول خوانده و ذخیره می‌شود (داده سرچ کنسول ۲ تا ۳ روز تأخیر دارد). «جایگاه فعلی» میانگین ۷ روز اخیر است و «تغییر» در مقایسه با ۷ روز قبل از آن. روزهایی که کلمه هیچ نمایشی نداشته، داده‌ای ندارند.
				<?php if ( $o['last'] ) : ?> آخرین دریافت: <?php echo esc_html( wp_date( 'Y/m/d H:i', $o['last'] ) ); ?>.<?php endif; ?>
				<?php if ( $o['error'] ) : ?><br><span style="color:#b32d2e">آخرین خطا: <?php echo esc_html( $o['error'] ); ?></span><?php endif; ?>
			</p>

			<div class="ssai-cards" style="display:flex;gap:12px;flex-wrap:wrap;margin:12px 0">
				<?php foreach ( array( array( 'کلمه در ردیابی', count( $kws ) ), array( 'سه رتبه اول', $sum['top3'] ), array( 'صفحه اول (۱ تا ۱۰)', $sum['top10'] ), array( 'بهتر شده', $sum['up'] ), array( 'بدتر شده', $sum['down'] ) ) as $c ) : ?>
					<div class="ssai-card" style="min-width:130px;margin:0"><div style="font-size:24px;font-weight:600"><?php echo esc_html( number_format_i18n( $c[1] ) ); ?></div><div class="description"><?php echo esc_html( $c[0] ); ?></div></div>
				<?php endforeach; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px">
				<input type="hidden" name="action" value="ssai_rank"><input type="hidden" name="do" value="fetch">
				<?php wp_nonce_field( 'ssai_rank' ); ?>
				<select name="days">
					<option value="7">۷ روز اخیر</option>
					<option value="90">۹۰ روز گذشته</option>
					<option value="480">۱۶ ماه گذشته (همه داده سرچ کنسول)</option>
				</select>
				<button class="button button-primary" <?php disabled( ! SSAI_GSC::connected() ); ?>>دریافت از سرچ کنسول</button>
				<span class="description">بعد از آن، هر روز خودکار به‌روز می‌شود.</span>
			</form>

			<?php if ( '' !== $sel && isset( $rows[ $sel ] ) ) : ?>
				<div class="ssai-card">
					<h2>«<?php echo esc_html( $sel ); ?>» — ۹۰ روز اخیر <a class="button" style="float:left" href="<?php echo esc_url( $base ); ?>">بستن</a></h2>
					<?php echo self::chart( $rows[ $sel ]['h'], 90 ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* ?>
					<p class="description">محور عمودی جایگاه است (۱ = بالاترین). روی هر نقطه بروید تا جزئیات آن روز را ببینید. نوارهای نارنجی، بازه به‌روزرسانی‌های رسمی رتبه‌بندی گوگل‌اند.</p>
				</div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead><tr>
					<th>کلمه کلیدی</th>
					<th><a href="<?php echo esc_url( add_query_arg( 'sort', 'pos', $base ) ); ?>">جایگاه فعلی</a></th>
					<th><a href="<?php echo esc_url( add_query_arg( 'sort', 'chg', $base ) ); ?>">تغییر ۷ روزه</a></th>
					<th>تغییر ۲۸ روزه</th>
					<th>بهترین</th>
					<th><a href="<?php echo esc_url( add_query_arg( 'sort', 'clk', $base ) ); ?>">کلیک ۲۸ روز</a></th>
					<th><a href="<?php echo esc_url( add_query_arg( 'sort', 'imp', $base ) ); ?>">نمایش ۲۸ روز</a></th>
					<th>روند ۹۰ روزه</th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="9">هنوز کلمه‌ای اضافه نشده است.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $k => $r ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'kw', rawurlencode( $k ), $base ) ); ?>"><strong><?php echo esc_html( $k ); ?></strong></a>
							<?php if ( $r['pid'] ) : ?><br><span class="description">کلمه اصلی: <a href="<?php echo esc_url( SSAI_Report::url( $r['pid'] ) ); ?>"><?php echo esc_html( get_the_title( $r['pid'] ) ); ?></a></span><?php endif; ?></td>
						<td><?php echo null === $r['pos'] ? '<span class="description">بدون نمایش</span>' : '<strong>' . esc_html( number_format_i18n( $r['pos'], 1 ) ) . '</strong>'; ?></td>
						<td><?php echo $fmt( $r['chg'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo $fmt( $r['chg28'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo null === $r['best'] ? '—' : esc_html( number_format_i18n( $r['best'], 1 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['clk'] ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $r['imp'] ) ); ?></td>
						<td><?php echo self::spark( $r['h'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td>
							<?php if ( ! $r['pid'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('حذف از ردیابی؟');">
									<input type="hidden" name="action" value="ssai_rank"><input type="hidden" name="do" value="remove"><input type="hidden" name="kw" value="<?php echo esc_attr( $k ); ?>">
									<?php wp_nonce_field( 'ssai_rank' ); ?>
									<button class="button-link" style="color:#b32d2e">حذف</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<div class="ssai-card" style="margin-top:16px">
				<h2>افزودن کلمه</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ssai_rank"><input type="hidden" name="do" value="add">
					<?php wp_nonce_field( 'ssai_rank' ); ?>
					<textarea name="kws" class="large-text" rows="4" placeholder="ایمپلنت دندان کرج&#10;قیمت لمینت"></textarea>
					<p class="description">هر خط یک کلمه (یا با کاما جدا کنید).</p>
					<p><label><input type="checkbox" name="auto" value="1" <?php checked( $o['auto'] ); ?>> کلمه کلیدی اصلی همه صفحه‌ها هم خودکار ردیابی شود</label></p>
					<p><button class="button button-primary">ذخیره</button></p>
				</form>
			</div>
		</div>
		<?php
		// phpcs:enable
	}
}
