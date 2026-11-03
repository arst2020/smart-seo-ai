<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the SEO-relevant parts of a post, compatible with Yoast SEO and Rank Math.
 */
class SSAI_Content {

	const META_DESC = '_ssai_meta_description';
	const META_KW   = '_ssai_focus_keyword';

	/** Which SEO plugin is active (yoast | rankmath | aioseo | ''). */
	public static function seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}
		return '';
	}

	public static function get_meta_description( $post_id ) {
		$p = self::seo_plugin();
		if ( 'yoast' === $p ) {
			$v = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
			if ( '' !== $v ) {
				return $v;
			}
		} elseif ( 'rankmath' === $p ) {
			$v = (string) get_post_meta( $post_id, 'rank_math_description', true );
			if ( '' !== $v ) {
				return $v;
			}
		}
		$own = (string) get_post_meta( $post_id, self::META_DESC, true );
		if ( '' === $own && '' === $p ) {
			// Rank Math / Yoast deactivated: keep using their saved data until it is migrated.
			foreach ( array( 'rank_math_description', '_yoast_wpseo_metadesc' ) as $k ) {
				$v = (string) get_post_meta( $post_id, $k, true );
				if ( '' !== $v ) {
					return $v;
				}
			}
		}
		return $own;
	}

	public static function set_meta_description( $post_id, $value ) {
		$value = sanitize_textarea_field( $value );
		update_post_meta( $post_id, self::META_DESC, wp_slash( $value ) );
		$p = self::seo_plugin();
		if ( 'yoast' === $p ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', wp_slash( $value ) );
		} elseif ( 'rankmath' === $p ) {
			update_post_meta( $post_id, 'rank_math_description', wp_slash( $value ) );
		}
	}

	const META_TITLE = '_ssai_seo_title';

	/** Split "a, b، c" into a clean keyword list (Persian comma supported). */
	public static function split_keywords( $value ) {
		$parts = preg_split( '/\s*[,،]\s*/u', (string) $value );
		$out   = array();
		foreach ( $parts as $p ) {
			$p = trim( sanitize_text_field( $p ) );
			if ( '' !== $p && ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
		}
		return array_slice( $out, 0, 5 );
	}

	/**
	 * All focus keywords: the first one is the primary keyword, the rest are secondary.
	 * The active SEO plugin (Rank Math / Yoast) is the source of truth when present.
	 */
	public static function get_focus_keywords( $post_id ) {
		$own = self::split_keywords( get_post_meta( $post_id, self::META_KW, true ) );
		$p   = self::seo_plugin();
		if ( 'rankmath' === $p ) {
			$rm = self::split_keywords( get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
			if ( $rm ) {
				return $rm;
			}
		} elseif ( 'yoast' === $p ) {
			$y = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) );
			if ( '' !== $y ) {
				return array_values( array_unique( array_merge( array( $y ), array_slice( $own, 1 ) ) ) );
			}
		}
		if ( $own ) {
			return $own;
		}
		// Fall back to other plugins' data even when they are inactive.
		$rm = self::split_keywords( get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
		if ( $rm ) {
			return $rm;
		}
		$y = trim( (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) );
		return '' !== $y ? array( $y ) : array();
	}

	public static function get_focus_keyword( $post_id ) {
		$k = self::get_focus_keywords( $post_id );
		return $k ? $k[0] : '';
	}

	/** $value: comma separated list, first = primary. */
	public static function set_focus_keyword( $post_id, $value ) {
		$list = self::split_keywords( $value );
		$csv  = implode( ',', $list );
		update_post_meta( $post_id, self::META_KW, wp_slash( $csv ) );
		$p = self::seo_plugin();
		if ( 'yoast' === $p ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', wp_slash( $list ? $list[0] : '' ) );
		} elseif ( 'rankmath' === $p ) {
			update_post_meta( $post_id, 'rank_math_focus_keyword', wp_slash( $csv ) );
		}
	}

	/** Raw SEO title as stored (may contain Rank Math / Yoast variables). */
	public static function get_seo_title_raw( $post_id ) {
		$p = self::seo_plugin();
		if ( 'rankmath' === $p ) {
			$v = (string) get_post_meta( $post_id, 'rank_math_title', true );
			if ( '' !== $v ) {
				return $v;
			}
		} elseif ( 'yoast' === $p ) {
			$v = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
			if ( '' !== $v ) {
				return $v;
			}
		}
		$own = (string) get_post_meta( $post_id, self::META_TITLE, true );
		if ( '' === $own && '' === $p ) {
			foreach ( array( 'rank_math_title', '_yoast_wpseo_title' ) as $k ) {
				$v = (string) get_post_meta( $post_id, $k, true );
				if ( '' !== $v ) {
					return $v;
				}
			}
		}
		return $own;
	}

	/** The title Google will most likely show (variables replaced). */
	public static function get_seo_title( $post_id ) {
		if ( '' === self::seo_plugin() && class_exists( 'SSAI_SEO' ) ) {
			$ctx = array(
				'type'  => 'post',
				'id'    => (int) $post_id,
				'paged' => 1,
			);
			if ( (int) get_option( 'page_on_front' ) === (int) $post_id && 'page' === get_option( 'show_on_front' ) ) {
				$ctx['front'] = true;
			}
			$m = SSAI_SEO::meta( $ctx );
			return $m['title'];
		}
		$raw   = self::get_seo_title_raw( $post_id );
		$title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		if ( '' === trim( $raw ) ) {
			$sep = '-';
			if ( 'rankmath' === self::seo_plugin() || 'yoast' === self::seo_plugin() ) {
				return $title . ' ' . $sep . ' ' . get_bloginfo( 'name' );
			}
			return $title;
		}
		$map = array(
			'%title%'    => $title,
			'%%title%%'  => $title,
			'%sitename%' => get_bloginfo( 'name' ),
			'%%sitename%%' => get_bloginfo( 'name' ),
			'%sep%'      => '-',
			'%%sep%%'    => '-',
		);
		$out = strtr( $raw, $map );
		$out = preg_replace( '/%%?[a-z_]+%%?/i', '', $out );
		return trim( preg_replace( '/\s+/u', ' ', $out ) );
	}

	public static function set_seo_title( $post_id, $value ) {
		$value = sanitize_text_field( $value );
		update_post_meta( $post_id, self::META_TITLE, wp_slash( $value ) );
		$p = self::seo_plugin();
		if ( 'rankmath' === $p ) {
			update_post_meta( $post_id, 'rank_math_title', wp_slash( $value ) );
		} elseif ( 'yoast' === $p ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', wp_slash( $value ) );
		}
	}

	/** Normalise Persian/Arabic letters and case for text comparison. */
	public static function norm( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = str_replace(
			array( 'ي', 'ك', 'ى', 'ۀ', 'ة', "\xE2\x80\x8C", '‌' ),
			array( 'ی', 'ک', 'ی', 'ه', 'ه', ' ', ' ' ),
			$s
		);
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( mb_strtolower( $s, 'UTF-8' ) );
	}

	public static function words( $text ) {
		$text = trim( preg_replace( '/[^\p{L}\p{N}\s\-]+/u', ' ', (string) $text ) );
		return '' === $text ? array() : preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	}

	public static function html_to_text( $html ) {
		$html = preg_replace( '/<!--.*?-->/s', ' ', (string) $html );
		$html = preg_replace( '/<\/(p|div|h[1-6]|li|br|tr)>/i', "$0\n", $html );
		$text = wp_strip_all_tags( strip_shortcodes( $html ) );
		return trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
	}

	/** All SEO-relevant data of a post, ready for analysis. */
	public static function data( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$builder = SSAI_Elementor::is_built( $post->ID ) ? 'elementor' : ( SSAI_Avada::is_built( $post->ID ) ? 'avada' : '' );
		$html    = 'elementor' === $builder ? SSAI_Elementor::html( $post->ID ) : ( 'avada' === $builder ? SSAI_Avada::html( $post->ID ) : (string) $post->post_content );
		if ( false === stripos( $html, '<p' ) && '' !== trim( $html ) ) {
			$html = wpautop( $html );
		}

		$headings = array();
		if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $html, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $h ) {
				$headings[] = array(
					'level' => (int) $h[1],
					'text'  => trim( wp_strip_all_tags( $h[2] ) ),
				);
			}
		}

		$images = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				$src = preg_match( '/(?<=\s)src=(["\'])(.*?)\1/i', $tag, $s ) ? html_entity_decode( $s[2] ) : '';
				$alt = preg_match( '/(?<=\s)alt=(["\'])(.*?)\1/i', $tag, $a ) ? html_entity_decode( $a[2] ) : '';
				if ( '' !== $src ) {
					$images[] = array(
						'src' => $src,
						'alt' => trim( $alt ),
					);
				}
			}
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal  = 0;
		$external  = 0;
		$dofollow  = 0;
		$anchors   = 0;
		if ( preg_match_all( '/<a\b[^>]*(?<=\s)href=(["\'])(.*?)\1[^>]*>/i', $html, $m ) ) {
			foreach ( $m[2] as $i => $href ) {
				$href = trim( $href );
				if ( '' !== $href && '#' === $href[0] && strlen( $href ) > 1 ) {
					$anchors++;
				}
				if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || strtolower( $host ) === strtolower( (string) $home_host ) ) {
					$internal++;
				} else {
					$external++;
					if ( ! preg_match( '/rel=(["\'])[^"\']*nofollow/i', $m[0][ $i ] ) ) {
						$dofollow++;
					}
				}
			}
		}

		$paragraphs = array();
		if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $html, $m ) ) {
			foreach ( $m[1] as $p ) {
				$t = trim( self::html_to_text( $p ) );
				if ( '' !== $t ) {
					$paragraphs[] = $t;
				}
			}
		}

		$text = self::html_to_text( $html );

		return array(
			'id'               => $post->ID,
			'type'             => $post->post_type,
			'status'           => $post->post_status,
			'title'            => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'slug'             => urldecode( (string) $post->post_name ),
			'url'              => get_permalink( $post ),
			'meta_description' => self::get_meta_description( $post->ID ),
			'focus_keyword'    => self::get_focus_keyword( $post->ID ),
			'keywords'         => self::get_focus_keywords( $post->ID ),
			'seo_title'        => self::get_seo_title( $post->ID ),
			'seo_title_raw'    => self::get_seo_title_raw( $post->ID ),
			'builder'          => $builder,
			'html'             => '' !== $builder ? $html : (string) $post->post_content,
			'text'             => $text,
			'words'            => count( self::words( $text ) ),
			'headings'         => $headings,
			'images'           => $images,
			'internal_links'   => $internal,
			'external_links'   => $external,
			'dofollow_links'   => $dofollow,
			'videos'           => preg_match_all( '/<(iframe|video)\b/i', $html ) + ( 'elementor' === $builder ? substr_count( (string) get_post_meta( $post->ID, '_elementor_data', true ), '"widgetType":"video"' ) : 0 ),
			'has_toc'          => $anchors >= 3 || (bool) preg_match( '/table-of-contents|ez-toc|rank-math-toc|wp-block-rank-math-toc|lwptoc|"widgetType":"table-of-contents"/i', $html . ( 'elementor' === $builder ? (string) get_post_meta( $post->ID, '_elementor_data', true ) : '' ) ),
			'paragraphs'       => $paragraphs,
		);
	}
}
