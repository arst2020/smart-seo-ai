<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "SEO settings" screen: modules, titles & meta, business / schema, social, sitemap,
 * robots.txt & verification, and the Rank Math switch-over assistant.
 */
class SSAI_SEO_Admin {

	const SLUG = 'smart-seo-ai-seo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 17 );
		add_action( 'admin_post_ssai_seo_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_notices', array( __CLASS__, 'takeover_notice' ) );
	}

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'تنظیمات سئو', 'تنظیمات سئو', 'ssai_seo_settings', self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function url( $tab = '' ) {
		return admin_url( 'admin.php?page=' . self::SLUG . ( $tab ? '&tab=' . $tab : '' ) );
	}

	/** One-time hint on the plugin screens while Rank Math is still active. */
	public static function takeover_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'smart-seo-ai' ) || false !== strpos( (string) $screen->id, self::SLUG ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( SSAI_Migrate::rm_active() ) {
			echo '<div class="notice notice-info"><p>Rank Math فعال است؛ عنوان، متا، نقشه سایت، Schema و ریدایرکت‌ها هنوز توسط Rank Math انجام می‌شود. برای جابه‌جایی امن به <a href="' . esc_url( self::url( 'migrate' ) ) . '">تنظیمات سئو › انتقال از Rank Math</a> بروید.</p></div>';
		}
	}

	private static function tabs() {
		return array(
			'general' => 'عمومی',
			'titles'  => 'عنوان‌ها و متا',
			'entity'  => 'کسب‌وکار و Schema',
			'social'  => 'شبکه‌های اجتماعی',
			'sitemap' => 'نقشه سایت',
			'robots'  => 'robots.txt و تأیید مالکیت',
			'migrate' => 'انتقال از Rank Math',
		);
	}

	/* ================================================================ Save */

	public static function save() {
		check_admin_referer( 'ssai_seo_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$in  = isset( $_POST['ssai'] ) ? wp_unslash( $_POST['ssai'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised per field below.
		$o   = SSAI_SEO::get();
		$txt = function ( $k, $def = '' ) use ( $in ) {
			return isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : $def;
		};
		switch ( $tab ) {
			case 'general':
				foreach ( array_keys( SSAI_SEO::defaults()['modules'] ) as $m ) {
					$o['modules'][ $m ] = empty( $in['modules'][ $m ] ) ? 0 : 1;
				}
				$o['sep']                 = '' !== $txt( 'sep' ) ? $txt( 'sep' ) : '-';
				$o['attachment_redirect'] = empty( $in['attachment_redirect'] ) ? 0 : 1;
				$old_strip                = ! empty( $o['strip_category'] );
				$o['strip_category']      = empty( $in['strip_category'] ) ? 0 : 1;
				$o['noindex_empty']       = empty( $in['noindex_empty'] ) ? 0 : 1;
				update_option( 'ssai_viewport', empty( $in['viewport'] ) ? 0 : 1, true );
				$o['crumb']               = array(
					'sep'       => isset( $in['crumb']['sep'] ) ? sanitize_text_field( $in['crumb']['sep'] ) : '»',
					'home'      => isset( $in['crumb']['home'] ) ? sanitize_text_field( $in['crumb']['home'] ) : '',
					'show_home' => empty( $in['crumb']['show_home'] ) ? 0 : 1,
				);
				if ( $old_strip !== (bool) $o['strip_category'] ) {
					SSAI_Permalinks::schedule_flush();
				}
				break;
			case 'titles':
				$o['home_title'] = $txt( 'home_title' );
				$o['home_desc']  = isset( $in['home_desc'] ) ? sanitize_textarea_field( $in['home_desc'] ) : '';
				$tpl             = array();
				foreach ( isset( $in['tpl'] ) && is_array( $in['tpl'] ) ? $in['tpl'] : array() as $k => $row ) {
					$k         = sanitize_key( $k );
					$tpl[ $k ] = array(
						'title'   => isset( $row['title'] ) ? sanitize_text_field( $row['title'] ) : '',
						'desc'    => isset( $row['desc'] ) ? sanitize_textarea_field( $row['desc'] ) : '',
						'noindex' => empty( $row['noindex'] ) ? 0 : 1,
					);
				}
				$o['tpl']       = $tpl;
				$o['schema_pt'] = array();
				foreach ( isset( $in['schema_pt'] ) && is_array( $in['schema_pt'] ) ? $in['schema_pt'] : array() as $pt => $v ) {
					$o['schema_pt'][ sanitize_key( $pt ) ] = sanitize_text_field( $v );
				}
				SSAI_Sitemap::bump();
				break;
			case 'entity':
				$e = array();
				foreach ( array_keys( SSAI_SEO::defaults()['entity'] ) as $k ) {
					$e[ $k ] = isset( $in['entity'][ $k ] ) ? ( 'hours' === $k ? sanitize_textarea_field( $in['entity'][ $k ] ) : sanitize_text_field( $in['entity'][ $k ] ) ) : '';
				}
				$e['type']   = in_array( $e['type'], array( 'Organization', 'LocalBusiness', 'Person' ), true ) ? $e['type'] : 'Organization';
				$e['logo']   = esc_url_raw( $e['logo'] );
				$e['email']  = sanitize_email( $e['email'] );
				$e['subtype'] = preg_replace( '/[^A-Za-z]/', '', $e['subtype'] );
				$o['entity'] = $e;
				break;
			case 'social':
				$o['facebook'] = esc_url_raw( $txt( 'facebook' ) );
				$o['twitter']  = ltrim( $txt( 'twitter' ), '@' );
				$o['same_as']  = isset( $in['same_as'] ) ? implode( "\n", array_filter( array_map( 'esc_url_raw', preg_split( '/\s+/', (string) $in['same_as'] ) ) ) ) : '';
				$o['og_image'] = esc_url_raw( $txt( 'og_image' ) );
				break;
			case 'sitemap':
				$o['sitemap']['per_page'] = max( 50, min( 5000, (int) $txt( 'per_page', 1000 ) ) );
				$o['sitemap']['images']   = empty( $in['images'] ) ? 0 : 1;
				$o['sitemap']['exclude']  = preg_replace( '/[^0-9,]/', '', str_replace( '،', ',', $txt( 'exclude' ) ) );
				$off                      = array();
				foreach ( SSAI_Admin::post_types() as $pt ) {
					if ( empty( $in['on'][ 'pt_' . $pt ] ) ) {
						$off[] = 'pt_' . $pt;
					}
				}
				foreach ( array_keys( SSAI_SEO::taxonomies() ) as $tax ) {
					if ( empty( $in['on'][ 'tax_' . $tax ] ) ) {
						$off[] = 'tax_' . $tax;
					}
				}
				$o['sitemap']['off'] = $off;
				$o['sitemap']['video']     = empty( $in['video'] ) ? 0 : 1;
				$o['sitemap']['news']      = empty( $in['news'] ) ? 0 : 1;
				$o['sitemap']['news_pt']   = isset( $in['news_pt'] ) && is_array( $in['news_pt'] ) ? array_values( array_intersect( array_map( 'sanitize_key', $in['news_pt'] ), SSAI_Admin::post_types() ) ) : array();
				$o['sitemap']['news_name'] = $txt( 'news_name' );
				SSAI_Sitemap::bump();
				break;
			case 'robots':
				$o['robots_txt'] = isset( $in['robots_txt'] ) ? sanitize_textarea_field( $in['robots_txt'] ) : '';
				foreach ( array_keys( SSAI_SEO::defaults()['verify'] ) as $k ) {
					$v = isset( $in['verify'][ $k ] ) ? trim( (string) $in['verify'][ $k ] ) : '';
					// Accept the whole <meta …> tag too.
					if ( preg_match( '/content=["\']([^"\']+)["\']/i', $v, $m ) ) {
						$v = $m[1];
					}
					$o['verify'][ $k ] = sanitize_text_field( $v );
				}
				break;
		}
		SSAI_SEO::save( $o );
		set_transient(
			'ssai_seo_notice_' . get_current_user_id(),
			array(
				'msg'  => 'ذخیره شد.',
				'type' => 'success',
			),
			60
		);
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	/* ================================================================ Page */

	public static function page() {
		$tabs   = self::tabs();
		$tab    = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$notice = get_transient( 'ssai_seo_notice_' . get_current_user_id() );
		delete_transient( 'ssai_seo_notice_' . get_current_user_id() );
		?>
		<div class="wrap ssai ssai-report ssai-seo-admin" dir="rtl">
			<h1>تنظیمات سئو</h1>
			<hr class="wp-header-end">
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['msg'] ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== SSAI_Content::seo_plugin() && 'migrate' !== $tab ) : ?>
				<div class="notice notice-warning"><p>تا وقتی <?php echo esc_html( self::plugin_name() ); ?> فعال است، این تنظیمات در سایت اعمال نمی‌شوند (تا خروجی تکراری ساخته نشود). می‌توانید همه را از الان آماده کنید. <a href="<?php echo esc_url( self::url( 'migrate' ) ); ?>">دستیار انتقال</a></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $k ) ); ?>" class="nav-tab <?php echo $tab === $k ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'migrate' === $tab ) {
				self::render_migrate();
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ssai-card">';
				echo '<input type="hidden" name="action" value="ssai_seo_save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
				wp_nonce_field( 'ssai_seo_save' );
				call_user_func( array( __CLASS__, 'render_' . $tab ) );
				submit_button( 'ذخیره تنظیمات' );
				echo '</form>';
			}
			?>
		</div>
		<?php
	}

	private static function plugin_name() {
		$p = SSAI_Content::seo_plugin();
		return 'rankmath' === $p ? 'Rank Math' : ( 'yoast' === $p ? 'Yoast SEO' : ( 'aioseo' === $p ? 'All in One SEO' : '' ) );
	}

	private static function check( $name, $on, $label, $help = '' ) {
		echo '<label class="ssai-switch"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( $on, true, false ) . '> ' . esc_html( $label ) . '</label>' . ( $help ? '<p class="description">' . wp_kses_post( $help ) . '</p>' : '' );
	}

	private static function render_general() {
		$o    = SSAI_SEO::get();
		$mods = array(
			'head'          => array( 'عنوان، توضیحات متا، Canonical، robots و Open Graph', 'خروجی بخش head همه صفحه‌ها.' ),
			'schema'        => array( 'داده ساختاریافته (Schema)', 'کسب‌وکار، سایت، صفحه، Breadcrumb، مقاله، خدمت و محصول ووکامرس.' ),
			'sitemap'       => array( 'نقشه سایت XML', 'آدرس: <a href="' . esc_url( SSAI_Sitemap::url() ) . '" target="_blank" rel="noopener" dir="ltr">' . esc_html( SSAI_Sitemap::url() ) . '</a>' ),
			'redirects'     => array( 'ریدایرکت‌ها', '' ),
			'auto_redirect' => array( 'ریدایرکت خودکار وقتی نامک (URL) یک صفحه منتشرشده عوض می‌شود', 'از ۴۰۴ شدن لینک‌های قدیمی جلوگیری می‌کند.' ),
			'monitor404'    => array( 'ثبت آدرس‌های ۴۰۴', '' ),
			'breadcrumbs'   => array( 'Breadcrumb', 'برای نمایش در صفحه، شورت‌کد <code>[ssai_breadcrumbs]</code> را در قالب یا ویجت «کد کوتاه» المنتور بگذارید. شورت‌کد و تابع Rank Math (<code>[rank_math_breadcrumb]</code>) هم بعد از غیرفعال شدن آن کار می‌کنند.' ),
			'robots_txt'    => array( 'مدیریت robots.txt', '' ),
		);
		?>
		<h2>بخش‌های فعال</h2>
		<?php foreach ( $mods as $k => $m ) : ?>
			<div class="ssai-mod"><?php self::check( 'ssai[modules][' . $k . ']', ! empty( $o['modules'][ $k ] ), $m[0], $m[1] ); ?></div>
		<?php endforeach; ?>
		<h2>عمومی</h2>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="ssai-sep">جداکننده عنوان</label></th><td><input type="text" id="ssai-sep" name="ssai[sep]" value="<?php echo esc_attr( $o['sep'] ); ?>" class="small-text"> <span class="description">مثل - یا | ؛ در الگوی عنوان با <code>%sep%</code> می‌آید.</span></td></tr>
			<tr><th scope="row">پیوست‌ها</th><td><?php self::check( 'ssai[attachment_redirect]', ! empty( $o['attachment_redirect'] ), 'صفحه پیوست (هر تصویر) به نوشته اصلی یا خود فایل ریدایرکت شود', 'وردپرس برای هر تصویر یک صفحه کم‌ارزش می‌سازد که بهتر است ایندکس نشود.' ); ?></td></tr>
			<tr><th scope="row">دسته‌ها</th><td>
				<?php self::check( 'ssai[strip_category]', ! empty( $o['strip_category'] ), 'حذف /category/ از آدرس دسته‌ها', 'اگر در Rank Math «Strip Category Base» روشن بوده، این را هم روشن بگذارید تا آدرس دسته‌ها عوض نشود. آدرس‌های قدیمی با /category/ خودکار ریدایرکت می‌شوند.' ); ?>
				<?php self::check( 'ssai[noindex_empty]', ! empty( $o['noindex_empty'] ), 'دسته‌ها و برچسب‌های خالی noindex شوند' ); ?>
			</td></tr>
			<tr><th scope="row">موبایل</th><td><?php self::check( 'ssai[viewport]', (bool) get_option( 'ssai_viewport' ), 'افزودن تگ viewport موبایل به همه صفحه‌ها', 'فقط اگر قالب این تگ را ندارد روشن کنید (بررسی سئو هر صفحه نشان می‌دهد)؛ اگر قالب خودش دارد، روشن بودن این گزینه تگ تکراری می‌سازد.' ); ?></td></tr>
			<tr><th scope="row">Breadcrumb</th><td>
				<label>جداکننده <input type="text" name="ssai[crumb][sep]" value="<?php echo esc_attr( $o['crumb']['sep'] ); ?>" class="small-text"></label>
				<label>نام صفحه اصلی <input type="text" name="ssai[crumb][home]" value="<?php echo esc_attr( $o['crumb']['home'] ); ?>" placeholder="<?php echo esc_attr( __( 'Home' ) ); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain ?>"></label>
				<?php self::check( 'ssai[crumb][show_home]', ! empty( $o['crumb']['show_home'] ), 'نمایش صفحه اصلی در ابتدای مسیر' ); ?>
			</td></tr>
			<?php if ( SSAI_SEO::multilingual() ) : ?>
			<tr><th scope="row">چندزبانه</th><td><p><?php echo esc_html( SSAI_SEO::multilingual() ); ?> فعال است. برچسب‌های hreflang را خود <?php echo esc_html( SSAI_SEO::multilingual() ); ?> می‌سازد؛ این افزونه Canonical و og:locale هر زبان، نقشه سایت همه زبان‌ها و ترجمه عنوان صفحه اصلی و نام کسب‌وکار (از بخش ترجمه رشته‌های <?php echo esc_html( SSAI_SEO::multilingual() ); ?>) را پشتیبانی می‌کند.</p></td></tr>
			<?php endif; ?>
		</table>
		<?php
	}

	private static function vars_help() {
		echo '<details class="ssai-vars"><summary>متغیرهای قابل استفاده</summary><p dir="ltr" style="text-align:right"><code>%title%</code> عنوان · <code>%sitename%</code> نام سایت · <code>%sitedesc%</code> معرفی کوتاه سایت · <code>%sep%</code> جداکننده · <code>%page%</code> شماره صفحه (در صفحه ۲ به بعد) · <code>%excerpt%</code> خلاصه خودکار · <code>%term%</code> نام دسته · <code>%term_description%</code> توضیح دسته · <code>%category%</code> دسته اصلی · <code>%name%</code> نویسنده · <code>%date%</code> تاریخ · <code>%focuskw%</code> کلمه کلیدی · <code>%search_query%</code> عبارت جستجو · <code>%pt_plural%</code> نام نوع محتوا · <code>%currentyear%</code> سال جاری</p><p class="description">متغیرهای Rank Math و Yoast (مثل %%title%%) هم شناخته می‌شوند.</p></details>';
	}

	private static function tpl_row( $key, $label, $show_desc = true, $schema_pt = '' ) {
		$t = SSAI_SEO::tpl( $key );
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo '<input type="text" class="large-text" name="ssai[tpl][' . esc_attr( $key ) . '][title]" value="' . esc_attr( $t['title'] ) . '" aria-label="' . esc_attr( 'عنوان ' . $label ) . '">';
		if ( $show_desc ) {
			echo '<textarea class="large-text" rows="1" name="ssai[tpl][' . esc_attr( $key ) . '][desc]" placeholder="توضیحات پیش‌فرض" aria-label="' . esc_attr( 'توضیحات ' . $label ) . '">' . esc_textarea( $t['desc'] ) . '</textarea>';
		} else {
			echo '<input type="hidden" name="ssai[tpl][' . esc_attr( $key ) . '][desc]" value="' . esc_attr( $t['desc'] ) . '">';
		}
		echo '<label><input type="checkbox" name="ssai[tpl][' . esc_attr( $key ) . '][noindex]" value="1" ' . checked( ! empty( $t['noindex'] ), true, false ) . '> noindex (در گوگل نیاید)</label>';
		if ( $schema_pt ) {
			$cur = SSAI_SEO::default_schema( $schema_pt );
			echo ' &nbsp; <label>Schema پیش‌فرض <select name="ssai[schema_pt][' . esc_attr( $schema_pt ) . ']">';
			foreach ( SSAI_SEO::schema_types( $schema_pt ) as $k => $l ) {
				if ( 'auto' === $k ) {
					continue;
				}
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( $cur, $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></label>';
		}
		echo '</td></tr>';
	}

	private static function render_titles() {
		$o = SSAI_SEO::get();
		self::vars_help();
		?>
		<p class="description">این الگوها فقط برای صفحه‌هایی استفاده می‌شوند که عنوان یا توضیحات سئوی اختصاصی ندارند.</p>
		<table class="form-table ssai-tpl" role="presentation">
			<?php if ( 'posts' === get_option( 'show_on_front' ) ) : ?>
				<tr><th scope="row">صفحه اصلی</th><td><input type="text" class="large-text" name="ssai[home_title]" value="<?php echo esc_attr( $o['home_title'] ); ?>"><textarea class="large-text" rows="2" name="ssai[home_desc]"><?php echo esc_textarea( $o['home_desc'] ); ?></textarea></td></tr>
			<?php else : ?>
				<tr><th scope="row">صفحه اصلی</th><td><p>صفحه اصلی سایت یک برگه است؛ عنوان و توضیحاتش را در <a href="<?php echo esc_url( SSAI_Report::url( (int) get_option( 'page_on_front' ) ) ); ?>">گزارش همان برگه</a> یا ویرایشگر آن تنظیم کنید.</p><input type="hidden" name="ssai[home_title]" value="<?php echo esc_attr( $o['home_title'] ); ?>"><input type="hidden" name="ssai[home_desc]" value="<?php echo esc_attr( $o['home_desc'] ); ?>"></td></tr>
			<?php endif; ?>
			<tr><th colspan="2"><h3>انواع محتوا</h3></th></tr>
			<?php
			foreach ( SSAI_Admin::post_types() as $pt ) {
				$obj = get_post_type_object( $pt );
				self::tpl_row( 'pt_' . $pt, $obj ? $obj->labels->name : $pt, true, $pt );
			}
			?>
			<tr><th colspan="2"><h3>دسته‌ها و برچسب‌ها</h3></th></tr>
			<?php
			foreach ( SSAI_SEO::taxonomies() as $tax => $obj ) {
				self::tpl_row( 'tax_' . $tax, $obj->labels->name );
			}
			?>
			<tr><th colspan="2"><h3>آرشیوهای دیگر</h3></th></tr>
			<?php
			self::tpl_row( 'author', 'آرشیو نویسنده' );
			self::tpl_row( 'date', 'آرشیو تاریخ', false );
			self::tpl_row( 'pta', 'آرشیو انواع محتوا', false );
			self::tpl_row( 'search', 'نتایج جستجو', false );
			self::tpl_row( '404', 'صفحه ۴۰۴', false );
			?>
		</table>
		<?php
	}

	private static function render_entity() {
		$e  = SSAI_SEO::get( 'entity' );
		$st = array(
			''               => '— نوع دقیق —',
			'Dentist'        => 'دندانپزشکی (Dentist)',
			'MedicalClinic'  => 'کلینیک پزشکی (MedicalClinic)',
			'Physician'      => 'مطب پزشک (Physician)',
			'Store'          => 'فروشگاه (Store)',
			'OnlineStore'    => 'فروشگاه اینترنتی (OnlineStore)',
			'Restaurant'     => 'رستوران (Restaurant)',
			'BeautySalon'    => 'سالن زیبایی (BeautySalon)',
			'LegalService'   => 'خدمات حقوقی (LegalService)',
			'RealEstateAgent' => 'املاک (RealEstateAgent)',
			'AutoRepair'     => 'تعمیرگاه خودرو (AutoRepair)',
			'HomeAndConstructionBusiness' => 'ساختمان و تأسیسات',
			'EducationalOrganization' => 'آموزشی (EducationalOrganization)',
			'Corporation'    => 'شرکت (Corporation)',
			'ProfessionalService' => 'خدمات تخصصی (ProfessionalService)',
		);
		$f  = function ( $k, $label, $attrs = '' ) use ( $e ) {
			echo '<tr><th scope="row"><label for="ssai-e-' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="ssai-e-' . esc_attr( $k ) . '" name="ssai[entity][' . esc_attr( $k ) . ']" value="' . esc_attr( $e[ $k ] ) . '" ' . $attrs . '></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		?>
		<p class="description">این اطلاعات در Schema همه صفحه‌ها می‌آید و به گوگل، نقشه گوگل و موتورهای هوش مصنوعی می‌گوید این سایت متعلق به کیست. باید با اطلاعات Google Business Profile یکی باشد.</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row">نوع</th><td>
				<select name="ssai[entity][type]">
					<option value="Organization" <?php selected( $e['type'], 'Organization' ); ?>>شرکت / سازمان (بدون مکان مراجعه)</option>
					<option value="LocalBusiness" <?php selected( $e['type'], 'LocalBusiness' ); ?>>کسب‌وکار محلی (مطب، فروشگاه، دفتر با آدرس)</option>
					<option value="Person" <?php selected( $e['type'], 'Person' ); ?>>شخص (سایت شخصی)</option>
				</select>
				<select name="ssai[entity][subtype]">
					<?php foreach ( $st as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $e['subtype'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
					<?php if ( '' !== $e['subtype'] && ! isset( $st[ $e['subtype'] ] ) ) : ?>
						<option value="<?php echo esc_attr( $e['subtype'] ); ?>" selected><?php echo esc_html( $e['subtype'] ); ?></option>
					<?php endif; ?>
				</select>
			</td></tr>
			<?php
			$f( 'name', 'نام', 'placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"' );
			$f( 'logo', 'آدرس لوگو', 'dir="ltr" placeholder="لوگوی قالب"' );
			$f( 'phone', 'تلفن', 'dir="ltr" placeholder="+98-26-1234-5678"' );
			$f( 'email', 'ایمیل', 'dir="ltr"' );
			$f( 'street', 'آدرس (خیابان و پلاک)' );
			$f( 'city', 'شهر' );
			$f( 'region', 'استان' );
			$f( 'postal', 'کد پستی', 'dir="ltr"' );
			$f( 'country', 'کشور (کد دوحرفی)', 'dir="ltr" placeholder="IR" maxlength="2" class="small-text"' );
			$f( 'lat', 'عرض جغرافیایی', 'dir="ltr" placeholder="35.8327"' );
			$f( 'lng', 'طول جغرافیایی', 'dir="ltr" placeholder="50.9915"' );
			$f( 'price_range', 'محدوده قیمت', 'placeholder="$$"' );
			?>
			<tr><th scope="row"><label for="ssai-e-hours">ساعت کاری</label></th><td><textarea id="ssai-e-hours" class="large-text" rows="4" name="ssai[entity][hours]" placeholder="شنبه تا چهارشنبه 09:00-20:00&#10;پنجشنبه 09:00-14:00"><?php echo esc_textarea( $e['hours'] ); ?></textarea>
				<p class="description">هر خط یک بازه؛ فارسی یا انگلیسی (Saturday-Wednesday 09:00-20:00).</p>
				<?php
				$h = SSAI_Schema::hours( $e['hours'] );
				if ( $h ) {
					echo '<p class="description">برداشت افزونه: ';
					foreach ( $h as $x ) {
						echo esc_html( implode( '، ', $x['dayOfWeek'] ) . ' ' . $x['opens'] . '–' . $x['closes'] ) . ' | ';
					}
					echo '</p>';
				}
				?>
			</td></tr>
		</table>
		<p class="description">برای «Schema هر صفحه» (مقاله، خدمت، محصول) نوع پیش‌فرض را در تب «عنوان‌ها و متا» و برای هر صفحه در «تنظیمات پیشرفته سئو» زیر ویرایشگر تعیین کنید.</p>
		<?php
	}

	private static function render_social() {
		$o = SSAI_SEO::get();
		?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="ssai-ogimg">تصویر پیش‌فرض اشتراک‌گذاری</label></th><td><input type="url" id="ssai-ogimg" class="large-text" dir="ltr" name="ssai[og_image]" value="<?php echo esc_attr( $o['og_image'] ); ?>"><p class="description">برای صفحه‌هایی که تصویر ندارند (۱۲۰۰×۶۳۰).</p></td></tr>
			<tr><th scope="row"><label for="ssai-fb">صفحه فیسبوک</label></th><td><input type="url" id="ssai-fb" class="large-text" dir="ltr" name="ssai[facebook]" value="<?php echo esc_attr( $o['facebook'] ); ?>"></td></tr>
			<tr><th scope="row"><label for="ssai-tw">نام کاربری X (توییتر)</label></th><td><input type="text" id="ssai-tw" dir="ltr" name="ssai[twitter]" value="<?php echo esc_attr( $o['twitter'] ); ?>" placeholder="@name"></td></tr>
			<tr><th scope="row"><label for="ssai-same">پروفایل‌های دیگر</label></th><td><textarea id="ssai-same" class="large-text" rows="5" dir="ltr" name="ssai[same_as]" placeholder="https://instagram.com/…&#10;https://www.linkedin.com/company/…&#10;https://maps.google.com/?cid=…&#10;https://neshan.org/maps/places/…"><?php echo esc_textarea( $o['same_as'] ); ?></textarea><p class="description">هر خط یک آدرس: اینستاگرام، لینکدین، آپارات، گوگل‌مپ، نشان، بلد… (در Schema به‌عنوان sameAs).</p></td></tr>
		</table>
		<?php
	}

	private static function render_sitemap() {
		$s   = SSAI_SEO::get( 'sitemap' );
		$off = (array) $s['off'];
		?>
		<p>نشانی نقشه سایت: <a href="<?php echo esc_url( SSAI_Sitemap::url() ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( SSAI_Sitemap::url() ); ?></a> — همان نشانی Rank Math است؛ لازم نیست در سرچ کنسول دوباره ثبت شود.</p>
		<table class="form-table" role="presentation">
			<tr><th scope="row">انواع محتوا</th><td>
				<?php
				foreach ( SSAI_Admin::post_types() as $pt ) {
					$obj = get_post_type_object( $pt );
					self::check( 'ssai[on][pt_' . $pt . ']', ! in_array( 'pt_' . $pt, $off, true ), $obj ? $obj->labels->name : $pt );
				}
				?>
			</td></tr>
			<tr><th scope="row">دسته‌ها و برچسب‌ها</th><td>
				<?php
				foreach ( SSAI_SEO::taxonomies() as $tax => $obj ) {
					self::check( 'ssai[on][tax_' . $tax . ']', ! in_array( 'tax_' . $tax, $off, true ), $obj->labels->name );
				}
				?>
				<p class="description">برچسب‌ها معمولاً صفحه‌های کم‌ارزش و تکراری می‌سازند؛ بهتر است خاموش باشند.</p>
			</td></tr>
			<tr><th scope="row">تصاویر</th><td><?php self::check( 'ssai[images]', ! empty( $s['images'] ), 'تصاویر هر صفحه هم در نقشه سایت بیاید (برای جستجوی تصویری گوگل)' ); ?></td></tr>
			<tr><th scope="row">نقشه سایت ویدیو</th><td><?php self::check( 'ssai[video]', ! empty( $s['video'] ), 'ویدیوهای یوتیوب، ویمئو، آپارات و فایل‌های ویدیویی داخل صفحه‌ها در video-sitemap.xml بیاید' ); ?>
				<p class="description"><a href="<?php echo esc_url( SSAI_Sitemap::url( 'video-sitemap.xml' ) ); ?>" target="_blank" rel="noopener" dir="ltr">video-sitemap.xml</a> — فقط وقتی ساخته می‌شود که صفحه‌ای ویدیو داشته باشد. برای ویمئو و آپارات، تصویر شاخص صفحه به‌عنوان پیش‌نمایش استفاده می‌شود.</p></td></tr>
			<tr><th scope="row">نقشه سایت اخبار</th><td><?php self::check( 'ssai[news]', ! empty( $s['news'] ), 'news-sitemap.xml برای Google News ساخته شود (مطالب ۴۸ ساعت اخیر)' ); ?>
				<p>
				<?php
				foreach ( SSAI_Admin::post_types() as $pt ) {
					$obj = get_post_type_object( $pt );
					echo '<label style="margin-left:12px"><input type="checkbox" name="ssai[news_pt][]" value="' . esc_attr( $pt ) . '" ' . checked( in_array( $pt, (array) $s['news_pt'], true ), true, false ) . '> ' . esc_html( $obj ? $obj->labels->name : $pt ) . '</label>';
				}
				?>
				</p>
				<p><label>نام نشریه <input type="text" name="ssai[news_name]" value="<?php echo esc_attr( $s['news_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></label></p>
				<p class="description">فقط برای سایت‌های خبری لازم است؛ نام نشریه باید با نام ثبت‌شده در Google Publisher Center یکی باشد.</p></td></tr>
			<tr><th scope="row"><label for="ssai-per">تعداد در هر فایل</label></th><td><input type="number" id="ssai-per" name="ssai[per_page]" value="<?php echo esc_attr( $s['per_page'] ); ?>" min="50" max="5000" class="small-text"></td></tr>
			<tr><th scope="row"><label for="ssai-exc">حذف این شناسه‌ها</label></th><td><input type="text" id="ssai-exc" dir="ltr" class="regular-text" name="ssai[exclude]" value="<?php echo esc_attr( $s['exclude'] ); ?>" placeholder="12, 45"><p class="description">صفحه‌های noindex و رمزدار خودکار حذف می‌شوند.</p></td></tr>
		</table>
		<?php
	}

	private static function render_robots() {
		$o    = SSAI_SEO::get();
		$file = file_exists( ABSPATH . 'robots.txt' );
		?>
		<h2>robots.txt</h2>
		<?php if ( $file ) : ?>
			<div class="notice notice-warning inline"><p>یک فایل واقعی robots.txt در پوشه اصلی سایت وجود دارد؛ سرور همان را نشان می‌دهد و تنظیم این‌جا اثری ندارد. برای استفاده از این بخش، آن فایل را حذف یا تغییر نام دهید.</p></div>
		<?php endif; ?>
		<textarea name="ssai[robots_txt]" class="large-text code" rows="10" dir="ltr" placeholder="<?php echo esc_attr( "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php" ); ?>"><?php echo esc_textarea( $o['robots_txt'] ); ?></textarea>
		<p class="description">خالی = robots.txt پیش‌فرض وردپرس. خط Sitemap خودکار اضافه می‌شود. مراقب باشید <code>Disallow: /</code> کل سایت را از گوگل حذف می‌کند. <a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener">مشاهده robots.txt فعلی</a></p>
		<h2>کدهای تأیید مالکیت</h2>
		<table class="form-table" role="presentation">
			<?php foreach ( array( 'google' => 'Google Search Console', 'bing' => 'Bing Webmaster', 'yandex' => 'Yandex', 'baidu' => 'Baidu', 'pinterest' => 'Pinterest' ) as $k => $l ) : ?>
				<tr><th scope="row"><label for="ssai-v-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></label></th><td><input type="text" id="ssai-v-<?php echo esc_attr( $k ); ?>" class="regular-text" dir="ltr" name="ssai[verify][<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( $o['verify'][ $k ] ); ?>"></td></tr>
			<?php endforeach; ?>
		</table>
		<p class="description">کد یا کل تگ &lt;meta&gt; را بچسبانید. این کدها فقط در صفحه اصلی می‌آیند.</p>
		<?php
	}

	/* ================================================================ Migration assistant */

	private static function mbutton( $do, $label, $primary = false, $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ssai-inline-form"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ')"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="ssai_migrate"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'ssai_migrate' );
		echo '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function render_migrate() {
		$active  = SSAI_Migrate::rm_active();
		$files   = SSAI_Migrate::rm_files();
		$report  = get_option( SSAI_Migrate::OPT_REPORT );
		$cmp     = get_option( SSAI_Migrate::OPT_COMPARE );
		$block   = SSAI_Migrate::blockers();
		$bposts  = SSAI_Migrate::block_posts();
		$can     = current_user_can( 'activate_plugins' );
		?>
		<section class="ssai-card">
			<h2>وضعیت</h2>
			<ul class="ssai-list">
				<li class="ssai-<?php echo $active ? 'warning' : 'good'; ?>"><span class="ssai-dot"></span><div><?php echo $active ? 'Rank Math فعال است و خروجی‌های سئو را می‌سازد؛ این افزونه فعلاً فقط تحلیل و پیشنهاد می‌دهد.' : ( $files ? 'Rank Math نصب ولی غیرفعال است؛ Smart SEO AI همه خروجی‌های سئو را می‌سازد.' : ( SSAI_Migrate::rm_has_data() ? 'Rank Math حذف شده ولی اطلاعاتش در پایگاه داده هست.' : 'Rank Math نصب نیست.' ) ); ?></div></li>
				<?php if ( 'yoast' === SSAI_Content::seo_plugin() || 'aioseo' === SSAI_Content::seo_plugin() ) : ?>
					<li class="ssai-warning"><span class="ssai-dot"></span><div><?php echo esc_html( self::plugin_name() ); ?> فعال است؛ تا وقتی فعال است این افزونه خروجی سئو نمی‌سازد. عنوان، توضیحات و کلمه کلیدی Yoast خودکار خوانده می‌شوند.</div></li>
				<?php endif; ?>
			</ul>
		</section>

		<section class="ssai-card">
			<h2>۱. انتقال اطلاعات</h2>
			<p>تنظیمات عنوان‌ها، اطلاعات کسب‌وکار، شبکه‌های اجتماعی، کدهای تأیید، robots.txt، نقشه سایت، و برای هر صفحه و دسته: عنوان، توضیحات، کلمات کلیدی، noindex، canonical، تصویر شبکه‌های اجتماعی، دسته اصلی و نوع Schema؛ به‌علاوه همه ریدایرکت‌ها. <strong>اطلاعات Rank Math پاک یا تغییر داده نمی‌شود</strong> و اطلاعاتی که قبلاً در این افزونه وارد کرده‌اید رونویسی نمی‌شود.</p>
			<?php
			if ( $can ) {
				self::mbutton( 'import', $report ? 'انتقال دوباره' : 'انتقال اطلاعات از Rank Math', ! $report );
			}
			if ( $report ) {
				echo '<p class="description">آخرین انتقال: ' . esc_html( wp_date( 'Y/m/d H:i', $report['time'] ) ) . ' — ' . esc_html( implode( '، ', $report['items'] ) ) . ' — ' . esc_html( sprintf( '%d صفحه، %d دسته، %d ریدایرکت', $report['posts'], $report['terms'], $report['redir'] ) ) . '</p>';
			}
			?>
		</section>

		<section class="ssai-card">
			<h2>۲. بلوک‌ها و شورت‌کدهای Rank Math در محتوا</h2>
			<?php if ( ! $bposts ) : ?>
				<p>✓ هیچ صفحه‌ای از بلوک یا شورت‌کد Rank Math استفاده نمی‌کند.</p>
			<?php else : ?>
				<p>این صفحه‌ها از بلوک یا شورت‌کد Rank Math استفاده می‌کنند. بعد از غیرفعال شدن Rank Math، <strong>بلوک FAQ</strong> باید تبدیل شود (دکمه پایین)؛ شورت‌کد Breadcrumb خودکار کار می‌کند؛ بلوک «HowTo» و بقیه را دستی بررسی کنید.</p>
				<ul class="ssai-plist">
					<?php foreach ( array_slice( $bposts, 0, 30, true ) as $id => $kinds ) : ?>
						<li><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a> <span class="description" dir="ltr"><?php echo esc_html( implode( ', ', $kinds ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
				<?php
				if ( $can ) {
					self::mbutton( 'faq', 'تبدیل بلوک‌های FAQ به پرسش‌وپاسخ این افزونه', false, 'بلوک FAQ رنک‌مث در این صفحه‌ها با شورت‌کد [ssai_faq] جایگزین می‌شود (همان پرسش‌ها، با Schema). از تاریخچه هر صفحه قابل بازگشت است. ادامه؟' );
				}
				?>
			<?php endif; ?>
		</section>

		<section class="ssai-card">
			<h2>۳. مقایسه خروجی</h2>
			<p>چند صفحه نمونه از سایت باز می‌شود و عنوان، توضیحات، canonical، noindex، تصویر اشتراک‌گذاری و Schema فعلی (<?php echo $active ? 'ساخته Rank Math' : 'خروجی فعلی سایت'; ?>) با خروجی این افزونه مقایسه می‌شود. تفاوت‌های قرمز را قبل از جابه‌جایی بررسی کنید.</p>
			<?php
			if ( $can ) {
				self::mbutton( 'compare', 'مقایسه خروجی', (bool) $report );
			}
			if ( is_array( $cmp ) && ! empty( $cmp['rows'] ) ) {
				self::render_compare( $cmp );
			}
			?>
		</section>

		<section class="ssai-card">
			<h2>تشخیص منبع Schema یک صفحه</h2>
			<p class="description">اگر در مقایسه، Schemaای قرمز ماند، آدرس همان صفحه را این‌جا بدهید تا معلوم شود از کجا می‌آید.</p>
			<?php if ( $can ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssai-inline-form">
					<input type="hidden" name="action" value="ssai_migrate"><input type="hidden" name="do" value="diagnose">
					<?php wp_nonce_field( 'ssai_migrate' ); ?>
					<input type="text" name="ref" dir="ltr" class="regular-text" placeholder="https://…/page/" required> <button class="button">تشخیص</button>
				</form>
			<?php endif; ?>
			<?php
			$dg = get_option( 'ssai_schema_diag' );
			if ( is_array( $dg ) ) :
				?>
				<h3><?php echo esc_html( $dg['title'] ); ?></h3>
				<ul class="ssai-plist">
					<li>Schemaهای سفارشی Rank Math در خود صفحه: <span dir="ltr"><?php echo esc_html( $dg['meta'] ? implode( ' | ', array_map( function ( $k, $v ) { return $v . ' (' . $k . ')'; }, array_keys( $dg['meta'] ), $dg['meta'] ) ) : '—' ); ?></span></li>
					<li>کپی منتقل‌شده در این افزونه: <span dir="ltr"><?php echo esc_html( $dg['own'] ? implode( ', ', $dg['own'] ) : '—' ); ?></span></li>
					<li>کد JSON-LD داخل محتوای صفحه (مثلاً ویجت HTML المنتور): <?php echo $dg['content'] ? 'بله – بعد از جابه‌جایی هم می‌ماند' : 'نه'; ?></li>
				</ul>
				<?php if ( $dg['templates'] ) : ?>
					<p>قالب‌های Schema رنک‌مث (Schema Templates):</p>
					<table class="widefat striped"><thead><tr><th>قالب</th><th>نوع</th><th>روی این صفحه</th><th>شرط نمایش</th></tr></thead><tbody>
					<?php foreach ( $dg['templates'] as $t ) : ?>
						<tr><td><?php echo esc_html( $t['title'] ); ?></td><td dir="ltr"><?php echo esc_html( $t['type'] ); ?></td><td><?php echo $t['match'] ? '✓ اعمال می‌شود' : '—'; ?></td><td dir="ltr" class="description" style="word-break:break-all"><?php echo esc_html( $t['cond'] ); ?></td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
				<p>JSON-LDهای صفحه زنده:</p>
				<table class="widefat striped"><thead><tr><th>تگ</th><th>انواع</th></tr></thead><tbody>
				<?php foreach ( $dg['scripts'] as $sc ) : ?>
					<tr><td dir="ltr" class="description"><?php echo esc_html( $sc['attrs'] ); ?></td><td dir="ltr"><?php echo esc_html( $sc['types'] ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</section>

		<section class="ssai-card">
			<h2>۴. جابه‌جایی</h2>
			<?php if ( $block ) : ?>
				<div class="notice notice-error inline"><p><strong>فعلاً Rank Math را غیرفعال نکنید.</strong> این تنظیم‌های Rank Math آدرس صفحه‌ها را تغییر داده‌اند و این افزونه هنوز آن‌ها را پیاده نکرده است:</p><ul><?php foreach ( $block as $b ) : ?><li>• <?php echo esc_html( $b ); ?></li><?php endforeach; ?></ul></div>
			<?php endif; ?>
			<?php if ( $active ) : ?>
				<p>بعد از غیرفعال شدن Rank Math، همه بخش‌ها (عنوان و متا، Schema، نقشه سایت، ریدایرکت، ۴۰۴، Breadcrumb و robots.txt) خودکار در این افزونه روشن می‌شوند. اطلاعات Rank Math می‌ماند و هر وقت خواستید با یک کلیک برمی‌گردد.</p>
				<ol>
					<li>Rank Math را یکی دو هفته <strong>حذف نکنید</strong>، فقط غیرفعال بماند.</li>
					<li>بعد از جابه‌جایی، در سرچ کنسول «Sitemaps» را باز کنید و مطمئن شوید <code>sitemap_index.xml</code> خطا ندارد.</li>
					<li>چند صفحه را با <a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener">Rich Results Test</a> بررسی کنید.</li>
					<li>اگر افزونه کش دارید، کش را پاک کنید.</li>
				</ol>
				<?php
				if ( $can && ! $block && $files ) {
					if ( $report ) {
						self::mbutton( 'deactivate', 'غیرفعال کردن Rank Math و جابه‌جایی', true, 'Rank Math غیرفعال می‌شود و Smart SEO AI خروجی‌های سئو را بر عهده می‌گیرد. ادامه؟' );
					} else {
						echo '<p class="description">اول مرحله ۱ (انتقال اطلاعات) را انجام دهید.</p>';
					}
				}
				?>
			<?php elseif ( $files && $can ) : ?>
				<p>Rank Math غیرفعال است. اگر مشکلی دیدید، می‌توانید آن را دوباره فعال کنید؛ این افزونه خودکار کنار می‌کشد.</p>
				<?php self::mbutton( 'activate', 'فعال کردن دوباره Rank Math' ); ?>
			<?php else : ?>
				<p>✓ این افزونه همه خروجی‌های سئو را می‌سازد.</p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_compare( $cmp ) {
		$fields = array(
			'title'     => 'عنوان',
			'desc'      => 'توضیحات',
			'canonical' => 'Canonical',
			'noindex'   => 'noindex',
			'og_image'  => 'تصویر اشتراک',
			'schema'    => 'Schema',
		);
		$diff   = 0;
		echo '<p class="description">زمان مقایسه: ' . esc_html( wp_date( 'Y/m/d H:i', $cmp['time'] ) ) . ( $cmp['rm'] ? ' — ستون «فعلی» خروجی Rank Math است.' : '' ) . '</p>';
		echo '<div class="ssai-table-wrap"><table class="widefat ssai-cmp"><thead><tr><th>صفحه</th><th>مورد</th><th>فعلی</th><th>Smart SEO AI</th></tr></thead><tbody>';
		foreach ( $cmp['rows'] as $r ) {
			$first = true;
			if ( ! $r['live'] ) {
				echo '<tr><td dir="ltr">' . esc_html( rawurldecode( $r['url'] ) ) . '</td><td colspan="3">صفحه باز نشد: ' . esc_html( $r['err'] ) . '</td></tr>';
				continue;
			}
			foreach ( $fields as $k => $label ) {
				$a = $r['live'][ $k ];
				$b = $r['ours'][ $k ];
				if ( 'schema' === $k ) {
					$skip = array( 'ImageObject', 'ListItem', 'PostalAddress', 'GeoCoordinates', 'OpeningHoursSpecification', 'SearchAction', 'EntryPoint', 'PropertyValueSpecification', 'Offer', 'AggregateOffer', 'Brand', 'Rating', 'AggregateRating', 'Review', 'ContactPoint', 'Place' );
					$a    = array_values( array_diff( (array) $a, $skip ) );
					$b    = array_values( array_diff( (array) $b, $skip ) );
					$miss = array_diff( $a, $b );
					if ( ! empty( $r['ours']['in_page'] ) ) {
						$label = 'Schema (شامل JSON-LD داخل صفحه)';
					}
					$ok   = ! $miss;
					$a    = implode( ', ', $a );
					$b    = implode( ', ', $b ) . ( $miss ? ' (نیست: ' . implode( ', ', $miss ) . ')' : '' );
				} else {
					$ok = SSAI_Migrate::same( $k, $a, $b );
					if ( 'noindex' === $k ) {
						$a = $a ? 'noindex' : 'index';
						$b = $b ? 'noindex' : 'index';
					}
				}
				$diff += $ok ? 0 : 1;
				echo '<tr class="' . ( $ok ? 'ssai-cmp-ok' : 'ssai-cmp-bad' ) . '">';
				echo '<td dir="ltr">' . ( $first ? '<a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . esc_html( rawurldecode( wp_parse_url( $r['url'], PHP_URL_PATH ) ) ) . '</a>' : '' ) . '</td>';
				echo '<td>' . ( $ok ? '✓ ' : '✗ ' ) . esc_html( $label ) . '</td><td dir="auto">' . esc_html( rawurldecode( (string) $a ) ) . '</td><td dir="auto">' . esc_html( rawurldecode( (string) $b ) ) . '</td></tr>';
				$first = false;
			}
		}
		echo '</tbody></table></div>';
		echo $diff ? '<p class="ssai-msg ssai-msg-warning">' . esc_html( $diff ) . ' تفاوت. تفاوت‌های کوچک (مثلاً جداکننده یا Schema اضافه‌ای که Rank Math دارد) معمولاً مشکلی ندارد؛ تفاوت در noindex و canonical را حتماً بررسی کنید.</p>' : '<p class="ssai-msg ssai-msg-ok">✓ خروجی‌ها یکسان‌اند.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
