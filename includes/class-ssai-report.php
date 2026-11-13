<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-page SEO report (on-page checks + Search Console performance, keyword positions and index status)
 * and the Search Console connection screen.
 */
class SSAI_Report {

	const SLUG = 'smart-seo-ai-report';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		foreach ( array( 'post_row_actions', 'page_row_actions' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'row_action' ), 10, 2 );
		}
	}

	public static function url( $post_id, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'post' => (int) $post_id ), $args ), admin_url( 'admin.php' ) );
	}

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'سرچ کنسول', 'سرچ کنسول', 'manage_options', 'smart-seo-ai-gsc', array( __CLASS__, 'page_gsc' ) );
		// Hidden page (no menu entry), opened from the links.
		$hook = add_submenu_page( '', 'گزارش سئوی صفحه', 'گزارش سئوی صفحه', 'edit_posts', self::SLUG, array( __CLASS__, 'page_report' ) );
		// Hidden pages get no admin title from WordPress (PHP 8 warning in admin-header.php).
		add_action(
			'load-' . $hook,
			function () {
				$GLOBALS['title'] = 'گزارش سئوی صفحه'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		);
	}

	public static function row_action( $actions, $post ) {
		if ( in_array( $post->post_type, SSAI_Admin::post_types(), true ) && current_user_can( 'edit_post', $post->ID ) && 'auto-draft' !== $post->post_status ) {
			$actions['ssai_report'] = '<a href="' . esc_url( self::url( $post->ID ) ) . '">گزارش سئو</a>';
		}
		return $actions;
	}

	/* ------------------------------------------------------------ Formatting helpers */

	private static function num( $n, $dec = 0 ) {
		return number_format_i18n( $n, $dec );
	}

	private static function delta( $now, $before, $lower_is_better = false, $pct = false ) {
		if ( ! $before ) {
			return '';
		}
		$diff = $now - $before;
		if ( abs( $pct ? $diff * 100 : $diff ) < 0.05 ) {
			return '<span class="ssai-delta">بدون تغییر</span>';
		}
		$good = $lower_is_better ? $diff < 0 : $diff > 0;
		// For positions "up" means a better (smaller) number, as in Google's ranking.
		$arrow = $lower_is_better ? ( $good ? '▲' : '▼' ) : ( $diff > 0 ? '▲' : '▼' );
		$txt   = $pct ? self::num( abs( $diff ) * 100, 1 ) . '٪' : self::num( abs( $diff ), $lower_is_better ? 1 : 0 );
		return '<span class="ssai-delta ' . ( $good ? 'ssai-up' : 'ssai-down' ) . '">' . $arrow . ' ' . esc_html( $txt ) . ' <span class="screen-reader-text">' . ( $good ? 'بهتر' : 'بدتر' ) . '</span></span>';
	}

	const LOW_DATA = 50;

	/** Position badge; with few impressions the average is not reliable, so it is marked. */
	private static function pos_badge( $pos, $impr = null ) {
		if ( null === $pos || $pos <= 0 ) {
			return '<span class="ssai-pos ssai-pos-none">—</span>';
		}
		$cls = $pos <= 3 ? 'top3' : ( $pos <= 10 ? 'top10' : ( $pos <= 20 ? 'top20' : 'far' ) );
		$low = null !== $impr && $impr < self::LOW_DATA;
		return '<span class="ssai-pos ssai-pos-' . $cls . ( $low ? ' ssai-pos-low' : '' ) . '"' . ( $low ? ' title="فقط ' . (int) $impr . ' نمایش؛ این میانگین قابل اتکا نیست"' : '' ) . '>' . ( $low ? '≈' : '' ) . esc_html( self::num( $pos, 1 ) ) . '</span>' . ( $low ? ' <span class="ssai-lowdata">داده کم</span>' : '' );
	}

	/**
	 * Small single-series line chart (inline SVG). One measure per chart – no dual axis.
	 *
	 * @param array  $points  [ [label, value], … ]
	 * @param bool   $invert  true for "position" (1 at the top).
	 */
	public static function chart( $points, $title, $color, $invert = false, $dec = 0 ) {
		$n = count( $points );
		if ( $n < 2 ) {
			return '<p class="description">داده کافی برای نمودار نیست.</p>';
		}
		$w    = 640;
		$h    = 170;
		$padl = 44;
		$padr = 12;
		$padt = 12;
		$padb = 26;
		$vals = wp_list_pluck( $points, 1 );
		$min  = $invert ? max( 1, floor( min( $vals ) ) ) : 0;
		$max  = max( $vals );
		$max  = $invert ? ceil( $max ) : ( $max > 0 ? $max : 1 );
		if ( $max <= $min ) {
			$max = $min + 1;
		}
		$x = function ( $i ) use ( $n, $w, $padl, $padr ) {
			return $padl + ( $w - $padl - $padr ) * $i / ( $n - 1 );
		};
		$y = function ( $v ) use ( $min, $max, $h, $padt, $padb, $invert ) {
			$t = ( $v - $min ) / ( $max - $min );
			if ( ! $invert ) {
				$t = 1 - $t;
			}
			return $padt + ( $h - $padt - $padb ) * $t;
		};
		$svg  = '<svg class="ssai-chart" direction="ltr" style="direction:ltr" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr( $title ) . '">';
		// Recessive grid: 3 lines with labels.
		for ( $g = 0; $g <= 2; $g++ ) {
			$v    = $min + ( $max - $min ) * $g / 2;
			$yy   = $y( $v );
			$svg .= '<line x1="' . $padl . '" x2="' . ( $w - $padr ) . '" y1="' . $yy . '" y2="' . $yy . '" class="ssai-grid"/>';
			$svg .= '<text x="' . ( $padl - 6 ) . '" y="' . ( $yy + 4 ) . '" class="ssai-axis" text-anchor="end">' . esc_html( self::num( $v, $invert ? 0 : ( $max < 5 ? 1 : 0 ) ) ) . '</text>';
		}
		$path = '';
		foreach ( $points as $i => $p ) {
			$path .= ( $i ? 'L' : 'M' ) . round( $x( $i ), 1 ) . ' ' . round( $y( $p[1] ), 1 );
		}
		$svg .= '<path d="' . $path . '" fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
		// Hover targets (wider than the mark) with native tooltips.
		$step = ( $w - $padl - $padr ) / ( $n - 1 );
		foreach ( $points as $i => $p ) {
			$cx   = round( $x( $i ), 1 );
			$cy   = round( $y( $p[1] ), 1 );
			$svg .= '<g class="ssai-hit"><rect x="' . ( $cx - $step / 2 ) . '" y="0" width="' . $step . '" height="' . $h . '" fill="transparent"/>'
				. '<line x1="' . $cx . '" x2="' . $cx . '" y1="' . $padt . '" y2="' . ( $h - $padb ) . '" class="ssai-cross"/>'
				. '<circle cx="' . $cx . '" cy="' . $cy . '" r="4" fill="' . esc_attr( $color ) . '" stroke="#fff" stroke-width="2"/>'
				. '<title>' . esc_html( $p[0] . ' — ' . $title . ': ' . self::num( $p[1], $dec ) ) . '</title></g>';
		}
		// First / last date labels.
		$svg .= '<text x="' . $padl . '" y="' . ( $h - 6 ) . '" class="ssai-axis" text-anchor="start">' . esc_html( $points[0][0] ) . '</text>';
		$svg .= '<text x="' . ( $w - $padr ) . '" y="' . ( $h - 6 ) . '" class="ssai-axis" text-anchor="end">' . esc_html( $points[ $n - 1 ][0] ) . '</text>';
		return $svg . '</svg>';
	}

	/* ------------------------------------------------------------ Report page */

	public static function page_report() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			echo '<div class="wrap"><p>صفحه پیدا نشد یا دسترسی ندارید.</p></div>';
			return;
		}
		$force = isset( $_GET['refresh'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'ssai_refresh_' . $post_id );

		$r    = SSAI_Analyzer::rules( $post_id, true );
		$d    = $r['data'];
		$url  = get_permalink( $post );
		$edit = SSAI_Elementor::is_built( $post_id ) ? SSAI_Elementor::edit_url( $post_id ) : ( SSAI_Avada::is_built( $post_id ) ? get_edit_post_link( $post_id, 'raw' ) : get_edit_post_link( $post_id, 'raw' ) . '#ssai-metabox' );
		$ai   = get_post_meta( $post_id, SSAI_Analyzer::META_AI, true );

		$errs  = 0;
		$warns = 0;
		$by_g  = array();
		foreach ( $r['items'] as $it ) {
			$by_g[ $it['group'] ][ $it['status'] ] = ( isset( $by_g[ $it['group'] ][ $it['status'] ] ) ? $by_g[ $it['group'] ][ $it['status'] ] : 0 ) + 1;
			if ( 'error' === $it['status'] ) {
				$errs++;
			} elseif ( 'warning' === $it['status'] ) {
				$warns++;
			}
		}
		$cls = $r['score'] >= 80 ? 'good' : ( $r['score'] >= 50 ? 'warning' : 'error' );
		?>
		<div class="wrap ssai ssai-report" dir="rtl">
			<hr class="wp-header-end">
			<?php
			$notice = get_transient( 'ssai_gsc_notice_' . get_current_user_id() );
			if ( $notice ) {
				delete_transient( 'ssai_gsc_notice_' . get_current_user_id() );
				echo '<div class="notice notice-' . esc_attr( $notice['type'] ) . '"><p>' . esc_html( $notice['msg'] ) . '</p></div>';
			}
			?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-pages' ) ); ?>">→ بازگشت به بررسی صفحه‌ها</a></p>
			<div class="ssai-rep-head">
				<span class="ssai-badge ssai-<?php echo esc_attr( $cls ); ?> ssai-big"><?php echo (int) $r['score']; ?></span>
				<div>
					<h1><?php echo esc_html( $d['title'] ? $d['title'] : '(بدون عنوان)' ); ?></h1>
					<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" dir="ltr" class="ssai-rep-url"><?php echo esc_html( urldecode( $url ) ); ?></a>
				</div>
				<div class="ssai-rep-actions">
					<a class="button button-primary" href="<?php echo esc_url( $edit ); ?>">ویرایش و اصلاح سئو<?php echo SSAI_Elementor::is_built( $post_id ) ? ' (المنتور)' : ''; ?></a>
					<?php if ( SSAI_GSC::connected() ) : ?>
						<a class="button" href="<?php echo esc_url( self::url( $post_id, array( 'refresh' => 1, '_wpnonce' => wp_create_nonce( 'ssai_refresh_' . $post_id ) ) ) ); ?>">به‌روزرسانی داده‌های گوگل</a>
					<?php endif; ?>
				</div>
			</div>

			<div class="ssai-cards">
				<section class="ssai-card">
					<h2>اطلاعات سئو</h2>
					<table class="ssai-kv">
						<tr><th>کلمه کلیدی اصلی</th><td><?php echo $d['keywords'] ? '<span class="ssai-chip ssai-chip-main">★ ' . esc_html( $d['keywords'][0] ) . '</span>' : '<em>تعیین نشده</em>'; ?></td></tr>
						<tr><th>کلمات فرعی</th><td><?php
						$sec = array_slice( $d['keywords'], 1 );
						echo $sec ? implode( ' ', array_map( function ( $k ) { return '<span class="ssai-chip">' . esc_html( $k ) . '</span>'; }, $sec ) ) : '—'; // phpcs:ignore
						?></td></tr>
						<tr><th>عنوان سئو</th><td><?php echo esc_html( $d['seo_title'] ); ?> <span class="description">(<?php echo (int) mb_strlen( $d['seo_title'] ); ?> کاراکتر)</span></td></tr>
						<tr><th>توضیحات متا</th><td><?php echo $d['meta_description'] ? esc_html( $d['meta_description'] ) . ' <span class="description">(' . (int) mb_strlen( $d['meta_description'] ) . ' کاراکتر)</span>' : '<em>ندارد</em>'; ?></td></tr>
						<tr><th>تعداد کلمات</th><td><?php echo esc_html( self::num( $d['words'] ) ); ?></td></tr>
						<tr><th>لینک‌ها</th><td>داخلی: <?php echo (int) $d['internal_links']; ?> — خارجی: <?php echo (int) $d['external_links']; ?></td></tr>
						<tr><th>تصاویر</th><td><?php echo count( $d['images'] ); ?></td></tr>
						<tr><th>ویرایشگر</th><td><?php echo 'elementor' === $d['builder'] ? 'المنتور' : 'وردپرس'; ?></td></tr>
					</table>
				</section>

				<section class="ssai-card">
					<h2>نتیجه بررسی‌ها <span class="description">(<?php echo (int) $errs; ?> خطا، <?php echo (int) $warns; ?> هشدار)</span></h2>
					<ul class="ssai-gsum">
						<?php foreach ( $r['groups'] as $g => $label ) : $c = isset( $by_g[ $g ] ) ? $by_g[ $g ] : array(); ?>
							<li><strong><?php echo esc_html( $label ); ?></strong>
								<?php if ( ! empty( $c['error'] ) ) : ?><span class="ssai-gbadge ssai-gbadge-err"><?php echo (int) $c['error']; ?> × خطا</span><?php endif; ?>
								<?php if ( ! empty( $c['warning'] ) ) : ?><span class="ssai-gbadge ssai-gbadge-warn"><?php echo (int) $c['warning']; ?> هشدار</span><?php endif; ?>
								<?php if ( empty( $c['error'] ) && empty( $c['warning'] ) ) : ?><span class="ssai-gbadge ssai-gbadge-ok">✓ خوب</span><?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<ul class="ssai-list">
						<?php
						$shown = 0;
						foreach ( $r['items'] as $it ) {
							if ( 'error' !== $it['status'] || $shown >= 6 || in_array( $it['id'], array( 'canonical', 'viewport' ), true ) ) {
								continue;
							}
							$shown++;
							echo '<li class="ssai-error"><span class="ssai-dot"></span>' . esc_html( $it['message'] ) . '</li>';
						}
						?>
					</ul>
					<?php
					// Technical head checks (canonical, mobile viewport) with their one-click fixes.
					$tech = array_filter(
						$r['items'],
						function ( $it ) {
							return in_array( $it['id'], array( 'canonical', 'viewport' ), true );
						}
					);
					if ( $tech ) :
						?>
						<h3 class="ssai-tech-h">بررسی فنی صفحه</h3>
						<ul class="ssai-list ssai-tech">
						<?php foreach ( $tech as $it ) : ?>
							<li class="ssai-<?php echo esc_attr( $it['status'] ); ?>"><span class="ssai-dot"></span><div class="ssai-li-body">
								<div><?php echo esc_html( $it['message'] ); ?></div>
								<?php if ( ! empty( $it['hint'] ) ) : ?><div class="ssai-hint"><?php echo esc_html( $it['hint'] ); ?></div><?php endif; ?>
								<?php if ( ! empty( $it['action'] ) && SSAI_Applier::can( $post_id ) ) : ?>
									<button type="button" class="ssai-fix-btn ssai-tech-fix" data-post="<?php echo esc_attr( $post_id ); ?>" data-kind="<?php echo esc_attr( $it['action']['kind'] ); ?>" data-confirm="<?php echo esc_attr( $it['action']['confirm'] ); ?>">🔧 <?php echo esc_html( $it['action']['label'] ); ?></button>
								<?php endif; ?>
							</div></li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>
					<?php if ( is_array( $ai ) && ! empty( $ai['summary'] ) ) : ?>
						<p class="ssai-ai-sum"><strong>خلاصه تحلیل هوش مصنوعی:</strong> <?php echo esc_html( $ai['summary'] ); ?></p>
					<?php endif; ?>
				</section>
			</div>

			<?php self::render_health( $post_id, $url ); ?>

			<?php self::render_gsc( $post_id, $url, $d, $force ); ?>

			<?php SSAI_Schema_Builder::render( $post_id ); ?>

			<section class="ssai-card" id="ssai-adv">
				<h2>تنظیمات پیشرفته سئو</h2>
				<?php if ( isset( $_GET['saved'] ) && 'adv' === $_GET['saved'] ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
					<p class="ssai-msg ssai-msg-ok">ذخیره شد.</p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ssai_adv_save"><input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>">
					<?php wp_nonce_field( 'ssai_adv_' . $post_id, 'ssai_adv_nonce' ); ?>
					<?php SSAI_SEO::adv_fields( $post_id ); ?>
					<?php submit_button( 'ذخیره', 'secondary' ); ?>
				</form>
			</section>
		</div>
		<?php
	}

	private static function render_gsc( $post_id, $url, $d, $force ) {
		$api   = SSAI_GSC::connected();
		$links = SSAI_GSC::console_links( $url );
		$rep   = null;
		$insp  = null;
		$err   = null;
		if ( $api ) {
			$rep  = SSAI_GSC::page_report( $url, $force );
			$insp = SSAI_GSC::inspect( $url, $force );
			if ( is_wp_error( $rep ) ) {
				$err = $rep;
				$rep = null;
			}
		}
		if ( ! $rep ) {
			$rep = SSAI_GSC_Import::page_report( $post_id, $url );
		}
		if ( ! $rep ) {
			echo '<section class="ssai-card"><h2>رتبه و وضعیت در گوگل</h2>';
			if ( $err ) {
				echo '<div class="ssai-msg ssai-msg-error">' . esc_html( $err->get_error_message() ) . '</div>';
			}
			echo '<p>برای دیدن کلیک، نمایش و جایگاه کلمات کلیدی، فایل خروجی سرچ کنسول را وارد کنید' . ( $api ? '' : ' یا افزونه را به سرچ کنسول وصل کنید' ) . '.</p>';
			self::import_form( $post_id, $url, true );
			echo '</section>';
			return;
		}
		$imported = 0 === strpos( (string) ( isset( $rep['source'] ) ? $rep['source'] : '' ), 'import' );
		$sitewide = isset( $rep['source'] ) && 'import-site' === $rep['source'];

		if ( ! $api || ! is_array( $insp ) ) {
			echo '<section class="ssai-card"><h2>وضعیت ایندکس در گوگل</h2>';
			if ( is_wp_error( $insp ) ) {
				echo '<div class="ssai-msg ssai-msg-error">' . esc_html( $insp->get_error_message() ) . '</div>';
			}
			echo '<p>وضعیت ایندکس در فایل خروجی سرچ کنسول نمی‌آید. آن را مستقیم در سرچ کنسول ببینید (آخرین خزش، canonical، درخواست ایندکس):</p>';
			echo '<a class="button" data-ssai-copy="' . esc_attr( $links['url'] ) . '" href="' . esc_url( $links['inspect'] ) . '" target="_blank" rel="noopener">کپی آدرس و باز کردن سرچ کنسول</a> <code dir="ltr" class="ssai-copy-url">' . esc_html( $links['url'] ) . '</code>';
			echo '<p class="description">آدرس صفحه کپی می‌شود؛ در سرچ کنسول آن را در کادر بالای صفحه («بررسی هر نشانی اینترنتی» / Inspect any URL) با Ctrl+V بچسبانید و Enter بزنید. برای دیدن وضعیت ایندکس همین‌جا، <a href="' . esc_url( admin_url( 'admin.php?page=smart-seo-ai-gsc' ) ) . '">سرچ کنسول را از طریق API وصل کنید</a>.</p></section>';
			$insp = null;
		}

		// ---- Index status (API only).
		if ( $insp ) {
		echo '<section class="ssai-card"><h2>وضعیت در سرچ کنسول</h2>';
		{
			$verdicts = array(
				'PASS'    => array( 'good', '✓ در گوگل ایندکس شده است' ),
				'PARTIAL' => array( 'warning', '! ایندکس شده، با هشدار' ),
				'NEUTRAL' => array( 'warning', '– در گوگل ایندکس نشده است' ),
				'FAIL'    => array( 'error', '✕ مشکل ایندکس' ),
			);
			$v = isset( $verdicts[ $insp['verdict'] ] ) ? $verdicts[ $insp['verdict'] ] : array( 'warning', $insp['verdict'] ? $insp['verdict'] : 'نامشخص' );
			echo '<p class="ssai-verdict ssai-verdict-' . esc_attr( $v[0] ) . '">' . esc_html( $v[1] ) . '</p>';
			echo '<table class="ssai-kv">';
			$rows = array(
				'وضعیت پوشش'            => $insp['coverage'],
				'آخرین خزش گوگل'        => $insp['last_crawl'] ? wp_date( 'Y/m/d H:i', strtotime( $insp['last_crawl'] ) ) : '',
				'خزش با'                => 'MOBILE' === $insp['crawled_as'] ? 'ربات موبایل' : ( 'DESKTOP' === $insp['crawled_as'] ? 'ربات دسکتاپ' : $insp['crawled_as'] ),
				'robots.txt'            => 'ALLOWED' === $insp['robots'] ? 'مجاز' : ( 'DISALLOWED' === $insp['robots'] ? 'مسدود ✕' : $insp['robots'] ),
				'اجازه ایندکس'          => 'INDEXING_ALLOWED' === $insp['indexing'] ? 'مجاز' : $insp['indexing'],
				'دریافت صفحه'           => 'SUCCESSFUL' === $insp['fetch'] ? 'موفق' : $insp['fetch'],
				'Canonical انتخابی شما' => $insp['user_canon'],
				'Canonical انتخابی گوگل' => $insp['google_canon'],
			);
			foreach ( $rows as $k => $val ) {
				if ( '' !== (string) $val ) {
					echo '<tr><th>' . esc_html( $k ) . '</th><td dir="auto">' . esc_html( $val ) . '</td></tr>';
				}
			}
			if ( $insp['google_canon'] && $insp['user_canon'] && untrailingslashit( $insp['google_canon'] ) !== untrailingslashit( $insp['user_canon'] ) ) {
				echo '<tr><th></th><td><span class="ssai-err">گوگل آدرس دیگری را به عنوان نسخه اصلی (canonical) انتخاب کرده است.</span></td></tr>';
			}
			if ( $insp['rich'] ) {
				$rt = array();
				foreach ( $insp['rich'] as $ri ) {
					$rt[] = $ri['type'] . ( $ri['issues'] ? ' (' . $ri['issues'] . ' مشکل)' : ' ✓' );
				}
				echo '<tr><th>نتایج غنی (Rich Results)</th><td>' . esc_html( implode( '، ', $rt ) ) . '</td></tr>';
			}
			echo '</table>';
			echo '<p class="description">بررسی ' . esc_html( wp_date( 'Y/m/d H:i', $insp['time'] ) ) . ' — <a data-ssai-copy="' . esc_attr( $links['url'] ) . '" href="' . esc_url( $links['inspect'] ) . '" target="_blank" rel="noopener">کپی آدرس و باز کردن سرچ کنسول</a> (آدرس را در کادر بالای سرچ کنسول بچسبانید؛ برای «درخواست ایندکس» از همان‌جا اقدام کنید)</p>';
		}
		echo '</section>';
		}

		// ---- Performance.
		$t = $rep['totals'];
		$p = $rep['prev'];
		if ( $imported ) {
			$cmp = ! empty( $p['clicks'] ) || ! empty( $p['position'] ) ? 'مقایسه با دوره/خروجی قبلی' : 'برای دیدن تغییرات، خروجی را در حالت «Compare» بگیرید یا دفعه بعد دوباره وارد کنید';
			$hdr = 'از فایل خروجی' . ( $sitewide ? ' کل سایت' : ' این صفحه' ) . ( $rep['range'] ? '، ' . $rep['range'] : '' ) . '؛ ' . $cmp;
		} else {
			$hdr = $rep['range'][0] . ' تا ' . $rep['range'][1] . '، ۲۸ روز؛ مقایسه با ۲۸ روز قبل';
		}
		echo '<section class="ssai-card"><h2>عملکرد در جستجوی گوگل <span class="description">(' . esc_html( $hdr ) . ')</span></h2>';
		if ( $sitewide ) {
			echo '<div class="ssai-msg ssai-rep-cta">این اعداد از خروجی <strong>کل سایت</strong> است. برای نمودار روزانه، همه جستجوهای همین صفحه و جایگاه دقیق هر کلمه، خروجی <strong>همین صفحه</strong> را وارد کنید (یک بار؛ از آن به بعد هر بار که این گزارش را باز کنید نمایش داده می‌شود).</div>';
			self::import_form( $post_id, $url, true );
		}
		if ( $sitewide && ! $t['impressions'] ) {
			echo '<p>این صفحه در فایل خروجی کل سایت نیست (در آن بازه در گوگل نمایش نداشته، یا آدرسش با آدرس خروجی فرق دارد).</p>';
		}
		echo '<div class="ssai-tiles">';
		echo '<div class="ssai-tile"><span>کلیک</span><strong>' . esc_html( self::num( $t['clicks'] ) ) . '</strong>' . self::delta( $t['clicks'], $p['clicks'] ) . '</div>'; // phpcs:ignore
		echo '<div class="ssai-tile"><span>نمایش</span><strong>' . esc_html( self::num( $t['impressions'] ) ) . '</strong>' . self::delta( $t['impressions'], $p['impressions'] ) . '</div>'; // phpcs:ignore
		echo '<div class="ssai-tile"><span>نرخ کلیک (CTR)</span><strong>' . esc_html( self::num( $t['ctr'] * 100, 1 ) ) . '٪</strong>' . self::delta( $t['ctr'], $p['ctr'], false, true ) . '</div>'; // phpcs:ignore
		$lowp = $t['impressions'] > 0 && $t['impressions'] < self::LOW_DATA;
		echo '<div class="ssai-tile"><span>میانگین جایگاه صفحه</span><strong>' . ( $t['position'] ? ( $lowp ? '≈' : '' ) . esc_html( self::num( $t['position'], 1 ) ) : '—' ) . '</strong>' . ( $lowp ? '<span class="ssai-lowdata">فقط ' . esc_html( self::num( $t['impressions'] ) ) . ' نمایش؛ قابل اتکا نیست</span>' : self::delta( $t['position'], $p['position'], true ) ) . '</div>'; // phpcs:ignore
		echo '</div>';

		if ( count( $rep['devices'] ) ) {
			$names = array(
				'mobile'  => 'موبایل',
				'desktop' => 'دسکتاپ',
				'tablet'  => 'تبلت',
			);
			$parts = array();
			foreach ( $rep['devices'] as $k => $dv ) {
				$parts[] = ( isset( $names[ $k ] ) ? $names[ $k ] : $k ) . ': ' . self::num( $dv['clicks'] ) . ' کلیک، جایگاه ' . self::num( $dv['position'], 1 );
			}
			echo '<p class="description">' . esc_html( implode( ' | ', $parts ) ) . '</p>';
		}

		if ( count( $rep['days'] ) >= 2 ) {
			$clicks = array();
			$pos    = array();
			foreach ( $rep['days'] as $day ) {
				$lbl      = wp_date( 'm/d', strtotime( $day['date'] ) );
				$clicks[] = array( $lbl, $day['clicks'] );
				$pos[]    = array( $lbl, $day['position'] );
			}
			echo '<div class="ssai-charts">';
			echo '<figure><figcaption>کلیک روزانه</figcaption>' . self::chart( $clicks, 'کلیک', '#2271b1' ) . '</figure>'; // phpcs:ignore
			echo '<figure><figcaption>میانگین جایگاه روزانه <span class="description">(بالاتر = بهتر؛ ۱ یعنی اول گوگل)</span></figcaption>' . self::chart( $pos, 'جایگاه', '#8a4b00', true, 1 ) . '</figure>'; // phpcs:ignore
			echo '</div>';
			echo '<details><summary>جدول داده روزانه</summary><table class="widefat striped"><thead><tr><th>تاریخ</th><th>کلیک</th><th>نمایش</th><th>CTR</th><th>جایگاه</th></tr></thead><tbody>';
			foreach ( $rep['days'] as $day ) {
				echo '<tr><td dir="ltr">' . esc_html( $day['date'] ) . '</td><td>' . esc_html( self::num( $day['clicks'] ) ) . '</td><td>' . esc_html( self::num( $day['impressions'] ) ) . '</td><td>' . esc_html( self::num( $day['ctr'] * 100, 1 ) ) . '٪</td><td>' . esc_html( self::num( $day['position'], 1 ) ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
		} elseif ( 0 === $t['impressions'] ) {
			echo '<p>این صفحه در ۲۸ روز گذشته در نتایج گوگل نمایش داده نشده است (یا هنوز ایندکس نشده).</p>';
		}
		echo '<p class="description">' . ( $imported ? 'تاریخ وارد کردن فایل: ' : 'داده‌های سرچ کنسول حدود ۲ تا ۳ روز تأخیر دارند. آخرین دریافت: ' ) . esc_html( wp_date( 'Y/m/d H:i', $rep['time'] ) ) . ' — <a href="' . esc_url( $links['performance'] ) . '" target="_blank" rel="noopener">مشاهده در سرچ کنسول</a></p>';
		if ( $sitewide ) {
			echo '<p class="description">برای نمودار روزانه و همه جستجوهای همین صفحه، در سرچ کنسول روی همین صفحه فیلتر کنید (Page › URL is) و خروجی را از پایین همین گزارش وارد کنید.</p>';
		}
		echo '</section>';

		// ---- Focus keyword positions.
		$byq = array();
		foreach ( $rep['queries'] as $q ) {
			$byq[ SSAI_Content::norm( $q['query'] ) ] = $q;
		}
		echo '<section class="ssai-card"><h2>رتبه کلمات کلیدی این صفحه</h2>';
		if ( ! $d['keywords'] ) {
			echo '<p>کلمه کلیدی تعیین نشده است.</p>';
		} else {
			echo '<table class="widefat ssai-kwtable"><thead><tr><th>کلمه کلیدی</th><th>جایگاه</th><th>تغییر</th><th>کلیک</th><th>نمایش</th><th>توضیح</th></tr></thead><tbody>';
			foreach ( $d['keywords'] as $i => $k ) {
				$nk    = SSAI_Content::norm( $k );
				$match = isset( $byq[ $nk ] ) ? $byq[ $nk ] : null;
				$note  = '';
				if ( ! $match ) {
					// Closest query that contains the keyword.
					foreach ( $rep['queries'] as $q ) {
						if ( false !== mb_strpos( SSAI_Content::norm( $q['query'] ), $nk ) && ( ! $match || $q['impressions'] > $match['impressions'] ) ) {
							$match = $q;
						}
					}
					$note = $match ? 'عبارت دقیق دیده نشد؛ نزدیک‌ترین جستجو: «' . $match['query'] . '»' : 'در بازه این گزارش، برای این کلمه در نتایج گوگل دیده نشده (یا جستجویش آن‌قدر کم بوده که گوگل در خروجی نیاورده).';
				}
				echo '<tr><td>' . ( 0 === $i ? '★ ' : '' ) . esc_html( $k ) . '</td><td>' . self::pos_badge( $match ? $match['position'] : null, $match ? $match['impressions'] : null ) . '</td>'; // phpcs:ignore
				echo '<td>' . ( $match && $match['prev'] ? self::delta( $match['position'], $match['prev'], true ) : '—' ) . '</td>'; // phpcs:ignore
				echo '<td>' . ( $match ? esc_html( self::num( $match['clicks'] ) ) : '—' ) . '</td><td>' . ( $match ? esc_html( self::num( $match['impressions'] ) ) : '—' ) . '</td><td class="description">' . esc_html( $note ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<details class="ssai-why"><summary>چرا این جایگاه با چیزی که خودم در گوگل می‌بینم فرق دارد؟</summary><ul>'
			. '<li><strong>میانگین است، نه لحظه‌ای:</strong> عدد سرچ کنسول میانگین همه نمایش‌ها در کل بازه' . ( $rep['range'] ? ' (' . esc_html( is_array( $rep['range'] ) ? implode( ' تا ', $rep['range'] ) : $rep['range'] ) . ')' : '' ) . ' است. اگر رتبه در این مدت بالا و پایین رفته، میانگین با امروز فرق دارد. برای عدد نزدیک به امروز، خروجی «۷ روز اخیر» بگیرید.</li>'
			. '<li><strong>کشور و شهر جستجوکننده:</strong> نتایج گوگل برای کسی که از آلمان جستجو می‌کند با کاربر کرج یا تهران فرق دارد، و این عدد شامل جستجو از همه کشورهاست. در سرچ کنسول قبل از خروجی گرفتن فیلتر «Country › Iran» بزنید تا عدد مربوط به مشتریان واقعی را ببینید.</li>'
			. '<li><strong>شخصی‌سازی و دستگاه:</strong> سابقه جستجوی شما، ورود به حساب گوگل و موبایل یا دسکتاپ بودن روی ترتیب نتایج اثر دارد. برای بررسی دستی از پنجره ناشناس استفاده کنید.</li>'
			. '<li><strong>نحوه شمارش گوگل:</strong> بخش‌هایی مثل نقشه (Local Pack)، «سؤالات مرتبط» و تصاویر هم یک جایگاه حساب می‌شوند؛ پس «جایگاه ۳» ممکن است در صفحه اولین لینک آبی باشد.</li>'
			. ( $sitewide ? '<li><strong>خروجی کل سایت:</strong> این اعداد جایگاه <em>بهترین صفحه سایت</em> برای هر جستجوست، نه لزوماً همین صفحه. اگر صفحه دیگری از سایت برای این کلمه بالا آمده باشد، عدد آن صفحه این‌جا نمایش داده می‌شود. برای عدد خود این صفحه، خروجی همین صفحه را وارد کنید.</li>' : '' )
			. '<li><strong>داده کم:</strong> وقتی نمایش‌ها کم است (کمتر از ' . self::LOW_DATA . ')، میانگین با چند جستجوی اتفاقی ساخته شده و قابل اتکا نیست؛ این موارد با «≈» و «داده کم» مشخص شده‌اند. گوگل جستجوهای خیلی کم‌تکرار را هم به دلایل حریم خصوصی اصلاً در خروجی نمی‌آورد.</li>'
			. '</ul><p class="description">برای اطمینان از درست خوانده شدن فایل: همین عدد را در خود سرچ کنسول (Performance، همان بازه و فیلترها) ببینید؛ باید یکی باشد.</p></details>';
		echo '</section>';

		// ---- All queries + opportunities.
		echo '<section class="ssai-card"><h2>' . ( $sitewide ? 'جستجوهای مرتبط با کلمات کلیدی این صفحه (از خروجی کل سایت)' : 'جستجوهایی که این صفحه در گوگل نمایش داده شده' ) . ' <span class="description">(' . count( $rep['queries'] ) . ')</span></h2>';
		if ( ! $rep['queries'] ) {
			echo '<p>هنوز داده‌ای نیست.</p>';
		} else {
			$kwn = array_map( array( 'SSAI_Content', 'norm' ), $d['keywords'] );
			$can = SSAI_Applier::can( $post_id );
			echo '<table class="widefat striped ssai-kwtable"><thead><tr><th>جستجو</th><th>جایگاه</th><th>تغییر</th><th>کلیک</th><th>نمایش</th><th>CTR</th><th></th></tr></thead><tbody>';
			foreach ( $rep['queries'] as $qi => $q ) {
				$nq     = SSAI_Content::norm( $q['query'] );
				$is_kw  = in_array( $nq, $kwn, true );
				$oppo   = ! $is_kw && $q['position'] > 3 && $q['position'] <= 20 && $q['impressions'] >= 10;
				$action = '';
				if ( $is_kw ) {
					$action = '<span class="ssai-chip">کلمه کلیدی شما</span>';
				} elseif ( $can && count( $d['keywords'] ) < 5 ) {
					$action = '<button type="button" class="button button-small ssai-add-kw" data-kw="' . esc_attr( $q['query'] ) . '">افزودن به کلمات فرعی</button>';
				} elseif ( $can ) {
					$action = '<button type="button" class="button button-small" disabled title="این صفحه ۵ کلمه کلیدی دارد (حداکثر). برای افزودن، اول یکی از کلمات فعلی را در ویرایشگر حذف کنید.">افزودن (۵ کلمه پر است)</button>';
				}
				echo '<tr class="' . ( $oppo ? 'ssai-oppo' : '' ) . ( $qi >= 50 ? ' ssai-more' : '' ) . '"' . ( $qi >= 50 ? ' hidden' : '' ) . '><td>' . esc_html( $q['query'] ) . ( $oppo ? ' <span class="ssai-chip ssai-chip-oppo" title="جایگاه ۴ تا ۲۰ با نمایش خوب؛ با کمی بهبود محتوا می‌تواند به صفحه اول یا بالای آن برسد">فرصت</span>' : '' ) . '</td>';
				echo '<td>' . self::pos_badge( $q['position'], $q['impressions'] ) . '</td><td>' . ( $q['prev'] ? self::delta( $q['position'], $q['prev'], true ) : '<span class="description">جدید</span>' ) . '</td>'; // phpcs:ignore
				echo '<td>' . esc_html( self::num( $q['clicks'] ) ) . '</td><td>' . esc_html( self::num( $q['impressions'] ) ) . '</td><td>' . esc_html( self::num( $q['ctr'] * 100, 1 ) ) . '٪</td><td>' . $action . '</td></tr>'; // phpcs:ignore
			}
			echo '</tbody></table>';
			if ( count( $rep['queries'] ) > 50 ) {
				echo '<p><button type="button" class="button" onclick="document.querySelectorAll(\'.ssai-more\').forEach(function(r){r.hidden=false});this.remove();">نمایش همه ' . esc_html( self::num( count( $rep['queries'] ) ) ) . ' جستجو</button></p>';
			}
			echo '<p class="description">«فرصت» = جستجوهایی با جایگاه ۴ تا ۲۰ که نمایش خوبی دارند؛ اگر به موضوع صفحه مربوط‌اند، آن‌ها را به کلمات فرعی اضافه کنید تا هوش مصنوعی در بازنویسی به آن‌ها توجه کند. گوگل بعضی جستجوهای کم‌تکرار را به دلایل حریم خصوصی نشان نمی‌دهد.</p>';
			if ( $can ) {
				?>
				<script>
				document.addEventListener('click', function (e) {
					var b = e.target.closest('.ssai-add-kw');
					if (!b || !window.SSAI) return;
					var list = <?php echo wp_json_encode( $d['keywords'] ); ?>;
					list.push(b.dataset.kw);
					b.disabled = true; b.textContent = '…';
					var fd = new FormData();
					fd.append('action', 'ssai_apply'); fd.append('nonce', SSAI.nonce); fd.append('post_id', '<?php echo (int) $post_id; ?>');
					fd.append('field', 'focus_keyword'); fd.append('value', list.join(','));
					fetch(SSAI.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
						if (j && j.success) { location.reload(); } else { b.disabled = false; b.textContent = 'خطا: ' + ((j && j.data && j.data.message) || ''); }
					});
				});
				</script>
				<?php
			}
		}
		echo '</section>';

		if ( ( ! $api || $err || $imported ) && ! $sitewide ) {
			echo '<section class="ssai-card"><h2>به‌روزرسانی با فایل خروجی سرچ کنسول</h2>';
			self::import_form( $post_id, $url, false );
			echo '</section>';
		}
	}

	/** Speed (PageSpeed Insights, run from the browser) and security (Observatory) cards. */
	private static function render_health( $post_id, $url ) {
		wp_enqueue_script( 'ssai-report', SSAI_URL . 'assets/report.js', array( 'ssai-admin' ), SSAI_VERSION, true );
		$obs = get_option( SSAI_Health::OPT_OBS );
		wp_localize_script(
			'ssai-report',
			'SSAI_REPORT',
			array(
				'postId' => (int) $post_id,
				'url'    => $url,
				'psiKey' => (string) SSAI_Settings::get( 'psi_key' ),
				'psi'    => SSAI_Health::psi( $post_id ),
				'obs'    => is_array( $obs ) ? $obs : null,
			)
		);
		?>
		<section class="ssai-card">
			<h2>سرعت و تجربه کاربری (Google PageSpeed)</h2>
			<p class="description">سرعت صفحه و Core Web Vitals روی رتبه گوگل اثر دارند. بررسی از مرورگر شما به گوگل فرستاده می‌شود و نتیجه برای دفعه بعد ذخیره می‌شود.</p>
			<p>
				<button type="button" class="button button-primary" id="ssai-psi-run-mobile">بررسی موبایل</button>
				<button type="button" class="button" id="ssai-psi-run-desktop">بررسی دسکتاپ</button>
				<?php if ( ! SSAI_Settings::get( 'psi_key' ) && current_user_can( 'manage_options' ) ) : ?>
					<span class="description">— برای سهمیه بیشتر، <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-settings' ) ); ?>">کلید رایگان PageSpeed</a> وارد کنید.</span>
				<?php endif; ?>
			</p>
			<div id="ssai-psi-status" class="ssai-msg"></div>
			<div class="ssai-psi-cols">
				<div><h3>موبایل</h3><div id="ssai-psi-mobile"></div></div>
				<div><h3>دسکتاپ</h3><div id="ssai-psi-desktop"></div></div>
			</div>
		</section>
		<section class="ssai-card">
			<h2>امنیت سایت (MDN HTTP Observatory)</h2>
			<p class="description">بررسی هدرهای امنیتی و HTTPS کل دامنه توسط موزیلا؛ روی اعتماد مرورگرها و کاربران اثر دارد.</p>
			<div id="ssai-obs"></div>
			<p><button type="button" class="button" id="ssai-obs-run">بررسی امنیت</button></p>
		</section>
		<?php
	}

	/** Upload form for a Search Console export (optionally bound to one page). */
	public static function import_form( $post_id, $url, $open ) {
		$links = SSAI_GSC::console_links( $url ? $url : home_url( '/' ) );
		?>
		<details class="ssai-import" <?php echo $open ? 'open' : ''; ?>>
			<summary><?php echo $post_id ? 'وارد کردن خروجی سرچ کنسول برای این صفحه' : 'وارد کردن فایل خروجی سرچ کنسول'; ?></summary>
			<ol class="ssai-steps">
				<?php if ( $post_id ) : ?>
					<li><a href="<?php echo esc_url( $links['performance'] ); ?>" target="_blank" rel="noopener">گزارش Performance همین صفحه را در سرچ کنسول باز کنید</a> (اگر فیلتر صفحه اعمال نشد: «+ New › Page › Exact URL» و آدرس صفحه را بدهید).</li>
				<?php else : ?>
					<li>در <a href="https://search.google.com/search-console/performance/search-analytics" target="_blank" rel="noopener">سرچ کنسول › Performance</a> بازه زمانی را انتخاب کنید (مثلاً ۳ ماه اخیر) بدون فیلتر صفحه.</li>
				<?php endif; ?>
				<li>برای دیدن تغییرات: از بخش تاریخ، تب «Compare» را انتخاب کنید (اختیاری).</li>
				<li>بالای صفحه «Export» › «Download CSV» را بزنید و فایل ZIP را همین‌جا بارگذاری کنید (یا فایل‌های CSV داخل آن را).<?php echo $post_id ? '' : ' می‌توانید خروجی کل سایت و خروجی چند صفحه را با هم (چند فایل ZIP) انتخاب کنید.'; ?></li>
			</ol>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="ssai_gsc_import">
				<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>">
				<?php wp_nonce_field( 'ssai_gsc_import' ); ?>
				<input type="file" name="gsc_files[]" multiple accept=".zip,.csv" required>
				<button class="button button-primary">وارد کردن</button>
			</form>
			<p class="description">هیچ درخواستی از سرور سایت به گوگل فرستاده نمی‌شود؛ فقط فایلی که خودتان از سرچ کنسول گرفته‌اید خوانده می‌شود. خروجی قبلی برای مقایسه نگه داشته می‌شود.</p>
			<?php if ( $post_id && get_post_meta( $post_id, SSAI_GSC_Import::POSTMETA, true ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('داده واردشده این صفحه حذف شود؟');">
					<input type="hidden" name="action" value="ssai_gsc_import_clear">
					<input type="hidden" name="post_id" value="<?php echo (int) $post_id; ?>">
					<?php wp_nonce_field( 'ssai_gsc_import_clear_' . $post_id ); ?>
					<button class="button-link button-link-delete">حذف داده واردشده برای این صفحه</button>
				</form>
			<?php endif; ?>
		</details>
		<?php
	}

	/* ------------------------------------------------------------ Search Console settings page */

	public static function page_gsc() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o      = SSAI_GSC::get();
		$notice = get_transient( 'ssai_gsc_notice_' . get_current_user_id() );
		delete_transient( 'ssai_gsc_notice_' . get_current_user_id() );
		$sites = SSAI_GSC::has_credentials() ? SSAI_GSC::sites() : array();
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>اتصال به گوگل سرچ کنسول</h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( SSAI_GSC::connected() ) : ?>
				<div class="notice notice-success inline"><p>✓ وصل است — ویژگی: <code dir="ltr"><?php echo esc_html( $o['site'] ); ?></code> <?php echo $o['email'] ? '— حساب سرویس: <code dir="ltr">' . esc_html( $o['email'] ) . '</code>' : ''; ?></p></div>
			<?php endif; ?>
			<p>دسترسی فقط خواندنی است (<code>webmasters.readonly</code>)؛ افزونه چیزی را در سرچ کنسول تغییر نمی‌دهد. اطلاعات اتصال فقط در دیتابیس سایت شما ذخیره می‌شود.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="ssai_gsc_save">
				<?php wp_nonce_field( 'ssai_gsc_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th>روش اتصال</th><td>
						<label><input type="radio" name="mode" value="service" <?php checked( $o['mode'], 'service' ); ?>> حساب سرویس (Service Account) — پیشنهادی، بدون انقضا</label><br>
						<label><input type="radio" name="mode" value="oauth" <?php checked( $o['mode'], 'oauth' ); ?>> ورود با حساب گوگل (OAuth)</label>
					</td></tr>
				</table>

				<div class="ssai-gsc-mode" data-mode="service">
					<h2>حساب سرویس</h2>
					<ol class="ssai-steps">
						<li>در <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> یک پروژه بسازید و از بخش «APIs &amp; Services › Library»، <strong>Google Search Console API</strong> را فعال کنید.</li>
						<li>در «IAM &amp; Admin › Service Accounts» یک حساب سرویس بسازید، در تب «Keys» گزینه «Add key › JSON» را بزنید و فایل را دانلود کنید.</li>
						<li>در <a href="https://search.google.com/search-console" target="_blank" rel="noopener">سرچ کنسول</a> › Settings › Users and permissions › Add user، ایمیل حساب سرویس (…@….iam.gserviceaccount.com) را با دسترسی <strong>Restricted</strong> اضافه کنید.</li>
						<li>فایل JSON را این‌جا بارگذاری کنید و ذخیره کنید.</li>
					</ol>
					<p><input type="file" name="sa_file" accept=".json,application/json"></p>
					<p><textarea name="sa_json" rows="4" class="large-text code" dir="ltr" placeholder="<?php echo $o['sa_json'] ? esc_attr( 'کلید ذخیره شده است – برای تغییر، فایل یا متن جدید وارد کنید' ) : esc_attr( 'یا محتوای فایل JSON را این‌جا بچسبانید' ); ?>"></textarea></p>
				</div>

				<div class="ssai-gsc-mode" data-mode="oauth">
					<h2>ورود با حساب گوگل</h2>
					<ol class="ssai-steps">
						<li>در Google Cloud Console، <strong>Google Search Console API</strong> را فعال کنید و «OAuth consent screen» را تنظیم کنید (نوع External، ایمیل خودتان را در Test users اضافه کنید).</li>
						<li>در «Credentials › Create credentials › OAuth client ID» نوع <strong>Web application</strong> را انتخاب کنید و این آدرس را در «Authorized redirect URIs» بگذارید:<br><code dir="ltr"><?php echo esc_html( SSAI_GSC::redirect_uri() ); ?></code></li>
						<li>Client ID و Client Secret را این‌جا وارد کنید و «اتصال با گوگل» را بزنید.</li>
					</ol>
					<p class="description">توجه: تا وقتی برنامه در حالت «Testing» باشد، گوگل اتصال را هر ۷ روز باطل می‌کند و باید دوباره وصل شوید؛ برای اتصال دائمی برنامه را «Publish» کنید یا از حساب سرویس استفاده کنید.</p>
					<table class="form-table" role="presentation">
						<tr><th>Client ID</th><td><input type="text" name="client_id" class="large-text" dir="ltr" value="<?php echo esc_attr( $o['client_id'] ); ?>"></td></tr>
						<tr><th>Client Secret</th><td><input type="password" name="client_secret" class="regular-text" dir="ltr" autocomplete="new-password" placeholder="<?php echo $o['client_secret'] ? esc_attr( '•••••• ذخیره شده' ) : ''; ?>"></td></tr>
					</table>
					<p><button class="button button-primary" name="connect" value="1">اتصال با گوگل</button></p>
				</div>

				<?php if ( SSAI_GSC::has_credentials() ) : ?>
					<h2>ویژگی (Property) سایت</h2>
					<?php if ( is_wp_error( $sites ) ) : ?>
						<div class="notice notice-error inline"><p><?php echo esc_html( $sites->get_error_message() ); ?></p></div>
					<?php elseif ( ! $sites ) : ?>
						<p class="ssai-err">هیچ ویژگی‌ای در دسترس نیست. ایمیل حساب سرویس را در سرچ کنسول به کاربران اضافه کنید.</p>
					<?php else : ?>
						<select name="site">
							<?php foreach ( $sites as $s ) : ?>
								<option value="<?php echo esc_attr( $s['siteUrl'] ); ?>" <?php selected( $o['site'], $s['siteUrl'] ); ?>><?php echo esc_html( $s['siteUrl'] . ' (' . $s['permissionLevel'] . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				<?php endif; ?>
				<?php submit_button( 'ذخیره' ); ?>
			</form>

			<hr>
			<h2>بدون اتصال: وارد کردن فایل خروجی سرچ کنسول</h2>
			<p>اگر سرور سایت نمی‌تواند به گوگل وصل شود، خروجی گزارش Performance را از سرچ کنسول بگیرید و این‌جا وارد کنید. خروجی کل سایت، کلیک و جایگاه همه صفحه‌ها را در «بررسی سایت» نشان می‌دهد؛ برای جزئیات یک صفحه، خروجی همان صفحه را از گزارش آن صفحه وارد کنید.</p>
			<?php
			$si = SSAI_GSC_Import::site();
			if ( $si ) {
				echo '<p class="description">آخرین خروجی کل سایت: ' . esc_html( wp_date( 'Y/m/d H:i', $si['time'] ) ) . ' — ' . count( $si['pages'] ) . ' صفحه، ' . count( $si['queries'] ) . ' جستجو' . ( $si['range'] ? ' (' . esc_html( $si['range'] ) . ')' : '' ) . '</p>';
			}
			self::import_form( 0, '', true );
			?>

			<?php if ( SSAI_GSC::has_credentials() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="ssai_gsc_disconnect">
					<?php wp_nonce_field( 'ssai_gsc_disconnect' ); ?>
					<button class="button button-link-delete">قطع اتصال و حذف کلیدها</button>
				</form>
			<?php endif; ?>
			<script>
			(function () {
				var radios = document.querySelectorAll('input[name=mode]');
				function upd() {
					var m = document.querySelector('input[name=mode]:checked').value;
					document.querySelectorAll('.ssai-gsc-mode').forEach(function (d) { d.hidden = d.dataset.mode !== m; });
				}
				radios.forEach(function (r) { r.addEventListener('change', upd); });
				upd();
			})();
			</script>
		</div>
		<?php
	}
}
