<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main dashboard: key numbers, search traffic, prioritised to-do list, recent AI changes,
 * module status and Google updates. Reads cached data only, so it opens instantly.
 */
class SSAI_Dashboard {

	/**
	 * Google's advice: compare the full week after a core update finished with the week before it began.
	 *
	 * @param array $items Ranking updates (title, date, end).
	 * @param array $dates Search Console daily rows (key = Y-m-d, clicks, impressions).
	 */
	private static function update_impact( $items, $dates ) {
		$core = null;
		foreach ( $items as $it ) {
			if ( preg_match( '/core update/i', $it['title'] ) ) {
				$core = $it;
				break;
			}
		}
		if ( ! $core || '' === $core['date'] ) {
			return '';
		}
		if ( empty( $core['end'] ) ) {
			return 'آپدیت هسته «' . esc_html( $core['title'] ) . '» هنوز در حال اجراست؛ تا پایان آن و یک هفته بعدش تغییر بزرگی در سایت ندهید.';
		}
		$b0    = gmdate( 'Y-m-d', strtotime( $core['date'] . ' -7 days' ) );
		$b1    = gmdate( 'Y-m-d', strtotime( $core['date'] . ' -1 day' ) );
		$a0    = gmdate( 'Y-m-d', strtotime( $core['end'] . ' +1 day' ) );
		$a1    = gmdate( 'Y-m-d', strtotime( $core['end'] . ' +7 days' ) );
		$ready = gmdate( 'Y-m-d', strtotime( $a1 . ' +3 days' ) ); // Search Console data lags 2–3 days.
		if ( gmdate( 'Y-m-d' ) < $ready ) {
			return 'اثر «' . esc_html( $core['title'] ) . '»: طبق توصیه گوگل هفته ' . esc_html( $a0 . ' تا ' . $a1 ) . ' با هفته ' . esc_html( $b0 . ' تا ' . $b1 ) . ' مقایسه می‌شود؛ از ' . esc_html( $ready ) . ' به بعد خروجی تازه سرچ کنسول را وارد کنید.';
		}
		$sum = function ( $from, $to ) use ( $dates ) {
			$c = 0;
			$i = 0;
			$n = 0;
			foreach ( $dates as $d ) {
				if ( isset( $d['key'] ) && $d['key'] >= $from && $d['key'] <= $to ) {
					$c += (int) $d['clicks'];
					$i += (int) $d['impressions'];
					++$n;
				}
			}
			return array( $c, $i, $n );
		};
		list( $bc, $bi, $bn ) = $sum( $b0, $b1 );
		list( $ac, $ai, $an ) = $sum( $a0, $a1 );
		if ( $bn < 5 || $an < 5 ) {
			return 'برای سنجش اثر «' . esc_html( $core['title'] ) . '» خروجی سرچ کنسولی وارد کنید که بازه ' . esc_html( $b0 . ' تا ' . $a1 ) . ' را پوشش دهد.';
		}
		$pct = function ( $a, $b ) {
			if ( ! $b ) {
				return '—';
			}
			$v = ( $a - $b ) / $b * 100;
			return '<strong style="color:' . ( $v >= 0 ? 'var(--s-good,#008a20)' : 'var(--s-bad,#b32d2e)' ) . '">' . ( $v >= 0 ? '▲ ' : '▼ ' ) . esc_html( number_format_i18n( abs( $v ), 1 ) ) . '٪</strong>';
		};
		return 'اثر «' . esc_html( $core['title'] ) . '» (هفته بعد از پایان در مقایسه با هفته قبل از شروع): کلیک ' . $pct( $ac, $bc ) . ' — نمایش ' . $pct( $ai, $bi ) . '.';
	}

	private static function tile( $label, $value, $sub = '', $cls = '', $link = '', $icon = '' ) {
		echo '<div class="ssai-tile' . ( $cls ? ' ssai-tile-' . esc_attr( $cls ) : '' ) . '">' . SSAI_UI::icon( $icon ) . '<span class="ssai-tile-label">' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong>' . ( $sub ? '<span class="description">' . wp_kses_post( $sub ) . '</span>' : '' ) . ( $link ? '<a class="ssai-tile-link" href="' . esc_url( $link ) . '">جزئیات ←</a>' : '' ) . '</div>';
	}

	private static function avg_score() {
		global $wpdb;
		$types = SSAI_Admin::post_types();
		if ( ! $types ) {
			return null;
		}
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT AVG(CAST(m.meta_value AS DECIMAL(5,1))) AS a, COUNT(*) AS n FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish' AND p.post_type IN ($in)", array_merge( array( SSAI_Analyzer::META_SCORE ), $types ) ) ); // phpcs:ignore WordPress.DB
		return $row && $row->n ? array( (int) round( $row->a ), (int) $row->n ) : null;
	}

	private static function published() {
		$n = 0;
		foreach ( SSAI_Admin::post_types() as $pt ) {
			$c  = wp_count_posts( $pt );
			$n += isset( $c->publish ) ? (int) $c->publish : 0;
		}
		return $n;
	}

	/** Latest applied changes across all pages. */
	private static function recent_changes( $n = 8 ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", SSAI_Applier::META_HISTORY ) ); // phpcs:ignore WordPress.DB
		$all  = array();
		foreach ( (array) $rows as $r ) {
			foreach ( (array) maybe_unserialize( $r->meta_value ) as $e ) {
				if ( is_array( $e ) && isset( $e['time'] ) ) {
					$e['post_id'] = (int) $r->post_id;
					$all[]        = $e;
				}
			}
		}
		usort(
			$all,
			function ( $a, $b ) {
				return (int) $b['time'] - (int) $a['time'];
			}
		);
		return array_slice( $all, 0, $n );
	}

	/**
	 * Prioritised to-do list from every cached check.
	 *
	 * @return array of [ level: 1 critical | 2 important | 3 improve, text, link, link label ]
	 */
	public static function actions() {
		$a     = array();
		$audit = SSAI_Site::cached_audit();
		$aeo   = SSAI_AEO::cached();
		$site  = SSAI_Site::url();
		$pages = admin_url( 'admin.php?page=smart-seo-ai-pages&orderby=score&order=asc' );
		$t     = $audit && isset( $audit['tech'] ) ? $audit['tech'] : null;

		if ( ! get_option( 'blog_public' ) ) {
			$a[] = array( 1, 'کل سایت از موتورهای جستجو پنهان است (تنظیمات › خواندن › «از موتورهای جستجو بخواه…»).', admin_url( 'options-reading.php' ), 'اصلاح' );
		}
		if ( $t && ! empty( $t['robots_all'] ) ) {
			$a[] = array( 1, 'robots.txt کل سایت را برای گوگل بسته است (Disallow: /).', SSAI_SEO_Admin::url( 'robots' ), 'اصلاح' );
		}
		if ( $t && null !== $t['ssl_days'] && $t['ssl_days'] < 14 ) {
			$a[] = array( 1, sprintf( 'گواهی SSL تا %s روز دیگر منقضی می‌شود.', number_format_i18n( max( 0, $t['ssl_days'] ) ) ), $site, 'جزئیات' );
		}
		if ( $aeo ) {
			$bots    = SSAI_AEO::bots();
			$blocked = array();
			foreach ( $aeo['bots'] as $tok => $b ) {
				if ( isset( $bots[ $tok ] ) && 'core' === $bots[ $tok ][2] && ! $b['ok'] ) {
					$blocked[] = $tok;
				}
			}
			if ( $blocked ) {
				$a[] = array( 1, 'robots.txt این ربات‌های اصلی جستجو را بسته است: ' . implode( '، ', $blocked ), SSAI_Site::url( array( 'tab' => 'ai' ) ), 'جزئیات' );
			}
		}
		if ( SSAI_Migrate::rm_active() ) {
			$a[] = array( 2, 'Rank Math هنوز فعال است؛ برای استفاده از همه امکانات این افزونه، جابه‌جایی امن را انجام دهید.', SSAI_SEO_Admin::url( 'migrate' ), 'دستیار انتقال' );
		}
		if ( SSAI_SEO::on( 'monitor404' ) && SSAI_Redirects::ready() ) {
			global $wpdb;
			$hot = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . SSAI_Redirects::log_table() . ' WHERE hits >= 3 AND last_seen >= %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
			if ( $hot ) {
				$a[] = array( 2, sprintf( '%s آدرس خراب (۴۰۴) بارها باز شده‌اند؛ آن‌ها را به صفحه مرتبط ریدایرکت کنید.', number_format_i18n( $hot ) ), admin_url( 'admin.php?page=' . SSAI_Redirects::SLUG . '&tab=log' ), 'ریدایرکت' );
			}
		}
		if ( $audit && empty( $audit['none'] ) ) {
			if ( ! empty( $audit['broken'] ) ) {
				$a[] = array( 2, sprintf( '%s لینک داخلی خراب در محتوا.', number_format_i18n( count( $audit['broken'] ) ) ), $site, 'جزئیات' );
			}
			if ( ! empty( $audit['dup_titles'] ) ) {
				$a[] = array( 2, sprintf( '%s گروه صفحه عنوان سئوی یکسان دارند (با هم رقابت می‌کنند).', number_format_i18n( count( $audit['dup_titles'] ) ) ), $site, 'جزئیات' );
			}
			if ( ! empty( $audit['no_meta'] ) ) {
				$a[] = array( 2, sprintf( '%s صفحه توضیحات متا ندارند.', number_format_i18n( count( $audit['no_meta'] ) ) ), $pages, 'اصلاح با هوش مصنوعی' );
			}
			if ( ! empty( $audit['with_err'] ) ) {
				$a[] = array( 2, sprintf( '%s صفحه خطای سئو دارند.', number_format_i18n( $audit['with_err'] ) ), $pages, 'ضعیف‌ترین‌ها' );
			}
			if ( ! empty( $audit['orphans'] ) ) {
				$a[] = array( 3, sprintf( '%s صفحه یتیم‌اند (هیچ صفحه‌ای به آن‌ها لینک نداده).', number_format_i18n( count( $audit['orphans'] ) ) ), $site, 'جزئیات' );
			}
			if ( ! empty( $audit['thin'] ) ) {
				$a[] = array( 3, sprintf( '%s صفحه کمتر از ۳۰۰ کلمه دارند.', number_format_i18n( count( $audit['thin'] ) ) ), $site, 'جزئیات' );
			}
			if ( ! empty( $audit['no_kw'] ) ) {
				$a[] = array( 3, sprintf( '%s صفحه کلمه کلیدی ندارند.', number_format_i18n( count( $audit['no_kw'] ) ) ), $pages, 'پیشنهاد کلمه' );
			}
			if ( $audit['time'] < time() - 14 * DAY_IN_SECONDS ) {
				$a[] = array( 3, 'بررسی کل سایت بیش از دو هفته پیش انجام شده؛ دوباره اجرا کنید.', $site, 'بررسی' );
			}
		} else {
			$a[] = array( 2, 'بررسی کل سایت هنوز انجام نشده است.', $site, 'شروع' );
		}
		if ( $aeo ) {
			$home = $aeo['schema'] ? reset( $aeo['schema'] ) : null;
			if ( $home && ! $home['org'] ) {
				$a[] = array( 2, 'صفحه اصلی Schema کسب‌وکار ندارد.', SSAI_SEO_Admin::url( 'entity' ), 'تکمیل اطلاعات کسب‌وکار' );
			}
			if ( ! empty( $aeo['sxo']['low_ctr'] ) ) {
				$a[] = array( 2, sprintf( '%s صفحه در صفحه اول گوگل‌اند ولی کلیک کمی می‌گیرند (عنوان/متا را جذاب‌تر کنید).', number_format_i18n( count( $aeo['sxo']['low_ctr'] ) ) ), SSAI_Site::url( array( 'tab' => 'ai' ) ), 'فهرست' );
			}
			if ( ! empty( $aeo['sxo']['striking'] ) ) {
				$a[] = array( 3, sprintf( '%s صفحه در جایگاه ۸ تا ۲۰ هستند و با کمی بهبود به صفحه اول می‌رسند.', number_format_i18n( count( $aeo['sxo']['striking'] ) ) ), SSAI_Site::url( array( 'tab' => 'ai' ) ), 'فهرست' );
			}
			if ( ! empty( $aeo['sxo']['questions'] ) ) {
				$a[] = array( 3, sprintf( '%s سؤال واقعی کاربران در گوگل؛ با پرسش‌های متداول جوابشان را بدهید.', number_format_i18n( count( $aeo['sxo']['questions'] ) ) ), SSAI_Site::url( array( 'tab' => 'ai' ) ), 'ایده‌ها' );
			}
		} else {
			$a[] = array( 3, 'بررسی AEO / GEO / SXO هنوز انجام نشده است.', SSAI_Site::url( array( 'tab' => 'ai' ) ), 'شروع' );
		}
		$ah = SSAI_Ahrefs::summary();
		if ( $ah ) {
			$au = SSAI_Site::url( array( 'tab' => 'ahrefs' ) );
			if ( $ah['rd'] && $ah['rd'][1] && $ah['rd'][0] > 5 * $ah['rd'][1] ) {
				$a[] = array( 2, 'دامنه‌های لینک‌دهنده (گزارش Ahrefs) در ۶ ماه اخیر به‌طور غیرعادی زیاد شده‌اند؛ احتمال لینک‌های اسپم خودکار.', $au, 'بررسی' );
			}
			if ( $ah['broken'] ) {
				$a[] = array( 2, sprintf( '%s لینک داخلی خراب (گزارش Ahrefs).', number_format_i18n( $ah['broken'] ) ), $au, 'اصلاح' );
			}
			if ( $ah['redirects'] ) {
				$a[] = array( 2, sprintf( '%s لینک داخلی به آدرس ریدایرکت‌شده (گزارش Ahrefs).', number_format_i18n( $ah['redirects'] ) ), $au, 'اصلاح' );
			}
			if ( $ah['no_h1'] || $ah['multi_h1'] ) {
				$a[] = array( 3, sprintf( '%s صفحه بدون H1 و %s صفحه با چند H1.', number_format_i18n( $ah['no_h1'] ), number_format_i18n( $ah['multi_h1'] ) ), $au, 'فهرست' );
			}
			if ( $ah['images'] ) {
				$a[] = array( 3, sprintf( '%s تصویر بدون alt (گزارش Ahrefs).', number_format_i18n( $ah['images'] ) ), $au, 'اصلاح' );
			}
			if ( $ah['mixed'] ) {
				$a[] = array( 3, sprintf( '%s لینک http در صفحه‌های https.', number_format_i18n( $ah['mixed'] ) ), $au, 'اصلاح' );
			}
			if ( null !== $ah['load'] && $ah['load'] > 800 ) {
				$a[] = array( 2, sprintf( 'زمان بارگذاری صفحه‌ها حدود %s میلی‌ثانیه است (کش صفحه / سرعت سرور را بررسی کنید).', number_format_i18n( $ah['load'] ) ), $au, 'جزئیات' );
			}
		} else {
			$a[] = array( 3, 'خروجی رایگان Site Audit از Ahrefs را وارد کنید تا لینک‌های خراب و ریدایرکت‌ها با یک کلیک اصلاح شوند.', SSAI_Site::url( array( 'tab' => 'ahrefs' ) ), 'وارد کردن' );
		}
		$gsc = SSAI_GSC_Import::site();
		if ( ! $gsc && ! SSAI_GSC::connected() ) {
			$a[] = array( 2, 'داده سرچ کنسول وارد نشده؛ بدون آن رتبه، کلیک و جستجوهای واقعی دیده نمی‌شود.', admin_url( 'admin.php?page=smart-seo-ai-gsc' ), 'وارد کردن' );
		} elseif ( $gsc && ! empty( $gsc['time'] ) && $gsc['time'] < time() - 30 * DAY_IN_SECONDS ) {
			$a[] = array( 3, 'داده سرچ کنسول قدیمی‌تر از یک ماه است؛ خروجی تازه وارد کنید.', admin_url( 'admin.php?page=smart-seo-ai-gsc' ), 'وارد کردن' );
		}
		$front = (int) get_option( 'page_on_front' );
		if ( $front && ! SSAI_Health::psi( $front ) ) {
			$a[] = array( 3, 'سرعت صفحه اصلی اندازه‌گیری نشده است.', SSAI_Report::url( $front ), 'اندازه‌گیری' );
		}
		usort(
			$a,
			function ( $x, $y ) {
				return $x[0] - $y[0];
			}
		);
		// Site-wide technical check (canonical / viewport), when it has been run.
		$tn = SSAI_Tech::counts();
		if ( $tn['pages'] ) {
			$turl = SSAI_Site::url( array( 'tab' => 'tech' ) );
			$cbad = ( isset( $tn['c']['missing'] ) ? $tn['c']['missing'] : 0 ) + ( isset( $tn['c']['multi'] ) ? $tn['c']['multi'] : 0 );
			$vbad = ( isset( $tn['v']['missing'] ) ? $tn['v']['missing'] : 0 ) + ( isset( $tn['v']['nowidth'] ) ? $tn['v']['nowidth'] : 0 );
			if ( $cbad ) {
				$a[] = array( 2, sprintf( '%s صفحه canonical ندارد یا چند canonical متفاوت دارد.', number_format_i18n( $cbad ) ), $turl, 'بررسی فنی' );
			}
			if ( $vbad ) {
				$a[] = array( 2, sprintf( '%s صفحه تگ viewport موبایل درست ندارد.', number_format_i18n( $vbad ) ), $turl, 'بررسی فنی' );
			}
		}
		return $a;
	}

	public static function page() {
		$avg   = self::avg_score();
		$audit = SSAI_Site::cached_audit();
		$aeo   = SSAI_AEO::cached();
		$gsc   = SSAI_GSC_Import::site();
		$auth  = SSAI_Site::authority();
		$risk  = SSAI_Site::risk_summary();
		$front = (int) get_option( 'page_on_front' );
		$psi   = $front ? SSAI_Health::psi( $front ) : array();
		$obs   = get_option( SSAI_Health::OPT_OBS );
		$pub   = self::published();
		$owner = SSAI_Content::seo_plugin();
		?>
		<div class="wrap ssai ssai-report ssai-dash" dir="rtl">
			<h1>Smart SEO AI <span class="description" dir="ltr"><?php echo esc_html( SSAI_Site::domain() ); ?></span></h1>
			<hr class="wp-header-end">
			<?php
			$acts  = self::actions();
			$urgent = count( array_filter( $acts, function ( $a ) { return $a[0] <= 2; } ) );
			$sc    = $avg ? (int) $avg[0] : 0;
			?>
			<section class="ssai-hero">
				<div class="ssai-ring" style="--v:<?php echo (int) $sc; ?>"><b><?php echo $avg ? esc_html( number_format_i18n( $sc ) ) : '—'; ?></b></div>
				<div>
					<h2><?php echo esc_html( ! $avg ? 'هنوز صفحه‌ای بررسی نشده' : ( $sc >= 80 ? 'سلامت سئوی سایت: خوب' : ( $sc >= 50 ? 'سلامت سئوی سایت: متوسط' : 'سلامت سئوی سایت: ضعیف' ) ) ); ?></h2>
					<p><?php echo $avg ? esc_html( 'میانگین امتیاز ' . number_format_i18n( $avg[1] ) . ' صفحه' ) : 'برای شروع، صفحه‌ها را بررسی کنید'; ?><?php echo $urgent ? esc_html( ' — ' . number_format_i18n( $urgent ) . ' کار مهم در انتظار شماست' ) : ' — کار فوری‌ای نیست'; ?></p>
				</div>
				<div class="ssai-hero-chips">
					<?php if ( '' === $owner ) : ?>
						<span class="ssai-hero-chip">✓ خروجی سئو با Smart SEO AI</span>
					<?php else : ?>
						<a class="ssai-hero-chip" href="<?php echo esc_url( SSAI_SEO_Admin::url( 'migrate' ) ); ?>">⚠ خروجی سئو فعلاً با <?php echo esc_html( 'rankmath' === $owner ? 'Rank Math' : ( 'yoast' === $owner ? 'Yoast' : 'افزونه دیگر' ) ); ?> — جابه‌جایی امن</a>
					<?php endif; ?>
					<?php if ( $audit && ! empty( $audit['time'] ) ) : ?>
						<span class="ssai-hero-chip">آخرین بررسی کل سایت: <?php echo esc_html( human_time_diff( $audit['time'] ) ); ?> پیش</span>
					<?php endif; ?>
					<a class="ssai-hero-chip" href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-pages' ) ); ?>">🔄 بررسی صفحه‌ها</a>
					<a class="ssai-hero-chip" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SSAI_History::SLUG ) ); ?>">📈 تاریخچه سئو</a>
				</div>
			</section>

			<section class="ssai-dash-kpis">
				<div class="ssai-tiles">
					<?php
					if ( $gsc && ! empty( $gsc['dates'] ) ) {
						$cl = array_sum( wp_list_pluck( $gsc['dates'], 'clicks' ) );
						$im = array_sum( wp_list_pluck( $gsc['dates'], 'impressions' ) );
						self::tile( 'کلیک از گوگل', number_format_i18n( $cl ), number_format_i18n( $im ) . ' نمایش — ' . esc_html( $gsc['range'] ), '', admin_url( 'admin.php?page=smart-seo-ai-gsc' ), 'click' );
					} else {
						self::tile( 'کلیک از گوگل', '—', 'خروجی سرچ کنسول را وارد کنید', '', admin_url( 'admin.php?page=smart-seo-ai-gsc' ), 'click' );
					}
					$seen = $gsc && ! empty( $gsc['pages'] ) ? count( $gsc['pages'] ) : null;
					self::tile( 'صفحه‌های دیده‌شده در گوگل', null !== $seen ? number_format_i18n( min( $seen, $pub ) ) : '—', 'از ' . number_format_i18n( $pub ) . ' صفحه منتشرشده', '', '', 'eye' );
					self::tile( 'امتیاز AEO', $aeo && $aeo['pages'] ? number_format_i18n( (int) round( array_sum( wp_list_pluck( $aeo['pages'], 'score' ) ) / count( $aeo['pages'] ) ) ) : '—', 'آمادگی برای پاسخ‌های هوش مصنوعی', '', SSAI_Site::url( array( 'tab' => 'ai' ) ), 'ai' );
					if ( isset( $psi['mobile']['scores']['performance'] ) ) {
						$p = (int) $psi['mobile']['scores']['performance'];
						self::tile( 'سرعت موبایل صفحه اصلی', number_format_i18n( $p ), 'PageSpeed', $p >= 90 ? 'good' : ( $p >= 50 ? 'warning' : 'error' ), SSAI_Report::url( $front ), 'speed' );
					} else {
						self::tile( 'سرعت موبایل صفحه اصلی', '—', 'اندازه‌گیری نشده', '', $front ? SSAI_Report::url( $front ) : '', 'speed' );
					}
					self::tile( 'امنیت (Observatory)', is_array( $obs ) && ! empty( $obs['grade'] ) ? $obs['grade'] : '—', 'هدرهای امنیتی', '', '', 'shield' );
					$st = SSAI_SEO::on( 'monitor404' ) ? SSAI_Redirects::log_stats( 7 ) : null;
					self::tile( '۴۰۴ در ۷ روز اخیر', $st ? number_format_i18n( $st['urls'] ) : '—', $st ? number_format_i18n( $st['hits'] ) . ' بار باز شده' : 'مانیتور ۴۰۴ فعال نیست', $st && $st['urls'] ? 'warning' : '', admin_url( 'admin.php?page=' . SSAI_Redirects::SLUG . '&tab=log' ), 'warn' );
					self::tile( 'اعتبار دامنه', isset( $auth['opr'] ) && null !== $auth['opr'] ? number_format_i18n( $auth['opr'], 2 ) : '—', 'Open PageRank' . ( $risk ? ' — ریسک بک‌لینک ' . number_format_i18n( $risk['pct'] ) . '٪' : '' ), '', SSAI_Site::url(), 'link' );
					?>
				</div>
			</section>

			<div class="ssai-dash-cols">
				<section class="ssai-card">
					<h2>کارهای بعدی</h2>
					<?php if ( ! $acts ) : ?>
						<p>✓ کار فوری‌ای نیست.</p>
					<?php else : ?>
						<ol class="ssai-actions">
							<?php foreach ( array_slice( $acts, 0, 12 ) as $x ) : ?>
								<li class="ssai-act-<?php echo (int) $x[0]; ?>"><span class="ssai-act-lvl"><?php echo esc_html( 1 === $x[0] ? 'فوری' : ( 2 === $x[0] ? 'مهم' : 'بهبود' ) ); ?></span> <?php echo esc_html( $x[1] ); ?> <?php if ( $x[2] ) : ?><a href="<?php echo esc_url( $x[2] ); ?>"><?php echo esc_html( $x[3] ); ?> ←</a><?php endif; ?></li>
							<?php endforeach; ?>
						</ol>
					<?php endif; ?>
				</section>

				<section class="ssai-card">
					<h2>جستجوی گوگل</h2>
					<?php
					if ( $gsc && count( $gsc['dates'] ) > 1 ) {
						$pts = array();
						$imp = array();
						foreach ( array_slice( $gsc['dates'], -90 ) as $d ) {
							$pts[] = array( $d['key'], $d['clicks'] );
							$imp[] = array( $d['key'], $d['impressions'] );
						}
						echo SSAI_Report::chart( $pts, 'کلیک', '#6366f1' ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG built with esc_* inside.
						echo SSAI_Report::chart( $imp, 'نمایش', '#a78bfa' ); // phpcs:ignore WordPress.Security.EscapeOutput
						$top = $gsc['pages'];
						uasort(
							$top,
							function ( $x, $y ) {
								return $y['clicks'] - $x['clicks'];
							}
						);
						echo '<h3>پربازدیدترین صفحه‌ها</h3><table class="widefat striped"><thead><tr><th>صفحه</th><th>کلیک</th><th>جایگاه</th></tr></thead><tbody>';
						foreach ( array_slice( $top, 0, 6 ) as $p ) {
							$pid = SSAI_GSC_Import::find_post( $p['key'] );
							echo '<tr><td>' . ( $pid ? '<a href="' . esc_url( SSAI_Report::url( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a>' : '<span dir="ltr">' . esc_html( rawurldecode( $p['key'] ) ) . '</span>' ) . '</td><td>' . esc_html( number_format_i18n( $p['clicks'] ) ) . '</td><td>' . esc_html( number_format_i18n( $p['position'], 1 ) ) . '</td></tr>';
						}
						echo '</tbody></table>';
					} else {
						echo '<p>برای نمودار کلیک و نمایش، خروجی کل سایت از سرچ کنسول را <a href="' . esc_url( admin_url( 'admin.php?page=smart-seo-ai-gsc' ) ) . '">وارد کنید</a>.</p>';
					}
					?>
				</section>
			</div>

			<div class="ssai-dash-cols">
				<section class="ssai-card">
					<h2>آخرین تغییرات اعمال‌شده</h2>
					<?php
					$ch     = self::recent_changes();
					$fields = SSAI_Applier::fields();
					if ( ! $ch ) {
						echo '<p class="description">هنوز تغییری با این افزونه اعمال نشده است.</p>';
					} else {
						echo '<ul class="ssai-plist">';
						foreach ( $ch as $e ) {
							$u = get_userdata( (int) $e['user'] );
							echo '<li><a href="' . esc_url( SSAI_Report::url( $e['post_id'] ) ) . '">' . esc_html( get_the_title( $e['post_id'] ) ) . '</a> — ' . esc_html( isset( $fields[ $e['field'] ] ) ? $fields[ $e['field'] ] : $e['field'] ) . ' <span class="description">' . esc_html( ( $u ? $u->display_name . '، ' : '' ) . human_time_diff( (int) $e['time'] ) . ' پیش' ) . '</span></li>';
						}
						echo '</ul>';
					}
					?>
				</section>

				<section class="ssai-card">
					<h2>بخش‌های سئو</h2>
					<ul class="ssai-list">
						<?php
						$labels = array(
							'head'       => 'عنوان، متا، Canonical و Open Graph',
							'schema'     => 'Schema',
							'sitemap'    => 'نقشه سایت',
							'redirects'  => 'ریدایرکت‌ها (' . number_format_i18n( SSAI_Redirects::count() ) . ')',
							'monitor404' => 'مانیتور ۴۰۴',
							'breadcrumbs' => 'Breadcrumb',
							'robots_txt' => 'robots.txt',
						);
						$mods   = SSAI_SEO::get( 'modules' );
						foreach ( $labels as $k => $l ) {
							$on  = ! empty( $mods[ $k ] );
							$run = SSAI_SEO::on( $k );
							$cls = $run ? 'good' : ( $on ? 'warning' : 'error' );
							echo '<li class="ssai-' . esc_attr( $cls ) . '"><span class="ssai-dot"></span><div>' . esc_html( $l ) . ' — ' . esc_html( $run ? 'فعال' : ( $on ? 'در انتظار غیرفعال شدن ' . ( 'rankmath' === $owner ? 'Rank Math' : 'افزونه سئوی دیگر' ) : 'خاموش' ) ) . '</div></li>';
						}
						?>
					</ul>
					<p><a href="<?php echo esc_url( SSAI_SEO_Admin::url() ); ?>">تنظیمات سئو ←</a> <?php if ( SSAI_SEO::on( 'sitemap' ) ) : ?>| <a href="<?php echo esc_url( SSAI_Sitemap::url() ); ?>" target="_blank" rel="noopener">نقشه سایت</a><?php endif; ?></p>
					<?php
					$up = SSAI_Guidelines::updates();
					if ( ! empty( $up['items'] ) ) {
						echo '<h3>آخرین به‌روزرسانی‌های گوگل</h3><ul class="ssai-plist">';
						foreach ( array_slice( $up['items'], 0, 4 ) as $it ) {
							$range = $it['date'] . ( ! empty( $it['end'] ) ? ' → ' . $it['end'] : ' (در حال اجرا)' );
							echo '<li>' . esc_html( $it['title'] ) . ' <span class="description" dir="ltr">' . esc_html( $range ) . '</span></li>';
						}
						echo '</ul>';
						$imp = self::update_impact( $up['items'], isset( $gsc ) && is_array( $gsc ) && ! empty( $gsc['dates'] ) ? $gsc['dates'] : array() );
						if ( $imp ) {
							echo '<p class="description">' . wp_kses_post( $imp ) . '</p>';
						}
					}
					?>
				</section>
			</div>
		</div>
		<?php
	}
}
