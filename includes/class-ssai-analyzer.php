<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule-based SEO checks (offline, instant) + AI-based review and rewrite.
 */
class SSAI_Analyzer {

	const META_AI    = '_ssai_last_ai';
	const META_SCORE = '_ssai_score';

	public static function groups() {
		return array(
			'basic'       => 'سئو پایه',
			'additional'  => 'اضافی',
			'title'       => 'خوانایی عنوان',
			'readability' => 'خوانایی محتوا',
		);
	}

	/**
	 * One check result.
	 *
	 * @param string $fix  How it can be fixed: seo_title | meta | slug | keywords | alts | links | rewrite | '' (manual).
	 * @param string $hint Instruction for the AI rewrite, or advice for manual fixes.
	 */
	private static function item( $id, $status, $msg, $group = 'basic', $fix = '', $hint = '' ) {
		return array(
			'id'      => $id,
			'status'  => $status, // good | warning | error
			'message' => $msg,
			'group'   => $group,
			'fix'     => 'good' === $status ? '' : $fix,
			'hint'    => 'good' === $status ? '' : $hint,
		);
	}

	/**
	 * H1 texts of the published page. $live fetches the page (cached a day / until the post changes);
	 * otherwise only the cache or the imported Ahrefs crawl is used. Returns null when unknown.
	 */
	public static function page_h1s( $post_id, $live = false ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$key = 'ssai_live_' . $post_id;
		$c   = get_transient( $key );
		if ( is_array( $c ) && isset( $c['mod'] ) && $c['mod'] === $post->post_modified_gmt && ( ! $live || isset( $c['head'] ) ) ) {
			return $c;
		}
		if ( $live && 'publish' === $post->post_status && empty( $post->post_password ) ) {
			$res = wp_remote_get(
				add_query_arg( 'ssai_h1', time(), get_permalink( $post ) ),
				array(
					'timeout'             => 12,
					'redirection'         => 2,
					'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
					'headers'             => array( 'Cache-Control' => 'no-cache' ),
					'limit_response_size' => 3 * MB_IN_BYTES,
				)
			);
			$html = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
			if ( '' !== $html && false !== stripos( $html, '<body' ) ) {
				$list = array();
				if ( preg_match_all( '/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
					foreach ( $m[1] as $h ) {
						$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $h ), ENT_QUOTES, 'UTF-8' ) ) );
						$list[] = '' === $t ? '(H1 خالی یا فقط تصویر)' : $t;
					}
				}
				$c = array(
					'h1'   => $list,
					'src'  => 'live',
					'mod'  => $post->post_modified_gmt,
					'main' => self::main_area( $html, $post ),
					'head' => self::head_tags( $html ),
				);
				set_transient( $key, $c, DAY_IN_SECONDS );
				return $c;
			}
		}
		$ah = get_option( 'ssai_ahrefs' );
		if ( ! empty( $ah['pages'] ) && class_exists( 'SSAI_GSC_Import' ) ) {
			$k = SSAI_GSC_Import::url_key( get_permalink( $post ) );
			if ( isset( $ah['pages'][ $k ] ) ) {
				return array( 'h1' => (array) $ah['pages'][ $k ]['h1'], 'src' => 'ahrefs' );
			}
		}
		return null;
	}

	/**
	 * Links and text of the page body as visitors see it (Elementor loop grids, global widgets, shortcodes…),
	 * without the site header, menu and footer.
	 *
	 * @return array|null internal (unique pages), external, dofollow, words, text, links[]
	 */
	private static function main_area( $html, $post ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$ok   = $doc->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return null;
		}
		$xp = new DOMXPath( $doc );
		foreach ( iterator_to_array( $xp->query( '//script|//style|//noscript|//template' ) ) as $n ) {
			$n->parentNode->removeChild( $n );
		}
		$id   = (int) $post->ID;
		$node = null;
		$full = false;
		foreach ( array( "//*[@data-elementor-id='$id'][@data-elementor-type!='header'][@data-elementor-type!='footer']", '//main', "//*[contains(concat(' ',normalize-space(@class),' '),' entry-content ')]", '//article', '//body' ) as $i => $q ) {
			$l = $xp->query( $q );
			if ( $l && $l->length ) {
				$node = $l->item( 0 );
				$full = $i > 0;
				break;
			}
		}
		if ( ! $node ) {
			return null;
		}
		if ( $full ) {
			// Theme layout: drop the site header, menus, sidebars and footer inside the chosen area.
			foreach ( iterator_to_array( $xp->query( './/header|.//nav|.//footer|.//aside|.//*[@data-elementor-type="header" or @data-elementor-type="footer" or @data-elementor-type="popup"]', $node ) ) as $n ) {
				$n->parentNode->removeChild( $n );
			}
		}
		$home  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$self  = untrailingslashit( strtok( (string) get_permalink( $post ), '?#' ) );
		$int   = array();
		$ext   = 0;
		$dof   = 0;
		foreach ( $xp->query( './/a[@href]', $node ) as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href[0] || preg_match( '#^(mailto|tel|javascript|whatsapp|sms):#i', $href ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			if ( '' === $host || $host === $home || 'www.' . $home === $host || 'www.' . $host === $home ) {
				$u = untrailingslashit( strtok( '' === $host ? home_url( '/' . ltrim( $href, '/' ) ) : $href, '?#' ) );
				if ( $u === $self || preg_match( '#/(wp-admin|wp-login\.php|feed)(/|$)#', $u ) ) {
					continue;
				}
				$int[ $u ] = true;
			} else {
				$ext++;
				if ( false === stripos( $a->getAttribute( 'rel' ), 'nofollow' ) ) {
					$dof++;
				}
			}
		}
		$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $node->textContent, ENT_QUOTES, 'UTF-8' ) ) );
		return array(
			'internal' => count( $int ),
			'external' => $ext,
			'dofollow' => $dof,
			'words'    => count( SSAI_Content::words( $text ) ),
			'text'     => mb_substr( $text, 0, 60000 ),
			'links'    => array_slice( array_keys( $int ), 0, 30 ),
		);
	}

	/** Canonical links, viewport and robots meta tags of the published page. */
	private static function head_tags( $html ) {
		$out = array( 'canonical' => array(), 'viewport' => array(), 'noindex' => false );
		if ( preg_match_all( '/<link\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '/\brel\s*=\s*["\']?[^"\'>]*\bcanonical\b/i', $tag ) && preg_match( '/\bhref\s*=\s*["\']([^"\']*)["\']/i', $tag, $h ) ) {
					$out['canonical'][] = html_entity_decode( trim( $h[1] ), ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		if ( preg_match_all( '/<meta\b[^>]*>/i', $html, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( ! preg_match( '/\bname\s*=\s*["\']?([a-z-]+)/i', $tag, $n ) ) {
					continue;
				}
				$content = preg_match( '/\bcontent\s*=\s*["\']([^"\']*)["\']/i', $tag, $c ) ? trim( $c[1] ) : '';
				if ( 'viewport' === strtolower( $n[1] ) ) {
					$out['viewport'][] = $content;
				} elseif ( 'robots' === strtolower( $n[1] ) && false !== stripos( $content, 'noindex' ) ) {
					$out['noindex'] = true;
				}
			}
		}
		return $out;
	}

	/** Same page? (ignores scheme, www and trailing slash; a query string makes it a different URL). */
	private static function same_url( $a, $b ) {
		$n = function ( $u ) {
			$u = strtolower( preg_replace( '#^https?://(www\.)?#i', '', trim( (string) $u ) ) );
			$u = preg_replace( '/#.*$/', '', $u );
			return rawurldecode( untrailingslashit( $u ) );
		};
		return $n( $a ) === $n( $b );
	}

	/** Canonical and viewport checks, from the published page's <head>. */
	private static function tech_items( $post_id, $ph ) {
		$items = array();
		if ( empty( $ph['head'] ) ) {
			return $items;
		}
		$hd   = $ph['head'];
		$self = get_permalink( $post_id );
		$can  = array_values( array_unique( $hd['canonical'] ) );
		$fix  = array(
			'kind'    => 'canonical',
			'label'   => 'افزودن canonical خودارجاع',
			'confirm' => "آدرس canonical این صفحه روی خودش تنظیم می‌شود:\n" . rawurldecode( $self ) . "\n\nیعنی به گوگل گفته می‌شود نسخه اصلی همین آدرس است. تغییر در «تاریخچه تغییرات» ثبت می‌شود و قابل برگشت است. ادامه؟",
		);
		if ( ! $can ) {
			$it           = self::item( 'canonical', $hd['noindex'] ? 'warning' : 'error', 'صفحه تگ canonical ندارد' . ( $hd['noindex'] ? ' (صفحه noindex است، پس الزامی نیست).' : '؛ گوگل خودش حدس می‌زند نسخه اصلی کدام آدرس است و ممکن است نسخه دارای پارامتر (مثلاً ?utm=) را انتخاب کند.' ), 'additional', '', 'معمولاً افزونه سئو canonical می‌گذارد؛ اگر نیست، قالب یا افزونه دیگری آن را حذف کرده است.' );
			$it['action'] = $fix;
			$items[]      = $it;
		} elseif ( count( $can ) > 1 ) {
			$items[] = self::item( 'canonical', 'error', sprintf( 'صفحه %d تگ canonical متفاوت دارد: %s — گوگل در این حالت ممکن است همه را نادیده بگیرد.', count( $can ), implode( ' ، ', $can ) ), 'additional', '', 'معمولاً دو افزونه سئو (مثلاً این افزونه و Rank Math/Yoast) یا قالب و افزونه با هم canonical چاپ می‌کنند؛ یکی را خاموش کنید.' );
		} elseif ( ! self::same_url( $can[0], $self ) ) {
			$it           = self::item( 'canonical', 'warning', 'canonical به آدرس دیگری اشاره می‌کند: ' . $can[0] . ' — یعنی به گوگل گفته‌اید نسخه اصلی آن صفحه است و این صفحه ایندکس نمی‌شود. اگر عمدی نیست، اصلاح کنید.', 'additional' );
			$fix['label'] = 'تبدیل به canonical خودارجاع';
			$it['action'] = $fix;
			$items[]      = $it;
		} elseif ( rawurldecode( untrailingslashit( $can[0] ) ) !== rawurldecode( untrailingslashit( $self ) ) ) {
			$it           = self::item( 'canonical', 'warning', 'canonical همین صفحه است ولی دقیقاً با آدرس اصلی یکی نیست (http/https، www یا آدرس نسبی): ' . $can[0] . ' — بهتر است کامل و دقیقاً ' . $self . ' باشد.', 'additional' );
			$fix['label'] = 'تبدیل به canonical خودارجاع';
			$it['action'] = $fix;
			$items[]      = $it;
		} else {
			$items[] = self::item( 'canonical', 'good', 'تگ canonical درست است و به خود صفحه اشاره می‌کند.', 'additional' );
		}

		$vp = $hd['viewport'];
		if ( ! $vp ) {
			$it           = self::item( 'viewport', 'error', 'تگ viewport موبایل ندارد؛ صفحه در موبایل کوچک و نامناسب نمایش داده می‌شود و گوگل (ایندکس موبایل‌اول) آن را برای موبایل مناسب نمی‌داند.', 'additional', '', 'این تگ را باید قالب بگذارد؛ اگر قالب ندارد، افزونه می‌تواند آن را برای همه صفحه‌ها اضافه کند.' );
			$it['action'] = array(
				'kind'    => 'viewport',
				'label'   => 'افزودن تگ viewport به همه صفحه‌ها',
				'confirm' => "تگ زیر از این به بعد در <head> همه صفحه‌های سایت اضافه می‌شود:\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n\nاین تنظیم سراسری است و در «تنظیمات سئو › عمومی» قابل خاموش کردن است. اگر قالب بعداً خودش این تگ را اضافه کرد، این گزینه را خاموش کنید. ادامه؟",
			);
			$items[]      = $it;
		} elseif ( count( $vp ) > 1 ) {
			$items[] = self::item( 'viewport', 'warning', sprintf( 'صفحه %d تگ viewport دارد (%s)؛ فقط یکی لازم است.', count( $vp ), implode( ' | ', $vp ) ), 'additional', '', get_option( 'ssai_viewport' ) ? 'قالب خودش viewport دارد؛ گزینه «افزودن تگ viewport» را در تنظیمات سئو › عمومی خاموش کنید.' : 'قالب یا یک افزونه تگ تکراری اضافه کرده است.' );
		} elseif ( false === stripos( $vp[0], 'width=device-width' ) ) {
			$items[] = self::item( 'viewport', 'error', 'تگ viewport هست ولی width=device-width ندارد («' . $vp[0] . '»)؛ صفحه در موبایل درست تنظیم نمی‌شود.', 'additional', '', 'در header.php قالب (یا تنظیمات قالب) مقدار را width=device-width, initial-scale=1 کنید.' );
		} elseif ( preg_match( '/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?(\D|$)/i', $vp[0] ) ) {
			$items[] = self::item( 'viewport', 'warning', 'تگ viewport زوم کاربر را بسته است («' . $vp[0] . '»)؛ برای کم‌بینایان مشکل‌ساز است و در گزارش دسترس‌پذیری گوگل ایراد حساب می‌شود.', 'additional', '', 'در header.php قالب، user-scalable=no و maximum-scale=1 را حذف کنید.' );
		} else {
			$items[] = self::item( 'viewport', 'good', 'تگ viewport موبایل درست است.', 'additional' );
		}
		return $items;
	}

	/** Words that make titles more clickable (Persian + English). */
	private static function power_words() {
		return array( 'بهترین', 'رایگان', 'تضمینی', 'فوری', 'کامل', 'جامع', 'راهنما', 'ضروری', 'حرفه‌ای', 'حرفه ای', 'جدید', 'سریع', 'آسان', 'ارزان', 'ویژه', 'تخصصی', 'مطمئن', 'بی‌درد', 'بدون درد', 'دائمی', 'معتبر', 'برتر', 'اصل', 'شگفت', 'نکته', 'ترفند', 'مقایسه', 'قیمت', 'هزینه', 'مشاوره رایگان', 'best', 'free', 'ultimate', 'guide', 'complete', 'proven', 'easy', 'fast', 'new', 'essential', 'expert', 'top', 'tips' );
	}

	/** Is the keyword already the focus keyword of another post? */
	private static function keyword_used_elsewhere( $post_id, $kw ) {
		global $wpdb;
		$types = SSAI_Admin::post_types();
		if ( ! $types ) {
			return 0;
		}
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key IN ('_ssai_focus_keyword','rank_math_focus_keyword','_yoast_wpseo_focuskw')
				 AND pm.meta_value LIKE %s AND pm.post_id <> %d AND p.post_status = 'publish' AND p.post_type IN ($in) LIMIT 50", // phpcs:ignore
				array_merge( array( '%' . $wpdb->esc_like( $kw ) . '%', $post_id ), $types )
			)
		);
		foreach ( $ids as $id ) {
			// Only a real competitor when it is the PRIMARY keyword there, not just a substring.
			$other = SSAI_Content::get_focus_keywords( (int) $id );
			if ( $other && SSAI_Content::norm( $other[0] ) === SSAI_Content::norm( $kw ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Run the built-in checks (modelled on common SEO-plugin checks and Google's guidelines).
	 *
	 * @return array { score:int, items:array, data:array }
	 */
	public static function rules( $post_id, $live = false ) {
		$d = SSAI_Content::data( $post_id );
		if ( ! $d ) {
			return array(
				'score' => 0,
				'items' => array(),
				'data'  => array(),
			);
		}

		// The saved content can miss what the page really shows (Elementor loop grids, global widgets,
		// shortcodes); the published page fills those gaps.
		$ph       = self::page_h1s( $post_id, $live );
		$live_txt = '';
		if ( $ph && ! empty( $ph['main'] ) ) {
			$lm = $ph['main'];
			if ( $lm['internal'] > $d['internal_links'] ) {
				$d['internal_links'] = $lm['internal'];
				$d['links_live']     = true;
			}
			if ( $lm['external'] > $d['external_links'] ) {
				$d['external_links'] = $lm['external'];
				$d['dofollow_links'] = max( $d['dofollow_links'], $lm['dofollow'] );
			}
			if ( $lm['words'] > $d['words'] ) {
				$d['words'] = $lm['words'];
				$d['text']  = $lm['text'];
				$live_txt   = ' (در صفحه منتشرشده)';
			}
		}

		$items  = array();
		$kw     = SSAI_Content::norm( $d['focus_keyword'] );
		$stitle = SSAI_Content::norm( $d['seo_title'] );
		$meta   = SSAI_Content::norm( $d['meta_description'] );
		$slug   = SSAI_Content::norm( str_replace( '-', ' ', $d['slug'] ) );
		$text   = SSAI_Content::norm( $d['text'] );
		$has_kw = '' !== $kw;

		/* ------------------------------------------------ Basic SEO */
		if ( ! $has_kw ) {
			$items[] = self::item( 'kw', 'error', 'کلمه کلیدی کانونی تعیین نشده است؛ بدون آن بیشتر بررسی‌ها انجام نمی‌شود.', 'basic', 'keywords' );
		} else {
			// Keywords that look like titles ("… | brand") are not searchable phrases.
			foreach ( $d['keywords'] as $kwi ) {
				if ( self::clean_one_keyword( $kwi ) !== $kwi ) {
					$items[] = self::item( 'kw_format', 'error', 'کلمه کلیدی «' . $kwi . '» شبیه عنوان صفحه است، نه عبارتی که مردم جستجو می‌کنند (بدون «|»، «-» و نام برند، حداکثر ۵ کلمه).', 'basic', 'keywords' );
				}
			}
			// Compare the primary keyword with real Google searches (only data already stored – no remote call here).
			$sq = self::search_queries( $post_id, false );
			if ( $sq['rows'] && 'page' === $sq['source'] ) {
				$prim = null;
				$best = null;
				foreach ( $sq['rows'] as $r ) {
					if ( SSAI_Content::norm( $r['q'] ) === $kw ) {
						$prim = $r;
					}
					if ( $r['position'] <= 20 && ( ! $best || $r['impressions'] > $best['impressions'] ) ) {
						$best = $r;
					}
				}
				if ( ! $prim ) {
					$items[] = self::item( 'kw_data', 'warning', 'کلمه کلیدی اصلی در جستجوهای واقعی این صفحه در گوگل دیده نشده' . ( $best ? '؛ پرنمایش‌ترین جستجوی این صفحه «' . $best['q'] . '» است (جایگاه ' . $best['position'] . '، ' . $best['impressions'] . ' نمایش).' : '.' ), 'basic', 'keywords' );
				} elseif ( $prim['position'] > 30 && $best && $best['impressions'] >= $prim['impressions'] * 0.5 ) {
					$items[] = self::item( 'kw_data', 'warning', sprintf( 'جایگاه کلمه اصلی در گوگل %s است، اما «%s» جایگاه %s و %d نمایش دارد؛ شاید کلمه اصلی بهتری باشد.', $prim['position'], $best['q'], $best['position'], $best['impressions'] ), 'basic', 'keywords' );
				} else {
					$items[] = self::item( 'kw_data', 'good', sprintf( 'کلمه کلیدی اصلی در جستجوهای واقعی گوگل هست (جایگاه %s، %d نمایش).', $prim['position'], $prim['impressions'] ), 'basic' );
				}
			}
			$items[] = false !== mb_strpos( $stitle, $kw )
				? self::item( 'kw_title', 'good', 'کلمه کلیدی متمرکز در عنوان سئو آمده است.', 'basic' )
				: self::item( 'kw_title', 'error', 'کلمه کلیدی متمرکز در عنوان سئو ظاهر نمی‌شود.', 'basic', 'seo_title' );
		}

		$ml = mb_strlen( $d['meta_description'], 'UTF-8' );
		if ( 0 === $ml ) {
			$items[] = self::item( 'meta_len', 'error', 'توضیحات متا نوشته نشده است.', 'basic', 'meta' );
		} elseif ( $ml < 120 || $ml > 160 ) {
			$items[] = self::item( 'meta_len', 'warning', sprintf( 'طول توضیحات متا %d کاراکتر است؛ ۱۲۰ تا ۱۶۰ کاراکتر مناسب است.', $ml ), 'basic', 'meta' );
		} else {
			$items[] = self::item( 'meta_len', 'good', sprintf( 'طول توضیحات متا مناسب است (%d کاراکتر).', $ml ), 'basic' );
		}
		if ( $has_kw && $ml > 0 ) {
			$items[] = false !== mb_strpos( $meta, $kw )
				? self::item( 'kw_meta', 'good', 'کلمه کلیدی متمرکز در توضیحات متا استفاده شده است.', 'basic' )
				: self::item( 'kw_meta', 'error', 'کلمه کلیدی متمرکز در توضیحات متا نیامده است.', 'basic', 'meta' );
		}

		if ( $has_kw ) {
			$items[] = false !== mb_strpos( $slug, $kw ) || false !== mb_strpos( SSAI_Content::norm( $d['slug'] ), str_replace( ' ', '-', $kw ) )
				? self::item( 'kw_slug', 'good', 'کلمه کلیدی متمرکز در URL وجود دارد.', 'basic' )
				: self::item( 'kw_slug', 'error', 'کلمه کلیدی متمرکز در URL پیدا نشد.', 'basic', 'slug', 'تغییر URL صفحه منتشرشده باعث ریدایرکت ۳۰۱ خودکار از آدرس قدیمی می‌شود؛ برای صفحه‌های قدیمی و پربازدید با احتیاط تغییر دهید.' );

			$ten     = mb_substr( $text, 0, max( 200, (int) ( mb_strlen( $text ) * 0.1 ) ) );
			$items[] = false !== mb_strpos( $ten, $kw )
				? self::item( 'kw_intro', 'good', 'کلمه کلیدی متمرکز در ۱۰٪ اول محتوا ظاهر می‌شود.', 'basic' )
				: self::item( 'kw_intro', 'error', 'کلمه کلیدی متمرکز در ۱۰٪ اول محتوا نیامده است.', 'basic', 'rewrite', 'کلمه کلیدی «' . $d['focus_keyword'] . '» را به‌صورت طبیعی در جمله اول یا پاراگراف اول بیاور.' );

			$count   = $d['words'] > 0 ? mb_substr_count( $text, $kw ) : 0;
			$items[] = $count > 0
				? self::item( 'kw_content', 'good', 'کلمه کلیدی متمرکز در محتوا پیدا شد.', 'basic' )
				: self::item( 'kw_content', 'error', 'کلمه کلیدی متمرکز در محتوا وجود ندارد.', 'basic', 'rewrite', 'کلمه کلیدی «' . $d['focus_keyword'] . '» را چند بار به‌صورت طبیعی در متن استفاده کن.' );
		}

		if ( $d['words'] < 600 ) {
			$items[] = self::item( 'words', $d['words'] < 300 ? 'error' : 'warning', sprintf( 'محتوا %d کلمه دارد' . $live_txt . '؛ حداقل ۶۰۰ کلمه محتوای مفید توصیه می‌شود (برای صفحه تماس/خدمات کوتاه‌تر هم قابل قبول است).', $d['words'] ), 'basic', 'rewrite', 'محتوا را کامل‌تر کن: بخش‌های مفید و واقعی مثل توضیح خدمات، مراحل، سؤالات متداول کاربران و نکات کاربردی اضافه کن. هیچ اطلاعات، قیمت یا ادعای ساختگی ننویس.' );
		} else {
			$items[] = self::item( 'words', 'good', sprintf( 'طول محتوا خوب است (%d کلمه%s).', $d['words'], $live_txt ), 'basic' );
		}

		/* ------------------------------------------------ Additional */
		if ( $has_kw ) {
			$kwh = false;
			$sub = 0;
			foreach ( $d['headings'] as $h ) {
				if ( $h['level'] >= 2 && $h['level'] <= 4 ) {
					$sub++;
					if ( false !== mb_strpos( SSAI_Content::norm( $h['text'] ), $kw ) ) {
						$kwh = true;
					}
				}
			}
			$items[] = $kwh
				? self::item( 'kw_heading', 'good', 'کلمه کلیدی در زیرعنوان‌ها (H2، H3، H4) آمده است.', 'additional' )
				: self::item( 'kw_heading', 'error', 'کلمه کلیدی متمرکز در زیرعنوان‌هایی مانند H2، H3، H4 پیدا نشد.', 'additional', 'rewrite', 'کلمه کلیدی «' . $d['focus_keyword'] . '» را به‌صورت طبیعی در یکی از تیترهای H2/H3 بیاور.' );

			$kw_alt = false;
			foreach ( $d['images'] as $img ) {
				if ( false !== mb_strpos( SSAI_Content::norm( $img['alt'] ), $kw ) ) {
					$kw_alt = true;
				}
			}
			if ( ! empty( $d['images'] ) ) {
				$items[] = $kw_alt
					? self::item( 'img_kw_alt', 'good', 'حداقل یک تصویر، کلمه کلیدی را در متن جایگزین (alt) دارد.', 'additional' )
					: self::item( 'img_kw_alt', 'error', 'یک تصویر با کلمه کلیدی متمرکز به عنوان متن جایگزین (alt) اضافه کنید.', 'additional', 'alts' );
			}

			$kwords  = max( 1, count( SSAI_Content::words( $kw ) ) );
			$density = $d['words'] > 0 ? round( $count * $kwords / $d['words'] * 100, 2 ) : 0;
			if ( $d['words'] < 100 ) {
				// Density is meaningless on very short pages.
				$density = 0;
			} elseif ( $count > 0 && $density < 0.5 ) {
				$items[] = self::item( 'density', 'warning', sprintf( 'چگالی کلمه کلیدی %s٪ است (%d بار)؛ ۰٫۵ تا ۲٫۵٪ مناسب است.', $density, $count ), 'additional', 'rewrite', 'کلمه کلیدی «' . $d['focus_keyword'] . '» و مترادف‌هایش را کمی بیشتر و کاملاً طبیعی در متن استفاده کن.' );
			} elseif ( $density > 2.5 ) {
				$items[] = self::item( 'density', 'error', sprintf( 'چگالی کلمه کلیدی %s٪ است (%d بار) و ممکن است «پر کردن کلمه کلیدی» تلقی شود.', $density, $count ), 'additional', 'rewrite', 'تکرار کلمه کلیدی «' . $d['focus_keyword'] . '» را کم کن و به جای آن از مترادف‌ها و عبارات طبیعی استفاده کن.' );
			} elseif ( $count > 0 ) {
				$items[] = self::item( 'density', 'good', sprintf( 'چگالی کلمه کلیدی %s٪ است و کلمه کلیدی %d بار آمده است.', $density, $count ), 'additional' );
			}

			$other = self::keyword_used_elsewhere( $post_id, $d['focus_keyword'] );
			$items[] = $other
				? self::item( 'kw_unique', 'error', sprintf( 'این کلمه کلیدی قبلاً برای صفحه «%s» استفاده شده است (رقابت دو صفحه روی یک کلمه).', get_the_title( $other ) ), 'additional', 'keywords', sprintf( 'آدرس صفحه دیگر: %s — کلمه کلیدی یکی از دو صفحه را تغییر دهید.', rawurldecode( get_permalink( $other ) ) ) )
				: self::item( 'kw_unique', 'good', 'شما قبلاً از این کلمه کلیدی متمرکز در صفحه دیگری استفاده نکرده‌اید.', 'additional' );

			// Secondary keywords.
			foreach ( array_slice( $d['keywords'], 1 ) as $sk ) {
				$skn     = SSAI_Content::norm( $sk );
				$items[] = false !== mb_strpos( $text, $skn ) || false !== mb_strpos( $stitle, $skn )
					? self::item( 'kw2_' . md5( $sk ), 'good', 'کلمه کلیدی فرعی «' . $sk . '» در محتوا آمده است.', 'additional' )
					: self::item( 'kw2_' . md5( $sk ), 'warning', 'کلمه کلیدی فرعی «' . $sk . '» در محتوا نیامده است.', 'additional', 'rewrite', 'کلمه کلیدی فرعی «' . $sk . '» را یک یا دو بار به‌صورت طبیعی در متن بیاور.' );
			}
		}

		$images_no_alt = 0;
		foreach ( $d['images'] as $img ) {
			if ( '' === $img['alt'] ) {
				$images_no_alt++;
			}
		}
		if ( $images_no_alt ) {
			$items[] = self::item( 'img_alt', 'error', sprintf( '%d تصویر از %d تصویر، متن جایگزین (alt) ندارد.', $images_no_alt, count( $d['images'] ) ), 'additional', 'alts' );
		}

		$ul = mb_strlen( urldecode( (string) wp_parse_url( $d['url'], PHP_URL_PATH ) ), 'UTF-8' );
		if ( $ul <= 1 ) {
			// Plain permalinks (?p=123): no readable URL to check.
			$items[] = self::item( 'url_len', 'warning', 'آدرس صفحه خوانا نیست (پیوند یکتای ساده). در «تنظیمات › پیوندهای یکتا» حالت «نام نوشته» را انتخاب کنید.', 'additional' );
		} else {
			$items[] = $ul <= 75
			? self::item( 'url_len', 'good', sprintf( 'طول URL %d کاراکتر است.', $ul ), 'additional' )
			: self::item( 'url_len', 'warning', sprintf( 'URL طولانی است (%d کاراکتر)؛ حداکثر ۷۵ کاراکتر توصیه می‌شود.', $ul ), 'additional', 'slug' );
		}

		if ( $d['external_links'] > 0 ) {
			$items[] = self::item( 'external', 'good', 'به منابع خارجی لینک داده‌اید.', 'additional' );
			$items[] = $d['dofollow_links'] > 0
				? self::item( 'dofollow', 'good', 'حداقل یک لینک خارجی دوفالو در محتوا هست.', 'additional' )
				: self::item( 'dofollow', 'warning', 'همه لینک‌های خارجی nofollow هستند؛ به منابع معتبر می‌توانید لینک عادی بدهید.', 'additional', '', 'در ویرایشگر، تیک nofollow یکی از لینک‌های معتبر را بردارید.' );
		} else {
			$items[] = self::item( 'external', 'warning', 'لینکی به منبع خارجی معتبر (مثلاً مقاله علمی یا سایت رسمی) وجود ندارد.', 'additional', '', 'یک لینک به منبع معتبر و مرتبط (انجمن تخصصی، مقاله علمی، سازمان رسمی) را خودتان اضافه کنید؛ هوش مصنوعی برای جلوگیری از لینک‌های ساختگی، لینک خارجی نمی‌سازد.' );
		}

		$items[] = $d['internal_links'] > 0
			? self::item( 'internal', 'good', sprintf( 'به %d صفحه دیگر از سایت خودتان لینک داده‌اید%s.', $d['internal_links'], ! empty( $d['links_live'] ) ? ' (در صفحه منتشرشده)' : '' ), 'additional' )
			: self::item( 'internal', 'error', 'لینک داخلی به صفحات دیگر سایت وجود ندارد.', 'additional', 'links' );

		/* ------------------------------------------------ Title readability */
		$tl = mb_strlen( $d['seo_title'], 'UTF-8' );
		if ( 0 === $tl ) {
			$items[] = self::item( 'title_len', 'error', 'صفحه عنوان ندارد.', 'title', 'seo_title' );
		} elseif ( $tl < 30 || $tl > 60 ) {
			$items[] = self::item( 'title_len', 'warning', sprintf( 'طول عنوان سئو %d کاراکتر است؛ ۳۰ تا ۶۰ کاراکتر مناسب است تا در گوگل کامل نمایش داده شود.', $tl ), 'title', 'seo_title' );
		} else {
			$items[] = self::item( 'title_len', 'good', sprintf( 'طول عنوان سئو مناسب است (%d کاراکتر).', $tl ), 'title' );
		}
		if ( $has_kw && false !== mb_strpos( $stitle, $kw ) ) {
			$pos     = mb_strpos( $stitle, $kw );
			$items[] = $pos <= max( 10, (int) ( mb_strlen( $stitle ) * 0.3 ) )
				? self::item( 'kw_title_start', 'good', 'کلمه کلیدی در ابتدای عنوان سئو آمده است.', 'title' )
				: self::item( 'kw_title_start', 'error', 'کلمه کلیدی متمرکز در ابتدای عنوان سئو ظاهر نمی‌شود.', 'title', 'seo_title' );
		} elseif ( $has_kw ) {
			$items[] = self::item( 'kw_title_start', 'error', 'کلمه کلیدی متمرکز در ابتدای عنوان سئو ظاهر نمی‌شود.', 'title', 'seo_title' );
		}
		$items[] = preg_match( '/[0-9۰-۹٠-٩]/u', $d['seo_title'] )
			? self::item( 'title_number', 'good', 'عنوان سئو عدد دارد.', 'title' )
			: self::item( 'title_number', 'warning', 'عنوان سئوی شما حاوی عدد نیست (عدد معمولاً نرخ کلیک را بالا می‌برد؛ فقط اگر واقعی و مرتبط است).', 'title', 'seo_title' );
		$power = false;
		foreach ( self::power_words() as $pw ) {
			if ( false !== mb_strpos( $stitle, SSAI_Content::norm( $pw ) ) ) {
				$power = true;
				break;
			}
		}
		$items[] = $power
			? self::item( 'title_power', 'good', 'عنوان سئو یک کلمه قدرتمند/ترغیب‌کننده دارد.', 'title' )
			: self::item( 'title_power', 'warning', 'عنوان سئو کلمه ترغیب‌کننده (مثل «تخصصی»، «راهنمای کامل»، «مشاوره رایگان») ندارد.', 'title', 'seo_title' );

		$h1 = 0;
		foreach ( $d['headings'] as $h ) {
			if ( 1 === $h['level'] ) {
				$h1++;
			}
		}
		// H1s of the published page as visitors and Google see it (theme title + builder headings).
		if ( $ph ) {
			$list = $ph['h1'];
			$src  = 'ahrefs' === $ph['src'] ? ' (طبق آخرین گزارش Ahrefs)' : ' (در صفحه منتشرشده)';
			$fix_hint = 'elementor' === $d['builder']
				? 'در المنتور: تنظیمات صفحه (چرخ‌دنده پایین پنل) ← «پنهان کردن عنوان» را روشن کنید، یا تگ HTML تیتر اضافه را H2 بگذارید.'
				: 'تیترهای H1 داخل متن را به H2 تبدیل کنید؛ عنوان صفحه را قالب به‌صورت H1 نمایش می‌دهد.';
			if ( ! $list ) {
				$items[] = self::item( 'h1', 'error', 'صفحه هیچ تیتر H1 ندارد' . $src . '؛ تیتر اصلی بالای صفحه را H1 کنید.', 'title', '', 'elementor' === $d['builder'] ? 'در المنتور روی تیتر اصلی کلیک کنید و «تگ HTML» را H1 بگذارید.' : 'از قالبی استفاده کنید که عنوان را H1 نشان دهد یا تیتر اصلی متن را H1 کنید.' );
			} elseif ( count( $list ) > 1 ) {
				$norm = array_map( array( 'SSAI_Content', 'norm' ), $list );
				$dup  = count( array_unique( $norm ) ) < count( $norm );
				if ( ! $dup ) {
					for ( $i = 1; $i < count( $norm ) && ! $dup; $i++ ) {
						similar_text( $norm[0], $norm[ $i ], $pct );
						$dup = $pct >= 85;
					}
				}
				$shown = implode( '» ، «', array_map( function ( $x ) { return mb_substr( $x, 0, 60 ); }, $list ) );
				$items[] = $dup
					? self::item( 'h1', 'error', sprintf( 'صفحه %d تیتر H1 تکراری دارد%s: «%s». %s', count( $list ), $src, $shown, 'elementor' === $d['builder'] ? 'احتمالاً عنوان قالب و تیتر المنتور هر دو H1 هستند.' : 'احتمالاً عنوان قالب H1 است و همان تیتر داخل متن هم H1 گذاشته شده.' ), 'title', '', $fix_hint )
					: self::item( 'h1', 'warning', sprintf( 'صفحه %d تیتر H1 دارد%s: «%s». هر صفحه فقط یک H1 داشته باشد.', count( $list ), $src, $shown ), 'title', '', $fix_hint );
			} else {
				$items[] = self::item( 'h1', 'good', 'صفحه دقیقاً یک تیتر H1 دارد: «' . mb_substr( $list[0], 0, 60 ) . '».', 'title' );
			}
		} elseif ( 'elementor' === $d['builder'] ) {
			if ( $h1 > 1 ) {
				$items[] = self::item( 'h1', 'warning', sprintf( 'صفحه %d تیتر H1 دارد؛ هر صفحه فقط یک H1 داشته باشد. بقیه را در تنظیمات ویجت به H2 تغییر دهید.', $h1 ), 'title', '', 'در المنتور روی تیتر کلیک کنید و «تگ HTML» را H2 بگذارید.' );
			}
		} elseif ( $h1 > 0 ) {
			$items[] = self::item( 'h1', 'warning', sprintf( 'متن شامل %d تیتر H1 است؛ عنوان صفحه خودش H1 است و بهتر است در متن از H2/H3 استفاده شود.', $h1 ), 'title', 'rewrite', 'تیترهای H1 داخل متن را به H2 تبدیل کن.' );
		}

		foreach ( self::tech_items( $post_id, $ph ) as $ti ) {
			$items[] = $ti;
		}

		/* ------------------------------------------------ Content readability */
		$long = 0;
		foreach ( $d['paragraphs'] as $p ) {
			if ( count( SSAI_Content::words( $p ) ) > 120 ) {
				$long++;
			}
		}
		$items[] = $long
			? self::item( 'paragraphs', 'warning', sprintf( '%d پاراگراف بیش از ۱۲۰ کلمه دارد؛ پاراگراف‌های کوتاه خواناترند.', $long ), 'readability', 'rewrite', 'پاراگراف‌های طولانی را به پاراگراف‌های کوتاه‌تر (حداکثر ۳-۴ جمله) تقسیم کن.' )
			: self::item( 'paragraphs', 'good', 'از پاراگراف‌های کوتاه استفاده کرده‌اید.', 'readability' );

		$sub_all = 0;
		foreach ( $d['headings'] as $h ) {
			if ( $h['level'] >= 2 ) {
				$sub_all++;
			}
		}
		if ( $d['words'] >= 300 && 0 === $sub_all ) {
			$items[] = self::item( 'subheadings', 'warning', 'محتوا زیرعنوان (H2/H3) ندارد.', 'readability', 'rewrite', 'محتوا را با زیرعنوان‌های H2/H3 گویا بخش‌بندی کن.' );
		}

		$media   = count( $d['images'] ) + (int) $d['videos'];
		$items[] = $media > 0
			? self::item( 'media', 'good', sprintf( 'محتوا %d تصویر/ویدیو دارد.', $media ), 'readability' )
			: self::item( 'media', 'warning', 'محتوا تصویر یا ویدیو ندارد.', 'readability', '', 'یک تصویر واقعی و مرتبط (مثلاً از مطب، تیم یا نمونه کار) با alt مناسب اضافه کنید.' );

		if ( $d['words'] >= 1000 ) {
			$items[] = $d['has_toc']
				? self::item( 'toc', 'good', 'محتوا فهرست مطالب دارد.', 'readability' )
				: self::item( 'toc', 'warning', 'محتوای طولانی بدون فهرست مطالب است.', 'readability', '', 'یک ویجت «فهرست مطالب» (Table of Contents) به بالای صفحه اضافه کنید.' );
		}

		/* ------------------------------------------------ Score */
		$weights = array(
			'kw'         => 3,
			'kw_title'   => 3,
			'kw_intro'   => 2,
			'kw_content' => 2,
			'meta_len'   => 2,
			'kw_meta'    => 2,
			'words'      => 2,
			'kw_unique'  => 2,
		);
		$total   = 0;
		$got     = 0;
		foreach ( $items as $it ) {
			$w      = isset( $weights[ $it['id'] ] ) ? $weights[ $it['id'] ] : 1;
			$total += $w;
			if ( 'good' === $it['status'] ) {
				$got += $w;
			} elseif ( 'warning' === $it['status'] ) {
				$got += $w * 0.5;
			}
		}
		$score = $total ? (int) round( $got / $total * 100 ) : 0;
		update_post_meta( $post_id, self::META_SCORE, $score );

		$order = array(
			'error'   => 0,
			'warning' => 1,
			'good'    => 2,
		);
		$gorder = array_flip( array_keys( self::groups() ) );
		usort(
			$items,
			function ( $a, $b ) use ( $order, $gorder ) {
				$g = $gorder[ $a['group'] ] - $gorder[ $b['group'] ];
				return $g ? $g : $order[ $a['status'] ] - $order[ $b['status'] ];
			}
		);

		return array(
			'score'  => $score,
			'items'  => $items,
			'groups' => self::groups(),
			'data'   => $d,
		);
	}

	/** Language the AI must answer in: the page's own language on multilingual sites, else the setting. */
	private static function lang_name( $post_id = 0 ) {
		$names = array(
			'fa' => 'Persian (Farsi)',
			'en' => 'English',
			'ar' => 'Arabic',
			'de' => 'German',
			'tr' => 'Turkish',
			'fr' => 'French',
			'es' => 'Spanish',
			'ru' => 'Russian',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'ku' => 'Kurdish',
			'az' => 'Azerbaijani',
			'ur' => 'Urdu',
			'zh' => 'Chinese',
		);
		if ( $post_id && '' !== SSAI_SEO::multilingual() ) {
			$l = SSAI_SEO::post_lang( $post_id );
			if ( isset( $names[ $l ] ) ) {
				return $names[ $l ];
			}
		}
		return 'en' === SSAI_Settings::get( 'language' ) ? 'English' : 'Persian (Farsi)';
	}

	/**
	 * AI review with concrete suggestions.
	 *
	 * @return array|WP_Error
	 */
	public static function ai_analyze( $post_id ) {
		$r = self::rules( $post_id );
		$d = $r['data'];
		if ( empty( $d ) ) {
			return new WP_Error( 'ssai_no_post', 'نوشته پیدا نشد.' );
		}
		$max  = (int) SSAI_Settings::get( 'max_chars' );
		$text = mb_substr( $d['text'], 0, $max, 'UTF-8' );

		$findings = array();
		foreach ( $r['items'] as $it ) {
			if ( 'good' !== $it['status'] ) {
				$findings[] = $it['message'];
			}
		}

		$lang   = self::lang_name( $post_id );
		$system = "You are a senior SEO specialist auditing a WordPress page. Analyse on-page SEO: search intent, keyword choice, SEO title, meta description, headings, content quality/E-E-A-T, readability, internal linking and image alt texts. "
			. "Respond ONLY with one valid JSON object, no markdown. Write all explanations (summary, message, fix, tips) in {$lang}. "
			. 'Suggested titles, meta description, keywords and alt texts must be in the SAME language as the page content. '
			. 'keywords: one primary keyword plus up to 4 secondary keywords. A keyword is a short phrase people type into Google (1-5 words), never a page title and never with "|" or "-". If search_queries are given (real Google searches with impressions and position), pick keywords only from them, preferring high impressions in position 3-20 that match the topic; keep the current primary only if it is supported by that data. '
			. 'seo_title: 50-60 characters, primary keyword at the beginning, a real number only if it is true for the business, one persuasive word, brand at the end if it fits. '
			. 'Meta description 120-160 characters with the primary keyword and a call to action. Slug: short, lowercase, hyphen separated. '
			. 'Only suggest alt texts for images listed in the input, using their exact src; include the primary keyword in at least one alt where it truly describes the image. '
			. "Never invent facts, prices, numbers or claims about the business.\n\n" . SSAI_Guidelines::prompt();

		$schema = '{"score":0-100,"summary":"2-4 sentences","issues":[{"severity":"high|medium|low","message":"problem","fix":"how to fix"}],"suggestions":{"keywords":{"primary":"","secondary":[""]},"seo_title":"","title":"page H1 title","meta_description":"","slug":"","image_alts":[{"src":"","alt":""}],"internal_link_ideas":["topic of an existing page to link to"],"content_tips":["concrete tip"]}}';

		$images = array_slice( $d['images'], 0, 20 );
		$input  = array(
			'url'              => $d['url'],
			'title'            => $d['title'],
			'seo_title'        => $d['seo_title'],
			'slug'             => $d['slug'],
			'meta_description' => $d['meta_description'],
			'keywords'         => $d['keywords'],
			'search_queries'   => self::search_queries( $post_id )['rows'],
			'site_name'        => get_bloginfo( 'name' ),
			'word_count'       => $d['words'],
			'headings'         => $d['headings'],
			'images'           => $images,
			'internal_links'   => $d['internal_links'],
			'external_links'   => $d['external_links'],
			'rule_findings'    => $findings,
			'content_text'     => $text,
		);

		$user = "Return JSON with exactly this structure:\n{$schema}\n\nPage data (JSON):\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		$res = SSAI_AI_Client::chat_json( $system, $user );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$out = self::clean_ai( $res, $images );
		$out['time']  = time();
		$out['model'] = SSAI_Settings::get( 'provider' ) . ' / ' . SSAI_Settings::model();
		update_post_meta( $post_id, self::META_AI, wp_slash( $out ) );
		return $out;
	}

	private static function clean_ai( $res, $images ) {
		$s   = isset( $res['suggestions'] ) && is_array( $res['suggestions'] ) ? $res['suggestions'] : array();
		$out = array(
			'score'       => isset( $res['score'] ) ? max( 0, min( 100, (int) $res['score'] ) ) : null,
			'summary'     => isset( $res['summary'] ) ? sanitize_textarea_field( (string) $res['summary'] ) : '',
			'issues'      => array(),
			'suggestions' => array(
				'title'               => isset( $s['title'] ) ? sanitize_text_field( (string) $s['title'] ) : '',
				'seo_title'           => isset( $s['seo_title'] ) ? sanitize_text_field( (string) $s['seo_title'] ) : '',
				'keywords'            => self::clean_keywords( isset( $s['keywords'] ) ? $s['keywords'] : ( isset( $s['focus_keyword'] ) ? $s['focus_keyword'] : '' ) ),
				'meta_description'    => isset( $s['meta_description'] ) ? sanitize_textarea_field( (string) $s['meta_description'] ) : '',
				'slug'                => isset( $s['slug'] ) ? urldecode( sanitize_title( (string) $s['slug'] ) ) : '',
				'image_alts'          => array(),
				'internal_link_ideas' => array(),
				'content_tips'        => array(),
			),
		);
		if ( ! empty( $res['issues'] ) && is_array( $res['issues'] ) ) {
			foreach ( array_slice( $res['issues'], 0, 20 ) as $i ) {
				if ( ! is_array( $i ) || empty( $i['message'] ) ) {
					continue;
				}
				$sev             = isset( $i['severity'] ) && in_array( $i['severity'], array( 'high', 'medium', 'low' ), true ) ? $i['severity'] : 'medium';
				$out['issues'][] = array(
					'severity' => $sev,
					'message'  => sanitize_text_field( (string) $i['message'] ),
					'fix'      => isset( $i['fix'] ) ? sanitize_text_field( (string) $i['fix'] ) : '',
				);
			}
		}
		$valid = wp_list_pluck( $images, 'src' );
		if ( ! empty( $s['image_alts'] ) && is_array( $s['image_alts'] ) ) {
			foreach ( $s['image_alts'] as $ia ) {
				if ( is_array( $ia ) && isset( $ia['src'], $ia['alt'] ) && in_array( $ia['src'], $valid, true ) && '' !== trim( $ia['alt'] ) ) {
					$out['suggestions']['image_alts'][] = array(
						'src' => (string) $ia['src'],
						'alt' => sanitize_text_field( (string) $ia['alt'] ),
					);
				}
			}
		}
		foreach ( array( 'internal_link_ideas', 'content_tips' ) as $k ) {
			if ( ! empty( $s[ $k ] ) && is_array( $s[ $k ] ) ) {
				foreach ( array_slice( $s[ $k ], 0, 10 ) as $t ) {
					if ( is_string( $t ) && '' !== trim( $t ) ) {
						$out['suggestions'][ $k ][] = sanitize_text_field( $t );
					}
				}
			}
		}
		return $out;
	}

	/** Accepts {"primary":"","secondary":[]} or "a, b" and returns "a, b, c". */
	private static function clean_keywords( $k ) {
		$list = array();
		if ( is_array( $k ) ) {
			if ( ! empty( $k['primary'] ) && is_string( $k['primary'] ) ) {
				$list[] = $k['primary'];
			}
			if ( ! empty( $k['secondary'] ) && is_array( $k['secondary'] ) ) {
				foreach ( $k['secondary'] as $sk ) {
					if ( is_string( $sk ) ) {
						$list[] = $sk;
					}
				}
			}
			if ( ! $list && isset( $k[0] ) ) {
				$list = array_filter( $k, 'is_string' );
			}
		} elseif ( is_string( $k ) ) {
			$list = array( $k );
		}
		$out = array();
		foreach ( $list as $kw ) {
			$kw = self::clean_one_keyword( $kw );
			if ( '' !== $kw ) {
				$out[] = $kw;
			}
		}
		return implode( '، ', SSAI_Content::split_keywords( implode( ',', $out ) ) );
	}

	/**
	 * A keyword is a search phrase, not a title: cut "| brand" / " - brand" parts, drop quotes and
	 * anything too long to be something people actually type.
	 */
	public static function clean_one_keyword( $kw ) {
		$kw = trim( wp_strip_all_tags( (string) $kw ), " \t\n\r\0\x0B\"'«»" );
		$kw = preg_split( '/\s*(?:\||｜|–|—|\s-\s|:|؛)\s*/u', $kw );
		$kw = trim( (string) $kw[0] );
		$kw = preg_replace( '/\s+/u', ' ', $kw );
		$words = SSAI_Content::words( $kw );
		if ( '' === $kw || count( $words ) > 6 || mb_strlen( $kw ) > 45 ) {
			return '';
		}
		return sanitize_text_field( $kw );
	}

	/**
	 * Real Google searches for this page (Search Console API or imported export), most important first.
	 *
	 * @return array { source: page|site|'', rows: [ {q, impressions, clicks, position} ] }
	 */
	public static function search_queries( $post_id, $remote = true ) {
		$url = get_permalink( $post_id );
		$rep = null;
		if ( SSAI_GSC::connected() ) {
			$r = $remote ? SSAI_GSC::page_report( $url ) : SSAI_GSC::cached_page_report( $url );
			if ( $r && ! is_wp_error( $r ) ) {
				$rep = $r;
			}
		}
		if ( ! $rep ) {
			$rep = SSAI_GSC_Import::page_report( $post_id, $url );
		}
		if ( ! $rep || empty( $rep['queries'] ) ) {
			return array(
				'source' => '',
				'rows'   => array(),
			);
		}
		$rows = array();
		foreach ( $rep['queries'] as $q ) {
			$rows[] = array(
				'q'           => $q['query'],
				'impressions' => (int) $q['impressions'],
				'clicks'      => (int) $q['clicks'],
				'position'    => round( (float) $q['position'], 1 ),
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['impressions'] - $a['impressions'];
			}
		);
		return array(
			'source' => ( isset( $rep['source'] ) && 'import-site' === $rep['source'] ) ? 'site' : 'page',
			'rows'   => array_slice( $rows, 0, 60 ),
		);
	}

	/**
	 * Keyword set built only from real search data (no AI): highest demand where the page can win
	 * (position up to ~20), skipping near-duplicates and other pages' primary keywords.
	 */
	public static function data_keywords( $rows, $taken = array() ) {
		$taken  = array_map( array( 'SSAI_Content', 'norm' ), $taken );
		$scored = array();
		foreach ( $rows as $r ) {
			$kw = self::clean_one_keyword( $r['q'] );
			if ( '' === $kw || $r['impressions'] < 10 ) {
				continue;
			}
			$weight   = $r['position'] <= 10 ? 1.0 : ( $r['position'] <= 20 ? 0.8 : ( $r['position'] <= 40 ? 0.35 : 0.15 ) );
			$scored[] = array( $kw, $r['impressions'] * $weight + $r['clicks'] * 5 );
		}
		usort(
			$scored,
			function ( $a, $b ) {
				return $b[1] <=> $a[1];
			}
		);
		$out  = array();
		$seen = array();
		foreach ( $scored as $s ) {
			$n = SSAI_Content::norm( $s[0] );
			// "ایمپلنت دیجیتال کرج" ≈ "ایمپلنت دیجیتال در کرج": same words apart from small connectors.
			$sig = preg_replace( '/(^| )(در|و|به|از|برای|با|the|in|for|of)( |$)/u', ' ', $n );
			$sig = implode( ' ', array_filter( explode( ' ', $sig ) ) );
			if ( isset( $seen[ $sig ] ) || in_array( $n, $taken, true ) ) {
				continue;
			}
			$seen[ $sig ] = true;
			$out[]        = $s[0];
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Targeted AI fix for one problem area (cheaper and more precise than a full analysis).
	 *
	 * @param string $target seo_title | meta | slug | keywords | alts
	 * @return array|WP_Error { target, options[] } or { target, image_alts[] }
	 */
	public static function ai_fix( $post_id, $target ) {
		$r = self::rules( $post_id );
		$d = $r['data'];
		if ( empty( $d ) ) {
			return new WP_Error( 'ssai_no_post', 'نوشته پیدا نشد.' );
		}
		$fails = array();
		foreach ( $r['items'] as $it ) {
			if ( 'good' !== $it['status'] && $it['fix'] === $target ) {
				$fails[] = $it['message'];
			}
		}
		$lang  = self::lang_name( $post_id );
		$tasks = array(
			'seo_title' => 'Write 3 alternative SEO titles (50-60 characters): primary keyword at the very beginning, natural wording, one persuasive word, a number only if it is factually true for this business (e.g. years of experience stated in the content), brand name at the end if it fits. Return {"options":["",""," "]}.',
			'meta'      => 'Write 3 alternative meta descriptions (120-160 characters): include the primary keyword naturally, summarise this page honestly, end with a call to action. Return {"options":["","",""]}.',
			'slug'      => 'Write 3 alternative URL slugs: short (max 5 words), contains the primary keyword, lowercase, words separated by hyphens, same language as the keyword or transliterated to English. Return {"options":["","",""]}.',
			'keywords'  => 'Suggest focus keywords for this page: 3 alternative sets, each with one primary keyword and up to 4 secondary keywords. A keyword is a short phrase people type into Google (1-5 words): never a page title, never with "|", "-" or the brand/clinic name unless people search exactly that. If search_queries (real Google searches that showed this page, with impressions and average position) are provided, choose keywords ONLY from them (exact text): prefer high impressions where the page is in position 3-20, matching the page topic and local intent; do not pick near-duplicates. If no search data is given, choose phrases real users most likely search. Avoid the keywords in other_pages_keywords (cannibalisation). Return {"options":[{"primary":"","secondary":[""]}]}.',
			'alts'      => 'Write descriptive alt text for each image (what it shows, in context of the page); include the primary keyword in at least one alt only where it truly describes the image. Use the exact src values. Return {"image_alts":[{"src":"","alt":""}]}.',
		);
		if ( ! isset( $tasks[ $target ] ) ) {
			return new WP_Error( 'ssai_bad_target', 'این مورد با هوش مصنوعی قابل رفع نیست.' );
		}

		$other_kw = array();
		if ( 'keywords' === $target ) {
			global $wpdb;
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ('_ssai_focus_keyword','rank_math_focus_keyword','_yoast_wpseo_focuskw') AND post_id <> %d AND meta_value <> '' LIMIT 100", $post_id ) );
			foreach ( $rows as $row ) {
				$ks = SSAI_Content::split_keywords( $row );
				if ( $ks ) {
					$other_kw[] = $ks[0];
				}
			}
			$other_kw = array_values( array_unique( $other_kw ) );
		}

		$sq = 'keywords' === $target ? self::search_queries( $post_id ) : array(
			'source' => '',
			'rows'   => array(),
		);
		$system = 'You are a senior SEO specialist. ' . $tasks[ $target ]
			. " Write in the same language as the page content. Respond ONLY with JSON. Never invent facts, prices or claims.\n\n" . SSAI_Guidelines::prompt();
		$input  = array(
			'problems_to_fix'      => $fails,
			'keywords'             => $d['keywords'],
			'seo_title'            => $d['seo_title'],
			'page_title'           => $d['title'],
			'site_name'            => get_bloginfo( 'name' ),
			'meta_description'     => $d['meta_description'],
			'slug'                 => $d['slug'],
			'headings'             => array_slice( $d['headings'], 0, 30 ),
			'images'               => 'alts' === $target ? array_slice( $d['images'], 0, 20 ) : count( $d['images'] ),
			'other_pages_keywords' => $other_kw,
			'search_queries'       => 'keywords' === $target ? $sq['rows'] : array(),
			'content_text'         => mb_substr( $d['text'], 0, min( 6000, (int) SSAI_Settings::get( 'max_chars' ) ), 'UTF-8' ),
		);
		$res = SSAI_AI_Client::chat_json( $system, 'Input (JSON):' . "\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		if ( 'alts' === $target ) {
			$clean = self::clean_ai( array( 'suggestions' => array( 'image_alts' => isset( $res['image_alts'] ) ? $res['image_alts'] : array() ) ), $d['images'] );
			if ( empty( $clean['suggestions']['image_alts'] ) ) {
				return new WP_Error( 'ssai_empty', 'هوش مصنوعی متن alt قابل استفاده‌ای برنگرداند. دوباره امتحان کنید.' );
			}
			return array(
				'target'     => 'alts',
				'image_alts' => $clean['suggestions']['image_alts'],
			);
		}

		$opts = array();
		if ( 'keywords' === $target && $sq['rows'] ) {
			$dk = self::data_keywords( $sq['rows'], $other_kw );
			if ( $dk ) {
				$opts[] = implode( '، ', $dk );
			}
		}
		$raw  = isset( $res['options'] ) && is_array( $res['options'] ) ? $res['options'] : array();
		foreach ( array_slice( $raw, 0, 5 ) as $o ) {
			if ( 'keywords' === $target ) {
				$v = self::clean_keywords( $o );
			} elseif ( 'slug' === $target ) {
				$v = is_string( $o ) ? urldecode( sanitize_title( $o ) ) : '';
			} elseif ( 'meta' === $target ) {
				$v = is_string( $o ) ? sanitize_textarea_field( $o ) : '';
			} else {
				$v = is_string( $o ) ? sanitize_text_field( $o ) : '';
			}
			if ( '' !== $v && ! in_array( $v, $opts, true ) ) {
				$opts[] = $v;
			}
		}
		if ( empty( $opts ) ) {
			return new WP_Error( 'ssai_empty', 'هوش مصنوعی پیشنهاد قابل استفاده‌ای برنگرداند. دوباره امتحان کنید.' );
		}
		return array(
			'target'  => $target,
			'options' => $opts,
			'data'    => 'keywords' === $target && $sq['rows'] ? ( 'page' === $sq['source'] ? 'page' : 'site' ) : '',
		);
	}

	/**
	 * AI rewrite of the full content. The result is kept server-side (transient) until the admin approves it.
	 *
	 * @return array|WP_Error { token, html, notes }
	 */
	public static function ai_rewrite( $post_id, $instructions = '', $link_ideas = array() ) {
		SSAI_AI_Client::$min_timeout = 180;
		$d = SSAI_Content::data( $post_id );
		if ( ! $d ) {
			return new WP_Error( 'ssai_no_post', 'نوشته پیدا نشد.' );
		}
		if ( 'elementor' === $d['builder'] || 'avada' === $d['builder'] ) {
			$cands = self::link_candidates( $post_id, $link_ideas );
			if ( is_wp_error( $cands ) ) {
				return $cands;
			}
			return self::ai_rewrite_elementor( $post_id, $d, $instructions, $cands );
		}
		$cands = self::link_candidates( $post_id, $link_ideas );
		if ( is_wp_error( $cands ) ) {
			return $cands;
		}
		$html = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $d['html'] );
		$html = trim( preg_replace( "/\n{3,}/", "\n\n", $html ) );
		if ( '' === $html ) {
			return new WP_Error( 'ssai_empty_content', 'محتوایی برای بازنویسی وجود ندارد.' );
		}
		$max = (int) SSAI_Settings::get( 'max_chars' );
		if ( mb_strlen( $html, 'UTF-8' ) > $max ) {
			return new WP_Error( 'ssai_too_long', sprintf( 'محتوا (%d کاراکتر) از سقف تنظیم‌شده (%d) طولانی‌تر است؛ بازنویسی ناقص خطرناک است. سقف را در تنظیمات بالا ببرید یا از پیشنهادهای جزئی استفاده کنید.', mb_strlen( $html, 'UTF-8' ), $max ) );
		}

		$lang   = self::lang_name( $post_id );
		$system = 'You are an expert SEO copy editor. Improve the given HTML article for SEO and readability while keeping its meaning, facts, tone and language. '
			. 'Rules: keep every <img>, <a>, <iframe>, <figure>, table and shortcode ([...]) exactly as they are (same attributes and URLs); do not invent facts, prices, statistics or claims; '
			. 'use <h2>/<h3> subheadings (never <h1>); use the focus keyword naturally in the first paragraph and in one subheading, without stuffing; '
			. 'if internal_link_candidates are given, add natural links (<a href>) to them with descriptive anchor text, never other new URLs; '
			. 'follow extra_instructions (e.g. add an FAQ section) as long as no facts are invented; '
			. 'split very long paragraphs; use lists where helpful; output clean HTML body only (no <html>, <head>, <body>, no markdown). '
			. "Respond ONLY with JSON: {\"html\":\"...\",\"notes\":[\"what changed\"]}. Write notes in {$lang}.\n\n" . SSAI_Guidelines::prompt();

		$user = wp_json_encode(
			array(
				'title'              => $d['title'],
				'focus_keyword'      => $d['focus_keyword'],
				'secondary_keywords' => array_slice( $d['keywords'], 1 ),
				'extra_instructions'       => sanitize_textarea_field( $instructions ),
				'internal_link_candidates' => $cands,
				'html'                     => $html,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$res = SSAI_AI_Client::chat_json( $system, "Return JSON {\"html\":\"...\",\"notes\":[]} for this input:\n" . $user );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['html'] ) || ! is_string( $res['html'] ) ) {
			return new WP_Error( 'ssai_bad_rewrite', 'هوش مصنوعی متن بازنویسی‌شده را برنگرداند.' );
		}
		$new = self::filter_links( wp_kses_post( $res['html'] ), $html, $cands );
		$new = preg_replace( '/<h1(\b[^>]*)>(.*?)<\/h1>/is', '<h2$1>$2</h2>', $new );

		// Safety: images and links must not disappear.
		$lost = array();
		foreach ( $d['images'] as $img ) {
			if ( false === strpos( $new, $img['src'] ) && false === strpos( $new, esc_attr( $img['src'] ) ) ) {
				$lost[] = 'تصویر: ' . $img['src'];
			}
		}
		$notes = array();
		if ( ! empty( $res['notes'] ) && is_array( $res['notes'] ) ) {
			foreach ( array_slice( $res['notes'], 0, 15 ) as $n ) {
				if ( is_string( $n ) ) {
					$notes[] = sanitize_text_field( $n );
				}
			}
		}

		$token = wp_generate_password( 20, false );
		set_transient(
			'ssai_rw_' . $post_id . '_' . get_current_user_id(),
			array(
				'token' => $token,
				'html'  => $new,
			),
			HOUR_IN_SECONDS
		);

		$old_words = $d['words'];
		$new_words = count( SSAI_Content::words( SSAI_Content::html_to_text( $new ) ) );

		return array(
			'token'     => $token,
			'html'      => $new,
			'notes'     => $notes,
			'lost'      => $lost,
			'old_words' => $old_words,
			'new_words' => $new_words,
			'blocks'    => has_blocks( $d['html'] ),
		);
	}

	/**
	 * Elementor pages: rewrite each text widget separately, so the design/layout stays intact.
	 *
	 * @return array|WP_Error { mode, token, items:[{id,label,old,new}], notes }
	 */
	private static function ai_rewrite_elementor( $post_id, $d, $instructions, $cands = array() ) {
		SSAI_AI_Client::$min_timeout = 180;
		$avada = 'avada' === $d['builder'];
		$items = $avada ? SSAI_Avada::text_items( $post_id ) : SSAI_Elementor::text_items( $post_id );
		if ( empty( $items ) ) {
			return new WP_Error( 'ssai_no_widgets', $avada ? 'المان Title یا Text قابل بازنویسی در این صفحه آوادا پیدا نشد.' : 'ویجت متنی قابل بازنویسی (تیتر، ویرایشگر متن، آیکون‌باکس…) در این صفحه المنتور پیدا نشد.' );
		}
		$max   = (int) SSAI_Settings::get( 'max_chars' );
		$total = 0;
		$send  = array();
		foreach ( $items as $it ) {
			$total += mb_strlen( $it['html'], 'UTF-8' );
			$send[] = array(
				'id'   => $it['id'],
				'type' => $it['label'],
				'html' => $it['html'],
			);
		}
		if ( $total > $max ) {
			return new WP_Error( 'ssai_too_long', sprintf( 'متن ویجت‌ها (%d کاراکتر) از سقف تنظیم‌شده (%d) بیشتر است. سقف را در تنظیمات بالا ببرید.', $total, $max ) );
		}

		$lang   = self::lang_name( $post_id );
		$system = 'You are an expert SEO copy editor improving the text widgets of a page-builder (' . ( $avada ? 'Avada' : 'Elementor' ) . ') landing page. Each item is one widget. '
			. 'Improve wording for SEO and readability, keep meaning, facts, tone and language; do not invent facts, prices or claims. '
			. 'Use the focus keyword naturally (in the first text block and one heading), without stuffing. '
			. 'Headings stay short single-line plain text. Text items may contain HTML: keep all <a>, <img> tags and shortcodes exactly; you may use <p>, <strong>, <ul>, <li>; never use <h1>. '
			. 'If internal_link_candidates are given, add natural links (<a href>) to them inside text items (not headings), never other new URLs. '
			. 'Follow extra_instructions where possible (e.g. an FAQ can be appended as <h3>/<p> pairs to the most suitable text item) without inventing facts. '
			. 'Return an improved version of EVERY item (improve each at least a little; make headings more descriptive with the keyword where natural). Copy each id string exactly as given. Keep roughly the same length for headings and box texts (the design depends on it). '
			. "Respond ONLY with JSON: {\"items\":[{\"id\":\"...\",\"html\":\"...\"}],\"notes\":[\"what changed\"]}. Write notes in {$lang}.\n\n" . SSAI_Guidelines::prompt();

		$user = wp_json_encode(
			array(
				'page_title'         => $d['title'],
				'focus_keyword'      => $d['focus_keyword'],
				'secondary_keywords' => array_slice( $d['keywords'], 1 ),
				'extra_instructions'       => sanitize_textarea_field( $instructions ),
				'internal_link_candidates' => $cands,
				'items'                    => $send,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$by_id = array();
		foreach ( $items as $it ) {
			$by_id[ $it['id'] ] = $it;
		}

		$prompt = "Return JSON {\"items\":[{\"id\":\"...\",\"html\":\"...\"}],\"notes\":[]} for this input:\n" . $user;
		$res    = SSAI_AI_Client::chat_json( $system, $prompt );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = self::collect_rewrites( $res, $items, $by_id, $cands );
		if ( empty( $out ) ) {
			// Small/free models sometimes return nothing useful – ask once more, more strictly.
			$res2 = SSAI_AI_Client::chat_json(
				$system . ' IMPORTANT: your previous answer contained no usable changes. You MUST return every item with rewritten, improved text and the exact same id.',
				$prompt
			);
			if ( ! is_wp_error( $res2 ) ) {
				$res = $res2;
				$out = self::collect_rewrites( $res, $items, $by_id, $cands );
			}
		}
		if ( empty( $out ) ) {
			$snippet = mb_substr( wp_strip_all_tags( wp_json_encode( $res, JSON_UNESCAPED_UNICODE ) ), 0, 250 );
			return new WP_Error( 'ssai_no_changes', 'هوش مصنوعی متن قابل استفاده‌ای برنگرداند (دو بار امتحان شد). یک مدل قوی‌تر انتخاب کنید یا دستور اضافه بنویسید. بخشی از پاسخ مدل: ' . $snippet );
		}

		$notes = array();
		if ( ! empty( $res['notes'] ) && is_array( $res['notes'] ) ) {
			foreach ( array_slice( $res['notes'], 0, 15 ) as $n ) {
				if ( is_string( $n ) ) {
					$notes[] = sanitize_text_field( $n );
				}
			}
		}

		$token = wp_generate_password( 20, false );
		set_transient(
			'ssai_rw_' . $post_id . '_' . get_current_user_id(),
			array(
				'token' => $token,
				'mode'  => 'elementor',
				'items' => wp_list_pluck( $out, 'new', 'id' ),
			),
			HOUR_IN_SECONDS
		);

		return array(
			'mode'  => 'elementor',
			'token' => $token,
			'items' => $out,
			'notes' => $notes,
		);
	}

	/** Accepts the different JSON shapes models return and maps them back to widget ids. */
	private static function collect_rewrites( $res, $items, $by_id, $cands ) {
		$list = null;
		foreach ( array( 'items', 'changes', 'widgets', 'results', 'data' ) as $k ) {
			if ( isset( $res[ $k ] ) && is_array( $res[ $k ] ) ) {
				$list = $res[ $k ];
				break;
			}
		}
		if ( null === $list && isset( $res[0] ) ) {
			$list = $res; // A bare array.
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		// Shape { "id": "html", ... }.
		if ( ! isset( $list[0] ) ) {
			$tmp = array();
			foreach ( $list as $k => $v ) {
				if ( is_string( $v ) ) {
					$tmp[] = array(
						'id'   => $k,
						'html' => $v,
					);
				}
			}
			$list = $tmp;
		}

		// Element id -> item ids (for answers that drop the ":key" part).
		$by_el = array();
		foreach ( $items as $it ) {
			$by_el[ strtok( $it['id'], ':' ) ][] = $it['id'];
		}

		$out = array();
		foreach ( array_values( $list ) as $i => $ri ) {
			if ( ! is_array( $ri ) ) {
				continue;
			}
			$html = null;
			foreach ( array( 'html', 'new_html', 'text', 'content', 'new', 'value', 'rewritten' ) as $k ) {
				if ( isset( $ri[ $k ] ) && is_string( $ri[ $k ] ) ) {
					$html = $ri[ $k ];
					break;
				}
			}
			if ( null === $html ) {
				continue;
			}
			$id = isset( $ri['id'] ) ? (string) $ri['id'] : ( isset( $ri['widget_id'] ) ? (string) $ri['widget_id'] : '' );
			if ( ! isset( $by_id[ $id ] ) && isset( $by_el[ $id ] ) && 1 === count( $by_el[ $id ] ) ) {
				$id = $by_el[ $id ][0];
			}
			if ( ! isset( $by_id[ $id ] ) && count( $list ) === count( $items ) && isset( $items[ $i ] ) ) {
				$id = $items[ $i ]['id']; // Same order as sent.
			}
			if ( ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$orig    = $by_id[ $id ];
			$is_head = 0 === strpos( $orig['label'], 'تیتر' ) || 0 === strpos( $orig['label'], 'عنوان' );
			$new     = $is_head ? sanitize_text_field( wp_strip_all_tags( $html ) ) : self::filter_links( wp_kses_post( preg_replace( '/<h1(\b[^>]*)>(.*?)<\/h1>/is', '<h2$1>$2</h2>', $html ) ), $orig['html'], $cands );
			$norm    = function ( $x ) {
				return preg_replace( '/\s+/u', ' ', trim( wp_strip_all_tags( $x ) ) );
			};
			if ( '' === $norm( $new ) || $norm( $new ) === $norm( $orig['html'] ) ) {
				continue;
			}
			// Safety: links and images in this widget must survive.
			$lost = array();
			if ( preg_match_all( '/(?<=\s)(?:href|src)=(["\'])(.*?)\1/i', $orig['html'], $m ) ) {
				foreach ( $m[2] as $url ) {
					if ( false === strpos( $new, $url ) && false === strpos( $new, esc_attr( $url ) ) ) {
						$lost[] = $url;
					}
				}
			}
			$out[ $id ] = array(
				'id'    => $id,
				'label' => $orig['label'],
				'old'   => $orig['html'],
				'new'   => $new,
				'lost'  => $lost,
			);
		}
		return array_values( $out );
	}

	/**
	 * Find existing published pages on the site that match the AI's internal-link ideas.
	 *
	 * @return array|WP_Error list of { title, url }
	 */
	private static function link_candidates( $post_id, $ideas ) {
		$ideas = array_filter( array_map( 'trim', (array) $ideas ) );
		if ( empty( $ideas ) ) {
			return array();
		}
		$out  = array();
		$seen = array( (int) $post_id => true );
		foreach ( array_slice( $ideas, 0, 5 ) as $idea ) {
			// Search the whole phrase first, then its longest words.
			$terms = array( $idea );
			$words = SSAI_Content::words( $idea );
			usort(
				$words,
				function ( $a, $b ) {
					return mb_strlen( $b ) - mb_strlen( $a );
				}
			);
			foreach ( array_slice( $words, 0, 3 ) as $w ) {
				if ( mb_strlen( $w ) >= 3 ) {
					$terms[] = $w;
				}
			}
			foreach ( $terms as $t ) {
				$q = new WP_Query(
					array(
						's'                   => $t,
						'post_type'           => SSAI_Admin::post_types(),
						'post_status'         => 'publish',
						'posts_per_page'      => 3,
						'no_found_rows'       => true,
						'ignore_sticky_posts' => true,
					)
				);
				foreach ( $q->posts as $p ) {
					if ( isset( $seen[ $p->ID ] ) ) {
						continue;
					}
					$seen[ $p->ID ] = true;
					$out[]          = array(
						'title' => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
						'url'   => get_permalink( $p ),
					);
				}
				if ( count( $out ) >= 8 ) {
					break 2;
				}
			}
		}
		if ( empty( $out ) ) {
			return new WP_Error( 'ssai_no_targets', 'صفحه مرتبطی در سایت برای این ایده لینک داخلی پیدا نشد. ابتدا آن صفحه را بسازید، یا ایده دیگری را امتحان کنید.' );
		}
		return $out;
	}

	/** Remove links the AI added that are neither in the original text nor in the allowed candidates. */
	private static function filter_links( $new, $orig, $cands ) {
		$allowed = array();
		if ( preg_match_all( '/(?<=\s)href=(["\'])(.*?)\1/i', (string) $orig, $m ) ) {
			foreach ( $m[2] as $u ) {
				$allowed[ html_entity_decode( $u ) ] = true;
			}
		}
		foreach ( (array) $cands as $c ) {
			$allowed[ $c['url'] ] = true;
		}
		return preg_replace_callback(
			'/<a\b[^>]*>(.*?)<\/a>/is',
			function ( $m ) use ( $allowed ) {
				if ( preg_match( '/(?<=\s)href=(["\'])(.*?)\1/i', $m[0], $h ) && isset( $allowed[ html_entity_decode( $h[2] ) ] ) ) {
					return $m[0];
				}
				return $m[1]; // Keep the text, drop the unknown link.
			},
			(string) $new
		);
	}
}
