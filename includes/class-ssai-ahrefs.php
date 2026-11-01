<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import of Ahrefs exports (free plan): Site Audit "Bulk export" CSVs and the Site Explorer
 * history. Turns them into fix lists: links to redirects, broken links, mixed content,
 * images without alt, orphan pages, page speed / H1 problems, referring-domain growth.
 */
class SSAI_Ahrefs {

	const OPT = 'ssai_ahrefs';

	public static function init() {
		add_action( 'admin_post_ssai_ahrefs_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ssai_ahrefs_clear', array( __CLASS__, 'handle_clear' ) );
		add_action( 'wp_ajax_ssai_ahrefs_alt', array( __CLASS__, 'ajax_alt' ) );
		add_action( 'wp_ajax_ssai_ahrefs_redirect', array( __CLASS__, 'ajax_redirect' ) );
	}

	public static function data() {
		$d = get_option( self::OPT );
		return is_array( $d ) ? $d : null;
	}

	/* ================================================================ Parsing */

	/** UTF-16 (Ahrefs Site Audit) or UTF-8 text → rows, tab or comma separated. */
	public static function rows( $raw ) {
		if ( "\xFF\xFE" === substr( $raw, 0, 2 ) || "\xFE\xFF" === substr( $raw, 0, 2 ) || substr_count( substr( $raw, 0, 200 ), "\0" ) > 20 ) {
			$enc = "\xFE\xFF" === substr( $raw, 0, 2 ) ? 'UTF-16BE' : 'UTF-16LE';
			$raw = preg_replace( '/^\xFF\xFE|^\xFE\xFF/', '', $raw );
			$raw = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $raw, 'UTF-8', $enc ) : iconv( $enc, 'UTF-8//IGNORE', $raw );
		}
		$raw   = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $raw );
		$first = strtok( $raw, "\n" );
		$delim = substr_count( (string) $first, "\t" ) > substr_count( (string) $first, ',' ) ? "\t" : ',';
		$fh    = fopen( 'php://temp', 'r+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		$rows = array();
		$head = null;
		while ( false !== ( $r = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) ) { // phpcs:ignore
			if ( null === $head ) {
				$head = array_map( 'trim', $r );
				continue;
			}
			if ( count( $r ) < 2 ) {
				continue;
			}
			$row = array();
			foreach ( $head as $i => $h ) {
				$row[ $h ] = isset( $r[ $i ] ) ? trim( $r[ $i ] ) : '';
			}
			$rows[] = $row;
		}
		fclose( $fh );
		return array( (array) $head, $rows );
	}

	/** Which Ahrefs report a file is (filename first, columns as fallback). */
	public static function kind( $name, $head ) {
		$n = strtolower( $name );
		$h = array_flip( $head );
		if ( isset( $h['Metric'] ) && isset( $h['Referring domains'] ) ) {
			return 'history';
		}
		if ( isset( $h['No. of redirect inlinks'] ) ) {
			return 'orphans';
		}
		if ( isset( $h['Loading time (ms)'] ) && isset( $h['URL'] ) ) {
			return 'pages';
		}
		if ( isset( $h['Source URL'], $h['Target URL'] ) ) {
			foreach ( array( 'target-redi' => 'redirects', 'redirect' => 'redirects', '3xx' => 'redirects', '4xx' => 'broken', 'broken' => 'broken', 'mixed' => 'mixed', 'image' => 'images', 'alt' => 'images' ) as $k => $v ) {
				if ( false !== strpos( $n, $k ) ) {
					return $v;
				}
			}
			if ( isset( $h['Image type'] ) ) {
				return 'images';
			}
			return 'links';
		}
		return '';
	}

	public static function parse( $files ) {
		$d     = self::data();
		$d     = $d ? $d : array();
		$got   = array();
		$pages = isset( $d['pages'] ) ? $d['pages'] : array();
		foreach ( $files as $f ) {
			list( $head, $rows ) = self::rows( $f[1] );
			$kind                = self::kind( $f[0], $head );
			if ( '' === $kind || 'links' === $kind ) {
				continue;
			}
			$got[] = $kind;
			switch ( $kind ) {
				case 'pages':
					$map = isset( $d['final'] ) ? $d['final'] : array();
					foreach ( $rows as $r ) {
						if ( ! empty( $r['Final redirect URL'] ) && $r['Final redirect URL'] !== $r['URL'] ) {
							$map[ $r['URL'] ] = $r['Final redirect URL'];
						}
						if ( '200' !== $r['HTTP status code'] || false === stripos( $r['Content type'], 'html' ) ) {
							continue;
						}
						$h1s = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $r['H1'] ) ) ) );
						$pages[ SSAI_GSC_Import::url_key( $r['URL'] ) ] = array(
							'url'   => $r['URL'],
							'load'  => (int) $r['Loading time (ms)'],
							'words' => (int) $r['No. of content words'],
							'in'    => (int) $r['No. of href inlinks'],
							'depth' => (int) $r['Depth'],
							'h1'    => $h1s,
							'tlen'  => (int) $r['Title length'],
							'mdlen' => (int) preg_replace( '/\D.*/s', '', $r['Meta description length'] ),
							'rd'    => isset( $r['No. of referring domains'] ) ? (int) $r['No. of referring domains'] : 0,
							'bl'    => isset( $r['No. of backlinks'] ) ? (int) $r['No. of backlinks'] : 0,
							'index' => 'true' === $r['Is indexable page'],
						);
					}
					$d['final'] = $map;
					break;
				case 'redirects':
				case 'broken':
				case 'mixed':
				case 'images':
					$list = array();
					foreach ( $rows as $r ) {
						if ( 'images' === $kind && '' !== $r['Alt attribute'] ) {
							continue;
						}
						if ( 'Sitemap URL' === $r['Link type'] ) {
							$key = $r['Target URL'];
							if ( ! isset( $list[ $key ] ) ) {
								$list[ $key ] = array( 'n' => 0, 'src' => array(), 'anchors' => array(), 'code' => '', 'sitemap' => true );
							}
							$list[ $key ]['sitemap'] = true;
							continue;
						}
						$key = $r['Target URL'];
						if ( ! isset( $list[ $key ] ) ) {
							$list[ $key ] = array( 'n' => 0, 'src' => array(), 'anchors' => array(), 'code' => isset( $r['Target HTTP status code'] ) ? $r['Target HTTP status code'] : '', 'sitemap' => false );
						}
						$list[ $key ]['n']++;
						if ( count( $list[ $key ]['src'] ) < 30 ) {
							$list[ $key ]['src'][] = $r['Source URL'];
						}
						$a = isset( $r['Anchor'] ) ? trim( $r['Anchor'] ) : '';
						if ( '' !== $a && count( $list[ $key ]['anchors'] ) < 5 && ! in_array( $a, $list[ $key ]['anchors'], true ) ) {
							$list[ $key ]['anchors'][] = $a;
						}
					}
					uasort(
						$list,
						function ( $a, $b ) {
							return $b['n'] - $a['n'];
						}
					);
					$d[ $kind ] = $list;
					break;
				case 'orphans':
					$d['orphans'] = array_values( wp_list_pluck( $rows, 'URL' ) );
					break;
				case 'history':
					$h = array();
					foreach ( $rows as $r ) {
						if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $r['Metric'] ) ) {
							$h[ $r['Metric'] ] = array( (int) $r['Referring domains'], (int) $r['Organic traffic'] );
						}
					}
					ksort( $h );
					// Keep one point per week (plus the last day) – enough for a chart.
					$keep = array();
					$i    = 0;
					foreach ( $h as $date => $v ) {
						if ( 0 === $i++ % 7 || array_key_last( $h ) === $date ) {
							$keep[ $date ] = $v;
						}
					}
					$d['history'] = array_slice( $keep, -160, null, true );
					break;
			}
		}
		if ( ! $got ) {
			return new WP_Error( 'ssai_ahrefs', 'در فایل‌ها خروجی شناخته‌شده‌ای از Ahrefs پیدا نشد (Bulk export در Site Audit، یا Overview در Site Explorer).' );
		}
		$d['pages'] = $pages;
		$d['time']  = time();
		$d['got']   = array_values( array_unique( array_merge( isset( $d['got'] ) ? $d['got'] : array(), $got ) ) );
		return $d;
	}

	/* ================================================================ Handlers */

	private static function back( $msg, $type = 'success' ) {
		set_transient( 'ssai_site_notice_' . get_current_user_id(), array( 'msg' => $msg, 'type' => $type ), 60 );
		wp_safe_redirect( SSAI_Site::url( array( 'tab' => 'ahrefs' ) ) );
		exit;
	}

	public static function handle_import() {
		check_admin_referer( 'ssai_ahrefs_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$files = array();
		$up    = isset( $_FILES['ahrefs_files'] ) ? $_FILES['ahrefs_files'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $up && is_array( $up['name'] ) ) {
			foreach ( $up['name'] as $i => $name ) {
				if ( UPLOAD_ERR_OK !== $up['error'][ $i ] ) {
					continue;
				}
				$path = $up['tmp_name'][ $i ];
				if ( preg_match( '/\.zip$/i', $name ) ) {
					$files = array_merge( $files, self::unzip( $path ) );
				} elseif ( preg_match( '/\.csv$/i', $name ) ) {
					$files[] = array( sanitize_file_name( $name ), (string) file_get_contents( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}
		if ( ! $files ) {
			self::back( 'فایل CSV یا ZIP معتبری پیدا نشد (اگر ZIP دادید، داخلش باید فایل‌های CSV باشد).', 'error' );
		}
		$d = self::parse( $files );
		if ( is_wp_error( $d ) ) {
			self::back( $d->get_error_message(), 'error' );
		}
		update_option( self::OPT, $d, false );
		$names = array(
			'pages'     => 'صفحه‌ها',
			'redirects' => 'لینک به ریدایرکت',
			'broken'    => 'لینک خراب',
			'mixed'     => 'Mixed content',
			'images'    => 'تصاویر بدون alt',
			'orphans'   => 'صفحه‌های یتیم',
			'history'   => 'تاریخچه بک‌لینک',
		);
		self::back( 'وارد شد: ' . implode( '، ', array_map( function ( $k ) use ( $names ) { return $names[ $k ]; }, $d['got'] ) ) );
	}

	/** CSV files inside a ZIP (ZipArchive, or WordPress's bundled PclZip when the extension is missing). */
	public static function unzip( $path ) {
		$out = array();
		if ( class_exists( 'ZipArchive' ) ) {
			$z = new ZipArchive();
			if ( true === $z->open( $path ) ) {
				for ( $j = 0; $j < $z->numFiles; $j++ ) {
					$en = $z->getNameIndex( $j );
					if ( preg_match( '/\.csv$/i', $en ) && false === strpos( $en, '__MACOSX' ) ) {
						$out[] = array( basename( $en ), (string) $z->getFromIndex( $j ) );
					}
				}
				$z->close();
			}
			return $out;
		}
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$z    = new PclZip( $path );
		$list = $z->extract( PCLZIP_OPT_EXTRACT_AS_STRING );
		foreach ( is_array( $list ) ? $list : array() as $f ) {
			if ( empty( $f['folder'] ) && preg_match( '/\.csv$/i', $f['filename'] ) && false === strpos( $f['filename'], '__MACOSX' ) ) {
				$out[] = array( basename( $f['filename'] ), (string) $f['content'] );
			}
		}
		return $out;
	}

	public static function handle_clear() {
		check_admin_referer( 'ssai_ahrefs_clear' );
		if ( current_user_can( 'manage_options' ) ) {
			delete_option( self::OPT );
		}
		self::back( 'داده‌های Ahrefs پاک شد.' );
	}

	public static function ajax_alt() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( SSAI_Settings::get( 'apply_cap' ) ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$src = isset( $_POST['src'] ) ? esc_url_raw( wp_unslash( $_POST['src'] ) ) : '';
		$alt = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		if ( '' === $alt ) {
			wp_send_json_error( array( 'message' => 'متن alt را بنویسید.' ) );
		}
		$id = attachment_url_to_postid( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $src ) );
		if ( ! $id ) {
			$id = attachment_url_to_postid( $src );
		}
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'این تصویر در کتابخانه رسانه پیدا نشد (احتمالاً بخشی از قالب یا افزونه است)؛ alt را در همان‌جا تنظیم کنید.' ) );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		// Elementor widgets that saved an empty alt for this image get it too.
		$n = 0;
		global $wpdb;
		$file = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s", '%' . $wpdb->esc_like( $file ) . '%' ) ) as $pid ) { // phpcs:ignore WordPress.DB
			$raw = SSAI_Elementor::get_raw( (int) $pid );
			$new = preg_replace( '/("id":' . (int) $id . ',[^{}]*?"alt":")("|[^"]*")/', '$1' . str_replace( array( '\\', '$', '"' ), array( '\\\\', '\$', '\\"' ), $alt ) . '"', $raw, -1, $c );
			if ( $c && is_array( json_decode( $new, true ) ) ) {
				SSAI_Elementor::save_raw( (int) $pid, $new );
				$n += $c;
			}
		}
		wp_send_json_success( array( 'message' => 'alt در کتابخانه رسانه ذخیره شد' . ( $n ? sprintf( ' و در %d ویجت المنتور', $n ) : '' ) . '. ویجت‌هایی که alt را از کتابخانه می‌خوانند خودکار درست می‌شوند؛ کش را پاک کنید.' ) );
	}

	public static function ajax_redirect() {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$from = isset( $_POST['from'] ) ? esc_url_raw( wp_unslash( $_POST['from'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		SSAI_Redirects::install();
		$r = SSAI_Redirects::add( $from, $to, 301, 'exact', 'از گزارش Ahrefs' );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'ریدایرکت 301 ساخته شد' . ( SSAI_SEO::on( 'redirects' ) ? '.' : ' (بعد از غیرفعال شدن Rank Math اجرا می‌شود).' ) ) );
	}

	/* ================================================================ Helpers */

	/** Best page for an anchor text (when the redirect target is the home page, the link is probably wrong). */
	public static function suggest_by_anchor( $anchors ) {
		$best = 0;
		$bid  = 0;
		$list = get_transient( 'ssai_title_list' );
		if ( ! is_array( $list ) ) {
			$list = array();
			foreach ( get_posts( array( 'post_type' => SSAI_Admin::post_types(), 'post_status' => 'publish', 'numberposts' => 2000, 'fields' => 'ids' ) ) as $id ) {
				$list[ $id ] = SSAI_Content::norm( get_the_title( $id ) . ' ' . SSAI_Content::get_focus_keyword( $id ) );
			}
			set_transient( 'ssai_title_list', $list, HOUR_IN_SECONDS );
		}
		foreach ( (array) $anchors as $a ) {
			$a = SSAI_Content::norm( $a );
			if ( mb_strlen( $a ) < 4 ) {
				continue;
			}
			foreach ( $list as $id => $t ) {
				similar_text( $a, $t, $pct );
				if ( false !== mb_strpos( $t, $a ) ) {
					$pct = max( $pct, 80 );
				}
				if ( $pct > $best ) {
					$best = $pct;
					$bid  = $id;
				}
			}
		}
		return $best >= 55 ? get_permalink( $bid ) : '';
	}

	/** Proposed new target for a link that currently goes through a redirect. */
	public static function proposal( $target, $row, $final_map ) {
		$final = isset( $final_map[ $target ] ) ? $final_map[ $target ] : '';
		if ( '' === $final ) {
			$m = SSAI_Redirects::ready() ? SSAI_Redirects::match( SSAI_Redirects::norm( $target ) ) : null;
			if ( $m && $m->url ) {
				$final = $m->url;
			}
		}
		$fp         = (string) wp_parse_url( $final, PHP_URL_PATH );
		$home_final = '' !== $final && in_array( $fp, array( '', '/' ), true ) && ! wp_parse_url( $final, PHP_URL_QUERY );
		$warn       = '';
		$to         = $final;
		if ( ( '' === $final || $home_final ) && $row['anchors'] ) {
			$s = self::suggest_by_anchor( $row['anchors'] );
			if ( $s ) {
				$to   = $s;
				$warn = $home_final ? 'این آدرس به صفحه اصلی ریدایرکت می‌شود، ولی متن لینک درباره موضوع دیگری است؛ صفحه پیشنهادی بر اساس متن لینک است.' : '';
			} elseif ( $home_final ) {
				$warn = 'این آدرس به صفحه اصلی ریدایرکت می‌شود؛ احتمالاً لینک اشتباه است. صفحه درست را وارد کنید.';
			}
		}
		return array( $final, $to, $warn );
	}

	/** Counts for the dashboard. */
	public static function summary() {
		$d = self::data();
		if ( ! $d ) {
			return null;
		}
		$pages = isset( $d['pages'] ) ? $d['pages'] : array();
		$sum   = function ( $k ) use ( $d ) {
			return isset( $d[ $k ] ) ? array_sum( wp_list_pluck( $d[ $k ], 'n' ) ) : 0;
		};
		$load  = $pages ? wp_list_pluck( $pages, 'load' ) : array();
		sort( $load );
		$rd    = null;
		if ( ! empty( $d['history'] ) ) {
			$h    = array_values( $d['history'] );
			$last = end( $h );
			$old  = count( $h ) > 26 ? $h[ count( $h ) - 27 ] : $h[0]; // ~6 months ago.
			$rd   = array( $last[0], $old[0] );
		}
		return array(
			'time'      => $d['time'],
			'pages'     => count( $pages ),
			'redirects' => $sum( 'redirects' ),
			'broken'    => $sum( 'broken' ),
			'mixed'     => $sum( 'mixed' ),
			'images'    => $sum( 'images' ),
			'orphans'   => isset( $d['orphans'] ) ? count( $d['orphans'] ) : 0,
			'no_h1'     => count( array_filter( $pages, function ( $p ) { return ! $p['h1']; } ) ),
			'multi_h1'  => count( array_filter( $pages, function ( $p ) { return count( $p['h1'] ) > 1; } ) ),
			'load'      => $load ? $load[ (int) floor( count( $load ) / 2 ) ] : null,
			'rd'        => $rd,
		);
	}

	/* ================================================================ Screen */

	private static function short( $url ) {
		$p = (string) wp_parse_url( $url, PHP_URL_PATH ) . ( wp_parse_url( $url, PHP_URL_QUERY ) ? '?' . wp_parse_url( $url, PHP_URL_QUERY ) : '' );
		$h = (string) wp_parse_url( $url, PHP_URL_HOST );
		return rawurldecode( $h && false === stripos( home_url(), $h ) ? $h . $p : $p );
	}

	private static function plink( $url ) {
		$id = SSAI_GSC_Import::find_post( $url );
		return $id ? '<a href="' . esc_url( SSAI_Report::url( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" dir="ltr">' . esc_html( self::short( $url ) ) . '</a>';
	}

	public static function render() {
		$d     = self::data();
		$admin = current_user_can( 'manage_options' );
		wp_enqueue_script( 'ssai-ahrefs', SSAI_URL . 'assets/ahrefs.js', array( 'ssai-admin' ), SSAI_VERSION, true );
		?>
		<section class="ssai-card">
			<h2>وارد کردن خروجی Ahrefs (پلن رایگان)</h2>
			<ol class="ssai-steps">
				<li>در Ahrefs به <strong>Site Audit › Bulk export</strong> بروید و از این جدول‌ها «Export CSV» بگیرید: <em>Internal URLs</em> (یا Internal HTML URLs)، <em>Links to 3xx</em>، <em>Links to 4xx</em>، <em>Image references without alt texts</em>، <em>Mixed content</em>، <em>Orphan pages</em>.</li>
				<li>اختیاری: در <strong>Site Explorer › Overview</strong> خروجی نمودار (Export) را بگیرید تا روند دامنه‌های ارجاع‌دهنده دیده شود.</li>
				<li>همه را با هم (یا یک ZIP) این‌جا بارگذاری کنید. هر بار که دوباره Crawl کردید، فایل‌های تازه را وارد کنید.</li>
			</ol>
			<?php if ( $admin ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="ssai_ahrefs_import">
					<?php wp_nonce_field( 'ssai_ahrefs_import' ); ?>
					<input type="file" name="ahrefs_files[]" multiple accept=".csv,.zip" required>
					<span class="description">یک فایل ZIP شامل همه CSVها، یا چند CSV با هم (در پنجره انتخاب فایل با Ctrl همه را انتخاب کنید).</span>
					<button class="button button-primary">وارد کردن</button>
				</form>
			<?php endif; ?>
			<?php if ( $d ) : ?>
				<p class="description">آخرین ورود: <?php echo esc_html( wp_date( 'Y/m/d H:i', $d['time'] ) ); ?>
				<?php if ( $admin ) : ?>
					— <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssai-inline-form"><input type="hidden" name="action" value="ssai_ahrefs_clear"><?php wp_nonce_field( 'ssai_ahrefs_clear' ); ?><button class="button-link ssai-del">پاک کردن داده‌ها</button></form>
				<?php endif; ?>
				</p>
			<?php endif; ?>
		</section>
		<?php
		if ( ! $d ) {
			return;
		}
		$s = self::summary();
		?>
		<section class="ssai-card">
			<h2>خلاصه</h2>
			<div class="ssai-tiles">
				<?php
				$t = function ( $label, $v, $sub = '', $cls = '', $anchor = '' ) {
					$tag = $anchor ? 'a href="#' . esc_attr( $anchor ) . '"' : 'div';
					echo '<' . $tag . ' class="ssai-tile' . ( $anchor ? ' ssai-tile-link-box' : '' ) . ( $cls ? ' ssai-tile-' . esc_attr( $cls ) : '' ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $v ) . '</strong>' . ( $sub ? '<span class="description">' . esc_html( $sub ) . '</span>' : '' ) . ( $anchor && '0' !== $v ? '<span class="ssai-tile-more">فهرست ↓</span>' : '' ) . '</' . ( $anchor ? 'a' : 'div' ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
				};
				$t( 'لینک داخلی به آدرس ریدایرکت‌شده', number_format_i18n( $s['redirects'] ), 'سرعت و اعتبار لینک را کم می‌کند', $s['redirects'] ? 'warning' : 'good', $s['redirects'] ? 'ssai-ah-redirects' : '' );
				$t( 'لینک خراب (4xx)', number_format_i18n( $s['broken'] ), '', $s['broken'] ? 'error' : 'good', $s['broken'] ? 'ssai-ah-broken' : '' );
				$t( 'Mixed content', number_format_i18n( $s['mixed'] ), 'لینک یا منبع http', $s['mixed'] ? 'warning' : 'good', $s['mixed'] ? 'ssai-ah-mixed' : '' );
				$t( 'تصویر بدون alt', number_format_i18n( $s['images'] ), '', $s['images'] ? 'warning' : 'good', $s['images'] ? 'ssai-ah-images' : '' );
				$t( 'صفحه یتیم', number_format_i18n( $s['orphans'] ), '', $s['orphans'] ? 'warning' : 'good', $s['orphans'] ? 'ssai-ah-orphans' : '' );
				$t( 'صفحه بدون H1', number_format_i18n( $s['no_h1'] ), 'هر صفحه باید یک H1 داشته باشد', $s['no_h1'] ? 'error' : 'good', $s['no_h1'] ? 'ssai-ah-h1' : '' );
				$t( 'صفحه با چند H1', number_format_i18n( $s['multi_h1'] ), '', $s['multi_h1'] ? 'warning' : 'good', $s['multi_h1'] ? 'ssai-ah-h1' : '' );
				if ( null !== $s['load'] ) {
					$t( 'زمان بارگذاری (میانه)', number_format_i18n( $s['load'] ) . ' ms', 'از دید ربات Ahrefs', $s['load'] > 800 ? 'error' : ( $s['load'] > 500 ? 'warning' : 'good' ), 'ssai-ah-pages' );
				}
				if ( $s['rd'] ) {
					$t( 'دامنه‌های ارجاع‌دهنده (Ahrefs)', number_format_i18n( $s['rd'][0] ), '۶ ماه پیش: ' . number_format_i18n( $s['rd'][1] ), $s['rd'][1] && $s['rd'][0] > 5 * $s['rd'][1] ? 'error' : '', 'ssai-ah-history' );
				}
				?>
			</div>
			<p class="description">Ahrefs: <a href="https://ahrefs.com/" target="_blank" rel="noopener">Domain Rating by Ahrefs</a>. هیچ تغییری بدون زدن دکمه و تأیید شما انجام نمی‌شود؛ هر اصلاح لینک از «تاریخچه اصلاح‌ها» پایین صفحه برمی‌گردد.</p>
		</section>
		<?php
		self::render_history( $d, $s );
		self::render_h1( $d );
		self::render_links( $d, 'redirects' );
		self::render_links( $d, 'broken' );
		self::render_links( $d, 'mixed' );
		self::render_images( $d );
		self::render_pages( $d );
		self::render_log();
	}

	private static function render_history( $d, $s ) {
		if ( empty( $d['history'] ) ) {
			return;
		}
		$pts = array();
		foreach ( $d['history'] as $date => $v ) {
			$pts[] = array( $date, $v[0] );
		}
		echo '<section class="ssai-card" id="ssai-ah-history"><h2>روند دامنه‌های ارجاع‌دهنده (Ahrefs)</h2>';
		echo SSAI_Report::chart( array_slice( $pts, -80 ), 'دامنه‌های ارجاع‌دهنده', '#d63638' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $s['rd'] && $s['rd'][1] && $s['rd'][0] > 5 * $s['rd'][1] ) {
			echo '<p class="ssai-msg ssai-msg-error"><strong>رشد ناگهانی:</strong> در حدود ۶ ماه دامنه‌های لینک‌دهنده از ' . esc_html( number_format_i18n( $s['rd'][1] ) ) . ' به ' . esc_html( number_format_i18n( $s['rd'][0] ) ) . ' رسیده است. اگر خودتان لینک نخریده‌اید یا کمپین لینک‌سازی نداشته‌اید، این الگوی معمول لینک‌های اسپم خودکار (یا حمله سئوی منفی) است. گوگل بیشتر این لینک‌ها را نادیده می‌گیرد؛ در Ahrefs به Site Explorer › Referring domains بروید و نمونه‌ها را ببینید. Disavow فقط وقتی لازم است که Manual action گرفته باشید.</p>';
		}
		echo '</section>';
	}

	private static function render_links( $d, $kind ) {
		if ( empty( $d[ $kind ] ) ) {
			return;
		}
		$titles = array(
			'redirects' => array( 'لینک‌های داخلی به آدرس‌های ریدایرکت‌شده', 'لینک را مستقیم به آدرس نهایی ببرید. آدرس‌هایی که در «همه صفحه‌ها» تکرار شده‌اند در هدر، فوتر یا منو هستند و با یک بار اصلاح درست می‌شوند.' ),
			'broken'    => array( 'لینک‌های خراب (4xx)', 'یا لینک را به صفحه درست ببرید، یا برای آدرس خراب یک ریدایرکت 301 بسازید (برای لینک‌هایی که از سایت‌های دیگر هم به آن آدرس می‌آیند بهتر است).' ),
			'mixed'     => array( 'Mixed content (لینک یا منبع http در صفحه https)', 'نسخه https را جایگزین کنید.' ),
		);
		$pages  = max( 1, count( isset( $d['pages'] ) ? $d['pages'] : array() ) );
		$final  = isset( $d['final'] ) ? $d['final'] : array();
		echo '<section class="ssai-card ssai-ah-links" id="ssai-ah-' . esc_attr( $kind ) . '"><h2>' . esc_html( $titles[ $kind ][0] ) . '</h2><p class="description">' . esc_html( $titles[ $kind ][1] ) . '</p>';
		echo '<div class="ssai-table-wrap"><table class="widefat striped"><thead><tr><th>آدرس فعلی</th><th>تعداد / متن لینک</th><th>جایگزین</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $d[ $kind ], 0, 60, true ) as $target => $row ) {
			$warn = '';
			if ( 'redirects' === $kind ) {
				list( $fin, $to, $warn ) = self::proposal( $target, $row, $final );
			} elseif ( 'broken' === $kind ) {
				$to = self::suggest_by_anchor( $row['anchors'] );
				$to = '' !== $to ? $to : SSAI_Redirects::suggest( (string) wp_parse_url( $target, PHP_URL_PATH ) );
			} else {
				$to = preg_replace( '#^http://#i', 'https://', $target );
			}
			$wide = $row['n'] >= max( 3, 0.8 * $pages );
			echo '<tr data-old="' . esc_attr( $target ) . '">';
			echo '<td dir="ltr" style="text-align:right;word-break:break-all">' . esc_html( self::short( $target ) ) . ( 'redirects' === $kind && ! empty( $fin ) ? '<div class="description">→ ریدایرکت به ' . esc_html( self::short( $fin ) ) . '</div>' : '' ) . ( $row['code'] && 'redirects' !== $kind ? ' <span class="description">(' . esc_html( $row['code'] ) . ')</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['n'] ) ) . ' لینک' . ( $wide ? ' <span class="ssai-pos ssai-risk-warning">همه صفحه‌ها (هدر/فوتر/منو)</span>' : '' ) . ( ! empty( $row['sitemap'] ) ? ' <span class="ssai-pos ssai-risk-warning">در نقشه سایت</span>' : '' ) . ( $row['anchors'] ? '<div class="description">«' . esc_html( implode( '»، «', $row['anchors'] ) ) . '»</div>' : '' ) . '</td>';
			echo '<td><input type="text" class="regular-text ssai-ah-new" dir="ltr" value="' . esc_attr( $to ) . '" aria-label="آدرس جایگزین">' . ( $warn ? '<div class="ssai-hint ssai-warn-text">' . esc_html( $warn ) . '</div>' : '' ) . '</td>';
			echo '<td class="ssai-ah-actions"><button type="button" class="button button-small ssai-ah-scan">پیدا کردن</button> <button type="button" class="button button-small button-primary ssai-ah-fix">جایگزینی</button>' . ( 'broken' === $kind ? ' <button type="button" class="button button-small ssai-ah-301">ریدایرکت 301</button>' : '' ) . '<div class="ssai-ah-out" role="status"></div></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></section>';
	}

	private static function render_images( $d ) {
		if ( empty( $d['images'] ) ) {
			return;
		}
		$pages = max( 1, count( isset( $d['pages'] ) ? $d['pages'] : array() ) );
		echo '<section class="ssai-card" id="ssai-ah-images"><h2>تصاویر بدون متن alt</h2><p class="description">تصاویری که در همه صفحه‌ها تکرار شده‌اند (لوگو و آیکن‌های هدر/فوتر) با یک بار ذخیره alt در کتابخانه رسانه درست می‌شوند. برای آیکن‌های تزئینی یک کلمه کوتاه کافی است (مثلاً «تماس تلفنی»). تصاویر داخل محتوای هر صفحه را با «alt با هوش مصنوعی» در گزارش همان صفحه اصلاح کنید.</p>';
		echo '<div class="ssai-table-wrap"><table class="widefat striped"><thead><tr><th>تصویر</th><th>تعداد</th><th>متن alt</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $d['images'], 0, 40, true ) as $src => $row ) {
			$wide = $row['n'] >= max( 3, 0.8 * $pages );
			echo '<tr data-src="' . esc_attr( $src ) . '"><td><img src="' . esc_url( $src ) . '" alt="" loading="lazy" style="max-width:48px;max-height:48px;vertical-align:middle"> <span dir="ltr" class="description">' . esc_html( rawurldecode( basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) ) . '</span></td>';
			echo '<td>' . esc_html( number_format_i18n( $row['n'] ) ) . ( $wide ? ' <span class="ssai-pos ssai-risk-warning">همه صفحه‌ها</span>' : '' ) . ( ! $wide && $row['src'] ? '<div class="description">' . wp_kses_post( self::plink( $row['src'][0] ) ) . '</div>' : '' ) . '</td>';
			echo '<td><input type="text" class="regular-text ssai-ah-alt" aria-label="متن alt"></td><td><button type="button" class="button button-small button-primary ssai-ah-altsave">ذخیره alt</button><div class="ssai-ah-out" role="status"></div></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/** Edit link for a URL (Elementor when the page is built with it). */
	private static function edit_link( $url ) {
		$id = SSAI_GSC_Import::find_post( $url );
		if ( ! $id ) {
			return '';
		}
		$el = SSAI_Elementor::is_built( $id );
		return ' <a class="button button-small" href="' . esc_url( $el ? SSAI_Elementor::edit_url( $id ) : get_edit_post_link( $id, 'raw' ) ) . '" target="_blank" rel="noopener">ویرایش' . ( $el ? ' در المنتور' : '' ) . '</a>';
	}

	private static function render_h1( $d ) {
		$pages = isset( $d['pages'] ) ? $d['pages'] : array();
		$none  = array_filter( $pages, function ( $p ) { return ! $p['h1']; } );
		$multi = array_filter( $pages, function ( $p ) { return count( $p['h1'] ) > 1; } );
		if ( ! $none && ! $multi ) {
			return;
		}
		echo '<section class="ssai-card" id="ssai-ah-h1"><h2>مشکلات H1</h2><p class="description">هر صفحه باید دقیقاً یک H1 داشته باشد که موضوع اصلی صفحه (و معمولاً کلمه کلیدی) را بگوید. در المنتور: روی ویجت «تیتر» کلیک کنید و در «تگ HTML» آن را H1 یا H2 بگذارید.</p>';
		if ( $none ) {
			echo '<h3>بدون H1 (' . esc_html( number_format_i18n( count( $none ) ) ) . ')</h3><table class="widefat striped"><tbody>';
			foreach ( $none as $p ) {
				echo '<tr><td>' . wp_kses_post( self::plink( $p['url'] ) ) . '</td><td>تیتر اصلی بالای صفحه را H1 کنید.</td><td>' . wp_kses_post( self::edit_link( $p['url'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $multi ) {
			echo '<h3>چند H1 (' . esc_html( number_format_i18n( count( $multi ) ) ) . ')</h3><table class="widefat striped"><thead><tr><th>صفحه</th><th>H1های فعلی (اولی را نگه دارید، بقیه را H2 کنید)</th><th></th></tr></thead><tbody>';
			foreach ( $multi as $p ) {
				echo '<tr><td>' . wp_kses_post( self::plink( $p['url'] ) ) . '</td><td><ol class="ssai-h1-list">';
				foreach ( $p['h1'] as $h ) {
					echo '<li>' . esc_html( $h ) . '</li>';
				}
				echo '</ol>';
				$n0 = SSAI_Content::norm( $p['h1'][0] );
				$n1 = SSAI_Content::norm( $p['h1'][1] );
				similar_text( $n0, $n1, $pct );
				if ( $pct >= 70 || false !== mb_strpos( $n1, $n0 ) || false !== mb_strpos( $n0, $n1 ) ) {
					echo '<div class="ssai-hint">H1ها تقریباً یکسان‌اند: احتمالاً قالب عنوان صفحه را H1 چاپ می‌کند و ویجت تیتر المنتور هم H1 است. در المنتور از تنظیمات صفحه (چرخ‌دنده پایین پنل) «پنهان کردن عنوان» را روشن کنید، یا تگ ویجت تیتر را H2 بگذارید.</div>';
				} elseif ( mb_strlen( $p['h1'][0] ) < 15 ) {
					echo '<div class="ssai-hint">H1 اول کوتاه است (احتمالاً عنوان بخش یا breadcrumb در قالب، مثل «دانشنامه»)؛ آن را H2 یا یک متن ساده کنید.</div>';
				}
				echo '</td><td>' . wp_kses_post( self::edit_link( $p['url'] ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</section>';
	}

	private static function render_pages( $d ) {
		$pages = isset( $d['pages'] ) ? $d['pages'] : array();
		$orph  = isset( $d['orphans'] ) ? $d['orphans'] : array();
		if ( $orph ) {
			echo '<section class="ssai-card" id="ssai-ah-orphans"><h2>صفحه‌های یتیم</h2><p class="description">هیچ صفحه‌ای به این‌ها لینک نداده و گوگل سخت پیدایشان می‌کند. از صفحه‌های مرتبط (و صفحه‌هایی که به نسخه‌های قدیمی همین موضوع لینک داده‌اند) به آن‌ها لینک بدهید.</p><ul class="ssai-plist">';
			foreach ( $orph as $u ) {
				echo '<li>' . wp_kses_post( self::plink( $u ) ) . '</li>';
			}
			echo '</ul></section>';
		}
		if ( ! $pages ) {
			return;
		}
		uasort(
			$pages,
			function ( $a, $b ) {
				$ia = ( ! $a['h1'] || count( $a['h1'] ) > 1 ? 2 : 0 ) + ( $a['load'] > 1500 ? 1 : 0 );
				$ib = ( ! $b['h1'] || count( $b['h1'] ) > 1 ? 2 : 0 ) + ( $b['load'] > 1500 ? 1 : 0 );
				return $ib - $ia ?: $b['load'] - $a['load'];
			}
		);
		echo '<section class="ssai-card" id="ssai-ah-pages"><h2>صفحه‌ها از دید Ahrefs</h2><div class="ssai-table-wrap"><table class="widefat striped"><thead><tr><th>صفحه</th><th>H1</th><th>بارگذاری</th><th>کلمات</th><th>لینک داخلی ورودی</th><th>دامنه‌های ارجاع‌دهنده</th></tr></thead><tbody>';
		foreach ( array_slice( $pages, 0, 100 ) as $p ) {
			$h1 = ! $p['h1'] ? '<span class="ssai-pos ssai-risk-error">ندارد</span>' : ( count( $p['h1'] ) > 1 ? '<span class="ssai-pos ssai-risk-warning">' . count( $p['h1'] ) . ' عدد</span><div class="description">' . esc_html( implode( ' | ', array_map( function ( $x ) { return mb_substr( $x, 0, 40 ); }, $p['h1'] ) ) ) . '</div>' : '✓' );
			echo '<tr><td>' . wp_kses_post( self::plink( $p['url'] ) ) . '</td><td>' . wp_kses_post( $h1 ) . '</td><td>' . esc_html( number_format_i18n( $p['load'] ) ) . ' ms</td><td>' . esc_html( number_format_i18n( $p['words'] ) ) . '</td><td>' . esc_html( number_format_i18n( $p['in'] ) ) . '</td><td>' . esc_html( number_format_i18n( $p['rd'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div><p class="description">چند H1 در یک صفحه معمولاً یعنی یک ویجت «تیتر» المنتور به‌جای H2 روی H1 مانده است.</p></section>';
	}

	private static function render_log() {
		$log = SSAI_Linkfix::log();
		if ( ! $log ) {
			return;
		}
		echo '<section class="ssai-card"><h2>تاریخچه اصلاح‌ها</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>از</th><th>به</th><th>جاها</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 30 ) as $e ) {
			echo '<tr><td>' . esc_html( wp_date( 'Y/m/d H:i', $e['time'] ) ) . '</td><td dir="ltr">' . esc_html( self::short( $e['old'] ) ) . '</td><td dir="ltr">' . esc_html( self::short( $e['new'] ) ) . '</td><td>' . esc_html( number_format_i18n( count( $e['locs'] ) ) ) . '</td><td><button type="button" class="button-link ssai-ah-undo" data-id="' . esc_attr( $e['id'] ) . '">بازگردانی</button> <span class="ssai-ah-out" role="status"></span></td></tr>';
		}
		echo '</tbody></table></section>';
	}
}
