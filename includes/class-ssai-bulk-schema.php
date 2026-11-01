<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk schema review: one row per product (shop) or page/post, with the current schema, a suggestion,
 * the problems found and a checkbox; the ticked rows get the suggested (or chosen) type.
 */
class SSAI_Bulk_Schema {

	const SLUG = 'smart-seo-ai-bulk-schema';
	const UNDO = 'ssai_bulk_schema_undo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ssai_bulk_schema', array( __CLASS__, 'handle' ) );
		foreach ( self::types() as $pt ) {
			add_filter( 'views_edit-' . $pt, array( __CLASS__, 'list_button' ) );
		}
	}

	/** Shops review their products; every site can review pages and posts. */
	public static function types() {
		$t = array( 'page', 'post' );
		if ( class_exists( 'WooCommerce' ) ) {
			array_unshift( $t, 'product' );
		}
		return $t;
	}

	public static function url( $pt ) {
		return add_query_arg( array( 'page' => self::SLUG, 'pt' => $pt ), admin_url( 'admin.php' ) );
	}

	public static function list_button( $views ) {
		$pt = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( current_user_can( 'edit_others_posts' ) ) {
			$views['ssai_bulk_schema'] = '<a href="' . esc_url( self::url( $pt ) ) . '" style="font-weight:600">🔎 بررسی دسته‌جمعی Schema</a>';
		}
		return $views;
	}

	public static function menu() {
		$hook = add_submenu_page( '', 'بررسی دسته‌جمعی Schema', 'بررسی دسته‌جمعی Schema', 'edit_others_posts', self::SLUG, array( __CLASS__, 'page' ) );
		add_action(
			'load-' . $hook,
			function () {
				$GLOBALS['title'] = 'بررسی دسته‌جمعی Schema'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
			}
		);
	}

	/* ------------------------------------------------------------ Analysis */

	/** Schema type the page outputs now (own setting, else the post type default). */
	public static function current( $id, $pt ) {
		$t = (string) get_post_meta( $id, SSAI_SEO::M_SCHEMA, true );
		return '' === $t ? SSAI_SEO::default_schema( $pt ) : $t;
	}

	private static function custom_types( $id ) {
		$out = array();
		foreach ( SSAI_Schema::raw_custom( $id ) as $n ) {
			foreach ( (array) ( isset( $n['@type'] ) ? $n['@type'] : array() ) as $t ) {
				$out[ $t ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * @return array suggest (type or '' = keep), reason, issues[] ([level, text]), current, custom[]
	 */
	public static function analyze( $post ) {
		$id     = $post->ID;
		$pt     = $post->post_type;
		$cur    = self::current( $id, $pt );
		$custom = self::custom_types( $id );
		$r      = array(
			'current' => $cur,
			'custom'  => $custom,
			'suggest' => '',
			'reason'  => '',
			'issues'  => array(),
		);
		if ( 'product' === $pt ) {
			return self::analyze_product( $post, $r );
		}

		$special = self::special_page( $post );
		if ( $special ) {
			if ( ! in_array( $cur, array( 'none', 'WebPage' ), true ) ) {
				$r['suggest'] = 'none';
				$r['reason']  = $special . '؛ Schema مقاله یا خدمت برای آن درست نیست (فقط WebPage کافی است).';
			} else {
				$r['reason'] = $special . '؛ همان WebPage درست است.';
			}
			return $r;
		}

		$d     = SSAI_Content::data( $id );
		$title = $d ? $d['title'] : get_the_title( $post );
		$text  = $d ? $d['text'] : wp_strip_all_tags( $post->post_content );
		$words = $d ? (int) $d['words'] : 0;
		$h2    = 0;
		$qs    = 0;
		foreach ( $d ? $d['headings'] : array() as $h ) {
			if ( 2 === $h['level'] ) {
				$h2++;
			}
			if ( preg_match( '/[؟?]\s*$/u', $h['text'] ) ) {
				$qs++;
			}
		}
		$service = preg_match( '/هزینه|قیمت|تعرفه|نوبت|رزرو|مشاوره|خدمات|درمان|price|cost|book|appointment|service/iu', $title . ' ' . mb_substr( $text, 0, 4000 ) );
		$howto   = preg_match( '/چیست|چگونه|چطور|راهنما|آیا|بهترین|مقایسه|علت|علائم|مراحل|what|how|guide|why|best|vs\b/iu', $title );

		if ( 'post' === $pt ) {
			if ( ! in_array( $cur, array( 'Article', 'BlogPosting', 'NewsArticle' ), true ) && ! array_intersect( $custom, array( 'Article', 'BlogPosting', 'NewsArticle', 'MedicalWebPage' ) ) ) {
				$r['suggest'] = 'BlogPosting';
				$r['reason']  = 'نوشته وبلاگ است ولی Schema مقاله ندارد؛ BlogPosting نویسنده، تاریخ و تصویر را به گوگل می‌دهد.';
			}
		} elseif ( $howto && $words >= 500 && $h2 >= 2 ) {
			if ( ! in_array( $cur, array( 'Article', 'BlogPosting' ), true ) && ! array_intersect( $custom, array( 'Article', 'BlogPosting', 'MedicalWebPage' ) ) ) {
				$r['suggest'] = 'Article';
				$r['reason']  = sprintf( 'برگه آموزشی/مقاله‌ای است (%s کلمه، %d زیرعنوان، عنوان سؤالی/راهنما)؛ Schema مقاله مناسب‌تر است.', number_format_i18n( $words ), $h2 );
			}
		} elseif ( $service ) {
			if ( 'Service' !== $cur && ! array_intersect( $custom, array( 'Service', 'MedicalProcedure', 'Product', 'OfferCatalog', 'Course', 'Event' ) ) ) {
				$r['suggest'] = 'Service';
				$r['reason']  = 'برگه معرفی خدمت است (هزینه، نوبت، مشاوره یا درمان در متن)؛ Schema خدمت (Service) با ارائه‌دهنده و منطقه خدمت.';
			}
		} elseif ( 'none' === $cur && $words >= 800 && $h2 >= 3 ) {
			$r['suggest'] = 'Article';
			$r['reason']  = sprintf( 'برگه محتوایی بلند است (%s کلمه)؛ Schema مقاله نویسنده و تاریخ را مشخص می‌کند.', number_format_i18n( $words ) );
		}

		if ( $qs >= 3 && ! in_array( 'FAQPage', $custom, true ) && ! get_post_meta( $id, SSAI_AEO::META_FAQ, true ) ) {
			$r['issues'][] = array( 'info', sprintf( '%d تیتر سؤالی در متن هست ولی FAQ ساختاریافته ندارد؛ از گزارش صفحه › AEO بسازید.', $qs ) );
		}
		if ( 'Service' === ( '' !== $r['suggest'] ? $r['suggest'] : $cur ) && preg_match( '/پزشک|دندان|درمان|جراحی|ایمپلنت|کلینیک|clinic|dental|medical/iu', $title ) && ! in_array( 'MedicalProcedure', $custom, true ) ) {
			$r['issues'][] = array( 'info', 'خدمت پزشکی است؛ برای دقت بیشتر MedicalProcedure را با «سازنده Schema» همین صفحه بسازید.' );
		}
		if ( '' === $r['reason'] ) {
			$r['reason'] = 'Schema فعلی مناسب است.';
		}
		return $r;
	}

	/** Contact, about, legal, home and shop system pages keep a plain WebPage. */
	private static function special_page( $post ) {
		if ( (int) get_option( 'page_on_front' ) === $post->ID ) {
			return 'صفحه اصلی سایت';
		}
		if ( (int) get_option( 'page_for_posts' ) === $post->ID ) {
			return 'صفحه وبلاگ';
		}
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms' ) as $p ) {
				if ( wc_get_page_id( $p ) === $post->ID ) {
					return 'برگه سیستمی فروشگاه';
				}
			}
		}
		$s = urldecode( $post->post_name ) . ' ' . get_the_title( $post );
		if ( preg_match( '/contact|تماس|ارتباط/iu', $s ) ) {
			return 'برگه تماس';
		}
		if ( preg_match( '/about|درباره|معرفی ما/iu', $s ) ) {
			return 'برگه درباره ما';
		}
		if ( preg_match( '/privacy|terms|policy|قوانین|حریم|شرایط|rules/iu', $s ) ) {
			return 'برگه قوانین';
		}
		return '';
	}

	private static function analyze_product( $post, $r ) {
		$id = $post->ID;
		if ( 'Product' !== $r['current'] && ! in_array( 'Product', $r['custom'], true ) ) {
			$r['suggest'] = 'Product';
			$r['reason']  = 'none' === $r['current']
				? 'محصول است ولی Schema محصول ندارد (فقط صفحه معمولی)؛ قیمت، موجودی و تصویر به گوگل نمی‌رسد.'
				: 'محصول است ولی Schema آن «' . $r['current'] . '» است؛ باید Product باشد.';
		} else {
			$r['reason'] = 'Schema محصول فعال است.';
		}
		$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
		if ( ! $p ) {
			return $r;
		}
		if ( ! $p->get_image_id() ) {
			$r['issues'][] = array( 'error', 'تصویر ندارد (برای نتیجه غنی محصول لازم است).' );
		}
		if ( $p->is_type( 'variable' ) ) {
			$prices = $p->get_variation_prices( true );
			if ( empty( $prices['price'] ) || ! array_filter( array_map( 'floatval', $prices['price'] ) ) ) {
				$r['issues'][] = array( 'error', 'هیچ‌کدام از متغیرها قیمت ندارند؛ بدون قیمت، گوگل نتیجه غنی محصول نشان نمی‌دهد.' );
			}
		} elseif ( '' === (string) $p->get_price() ) {
			$r['issues'][] = array( 'error', 'قیمت ندارد؛ بدون قیمت (Offer)، گوگل نتیجه غنی محصول نشان نمی‌دهد.' );
		}
		if ( '' === trim( wp_strip_all_tags( $p->get_short_description() . $p->get_description() ) ) ) {
			$r['issues'][] = array( 'warning', 'توضیحات ندارد.' );
		}
		if ( ! $p->get_sku() && ! ( method_exists( $p, 'get_global_unique_id' ) && $p->get_global_unique_id() ) ) {
			$r['issues'][] = array( 'warning', 'شناسه (SKU) یا بارکد (GTIN) ندارد.' );
		}
		$schema = SSAI_Schema::product( $p, get_permalink( $id ), array( 'desc' => '' ) );
		if ( empty( $schema['brand'] ) ) {
			$r['issues'][] = array( 'warning', 'برند مشخص نیست (ویژگی brand یا طبقه‌بندی برند).' );
		}
		if ( 'outofstock' === $p->get_stock_status() ) {
			$r['issues'][] = array( 'info', 'ناموجود است (در Schema به‌صورت OutOfStock اعلام می‌شود).' );
		}
		return $r;
	}

	/* ------------------------------------------------------------ Screen */

	public static function page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		$pt    = isset( $_GET['pt'] ) ? sanitize_key( $_GET['pt'] ) : 'page'; // phpcs:ignore WordPress.Security.NonceVerification
		$pt    = in_array( $pt, self::types(), true ) ? $pt : 'page';
		$all   = ! empty( $_GET['all'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$paged = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$per   = 100;
		$obj   = get_post_type_object( $pt );
		$q     = new WP_Query(
			array(
				'post_type'      => $pt,
				'post_status'    => 'publish',
				'posts_per_page' => $per,
				'paged'          => $paged,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);
		$labels = SSAI_SEO::schema_types( $pt );
		$labels['WebPage'] = 'WebPage';
		$label  = function ( $t ) use ( $labels ) {
			return 'none' === $t ? 'فقط صفحه معمولی (WebPage)' : ( isset( $labels[ $t ] ) ? $labels[ $t ] : $t );
		};
		$rows   = array();
		$counts = array( 'suggest' => 0, 'error' => 0 );
		foreach ( $q->posts as $post ) {
			$a = self::analyze( $post );
			if ( '' !== $a['suggest'] ) {
				$counts['suggest']++;
			}
			foreach ( $a['issues'] as $is ) {
				if ( 'error' === $is[0] ) {
					$counts['error']++;
					break;
				}
			}
			if ( $all || '' !== $a['suggest'] || $a['issues'] ) {
				$rows[] = array( $post, $a );
			}
		}
		$undo = get_option( self::UNDO );
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>بررسی دسته‌جمعی Schema — <?php echo esc_html( $obj ? $obj->labels->name : $pt ); ?></h1>
			<?php
			if ( ! empty( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo '<div class="notice notice-success"><p>' . esc_html( sprintf( 'Schema %s مورد تغییر کرد.', number_format_i18n( (int) $_GET['done'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
			}
			if ( ! empty( $_GET['undone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				echo '<div class="notice notice-success"><p>آخرین تغییر دسته‌جمعی برگردانده شد.</p></div>';
			}
			if ( ! SSAI_SEO::on( 'schema' ) ) {
				echo '<div class="notice notice-warning"><p>ماژول Schema این افزونه الان خروجی نمی‌دهد (افزونه سئوی دیگری فعال است یا ماژول خاموش است). تغییرات ذخیره می‌شوند و بعد از غیرفعال کردن آن افزونه اعمال می‌شوند.</p></div>';
			}
			?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::types() as $t ) : $o = get_post_type_object( $t ); ?>
					<a class="nav-tab <?php echo $t === $pt ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $t ) ); ?>"><?php echo esc_html( $o ? $o->labels->name : $t ); ?></a>
				<?php endforeach; ?>
			</nav>
			<p>
				<?php echo esc_html( sprintf( '%s مورد بررسی شد؛ %s پیشنهاد تغییر', number_format_i18n( count( $q->posts ) ), number_format_i18n( $counts['suggest'] ) ) ); ?>
				<?php if ( $counts['error'] ) : ?>
					<?php echo esc_html( sprintf( '، %s مورد دارای خطای داده (باید در خود محصول/صفحه اصلاح شود)', number_format_i18n( $counts['error'] ) ) ); ?>
				<?php endif; ?>.
				&nbsp; <a href="<?php echo esc_url( add_query_arg( 'all', $all ? false : 1 ) ); ?>"><?php echo $all ? 'فقط موارد دارای پیشنهاد یا مشکل' : 'نمایش همه'; ?></a>
			</p>
			<?php if ( is_array( $undo ) && ! empty( $undo['items'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px">
					<?php wp_nonce_field( 'ssai_bulk_schema' ); ?>
					<input type="hidden" name="action" value="ssai_bulk_schema"><input type="hidden" name="undo" value="1"><input type="hidden" name="pt" value="<?php echo esc_attr( $pt ); ?>">
					<button class="button"><?php echo esc_html( sprintf( 'برگرداندن آخرین تغییر دسته‌جمعی (%s مورد، %s)', number_format_i18n( count( $undo['items'] ) ), wp_date( 'Y/m/d H:i', $undo['time'] ) ) ); ?></button>
				</form>
			<?php endif; ?>

			<?php if ( ! $rows ) : ?>
				<div class="ssai-card"><p>✓ همه موارد این صفحه Schema مناسب دارند.</p></div>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ssai_bulk_schema' ); ?>
				<input type="hidden" name="action" value="ssai_bulk_schema">
				<input type="hidden" name="pt" value="<?php echo esc_attr( $pt ); ?>">
				<table class="widefat striped ssai-bulk">
					<thead><tr>
						<td class="check-column"><input type="checkbox" id="ssai-bulk-all" title="انتخاب همه"></td>
						<th>عنوان</th><th>Schema فعلی</th><th>تغییر به</th><th>دلیل و مشکلات</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $row ) : list( $post, $a ) = $row; $target = '' !== $a['suggest'] ? $a['suggest'] : $a['current']; ?>
						<tr>
							<th class="check-column"><input type="checkbox" class="ssai-bulk-cb" name="ids[]" value="<?php echo esc_attr( $post->ID ); ?>" <?php checked( '' !== $a['suggest'] ); ?>></th>
							<td><strong><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post ) ? get_the_title( $post ) : '(بدون عنوان)' ); ?></a></strong>
								<div class="row-actions"><a href="<?php echo esc_url( get_permalink( $post ) ); ?>" target="_blank" rel="noopener">نمایش</a> | <a href="<?php echo esc_url( SSAI_Report::url( $post->ID ) ); ?>#ssai-schema-builder">سازنده Schema</a></div></td>
							<td><?php echo esc_html( $label( $a['current'] ) ); ?>
								<?php if ( $a['custom'] ) : ?><div class="description">+ <?php echo esc_html( implode( '، ', $a['custom'] ) ); ?></div><?php endif; ?></td>
							<td><select name="type[<?php echo esc_attr( $post->ID ); ?>]">
								<?php foreach ( SSAI_SEO::schema_types( $pt ) as $k => $l ) : if ( 'auto' === $k ) { continue; } ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $target, $k ); ?>><?php echo esc_html( $label( $k ) . ( $k === $a['suggest'] ? ' ← پیشنهاد' : '' ) ); ?></option>
								<?php endforeach; ?>
							</select></td>
							<td><?php echo esc_html( $a['reason'] ); ?>
								<?php if ( $a['issues'] ) : ?><ul class="ssai-bulk-issues">
									<?php foreach ( $a['issues'] as $is ) : ?>
										<li class="ssai-bulk-<?php echo esc_attr( $is[0] ); ?>"><?php echo esc_html( ( 'error' === $is[0] ? '✗ ' : ( 'warning' === $is[0] ? '⚠ ' : 'ℹ ' ) ) . $is[1] ); ?></li>
									<?php endforeach; ?>
								</ul><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button class="button button-primary">اعمال روی موارد تیک‌خورده</button>
					<span class="description">فقط نوع Schema ردیف‌های انتخاب‌شده عوض می‌شود؛ خطاهای داده (قیمت، تصویر…) را باید در خود محصول اصلاح کنید. تغییر قابل برگشت است.</span></p>
			</form>
			<?php
			endif;
			if ( $q->max_num_pages > 1 ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $q->max_num_pages ) ) ) . '</div></div>';
			}
			?>
			<script>
			(function(){var a=document.getElementById('ssai-bulk-all');if(!a)return;a.addEventListener('change',function(){document.querySelectorAll('.ssai-bulk-cb').forEach(function(c){c.checked=a.checked;});});
			document.querySelectorAll('.ssai-bulk select').forEach(function(s){s.addEventListener('change',function(){var c=s.closest('tr').querySelector('.ssai-bulk-cb');if(c)c.checked=true;});});})();
			</script>
		</div>
		<?php
	}

	/* ------------------------------------------------------------ Save / undo */

	public static function handle() {
		check_admin_referer( 'ssai_bulk_schema' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$pt = isset( $_POST['pt'] ) ? sanitize_key( $_POST['pt'] ) : 'page';
		if ( ! empty( $_POST['undo'] ) ) {
			$u = get_option( self::UNDO );
			if ( is_array( $u ) && ! empty( $u['items'] ) ) {
				foreach ( $u['items'] as $id => $old ) {
					if ( current_user_can( 'edit_post', $id ) ) {
						'' === $old ? delete_post_meta( $id, SSAI_SEO::M_SCHEMA ) : update_post_meta( $id, SSAI_SEO::M_SCHEMA, $old );
						SSAI_Applier::purge_cache( $id );
					}
				}
			}
			delete_option( self::UNDO );
			wp_safe_redirect( add_query_arg( 'undone', 1, self::url( $pt ) ) );
			exit;
		}
		$ids   = isset( $_POST['ids'] ) ? array_map( 'intval', (array) $_POST['ids'] ) : array();
		$types = isset( $_POST['type'] ) ? (array) wp_unslash( $_POST['type'] ) : array();
		$undo  = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! current_user_can( 'edit_post', $id ) || ! isset( $types[ $id ] ) ) {
				continue;
			}
			$t     = sanitize_text_field( $types[ $id ] );
			$valid = SSAI_SEO::schema_types( $post->post_type );
			if ( ! isset( $valid[ $t ] ) || 'auto' === $t ) {
				continue;
			}
			$old = (string) get_post_meta( $id, SSAI_SEO::M_SCHEMA, true );
			if ( self::current( $id, $post->post_type ) === $t ) {
				continue;
			}
			$undo[ $id ] = $old;
			// Store nothing when the choice equals the post type default, so later default changes still apply.
			SSAI_SEO::default_schema( $post->post_type ) === $t ? delete_post_meta( $id, SSAI_SEO::M_SCHEMA ) : update_post_meta( $id, SSAI_SEO::M_SCHEMA, $t );
			SSAI_Applier::purge_cache( $id );
		}
		if ( $undo ) {
			update_option( self::UNDO, array( 'time' => time(), 'items' => $undo ), false );
		}
		wp_safe_redirect( add_query_arg( 'done', count( $undo ), self::url( $pt ) ) );
		exit;
	}
}
