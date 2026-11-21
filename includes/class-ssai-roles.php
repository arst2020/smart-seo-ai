<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Role manager: the plugin checks its own capabilities (ssai_*). They are granted on the fly
 * (user_has_cap), nothing is written into the WordPress roles, so deactivating the plugin leaves
 * the site clean. A role that was never configured keeps the old behaviour (the WordPress
 * capability in self::caps()). Administrators always have everything, so nobody can lock
 * themselves out.
 */
class SSAI_Roles {

	const OPTION = 'ssai_roles';
	const SLUG   = 'smart-seo-ai-roles';

	/** cap => [ label, description, WordPress capability used when the role is not configured ]. */
	public static function caps() {
		return array(
			'ssai_pages'         => array( 'بررسی صفحه‌ها و گزارش صفحه', 'فهرست بررسی سئو، گزارش هر صفحه، جعبه سئو زیر ویرایشگر (فقط صفحه‌هایی که اجازه ویرایششان را دارد)', 'edit_posts' ),
			'ssai_site'          => array( 'داشبورد و نمای کلی سایت', 'دیدن داشبورد، نمای کلی سایت، بررسی فنی و Schema گروهی', 'edit_others_posts' ),
			'ssai_site_actions'  => array( 'اجرای بررسی کل سایت و تغییرات سراسری', 'اجرای بررسی کل سایت، AEO و llms.txt، ورود لینک‌ها', 'manage_options' ),
			'ssai_studio'        => array( 'تولید محتوا و طراح صفحه', 'استودیو محتوا، تقویم انتشار، طراحی صفحه با هوش مصنوعی', 'edit_others_posts' ),
			'ssai_reports'       => array( 'سرچ کنسول، Ahrefs و رتبه کلمات', 'گزارش‌ها و تنظیم اتصال سرچ کنسول، ورود Ahrefs، ردیابی رتبه', 'manage_options' ),
			'ssai_index'         => array( 'ارسال فوری برای ایندکس', 'ارسال صفحه‌ها به گوگل و IndexNow', 'edit_others_posts' ),
			'ssai_redirects'     => array( 'ریدایرکت و ۴۰۴', 'ساخت و حذف ریدایرکت، لاگ خطای ۴۰۴', 'manage_options' ),
			'ssai_seo_settings'  => array( 'تنظیمات سئو و سئوی محلی', 'قالب عنوان‌ها، نقشه سایت، Schema سایت، شعبه‌ها', 'manage_options' ),
			'ssai_settings'      => array( 'هوش مصنوعی و سرویس‌ها', 'کلیدهای API و تنظیمات افزونه', 'manage_options' ),
		);
	}

	public static function init() {
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 4 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_ssai_roles_save', array( __CLASS__, 'save' ) );
	}

	/** Roles that can be configured (administrator is fixed). */
	public static function roles() {
		$out = array();
		foreach ( wp_roles()->roles as $key => $r ) {
			if ( 'administrator' !== $key ) {
				$out[ $key ] = translate_user_role( $r['name'] );
			}
		}
		return $out;
	}

	/** Current settings: role => list of caps, only for configured roles. */
	public static function config() {
		$c = get_option( self::OPTION, array() );
		return is_array( $c ) ? $c : array();
	}

	/** Does this role have the cap (configured value or the WordPress fallback)? */
	public static function role_can( $role, $cap ) {
		if ( 'administrator' === $role ) {
			return true;
		}
		$c = self::config();
		if ( isset( $c[ $role ] ) && is_array( $c[ $role ] ) ) {
			return in_array( $cap, $c[ $role ], true );
		}
		$caps = self::caps();
		$r    = get_role( $role );
		return $r && isset( $caps[ $cap ] ) && $r->has_cap( $caps[ $cap ][2] );
	}

	public static function grant( $allcaps, $caps, $args, $user ) {
		$defs = self::caps();
		foreach ( (array) $caps as $cap ) {
			if ( ! isset( $defs[ $cap ] ) ) {
				continue;
			}
			$ok = is_multisite() && is_super_admin( $user->ID );
			foreach ( (array) $user->roles as $role ) {
				if ( self::role_can( $role, $cap ) ) {
					$ok = true;
					break;
				}
			}
			// Users with extra per-user capabilities (no matching role) fall back to WordPress.
			if ( ! $ok && ! empty( $allcaps[ $defs[ $cap ][2] ] ) && ! array_intersect( (array) $user->roles, array_keys( self::config() ) ) ) {
				$ok = true;
			}
			$allcaps[ $cap ] = $ok;
		}
		return $allcaps;
	}

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'نقش‌ها و دسترسی', 'نقش‌ها و دسترسی', 'manage_options', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function save() {
		check_admin_referer( 'ssai_roles' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		if ( ! empty( $_POST['reset'] ) ) {
			delete_option( self::OPTION );
		} else {
			$in   = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$defs = self::caps();
			$out  = array();
			foreach ( array_keys( self::roles() ) as $role ) {
				$list = isset( $in[ $role ] ) && is_array( $in[ $role ] ) ? array_map( 'sanitize_key', $in[ $role ] ) : array();
				$out[ $role ] = array_values( array_intersect( $list, array_keys( $defs ) ) );
			}
			update_option( self::OPTION, $out, false );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$defs  = self::caps();
		$roles = self::roles();
		$conf  = self::config();
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>نقش‌ها و دسترسی</h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>ذخیره شد.</p></div>
			<?php endif; ?>
			<p class="description">مشخص کنید هر نقش کاربری به کدام بخش افزونه دسترسی دارد. «مدیر کل» همیشه به همه‌چیز دسترسی دارد. تا وقتی ذخیره نکرده‌اید، هر نقش همان دسترسی پیش‌فرض را دارد (تیک‌های فعلی). این تنظیم چیزی در نقش‌های وردپرس ذخیره نمی‌کند؛ با غیرفعال کردن افزونه اثری باقی نمی‌ماند.</p>
			<p class="description">صفحه «نقش‌ها و دسترسی» و «به‌روزرسانی افزونه» همیشه فقط برای مدیر کل است. اعمال تغییرات روی هر صفحه علاوه بر این، به اجازه ویرایش همان صفحه هم نیاز دارد.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ssai_roles_save">
				<?php wp_nonce_field( 'ssai_roles' ); ?>
				<table class="widefat striped ssai-roles">
					<thead><tr><th>بخش</th><th>مدیر کل</th>
						<?php foreach ( $roles as $key => $name ) : ?>
							<th><?php echo esc_html( $name ); ?><?php echo isset( $conf[ $key ] ) ? '' : ' <small title="هنوز تنظیم نشده؛ پیش‌فرض">(پیش‌فرض)</small>'; ?></th>
						<?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( $defs as $cap => $d ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $d[0] ); ?></strong><br><span class="description"><?php echo esc_html( $d[1] ); ?></span></td>
							<td><input type="checkbox" checked disabled></td>
							<?php foreach ( $roles as $key => $name ) : ?>
								<td><input type="checkbox" name="caps[<?php echo esc_attr( $key ); ?>][]" value="<?php echo esc_attr( $cap ); ?>" <?php checked( self::role_can( $key, $cap ) ); ?>></td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<button class="button button-primary">ذخیره</button>
					<button class="button" name="reset" value="1" onclick="return confirm('همه نقش‌ها به دسترسی پیش‌فرض برگردند؟');">بازگشت به پیش‌فرض</button>
				</p>
			</form>
		</div>
		<?php
	}
}
