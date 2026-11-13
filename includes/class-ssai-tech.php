<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-wide technical check: canonical and mobile viewport of every published page, read from the live
 * page in small batches (results kept, unchanged pages are not fetched again), with a bulk canonical fix.
 */
class SSAI_Tech {

	const OPT = 'ssai_tech';

	public static function init() {
		foreach ( array( 'ids', 'scan', 'fix' ) as $a ) {
			add_action( 'wp_ajax_ssai_techscan_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function data() {
		$d = get_option( self::OPT );
		return wp_parse_args( is_array( $d ) ? $d : array(), array( 'items' => array(), 'last' => 0 ) );
	}

	public static function canonical_labels() {
		return array(
			'ok'       => array( 'good', 'درست (خودارجاع)' ),
			'missing'  => array( 'error', 'ندارد' ),
			'multi'    => array( 'error', 'چند canonical متفاوت' ),
			'other'    => array( 'warning', 'به آدرس دیگر' ),
			'variant'  => array( 'warning', 'همین صفحه با آدرس متفاوت (http/www/نسبی)' ),
			'noindex'  => array( 'good', 'ندارد (صفحه noindex است)' ),
			'unknown'  => array( 'warning', 'صفحه باز نشد' ),
		);
	}

	public static function viewport_labels() {
		return array(
			'ok'      => array( 'good', 'درست' ),
			'missing' => array( 'error', 'ندارد' ),
			'nowidth' => array( 'error', 'بدون width=device-width' ),
			'multi'   => array( 'warning', 'تکراری' ),
			'zoom'    => array( 'warning', 'زوم بسته' ),
			'unknown' => array( 'warning', 'صفحه باز نشد' ),
		);
	}

	private static function norm( $u ) {
		$u = strtolower( preg_replace( '#^https?://(www\.)?#i', '', trim( (string) $u ) ) );
		return rawurldecode( untrailingslashit( preg_replace( '/#.*$/', '', $u ) ) );
	}

	/** Status codes for one page from its <head> data. */
	public static function classify( $post_id, $head ) {
		if ( ! is_array( $head ) ) {
			return array( 'c' => 'unknown', 'cv' => array(), 'v' => 'unknown', 'vv' => array() );
		}
		$self = get_permalink( $post_id );
		$can  = array_values( array_unique( $head['canonical'] ) );
		if ( ! $can ) {
			$c = $head['noindex'] ? 'noindex' : 'missing';
		} elseif ( count( $can ) > 1 ) {
			$c = 'multi';
		} elseif ( self::norm( $can[0] ) !== self::norm( $self ) ) {
			$c = 'other';
		} elseif ( rawurldecode( untrailingslashit( $can[0] ) ) !== rawurldecode( untrailingslashit( $self ) ) ) {
			$c = 'variant';
		} else {
			$c = 'ok';
		}
		$vp = $head['viewport'];
		if ( ! $vp ) {
			$v = 'missing';
		} elseif ( count( $vp ) > 1 ) {
			$v = 'multi';
		} elseif ( false === stripos( $vp[0], 'width=device-width' ) ) {
			$v = 'nowidth';
		} elseif ( preg_match( '/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?(\D|$)/i', $vp[0] ) ) {
			$v = 'zoom';
		} else {
			$v = 'ok';
		}
		return array( 'c' => $c, 'cv' => array_slice( $can, 0, 3 ), 'v' => $v, 'vv' => array_slice( $vp, 0, 2 ) );
	}

	private static function post_types() {
		return array_values( array_filter( SSAI_Admin::post_types(), 'post_type_exists' ) );
	}

	/** Check one page (fresh = ignore the cached fetch). */
	private static function check( $post_id, $fresh = false ) {
		if ( $fresh ) {
			delete_transient( 'ssai_live_' . $post_id );
		}
		$ph  = SSAI_Analyzer::page_h1s( $post_id, true );
		$row = self::classify( $post_id, is_array( $ph ) && isset( $ph['head'] ) ? $ph['head'] : null );
		$row['mod'] = (string) get_post_field( 'post_modified_gmt', $post_id );
		$row['t']   = time();
		return $row;
	}

	private static function guard() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
	}

	/** Pages to scan: new or changed since the last check (or all). */
	public static function ajax_ids() {
		self::guard();
		$all  = ! empty( $_POST['all'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$d    = self::data();
		$ids  = get_posts(
			array(
				'post_type'      => self::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 3000,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'has_password'   => false,
			)
		);
		$todo = array();
		foreach ( $ids as $id ) {
			$r = isset( $d['items'][ $id ] ) ? $d['items'][ $id ] : null;
			if ( $all || ! $r || $r['mod'] !== get_post_field( 'post_modified_gmt', $id ) || 'unknown' === $r['c'] ) {
				$todo[] = $id;
			}
		}
		// Forget pages that are no longer published.
		$d['items'] = array_intersect_key( $d['items'], array_flip( $ids ) );
		update_option( self::OPT, $d, false );
		wp_send_json_success( array( 'ids' => $todo, 'total' => count( $ids ) ) );
	}

	/** Check a batch of up to 5 pages. */
	public static function ajax_scan() {
		self::guard();
		$ids   = isset( $_POST['ids'] ) ? array_slice( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ), 0, 5 ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$fresh = ! empty( $_POST['fresh'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$d     = self::data();
		foreach ( $ids as $id ) {
			if ( 'publish' === get_post_status( $id ) && current_user_can( 'edit_post', $id ) ) {
				$d['items'][ $id ] = self::check( $id, $fresh );
			}
		}
		$d['last'] = time();
		update_option( self::OPT, $d, false );
		wp_send_json_success( array( 'done' => count( $ids ) ) );
	}

	/** Bulk self-referencing canonical (the user confirmed the explanation in the browser). */
	public static function ajax_fix() {
		self::guard();
		$ids = isset( $_POST['ids'] ) ? array_slice( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ), 0, 10 ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$d   = self::data();
		$ok  = 0;
		$err = array();
		foreach ( $ids as $id ) {
			if ( ! SSAI_Applier::can( $id ) ) {
				$err[] = get_the_title( $id ) . ': دسترسی ندارید';
				continue;
			}
			$force = isset( $d['items'][ $id ] ) && 'missing' === $d['items'][ $id ]['c'];
			$r     = SSAI_Applier::apply( $id, 'canonical', 'self', array( 'force' => $force ) );
			if ( is_wp_error( $r ) ) {
				$err[] = get_the_title( $id ) . ': ' . $r->get_error_message();
				continue;
			}
			SSAI_Applier::purge_cache( $id );
			$d['items'][ $id ] = self::check( $id, true );
			$ok++;
		}
		update_option( self::OPT, $d, false );
		wp_send_json_success( array( 'ok' => $ok, 'errors' => $err ) );
	}

	/** Counts for tiles and the dashboard. */
	public static function counts() {
		$d = self::data();
		$n = array( 'pages' => count( $d['items'] ), 'c' => array(), 'v' => array() );
		foreach ( $d['items'] as $r ) {
			$n['c'][ $r['c'] ] = ( isset( $n['c'][ $r['c'] ] ) ? $n['c'][ $r['c'] ] : 0 ) + 1;
			$n['v'][ $r['v'] ] = ( isset( $n['v'][ $r['v'] ] ) ? $n['v'][ $r['v'] ] : 0 ) + 1;
		}
		return $n;
	}

	/* ------------------------------------------------------------ Screen */

	public static function render() {
		wp_enqueue_script( 'ssai-tech', SSAI_URL . 'assets/tech.js', array( 'ssai-admin' ), SSAI_VERSION, true );
		$d    = self::data();
		$n    = self::counts();
		$cl   = self::canonical_labels();
		$vl   = self::viewport_labels();
		$only = ! isset( $_GET['all'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$c    = function ( $k ) use ( $n ) {
			return isset( $n['c'][ $k ] ) ? $n['c'][ $k ] : 0;
		};
		$v    = function ( $k ) use ( $n ) {
			return isset( $n['v'][ $k ] ) ? $n['v'][ $k ] : 0;
		};
		$vbad = $v( 'missing' ) + $v( 'nowidth' );
		?>
		<section class="ssai-card">
			<h2>بررسی فنی همه صفحه‌ها: canonical و viewport موبایل</h2>
			<p class="description">صفحه‌های منتشرشده یکی‌یکی (۵ تا در هر نوبت) باز و <code>&lt;head&gt;</code> آن‌ها بررسی می‌شود. نتیجه ذخیره می‌شود و دفعه بعد فقط صفحه‌های جدید یا تغییرکرده بررسی می‌شوند. وسط کار می‌توانید صفحه را ببندید و بعداً ادامه دهید.</p>
			<p>
				<button type="button" class="button button-primary" id="ssai-tech-run"><?php echo $d['items'] ? 'بررسی صفحه‌های جدید و تغییرکرده' : 'بررسی همه صفحه‌ها'; ?></button>
				<?php if ( $d['items'] ) : ?><button type="button" class="button" id="ssai-tech-all">بررسی دوباره همه</button><?php endif; ?>
				<span id="ssai-tech-progress" class="description"></span>
			</p>
			<?php if ( $d['last'] ) : ?>
				<p class="description"><?php echo esc_html( sprintf( '%s صفحه بررسی شده — آخرین بررسی: %s پیش', number_format_i18n( $n['pages'] ), human_time_diff( $d['last'] ) ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php if ( ! $d['items'] ) : ?>
			<?php return; ?>
		<?php endif; ?>
		<div class="ssai-tiles">
			<?php
			$tile = function ( $label, $num, $cls, $sub = '' ) {
				echo '<div class="ssai-tile ssai-tile-' . esc_attr( $cls ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $num ) ) . '</strong>' . ( $sub ? '<span class="description">' . esc_html( $sub ) . '</span>' : '' ) . '</div>';
			};
			$tile( 'بدون canonical', $c( 'missing' ), $c( 'missing' ) ? 'error' : 'good' );
			$tile( 'چند canonical', $c( 'multi' ), $c( 'multi' ) ? 'error' : 'good' );
			$tile( 'canonical به آدرس دیگر', $c( 'other' ), $c( 'other' ) ? 'warning' : 'good', 'شاید عمدی باشد' );
			$tile( 'canonical با آدرس متفاوت', $c( 'variant' ), $c( 'variant' ) ? 'warning' : 'good', 'http/www/نسبی' );
			$tile( 'viewport ندارد یا ناقص', $vbad, $vbad ? 'error' : 'good' );
			$tile( 'viewport تکراری / زوم بسته', $v( 'multi' ) + $v( 'zoom' ), $v( 'multi' ) + $v( 'zoom' ) ? 'warning' : 'good' );
			?>
		</div>
		<?php
		if ( $vbad ) {
			$all_bad = $vbad >= $n['pages'] - $v( 'unknown' );
			echo '<div class="notice notice-' . ( $all_bad ? 'error' : 'warning' ) . ' inline"><p>';
			if ( $all_bad && ! get_option( 'ssai_viewport' ) ) {
				echo esc_html( 'هیچ صفحه‌ای تگ viewport درست ندارد؛ یعنی قالب آن را نمی‌گذارد. ' );
				if ( current_user_can( 'manage_options' ) ) {
					echo '<button type="button" class="button button-primary ssai-tech-fix" data-post="0" data-kind="viewport" data-confirm="' . esc_attr( "تگ زیر از این به بعد در <head> همه صفحه‌های سایت اضافه می‌شود:\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n\nتنظیم سراسری است و در «تنظیمات سئو › عمومی» قابل خاموش کردن است. ادامه؟" ) . '">🔧 افزودن تگ viewport به همه صفحه‌ها</button> ';
					echo '<span class="description">بعد «بررسی دوباره همه» را بزنید.</span>';
				}
			} else {
				echo esc_html( sprintf( '%s صفحه viewport درست ندارد ولی بقیه دارند؛ این صفحه‌ها احتمالاً قالب صفحه یا صفحه‌ساز جداگانه‌ای دارند (مثلاً قالب «Canvas» یا صفحه فرود). تنظیمات قالب همان صفحه‌ها را بررسی کنید.', number_format_i18n( $vbad ) ) );
			}
			echo '</p></div>';
		}
		$rows = $d['items'];
		if ( $only ) {
			$rows = array_filter(
				$rows,
				function ( $r ) {
					return ! in_array( $r['c'], array( 'ok', 'noindex' ), true ) || 'ok' !== $r['v'];
				}
			);
		}
		$order = array( 'multi' => 0, 'missing' => 1, 'other' => 2, 'variant' => 3, 'unknown' => 4, 'noindex' => 5, 'ok' => 6 );
		uasort(
			$rows,
			function ( $a, $b ) use ( $order ) {
				return $order[ $a['c'] ] <=> $order[ $b['c'] ];
			}
		);
		?>
		<section class="ssai-card">
			<h2>صفحه‌ها</h2>
			<p>
				<a href="<?php echo esc_url( SSAI_Site::url( $only ? array( 'tab' => 'tech', 'all' => 1 ) : array( 'tab' => 'tech' ) ) ); ?>"><?php echo $only ? 'نمایش همه صفحه‌ها' : 'فقط صفحه‌های مشکل‌دار'; ?></a>
				&nbsp;|&nbsp;
				<button type="button" class="button button-primary" id="ssai-tech-bulk">🔧 تنظیم canonical خودارجاع برای تیک‌خورده‌ها</button>
				<span class="description">صفحه‌های «بدون canonical» و «آدرس متفاوت» از قبل تیک خورده‌اند؛ «به آدرس دیگر» را فقط اگر عمدی نیست تیک بزنید.</span>
			</p>
			<?php if ( ! $rows ) : ?>
				<p>✓ مشکلی پیدا نشد.</p>
			<?php else : ?>
			<table class="widefat striped ssai-tech-table">
				<thead><tr><td class="check-column"><input type="checkbox" id="ssai-tech-cball"></td><th>صفحه</th><th>canonical</th><th>viewport</th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $rows, 0, 500, true ) as $id => $r ) : ?>
					<?php
					$cs = isset( $cl[ $r['c'] ] ) ? $cl[ $r['c'] ] : $cl['unknown'];
					$vs = isset( $vl[ $r['v'] ] ) ? $vl[ $r['v'] ] : $vl['unknown'];
					$fixable = in_array( $r['c'], array( 'missing', 'other', 'variant' ), true );
					?>
					<tr data-id="<?php echo esc_attr( $id ); ?>">
						<th class="check-column"><?php if ( $fixable ) : ?><input type="checkbox" class="ssai-tech-cb" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $r['c'], array( 'missing', 'variant' ), true ) ); ?>><?php endif; ?></th>
						<td><a href="<?php echo esc_url( SSAI_Report::url( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ? get_the_title( $id ) : '(بدون عنوان)' ); ?></a>
							<div class="row-actions"><a href="<?php echo esc_url( get_permalink( $id ) ); ?>" target="_blank" rel="noopener">نمایش</a></div></td>
						<td><span class="ssai-tech-st ssai-<?php echo esc_attr( $cs[0] ); ?>"><?php echo esc_html( $cs[1] ); ?></span>
							<?php if ( $r['cv'] && 'ok' !== $r['c'] ) : ?><div class="description" dir="ltr"><?php echo esc_html( implode( ' | ', array_map( 'rawurldecode', $r['cv'] ) ) ); ?></div><?php endif; ?></td>
						<td><span class="ssai-tech-st ssai-<?php echo esc_attr( $vs[0] ); ?>"><?php echo esc_html( $vs[1] ); ?></span>
							<?php if ( $r['vv'] && 'ok' !== $r['v'] ) : ?><div class="description" dir="ltr"><?php echo esc_html( implode( ' | ', $r['vv'] ) ); ?></div><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</section>
		<?php
	}
}
