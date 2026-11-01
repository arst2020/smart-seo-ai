<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens: settings, site scan, editor meta box and AJAX endpoints.
 */
class SSAI_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SSAI_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'save_post', array( __CLASS__, 'flush_row' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_row' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $h ) {
			add_action( $h, array( __CLASS__, 'flush_row_meta' ), 10, 3 );
		}

		foreach ( array( 'rules', 'ai_analyze', 'ai_rewrite', 'ai_fix', 'apply', 'undo', 'test', 'fetch_updates' ) as $a ) {
			add_action( 'wp_ajax_ssai_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	/** Real content types only (no media, Elementor templates/mega-menu items, or other internal types). */
	public static function post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
			if ( 'attachment' === $name || ! empty( $obj->exclude_from_search ) || 0 === strpos( $name, 'elementor' ) || 0 === strpos( $name, 'e-' ) ) {
				continue;
			}
			$out[] = $name;
		}
		return apply_filters( 'ssai_post_types', $out );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=smart-seo-ai-settings' ) ) . '">تنظیمات</a>' );
		return $links;
	}

	public static function menu() {
		add_menu_page( 'Smart SEO AI', 'Smart SEO AI', 'edit_posts', 'smart-seo-ai', array( 'SSAI_Dashboard', 'page' ), 'dashicons-search', 81 );
		add_submenu_page( 'smart-seo-ai', 'داشبورد Smart SEO AI', 'داشبورد', 'edit_others_posts', 'smart-seo-ai', array( 'SSAI_Dashboard', 'page' ) );
		add_submenu_page( 'smart-seo-ai', 'بررسی سئوی صفحه‌ها', 'بررسی صفحه‌ها', 'edit_posts', 'smart-seo-ai-pages', array( __CLASS__, 'page_scan' ) );
		add_submenu_page( 'smart-seo-ai', 'تنظیمات هوش مصنوعی و سرویس‌ها', 'هوش مصنوعی و سرویس‌ها', 'manage_options', 'smart-seo-ai-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function assets( $hook ) {
		$is_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		$is_plugin = false !== strpos( (string) $hook, 'smart-seo-ai' );
		if ( ! $is_editor && ! $is_plugin ) {
			return;
		}
		$post_id = 0;
		if ( $is_editor ) {
			global $post;
			if ( $post instanceof WP_Post ) {
				$post_id = (int) $post->ID;
			}
		}
		self::enqueue( $post_id, false );
	}

	/**
	 * Load the UI. $elementor = true when called inside the Elementor editor.
	 */
	public static function enqueue( $post_id, $elementor ) {
		wp_enqueue_style( 'ssai-admin', SSAI_URL . 'assets/admin.css', array(), SSAI_VERSION );
		wp_enqueue_script( 'ssai-admin', SSAI_URL . 'assets/admin.js', array(), SSAI_VERSION, true );

		$last_ai = $post_id ? get_post_meta( $post_id, SSAI_Analyzer::META_AI, true ) : null;

		wp_localize_script(
			'ssai-admin',
			'SSAI',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'ssai' ),
				'postId'    => $post_id,
				'canApply'  => $post_id ? SSAI_Applier::can( $post_id ) : current_user_can( SSAI_Settings::get( 'apply_cap' ) ),
				'lastAi'    => is_array( $last_ai ) ? $last_ai : null,
				'fields'    => SSAI_Applier::fields(),
				'elementor' => (bool) $elementor,
				'isBuilt'   => $post_id ? SSAI_Elementor::is_built( $post_id ) : false,
				'elUrl'     => $post_id ? SSAI_Elementor::edit_url( $post_id ) : '',
				'reportUrl' => $post_id ? SSAI_Report::url( $post_id ) : '',
			)
		);
	}

	private static function history_for_js( $post_id ) {
		$out = array();
		foreach ( SSAI_Applier::history( $post_id ) as $e ) {
			$short = function ( $v ) {
				$v = wp_strip_all_tags( (string) $v );
				return mb_strlen( $v ) > 120 ? mb_substr( $v, 0, 120 ) . '…' : $v;
			};
			if ( 'elementor_text' === $e['field'] ) {
				$e['old'] = 'نسخه قبلی صفحه';
			}
			$u     = get_userdata( (int) $e['user'] );
			$out[] = array(
				'id'     => $e['id'],
				'field'  => $e['field'],
				'old'    => $short( $e['old'] ),
				'new'    => $short( $e['new'] ),
				'user'   => $u ? $u->display_name : '',
				'time'   => wp_date( 'Y/m/d H:i', (int) $e['time'] ),
				'undone' => ! empty( $e['undone'] ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------- Meta box */

	public static function meta_box() {
		foreach ( self::post_types() as $pt ) {
			add_meta_box( 'ssai-metabox', 'Smart SEO AI – بررسی سئو', array( __CLASS__, 'render_meta_box' ), $pt, 'normal', 'high' );
		}
	}

	public static function render_meta_box( $post ) {
		echo '<div id="ssai-box" class="ssai" dir="rtl" data-post="' . esc_attr( $post->ID ) . '">';
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>برای بررسی سئو ابتدا نوشته را یک بار ذخیره (پیش‌نویس) کنید.</p></div>';
			return;
		}
		if ( SSAI_Elementor::is_built( $post->ID ) ) {
			echo '<p class="ssai-el-note">این صفحه با المنتور ساخته شده است. برای کار راحت‌تر، آن را با المنتور باز کنید و دکمه «SEO» گوشه صفحه را بزنید. <a href="' . esc_url( SSAI_Elementor::edit_url( $post->ID ) ) . '">باز کردن در المنتور</a></p>';
		}
		echo '<div class="ssai-loading">در حال بررسی…</div></div>';
	}

	/* ---------------------------------------------------------------- Scan page */

	/**
	 * One row of the site overview (SEO part cached per post; invalidated whenever the post or its SEO fields change).
	 */
	public static function scan_row( $post ) {
		$key = 'ssai_row_' . $post->ID;
		$row = get_transient( $key );
		if ( is_array( $row ) && isset( $row['mod'] ) && $row['mod'] === $post->post_modified_gmt ) {
			return $row;
		}
		$r   = SSAI_Analyzer::rules( $post->ID );
		$err = 0;
		$wrn = 0;
		$top = '';
		foreach ( $r['items'] as $it ) {
			if ( 'error' === $it['status'] ) {
				$err++;
			} elseif ( 'warning' === $it['status'] ) {
				$wrn++;
			}
			if ( '' === $top && 'good' !== $it['status'] ) {
				$top = $it['message'];
			}
		}
		$row = array(
			'mod'   => $post->post_modified_gmt,
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'kw'    => $r['data']['focus_keyword'],
			'score' => (int) $r['score'],
			'err'   => $err,
			'wrn'   => $wrn,
			'top'   => $top,
			'url'   => get_permalink( $post ),
			'el'    => SSAI_Elementor::is_built( $post->ID ),
		);
		set_transient( $key, $row, DAY_IN_SECONDS );
		return $row;
	}

	/** Drop the cached overview row when a post or one of its SEO fields changes. */
	public static function flush_row( $post_id ) {
		delete_transient( 'ssai_row_' . (int) $post_id );
	}

	public static function flush_row_meta( $meta_id, $post_id, $meta_key ) {
		$keys = array( '_ssai_focus_keyword', '_ssai_meta_description', '_ssai_seo_title', 'rank_math_focus_keyword', 'rank_math_description', 'rank_math_title', '_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc', '_yoast_wpseo_title', '_elementor_data', '_wp_attachment_image_alt' );
		if ( in_array( $meta_key, $keys, true ) ) {
			self::flush_row( $post_id );
		}
	}

	private static function sort_link( $col, $label, $orderby, $order, $base ) {
		$is   = $orderby === $col;
		// First click: numbers that matter most come first (low score, many errors, many clicks, best position).
		$def  = in_array( $col, array( 'score', 'position', 'title', 'type', 'status', 'kw' ), true ) ? 'asc' : 'desc';
		$next = $is ? ( 'asc' === $order ? 'desc' : 'asc' ) : $def;
		$url  = add_query_arg(
			array(
				'orderby' => $col,
				'order'   => $next,
				'paged'   => 1,
			),
			$base
		);
		$arrow = $is ? ( 'asc' === $order ? ' ▲' : ' ▼' ) : '';
		$aria  = $is ? ( 'asc' === $order ? 'ascending' : 'descending' ) : 'none';
		return '<th scope="col" aria-sort="' . $aria . '" class="ssai-sortable' . ( $is ? ' is-sorted' : '' ) . '"><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<span class="ssai-sort-ind" aria-hidden="true">' . $arrow . '</span></a></th>';
	}

	public static function page_scan() {
		// phpcs:disable WordPress.Security.NonceVerification
		$types = self::post_types();
		$type  = isset( $_GET['ssai_type'] ) ? sanitize_key( wp_unslash( $_GET['ssai_type'] ) ) : 'any';
		if ( 'any' !== $type && ! in_array( $type, $types, true ) ) {
			$type = 'any';
		}
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$cols    = array( 'title', 'type', 'status', 'kw', 'score', 'err', 'wrn', 'clicks', 'position', 'modified' );
		$orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], $cols, true ) ? sanitize_key( $_GET['orderby'] ) : 'modified';
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		// phpcs:enable

		$ids = get_posts(
			array(
				'post_type'      => 'any' === $type ? $types : $type,
				'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'perm'           => 'editable', // Authors/contributors see only their own posts.
				'posts_per_page' => 2000,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				's'              => $search,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$gsc_pages = SSAI_GSC::connected() ? SSAI_GSC::all_pages() : SSAI_GSC_Import::all_pages();
		$gsc       = SSAI_GSC::connected() || ! empty( $gsc_pages );

		// Build all rows, then sort, then paginate (so sorting covers every page of results).
		$rows = array();
		foreach ( $ids as $i => $id ) {
			$p   = get_post( $id );
			$row = self::scan_row( $p );
			$g   = $gsc ? ( isset( $gsc_pages[ SSAI_GSC_Import::url_key( $row['url'] ) ] ) ? $gsc_pages[ SSAI_GSC_Import::url_key( $row['url'] ) ] : null ) : null;
			$pto = get_post_type_object( $p->post_type );

			$row['id']       = $id;
			$row['i']        = $i; // Modified order (newest first).
			$row['type']     = $pto ? $pto->labels->singular_name : $p->post_type;
			$row['status']   = $p->post_status;
			$row['clicks']   = $g ? (int) $g['clicks'] : null;
			$row['position'] = $g && $g['position'] > 0 ? (float) $g['position'] : null;
			$row['impr']     = $g ? (int) $g['impressions'] : 0;
			$rows[]          = $row;
		}

		$dir = 'asc' === $order ? 1 : -1;
		usort(
			$rows,
			function ( $a, $b ) use ( $orderby, $dir ) {
				if ( 'modified' === $orderby ) {
					return ( $a['i'] - $b['i'] ) * -$dir; // "desc" = newest first.
				}
				$va = $a[ $orderby ];
				$vb = $b[ $orderby ];
				// Rows without data always go to the end, whatever the direction.
				$ea = null === $va || '' === $va;
				$eb = null === $vb || '' === $vb;
				if ( $ea || $eb ) {
					return $ea === $eb ? $a['i'] - $b['i'] : ( $ea ? 1 : -1 );
				}
				$c = is_string( $va ) ? strcmp( mb_strtolower( $va ), mb_strtolower( $vb ) ) : ( $va <=> $vb );
				return $c ? $c * $dir : $a['i'] - $b['i'];
			}
		);

		$per   = 30;
		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / $per ) );
		$paged = min( $paged, $pages );
		$shown = array_slice( $rows, ( $paged - 1 ) * $per, $per );

		$base = remove_query_arg( array( 'orderby', 'order', 'paged' ) );
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>بررسی سئوی سایت</h1>
			<?php if ( ! $gsc && current_user_can( 'manage_options' ) ) : ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-gsc' ) ); ?>">اتصال به سرچ کنسول یا وارد کردن فایل خروجی آن</a> برای دیدن کلیک و جایگاه هر صفحه در گوگل.</p>
			<?php endif; ?>
			<p>امتیاز با قوانین داخلی و بدون هوش مصنوعی محاسبه می‌شود. روی عنوان هر صفحه کلیک کنید تا گزارش کامل آن باز شود؛ روی عنوان هر ستون کلیک کنید تا جدول بر اساس آن مرتب شود.</p>
			<?php if ( '' === SSAI_Settings::api_key() && SSAI_Settings::provider_info()['key'] ) : ?>
				<div class="notice notice-warning"><p>کلید API هوش مصنوعی هنوز تنظیم نشده است. <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-settings' ) ); ?>">رفتن به تنظیمات</a></p></div>
			<?php endif; ?>
			<form method="get" class="ssai-filter">
				<input type="hidden" name="page" value="smart-seo-ai-pages">
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $orderby ); ?>">
				<input type="hidden" name="order" value="<?php echo esc_attr( $order ); ?>">
				<select name="ssai_type">
					<option value="any">همه انواع محتوا</option>
					<?php foreach ( $types as $t ) : $o = get_post_type_object( $t ); ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $o ? $o->labels->name : $t ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="جستجو در عنوان و متن">
				<button class="button">فیلتر</button>
				<span class="description"><?php echo esc_html( number_format_i18n( $total ) ); ?> مورد</span>
			</form>
			<table class="widefat striped ssai-scan">
				<thead><tr>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- sort_link() escapes.
					echo self::sort_link( 'title', 'عنوان', $orderby, $order, $base );
					echo self::sort_link( 'type', 'نوع', $orderby, $order, $base );
					echo self::sort_link( 'status', 'وضعیت', $orderby, $order, $base );
					echo self::sort_link( 'kw', 'کلمه کلیدی', $orderby, $order, $base );
					echo self::sort_link( 'score', 'امتیاز', $orderby, $order, $base );
					echo self::sort_link( 'err', 'خطا', $orderby, $order, $base );
					echo self::sort_link( 'wrn', 'هشدار', $orderby, $order, $base );
					if ( $gsc ) {
						echo self::sort_link( 'clicks', 'کلیک', $orderby, $order, $base );
						echo self::sort_link( 'position', 'جایگاه', $orderby, $order, $base );
					}
					echo self::sort_link( 'modified', 'آخرین ویرایش', $orderby, $order, $base );
					// phpcs:enable
					?>
					<th>مهم‌ترین مشکل</th><th></th>
				</tr></thead>
				<tbody>
				<?php
				if ( ! $shown ) {
					echo '<tr><td colspan="12">محتوایی پیدا نشد.</td></tr>';
				}
				foreach ( $shown as $row ) {
					$cls = $row['score'] >= 80 ? 'good' : ( $row['score'] >= 50 ? 'warning' : 'error' );
					echo '<tr>';
					echo '<td><strong><a href="' . esc_url( SSAI_Report::url( $row['id'] ) ) . '" title="گزارش کامل سئو و گوگل">' . esc_html( '' !== $row['title'] ? $row['title'] : '(بدون عنوان)' ) . '</a></strong></td>';
					echo '<td>' . esc_html( $row['type'] ) . ( $row['el'] ? ' <span class="ssai-el-tag">Elementor</span>' : '' ) . '</td>';
					echo '<td>' . esc_html( $row['status'] ) . '</td>';
					echo '<td>' . esc_html( '' !== $row['kw'] ? $row['kw'] : '—' ) . '</td>';
					echo '<td><span class="ssai-badge ssai-' . esc_attr( $cls ) . '">' . (int) $row['score'] . '</span></td>';
					echo '<td>' . (int) $row['err'] . '</td><td>' . (int) $row['wrn'] . '</td>';
					if ( $gsc ) {
						echo '<td>' . ( null !== $row['clicks'] ? esc_html( number_format_i18n( $row['clicks'] ) ) : '—' ) . '</td>';
						$low = null !== $row['position'] && $row['impr'] < 50;
						echo '<td' . ( $low ? ' class="ssai-lowcell" title="فقط ' . (int) $row['impr'] . ' نمایش؛ میانگین قابل اتکا نیست"' : '' ) . '>' . ( null !== $row['position'] ? ( $low ? '≈' : '' ) . esc_html( number_format_i18n( $row['position'], 1 ) ) : '—' ) . '</td>';
					}
					echo '<td class="ssai-nowrap">' . esc_html( wp_date( 'Y/m/d', strtotime( $row['mod'] . ' UTC' ) ) ) . '</td>';
					echo '<td class="ssai-top">' . esc_html( $row['top'] ) . '</td>';
					echo '<td class="ssai-nowrap">';
					if ( current_user_can( 'edit_post', $row['id'] ) ) {
						if ( $row['el'] ) {
							echo '<a class="button button-small button-primary" href="' . esc_url( SSAI_Elementor::edit_url( $row['id'] ) ) . '">بررسی در المنتور</a> ';
						}
						echo '<a class="button button-small" href="' . esc_url( get_edit_post_link( $row['id'] ) . '#ssai-metabox' ) . '">بررسی و اصلاح</a>';
					}
					echo '</td></tr>';
				}
				?>
				</tbody>
			</table>
			<?php
			$links = paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			if ( $links ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
			}
			?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- Settings page */

	public static function page_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o         = SSAI_Settings::get();
		$providers = SSAI_Settings::providers();
		$name      = SSAI_Settings::OPTION;
		$key_const = defined( 'SSAI_API_KEY' ) && SSAI_API_KEY;
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>تنظیمات Smart SEO AI</h1>
			<form method="post" action="options.php">
				<?php settings_errors( SSAI_Settings::OPTION ); ?>
				<?php settings_fields( 'ssai_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ssai-provider">سرویس هوش مصنوعی</label></th>
						<td>
							<select id="ssai-provider" name="<?php echo esc_attr( $name ); ?>[provider]">
								<?php foreach ( $providers as $k => $p ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" data-model="<?php echo esc_attr( $p['model'] ); ?>" data-base="<?php echo esc_attr( $p['base'] ); ?>" data-help="<?php echo esc_attr( $p['help'] ); ?>" <?php selected( $o['provider'], $k ); ?>><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description" id="ssai-provider-help"></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-key">کلید API</label></th>
						<td>
							<?php if ( $key_const ) : ?>
								<p>کلید از ثابت <code>SSAI_API_KEY</code> در wp-config.php خوانده می‌شود.</p>
							<?php else : ?>
								<input type="password" id="ssai-key" class="regular-text" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" placeholder="<?php echo $o['api_key'] ? esc_attr( '•••••••• ذخیره شده (برای تغییر، کلید جدید وارد کنید)' ) : ''; ?>">
								<?php if ( $o['api_key'] ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_key]" value="1"> حذف کلید ذخیره‌شده</label>
								<?php endif; ?>
								<p class="description">امن‌تر: به جای ذخیره در دیتابیس، در wp-config.php بنویسید: <code>define( 'SSAI_API_KEY', '...' );</code></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-model">مدل</label></th>
						<td><input type="text" id="ssai-model" class="regular-text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[model]" value="<?php echo esc_attr( $o['model'] ); ?>">
							<p class="description">خالی = مدل پیش‌فرض سرویس: <code id="ssai-model-default"></code></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-base">آدرس API (اختیاری)</label></th>
						<td><input type="url" id="ssai-base" class="regular-text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[base_url]" value="<?php echo esc_attr( $o['base_url'] ); ?>">
							<p class="description">خالی = آدرس پیش‌فرض: <code id="ssai-base-default"></code>. برای Ollama روی سرور دیگر یا سرویس دلخواه سازگار با OpenAI پر کنید.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-cf">Cloudflare Account ID</label></th>
						<td><input type="text" id="ssai-cf" class="regular-text" dir="ltr" maxlength="40" name="<?php echo esc_attr( $name ); ?>[cf_account]" value="<?php echo esc_attr( $o['cf_account'] ); ?>" placeholder="32 کاراکتر، مثل a6bf54c2…">
							<p class="description">فقط برای Cloudflare Workers AI (اصلی یا پشتیبان). همان رشته ۳۲ کاراکتری آدرس داشبورد: <code>dash.cloudflare.com/&lt;Account ID&gt;/…</code> — توکن را با قالب «Workers AI» بسازید.</p></td>
					</tr>
					<tr>
						<th scope="row">سرویس‌های پشتیبان</th>
						<td>
							<p class="description">اگر سقف رایگان سرویس اصلی پر شود، اعتبارش تمام شود، کلیدش کار نکند یا در دسترس نباشد، افزونه خودکار به ترتیب سراغ سرویس‌های زیر می‌رود (رایگان‌ها اول). سرویسی که خطا داده مدتی (۵ تا ۶۰ دقیقه) کنار گذاشته می‌شود.</p>
							<table class="widefat striped ssai-backup">
								<thead><tr><th>فعال</th><th>سرویس</th><th>کلید API</th><th>مدل (خالی = پیش‌فرض)</th></tr></thead>
								<tbody>
								<?php
								$bk = (array) $o['backup'];
								foreach ( SSAI_Settings::backup_ids() as $bid ) :
									$bp  = $providers[ $bid ];
									$row = isset( $bk[ $bid ] ) ? $bk[ $bid ] : array( 'on' => 0, 'key' => '', 'model' => '' );
									$bn  = $name . '[backup][' . $bid . ']';
									?>
									<tr class="ssai-backup-<?php echo esc_attr( $bid ); ?>">
										<td><input type="hidden" name="<?php echo esc_attr( $bn ); ?>[on]" value="0"><input type="checkbox" name="<?php echo esc_attr( $bn ); ?>[on]" value="1" <?php checked( ! empty( $row['on'] ) ); ?>></td>
										<td><strong><?php echo esc_html( $bp['label'] ); ?></strong> <span class="ssai-backup-main description" <?php echo $bid === $o['provider'] ? '' : 'hidden'; ?>>(سرویس اصلی است)</span><br><a href="<?php echo esc_url( $bp['help'] ); ?>" target="_blank" rel="noopener">دریافت کلید</a></td>
										<td><input type="password" class="regular-text" dir="ltr" autocomplete="new-password" name="<?php echo esc_attr( $bn ); ?>[key]" value="" placeholder="<?php echo $row['key'] ? esc_attr( '•••••••• ذخیره شده' ) : ''; ?>">
											<?php if ( $row['key'] ) : ?><br><label><input type="checkbox" name="<?php echo esc_attr( $bn ); ?>[clear]" value="1"> حذف کلید</label><?php endif; ?></td>
										<td><input type="text" dir="ltr" name="<?php echo esc_attr( $bn ); ?>[model]" value="<?php echo esc_attr( $row['model'] ); ?>" placeholder="<?php echo esc_attr( $bp['model'] ); ?>"></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
							<?php
							$mn = get_option( 'ssai_model_note' );
							if ( is_array( $mn ) && ! empty( $mn['to'] ) && time() - (int) $mn['time'] < 30 * DAY_IN_SECONDS ) {
								echo '<p class="description">ℹ مدل <code>' . esc_html( $mn['from'] ) . '</code> از طرف سرویس بازنشسته شده بود؛ افزونه خودکار به <code>' . esc_html( $mn['to'] ) . '</code> تغییر داد (' . esc_html( human_time_diff( $mn['time'] ) ) . ' پیش).</p>';
							}
							$last = get_option( 'ssai_ai_last' );
							if ( is_array( $last ) && ! empty( $last['provider'] ) && isset( $providers[ $last['provider'] ] ) ) {
								echo '<p class="description">آخرین پاسخ: <strong>' . esc_html( preg_replace( '/\s*\(.*$/u', '', $providers[ $last['provider'] ]['label'] ) ) . '</strong> / <code>' . esc_html( $last['model'] ) . '</code> — ' . esc_html( human_time_diff( $last['time'] ) ) . ' پیش' . ( ! empty( $last['fallback'] ) ? ' (سرویس اصلی جواب نداد و پشتیبان استفاده شد)' : '' ) . '</p>';
							}
							?>
							<p class="description">با زدن «آزمایش اتصال» پایین صفحه، همه سرویس‌های فعال جداگانه آزمایش می‌شوند (اول تنظیمات را ذخیره کنید).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">زبان توضیحات هوش مصنوعی</th>
						<td><select name="<?php echo esc_attr( $name ); ?>[language]">
							<option value="fa" <?php selected( $o['language'], 'fa' ); ?>>فارسی</option>
							<option value="en" <?php selected( $o['language'], 'en' ); ?>>English</option>
						</select></td>
					</tr>
					<tr>
						<th scope="row">چه کسی می‌تواند تغییرات را اعمال کند؟</th>
						<td><select name="<?php echo esc_attr( $name ); ?>[apply_cap]">
							<option value="manage_options" <?php selected( $o['apply_cap'], 'manage_options' ); ?>>فقط مدیر کل (Administrator)</option>
							<option value="edit_others_posts" <?php selected( $o['apply_cap'], 'edit_others_posts' ); ?>>مدیر کل و ویرایشگر (Editor)</option>
						</select>
						<p class="description">بقیه کاربران فقط گزارش و پیشنهادها را می‌بینند.</p></td>
					</tr>
					<tr>
						<th scope="row">خروجی سئو</th>
						<td><input type="hidden" name="<?php echo esc_attr( $name ); ?>[output_meta]" value="<?php echo esc_attr( $o['output_meta'] ); ?>">
						<p>عنوان، متا، Schema، نقشه سایت و ریدایرکت‌ها در <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-seo' ) ); ?>">تنظیمات سئو</a> تنظیم می‌شوند. افزونه سئوی فعال دیگر: <strong><?php echo esc_html( SSAI_Content::seo_plugin() ? SSAI_Content::seo_plugin() : 'هیچ' ); ?></strong> — تا وقتی فعال است، این افزونه خروجی سئو نمی‌سازد و داده‌ها را در فیلدهای همان افزونه هم ذخیره می‌کند.</p></td>
					</tr>
					<tr>
						<th scope="row">مهلت پاسخ (ثانیه)</th>
						<td><input type="number" min="10" max="300" name="<?php echo esc_attr( $name ); ?>[timeout]" value="<?php echo esc_attr( $o['timeout'] ); ?>">
							<p class="description">برای بازنویسی کامل صفحه، دست‌کم ۱۸۰ ثانیه صبر می‌شود. اگر بعد از حدود ۶۰ ثانیه خطای ۵۰۴ گرفتید، سرور یا هاست درخواست را زودتر قطع می‌کند و باید از هاست بخواهید زمان اجرای PHP را بیشتر کند.</p></td>
					</tr>
					<tr>
						<th scope="row">حداکثر طول متن ارسالی (کاراکتر)</th>
						<td><input type="number" min="2000" max="60000" step="1000" name="<?php echo esc_attr( $name ); ?>[max_chars]" value="<?php echo esc_attr( $o['max_chars'] ); ?>">
							<p class="description">سرویس‌های رایگان محدودیت حجم دارند. بازنویسی کامل فقط برای محتوای کوتاه‌تر از این مقدار انجام می‌شود.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-guidelines">قوانین گوگل که به هوش مصنوعی داده می‌شود</label></th>
						<td>
							<textarea id="ssai-guidelines" class="large-text code" rows="12" dir="ltr" name="<?php echo esc_attr( $name ); ?>[guidelines]"><?php echo esc_textarea( '' !== $o['guidelines'] ? $o['guidelines'] : SSAI_Guidelines::default_text() ); ?></textarea>
							<p class="description">خلاصه راهنمای فعلی گوگل (Search Essentials، محتوای مفید، E-E-A-T، سیاست‌های اسپم). هر وقت گوگل قانون جدیدی اعلام کرد، می‌توانید یک خط به این متن اضافه کنید. اگر متن پیش‌فرض را تغییر ندهید، با به‌روزرسانی افزونه خودکار تازه می‌شود.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-psi">کلید PageSpeed Insights (اختیاری)</label></th>
						<td><input type="text" id="ssai-psi" class="regular-text" dir="ltr" placeholder="AIza…" name="<?php echo esc_attr( $name ); ?>[psi_key]" value="<?php echo esc_attr( $o['psi_key'] ); ?>">
							<p class="description">بررسی سرعت از <strong>مرورگر خود شما</strong> به گوگل فرستاده می‌شود، نه از سرور سایت. بدون کلید هم کار می‌کند ولی سهمیه کمی دارد. کلید رایگان: در Google Cloud، «PageSpeed Insights API» را فعال کنید و در Credentials یک API key بسازید (پیشنهاد: کلید را فقط به همین API محدود کنید، چون در مرورگر استفاده می‌شود).</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-opr">کلید Open PageRank (اختیاری)</label></th>
						<td><input type="text" id="ssai-opr" class="regular-text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[opr_key]" value="<?php echo esc_attr( $o['opr_key'] ); ?>">
							<p class="description">اعتبار دامنه سایت و دامنه‌های لینک‌دهنده (۰ تا ۱۰). رایگان از <a href="https://keywordseverywhere.com/openpagerank/" target="_blank" rel="noopener">keywordseverywhere.com/openpagerank</a> — کلید رایگان فقط از یک IP کار می‌کند؛ IP سرور سایت را در حسابتان ثبت کنید<?php echo isset( $_SERVER['SERVER_ADDR'] ) ? ' (IP سرور، طبق خود سرور: <code dir="ltr">' . esc_html( sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) ) . '</code> — اگر سرور پشت NAT باشد IP خروجی ممکن است فرق کند)' : ''; ?>.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ssai-moz">توکن Moz API (اختیاری)</label></th>
						<td><input type="password" id="ssai-moz" class="regular-text" dir="ltr" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[moz_token]" value="" placeholder="<?php echo $o['moz_token'] ? esc_attr( '•••••• ذخیره شده' ) : ''; ?>">
							<?php if ( $o['moz_token'] ) : ?><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[moz_clear]" value="1"> حذف توکن</label><?php endif; ?>
							<p class="description">کاملاً اختیاری – Moz برای فعال‌سازی کارت بانکی بین‌المللی می‌خواهد. بدون آن، افزونه ریسک اسپم بک‌لینک‌ها را خودش از خروجی سرچ کنسول تخمین می‌زند. اگر توکن دارید: Domain Authority و Spam Score، ۵۰ استعلام در ماه. توکن از <a href="https://moz.com/products/api" target="_blank" rel="noopener">moz.com/products/api</a>.</p></td>
					</tr>
					<tr>
						<th scope="row">IndexNow</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[indexnow]" value="on" <?php checked( $o['indexnow'], 'on' ); ?>> هر بار صفحه‌ای منتشر یا به‌روز شد، به Bing، Yandex و دیگر موتورهای عضو IndexNow خبر داده شود</label>
							<?php if ( $o['indexnow_key'] ) : ?>
								<p class="description">فایل کلید: <a href="<?php echo esc_url( SSAI_Health::key_url() ); ?>" target="_blank" rel="noopener" dir="ltr"><?php echo esc_html( SSAI_Health::key_url() ); ?></a> (باید متن کلید را نشان دهد)</p>
								<button type="button" class="button" id="ssai-inx-test">ارسال آزمایشی صفحه اصلی</button> <span id="ssai-inx-result"></span>
								<?php
								$log = SSAI_Health::log();
								if ( $log ) {
									echo '<ul class="ssai-updates">';
									foreach ( array_slice( $log, 0, 5 ) as $l ) {
										echo '<li>' . esc_html( wp_date( 'Y/m/d H:i', $l['time'] ) ) . ' — ' . ( $l['ok'] ? '✓ ' : '✕ ' ) . esc_html( $l['text'] . ' (' . $l['count'] . ' آدرس)' ) . '</li>';
									}
									echo '</ul>';
								}
								?>
							<?php endif; ?>
							<p class="description">گوگل از IndexNow استفاده نمی‌کند؛ برای گوگل همان نقشه سایت (sitemap) و سرچ کنسول کافی است.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">به‌روزرسانی‌های رسمی گوگل</th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_updates]" value="on" <?php checked( $o['auto_updates'], 'on' ); ?>> هر هفته آخرین به‌روزرسانی‌های رتبه‌بندی (Core/Spam Update) از داشبورد رسمی وضعیت جستجوی گوگل خوانده و به هوش مصنوعی گفته شود</label>
							<?php $u = SSAI_Guidelines::updates(); ?>
							<p class="description">
								آخرین بررسی: <?php echo $u['time'] ? esc_html( wp_date( 'Y/m/d H:i', $u['time'] ) ) : 'هنوز انجام نشده'; ?>
								<?php if ( ! empty( $u['error'] ) ) : ?> — <span style="color:#d63638">خطا: <?php echo esc_html( $u['error'] ); ?> (اگر سرور به گوگل دسترسی ندارد، فقط متن قوانین بالا استفاده می‌شود)</span><?php endif; ?>
							</p>
							<?php if ( ! empty( $u['items'] ) ) : ?>
								<ul class="ssai-updates"><?php foreach ( $u['items'] as $it ) : ?><li dir="ltr"><?php echo esc_html( $it['date'] . ' — ' . $it['title'] ); ?></li><?php endforeach; ?></ul>
							<?php endif; ?>
							<button type="button" class="button" id="ssai-fetch-updates">دریافت الان</button> <span id="ssai-fetch-result"></span>
						</td>
					</tr>
				</table>
				<p class="ssai-privacy"><strong>توجه حریم خصوصی:</strong> هنگام تحلیل، عنوان و متن نوشته برای سرویس هوش مصنوعی انتخاب‌شده ارسال می‌شود. سرویس‌های رایگان ممکن است داده را برای بهبود مدل استفاده کنند؛ برای محتوای محرمانه از Ollama روی سرور خودتان استفاده کنید.</p>
				<?php submit_button( 'ذخیره تنظیمات' ); ?>
			</form>
			<hr>
			<h2>آزمایش اتصال</h2>
			<p>ابتدا تنظیمات را ذخیره کنید، سپس:</p>
			<button type="button" class="button" id="ssai-test">آزمایش اتصال به هوش مصنوعی</button>
			<span id="ssai-test-result"></span>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- AJAX */

	private static function post_from_request( $need_apply = false ) {
		check_ajax_referer( 'ssai', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید یا نوشته پیدا نشد.' ), 403 );
		}
		if ( $need_apply && ! SSAI_Applier::can( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'فقط کاربران مجاز (طبق تنظیمات) می‌توانند تغییرات را اعمال کنند.' ), 403 );
		}
		return $post_id;
	}

	private static function rate_limit() {
		$k = 'ssai_lock_' . get_current_user_id();
		if ( get_transient( $k ) ) {
			wp_send_json_error( array( 'message' => 'یک درخواست هوش مصنوعی در حال انجام است یا همین الان انجام شد؛ چند ثانیه صبر کنید.' ), 429 );
		}
		// Held while the request runs (max 2 min), then shortened to a 3-second gap.
		set_transient( $k, 1, 120 );
		add_action(
			'shutdown',
			function () use ( $k ) {
				set_transient( $k, 1, 3 );
			}
		);
	}

	private static function send( $r ) {
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( $r );
	}

	public static function ajax_rules() {
		$post_id = self::post_from_request();
		$r       = SSAI_Analyzer::rules( $post_id, true );
		$d       = $r['data'];
		wp_send_json_success(
			array(
				'score'   => $r['score'],
				'items'   => $r['items'],
				'groups'  => $r['groups'],
				'current' => array(
					'title'            => $d['title'],
					'seo_title'        => $d['seo_title'],
					'seo_title_raw'    => $d['seo_title_raw'],
					'keywords'         => $d['keywords'],
					'url'              => $d['url'],
					'meta_description' => $d['meta_description'],
					'focus_keyword'    => $d['focus_keyword'],
					'slug'             => $d['slug'],
					'images'           => $d['images'],
				),
				'seoPlugin' => SSAI_Content::seo_plugin(),
				'history'   => self::history_for_js( $post_id ),
			)
		);
	}

	public static function ajax_ai_analyze() {
		$post_id = self::post_from_request();
		self::rate_limit();
		self::send( SSAI_Analyzer::ai_analyze( $post_id ) );
	}

	public static function ajax_ai_rewrite() {
		$post_id = self::post_from_request( true );
		self::rate_limit();
		$ins   = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';
		$ideas = isset( $_POST['link_ideas'] ) ? array_map( 'sanitize_text_field', explode( "\n", wp_unslash( $_POST['link_ideas'] ) ) ) : array();
		self::send( SSAI_Analyzer::ai_rewrite( $post_id, $ins, $ideas ) );
	}

	public static function ajax_ai_fix() {
		$post_id = self::post_from_request();
		self::rate_limit();
		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : '';
		self::send( SSAI_Analyzer::ai_fix( $post_id, $target ) );
	}

	public static function ajax_fetch_updates() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$r = SSAI_Guidelines::fetch();
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => 'دریافت ناموفق بود: ' . $r->get_error_message() ) );
		}
		$u = SSAI_Guidelines::updates();
		wp_send_json_success( array( 'message' => sprintf( '%d به‌روزرسانی رسمی دریافت شد. صفحه را تازه کنید.', count( $u['items'] ) ) ) );
	}

	public static function ajax_apply() {
		$post_id = self::post_from_request( true );
		$field   = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		// Raw Elementor JSON is never accepted from the browser (it would bypass Elementor's own sanitising);
		// Elementor text comes only from the server-side stored AI rewrite ("content" + token) or from undo.
		if ( ! in_array( $field, array( 'title', 'slug', 'meta_description', 'focus_keyword', 'seo_title', 'image_alt', 'content' ), true ) ) {
			wp_send_json_error( array( 'message' => 'فیلد نامعتبر.' ), 400 );
		}
		$value   = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : ''; // Sanitised per field in SSAI_Applier.
		$extra   = array();

		if ( 'image_alt' === $field ) {
			$extra['src'] = isset( $_POST['src'] ) ? esc_url_raw( wp_unslash( $_POST['src'] ) ) : '';
			// esc_url_raw may alter the src; match against the real list instead.
			$raw_src = isset( $_POST['src'] ) ? (string) wp_unslash( $_POST['src'] ) : '';
			$d       = SSAI_Content::data( $post_id );
			foreach ( $d['images'] as $img ) {
				if ( $img['src'] === $raw_src ) {
					$extra['src'] = $img['src'];
				}
			}
		} elseif ( 'content' === $field ) {
			// Content is never taken from the browser: only the server-side stored AI rewrite can be applied.
			$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
			$tkey   = 'ssai_rw_' . $post_id . '_' . get_current_user_id();
			$stored = get_transient( $tkey );
			if ( ! is_array( $stored ) || ! hash_equals( (string) $stored['token'], $token ) ) {
				wp_send_json_error( array( 'message' => 'نسخه بازنویسی منقضی شده است؛ دوباره بازنویسی را اجرا کنید.' ) );
			}
			if ( isset( $stored['mode'] ) && 'elementor' === $stored['mode'] ) {
				$ids   = isset( $_POST['ids'] ) ? array_filter( array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_POST['ids'] ) ) ) ) : array();
				$value = array_intersect_key( $stored['items'], array_flip( $ids ) );
				if ( empty( $value ) ) {
					wp_send_json_error( array( 'message' => 'هیچ موردی انتخاب نشده است.' ) );
				}
				$field = 'elementor_text';
			} else {
				$value = $stored['html'];
			}
			delete_transient( $tkey );
		}

		$r = SSAI_Applier::apply( $post_id, $field, $value, $extra );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'تغییر اعمال شد.' ) );
	}

	public static function ajax_undo() {
		$post_id = self::post_from_request( true );
		$entry   = isset( $_POST['entry'] ) ? sanitize_text_field( wp_unslash( $_POST['entry'] ) ) : '';
		$r       = SSAI_Applier::undo( $post_id, $entry );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'تغییر برگردانده شد.' ) );
	}

	public static function ajax_test() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$chain = SSAI_Settings::chain();
		if ( ! $chain ) {
			wp_send_json_error( array( 'message' => 'کلید API هوش مصنوعی در تنظیمات افزونه وارد نشده است.' ) );
		}
		$lines = array();
		$ok    = 0;
		foreach ( $chain as $i => $c ) {
			$r = SSAI_AI_Client::call( $c, 'You are a test endpoint. Reply only with JSON.', 'Return this JSON exactly: {"ok":true,"answer":"سلام"}' );
			if ( ! is_wp_error( $r ) && null === SSAI_AI_Client::parse_json( $r ) ) {
				$r = new WP_Error( 'ssai_bad_json', 'پاسخ JSON معتبر نبود؛ مدل دیگری امتحان کنید.' );
			}
			$tag = ( 0 === $i ? 'اصلی' : 'پشتیبان ' . $i ) . ' – ' . $c['label'] . ' / ' . $c['model'] . ': ';
			if ( is_wp_error( $r ) ) {
				$lines[] = '✗ ' . $tag . $r->get_error_message();
			} else {
				$ok++;
				delete_transient( 'ssai_cool_' . $c['provider'] );
				$lines[] = '✓ ' . $tag . 'اتصال موفق';
			}
		}
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => implode( "\n", $lines ) ) );
		}
		wp_send_json_success( array( 'message' => implode( "\n", $lines ) ) );
	}
}
