<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies approved changes to a post and keeps an undo history.
 * Nothing here runs automatically – every call comes from an explicit admin click.
 */
class SSAI_Applier {

	const META_HISTORY = '_ssai_history';
	const MAX_HISTORY  = 20;

	public static function fields() {
		return array(
			'title'            => 'عنوان صفحه',
			'seo_title'        => 'عنوان سئو',
			'meta_description' => 'توضیحات متا',
			'focus_keyword'    => 'کلمات کلیدی',
			'slug'             => 'نامک (URL)',
			'image_alt'        => 'متن alt تصویر',
			'content'          => 'محتوای کامل',
			'elementor_text'   => 'متن ویجت‌های المنتور',
		);
	}

	public static function can( $post_id ) {
		return current_user_can( SSAI_Settings::get( 'apply_cap' ) ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function apply( $post_id, $field, $value, $extra = array(), $log = true ) {
		if ( ! self::can( $post_id ) ) {
			return new WP_Error( 'ssai_forbidden', 'شما اجازه اعمال تغییرات را ندارید.' );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'ssai_no_post', 'نوشته پیدا نشد.' );
		}
		$old = '';
		$new = '';

		switch ( $field ) {
			case 'title':
				$old = $post->post_title;
				$new = sanitize_text_field( $value );
				if ( '' === $new ) {
					return new WP_Error( 'ssai_empty', 'عنوان خالی است.' );
				}
				$r = wp_update_post(
					wp_slash(
						array(
							'ID'         => $post_id,
							'post_title' => $new,
						)
					),
					true
				);
				break;

			case 'slug':
				$old = urldecode( $post->post_name );
				$new = sanitize_title( $value );
				if ( '' === $new ) {
					return new WP_Error( 'ssai_empty', 'نامک خالی است.' );
				}
				// WordPress stores the old slug in _wp_old_slug and redirects it automatically for published posts.
				$r   = wp_update_post(
					wp_slash(
						array(
							'ID'        => $post_id,
							'post_name' => $new,
						)
					),
					true
				);
				$new = urldecode( get_post_field( 'post_name', $post_id ) );
				break;

			case 'meta_description':
				$old = SSAI_Content::get_meta_description( $post_id );
				$new = sanitize_textarea_field( $value );
				SSAI_Content::set_meta_description( $post_id, $new );
				$r = true;
				break;

			case 'focus_keyword':
				$old = implode( '، ', SSAI_Content::get_focus_keywords( $post_id ) );
				SSAI_Content::set_focus_keyword( $post_id, (string) $value );
				$new = implode( '، ', SSAI_Content::get_focus_keywords( $post_id ) );
				$r   = true;
				break;

			case 'seo_title':
				$old = SSAI_Content::get_seo_title_raw( $post_id );
				$new = sanitize_text_field( $value );
				SSAI_Content::set_seo_title( $post_id, $new );
				$r = true;
				break;

			case 'image_alt':
				$src = isset( $extra['src'] ) ? (string) $extra['src'] : '';
				if ( '' === $src ) {
					return new WP_Error( 'ssai_no_src', 'آدرس تصویر مشخص نیست.' );
				}
				$new = sanitize_text_field( $value );
				if ( SSAI_Elementor::is_built( $post_id ) ) {
					$res = SSAI_Elementor::set_image_alt( $post_id, $src, $new );
					if ( null === $res ) {
						return new WP_Error( 'ssai_img_not_found', 'این تصویر در صفحه المنتور پیدا نشد.' );
					}
					$old = $res[0];
					$r   = true;
					break;
				}
				$old     = null;
				$att_id  = 0;
				$content = self::replace_img_alt( $post->post_content, $src, $new, $old, $att_id );
				if ( null === $old ) {
					return new WP_Error( 'ssai_img_not_found', 'این تصویر در محتوای فعلی پیدا نشد (شاید محتوا تغییر کرده است).' );
				}
				$r = wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => $content,
						)
					),
					true
				);
				if ( ! is_wp_error( $r ) && $att_id && current_user_can( 'edit_post', $att_id ) ) {
					update_post_meta( $att_id, '_wp_attachment_image_alt', wp_slash( $new ) );
				}
				break;

			case 'content':
				$old = $post->post_content;
				$new = (string) $value;
				$r   = wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => $new,
						)
					),
					true
				);
				break;

			case 'elementor_text':
				// $value: array( 'elId:key' => html ) when applying, or the full raw JSON string when undoing.
				$old = SSAI_Elementor::get_raw( $post_id );
				if ( is_string( $value ) ) {
					$decoded = json_decode( $value, true );
					if ( ! is_array( $decoded ) ) {
						return new WP_Error( 'ssai_bad_data', 'داده المنتور نامعتبر است.' );
					}
					SSAI_Elementor::save_raw( $post_id, $value );
					$new = 'بازگردانی نسخه قبلی';
				} else {
					$n = SSAI_Elementor::apply_text( $post_id, (array) $value );
					if ( ! $n ) {
						return new WP_Error( 'ssai_nothing', 'ویجت‌های موردنظر در صفحه پیدا نشدند (شاید صفحه تغییر کرده است).' );
					}
					$new = sprintf( '%d ویجت متنی بازنویسی شد', $n );
				}
				$r = true;
				break;

			default:
				return new WP_Error( 'ssai_bad_field', 'فیلد نامعتبر.' );
		}

		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $log ) {
			self::log( $post_id, $field, $old, $new, $extra );
		}
		self::purge_cache( $post_id );
		return true;
	}

	/** Clear page caches of common caching plugins so the change is visible on the site. */
	public static function purge_cache( $post_id ) {
		clean_post_cache( $post_id );
		do_action( 'litespeed_purge_post', $post_id );          // LiteSpeed Cache.
		if ( function_exists( 'rocket_clean_post' ) ) {          // WP Rocket.
			rocket_clean_post( $post_id );
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {            // W3 Total Cache.
			w3tc_flush_post( $post_id );
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {       // WP Super Cache.
			wp_cache_post_change( $post_id );
		}
		do_action( 'ssai_after_change', $post_id );
	}

	/**
	 * Change the alt attribute of the image with the given src.
	 *
	 * @param string   $html    Post content.
	 * @param string   $src     Image src to find.
	 * @param string   $alt     New alt.
	 * @param string   $old     (out) previous alt, null if not found.
	 * @param int      $att_id  (out) attachment id from wp-image-123 class.
	 */
	public static function replace_img_alt( $html, $src, $alt, &$old, &$att_id ) {
		$old    = null;
		$att_id = 0;
		$done   = false;
		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $m ) use ( $src, $alt, &$old, &$att_id, &$done ) {
				$tag = $m[0];
				if ( $done || ! preg_match( '/(?<=\s)src=(["\'])(.*?)\1/i', $tag, $s ) || html_entity_decode( $s[2] ) !== $src ) {
					return $tag;
				}
				$done = true;
				$attr = 'alt="' . esc_attr( $alt ) . '"';
				if ( preg_match( '/(?<=\s)alt=(["\'])(.*?)\1/i', $tag, $a ) ) {
					$old = html_entity_decode( $a[2] );
					$tag = preg_replace_callback(
						'/(?<=\s)alt=(["\']).*?\1/i',
						function () use ( $attr ) {
							return $attr;
						},
						$tag,
						1
					);
				} else {
					$old = '';
					$tag = preg_replace_callback(
						'/^<img\b/i',
						function () use ( $attr ) {
							return '<img ' . $attr;
						},
						$tag,
						1
					);
				}
				if ( preg_match( '/wp-image-(\d+)/', $tag, $id ) ) {
					$att_id = (int) $id[1];
				}
				return $tag;
			},
			$html
		);
	}

	private static function log( $post_id, $field, $old, $new, $extra ) {
		$h = self::history( $post_id );
		array_unshift(
			$h,
			array(
				'id'     => wp_generate_password( 10, false ),
				'field'  => $field,
				'old'    => $old,
				'new'    => $new,
				'extra'  => $extra,
				'user'   => get_current_user_id(),
				'time'   => time(),
				'undone' => false,
			)
		);
		update_post_meta( $post_id, self::META_HISTORY, wp_slash( array_slice( $h, 0, self::MAX_HISTORY ) ) );
	}

	public static function history( $post_id ) {
		$h = get_post_meta( $post_id, self::META_HISTORY, true );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Restore the previous value of one history entry.
	 *
	 * @return true|WP_Error
	 */
	public static function undo( $post_id, $entry_id ) {
		$h = self::history( $post_id );
		foreach ( $h as $i => $e ) {
			if ( $e['id'] !== $entry_id ) {
				continue;
			}
			if ( ! empty( $e['undone'] ) ) {
				return new WP_Error( 'ssai_already', 'این تغییر قبلاً برگردانده شده است.' );
			}
			$extra = is_array( $e['extra'] ) ? $e['extra'] : array();
			$r     = self::apply( $post_id, $e['field'], (string) $e['old'], $extra, false );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$h[ $i ]['undone'] = true;
			update_post_meta( $post_id, self::META_HISTORY, wp_slash( $h ) );
			return true;
		}
		return new WP_Error( 'ssai_no_entry', 'مورد در تاریخچه پیدا نشد.' );
	}
}
