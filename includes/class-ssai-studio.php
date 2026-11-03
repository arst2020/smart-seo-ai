<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content studio: brand profile, ideas (own topic, Search Console, refresh), brief → outline → section-by-section
 * writing → draft (Gutenberg or Elementor template), category intros, product descriptions, and a publishing calendar.
 * Nothing is published without the user's approval; numbers, prices and claims the AI cannot know are marked for review.
 */
class SSAI_Studio {

	const SLUG    = 'smart-seo-ai-studio';
	const CPT     = 'ssai_content';
	const META    = '_ssai_cs';
	const BRAND   = 'ssai_brand';
	const CAL     = 'ssai_calendar';
	const ITEM_PM = '_ssai_cs_item'; // On a created post: the studio item it came from.
	const CAP     = 'edit_others_posts';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_publish' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'guard_notice' ) );
		add_action( 'wp_head', array( __CLASS__, 'marker_css' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_css' ) );
		add_action(
			'admin_enqueue_scripts',
			function ( $hook ) {
				if ( false !== strpos( (string) $hook, self::SLUG ) ) {
					wp_enqueue_media(); // Media library picker for the section images.
				}
			}
		);
		foreach ( array( 'profile_save', 'profile_learn', 'ideas', 'item_save', 'item_get', 'item_delete', 'outline', 'section', 'save', 'schedule', 'calendar_save', 'autofill', 'alt', 'short', 'short_apply', 'short_undo', 'shorts' ) as $a ) {
			add_action( 'wp_ajax_ssai_cs_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
	}

	public static function register() {
		register_post_type(
			self::CPT,
			array(
				'public'       => false,
				'show_ui'      => false,
				'rewrite'      => false,
				'query_var'    => false,
				'supports'     => array( 'title' ),
				'can_export'   => true,
				'labels'       => array( 'name' => 'محتوای در حال تولید' ),
			)
		);
	}

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'تولید محتوا', 'تولید محتوا', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	public static function url( $tab = '', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $tab ? array( 'tab' => $tab ) : array(), $args ), admin_url( 'admin.php' ) );
	}

	/* ================================================================ Data */

	public static function types() {
		return array(
			'article'  => 'مقاله وبلاگ',
			'service'  => 'صفحه خدمات',
			'category' => 'متن معرفی دسته',
			'product'  => 'توضیحات محصول',
		);
	}

	public static function statuses() {
		return array(
			'idea'      => 'ایده',
			'outline'   => 'سرفصل',
			'writing'   => 'در حال نوشتن',
			'draft'     => 'پیش‌نویس',
			'scheduled' => 'زمان‌بندی‌شده',
			'published' => 'منتشرشده',
			'applied'   => 'اعمال‌شده',
		);
	}

	public static function brand() {
		$b = get_option( self::BRAND );
		return wp_parse_args(
			is_array( $b ) ? $b : array(),
			array(
				'business' => '',
				'audience' => '',
				'tone'     => 'friendly',
				'person'   => 'we',
				'cta_text' => '',
				'cta_url'  => '',
				'avoid'    => '',
				'rules'    => '',
				'style'    => '',
			)
		);
	}

	public static function calendar() {
		$c = get_option( self::CAL );
		return wp_parse_args( is_array( $c ) ? $c : array(), array( 'per_week' => 2, 'days' => array( 6, 2 ), 'time' => '10:00' ) );
	}

	private static function defaults() {
		return array(
			'type'      => 'article',
			'source'    => 'manual',
			'topic'     => '',
			'keyword'   => '',
			'secondary' => '',
			'audience'  => '',
			'length'    => 'medium',
			'images'    => 3,       // Number of images inside the text (chosen by the user).
			'lang'      => '',
			'post_type' => 'post',
			'category'  => 0,
			'output'    => 'gutenberg',
			'template'  => 0,
			'target'    => 0,     // Refresh: post id. Category: term id.
			'tax'       => '',
			'notes'     => '',
			'titles'    => array(),
			'title'     => '',
			'outline'   => array(), // [ level (0 = intro), heading, notes, image{desc,alt}|null ]
			'sections'  => array(), // index => html
			'seo'       => array( 'seo_title' => '', 'meta' => '', 'slug' => '', 'keywords' => array() ),
			'status'    => 'idea',
			'post_id'   => 0,
			'date'      => '',      // Planned / scheduled date (Y-m-d H:i, site time).
		);
	}

	public static function item( $id ) {
		$p = get_post( (int) $id );
		if ( ! $p || self::CPT !== $p->post_type ) {
			return null;
		}
		$m       = get_post_meta( $p->ID, self::META, true );
		$m       = wp_parse_args( is_array( $m ) ? $m : array(), self::defaults() );
		$m['id'] = $p->ID;
		// Keep the status in step with the real post (published / scheduled / deleted outside the studio).
		if ( $m['post_id'] ) {
			$post = get_post( $m['post_id'] );
			if ( ! $post || 'trash' === $post->post_status ) {
				$m['post_id'] = 0;
				$m['status']  = $m['sections'] ? 'writing' : ( $m['outline'] ? 'outline' : 'idea' );
			} elseif ( 'publish' === $post->post_status && in_array( $m['type'], array( 'article', 'service' ), true ) && ! $m['target'] ) {
				$m['status'] = 'published';
				$m['date']   = get_date_from_gmt( $post->post_date_gmt, 'Y-m-d H:i' );
			} elseif ( 'future' === $post->post_status ) {
				$m['status'] = 'scheduled';
				$m['date']   = get_date_from_gmt( $post->post_date_gmt, 'Y-m-d H:i' );
			}
		}
		return $m;
	}

	private static function store( $m ) {
		$title = '' !== $m['title'] ? $m['title'] : ( '' !== $m['topic'] ? $m['topic'] : '(بدون موضوع)' );
		if ( empty( $m['id'] ) ) {
			$id = wp_insert_post(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'publish',
					'post_title'  => wp_strip_all_tags( $title ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$m['id'] = $id;
		} else {
			wp_update_post( array( 'ID' => (int) $m['id'], 'post_title' => wp_strip_all_tags( $title ) ) );
		}
		$save = $m;
		unset( $save['id'] );
		update_post_meta( $m['id'], self::META, wp_slash( $save ) );
		return $m;
	}

	public static function items() {
		$q   = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$out = array();
		foreach ( $q as $p ) {
			$out[] = self::item( $p->ID );
		}
		return $out;
	}

	/* ================================================================ AI prompts */

	private static function lang_name( $code ) {
		$names = array( 'fa' => 'Persian (Farsi)', 'en' => 'English', 'ar' => 'Arabic', 'de' => 'German', 'tr' => 'Turkish', 'fr' => 'French', 'es' => 'Spanish', 'ru' => 'Russian', 'it' => 'Italian', 'nl' => 'Dutch', 'ku' => 'Kurdish', 'az' => 'Azerbaijani', 'ur' => 'Urdu', 'zh' => 'Chinese' );
		if ( '' === $code ) {
			$code = 'en' === SSAI_Settings::get( 'language' ) ? 'en' : substr( get_locale(), 0, 2 );
		}
		return isset( $names[ $code ] ) ? $names[ $code ] : 'Persian (Farsi)';
	}

	public static function languages() {
		$out = array();
		if ( function_exists( 'pll_languages_list' ) ) {
			foreach ( (array) pll_languages_list( array( 'fields' => 'slug' ) ) as $l ) {
				$out[ $l ] = $l;
			}
		} else {
			$w = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			if ( is_array( $w ) ) {
				foreach ( $w as $code => $l ) {
					$out[ $code ] = isset( $l['native_name'] ) ? $l['native_name'] : $code;
				}
			}
		}
		return $out;
	}

	/** The writer's brief: who we are, how we write, and the accuracy rules. */
	private static function system( $m ) {
		$b     = self::brand();
		$tones = array(
			'formal'   => 'formal and respectful',
			'friendly' => 'warm, friendly and easy to read, but still professional',
			'expert'   => 'expert and precise, like a specialist explaining to an informed reader',
			'simple'   => 'very simple and clear, short sentences, no jargon',
		);
		$s  = 'You are a senior content writer and SEO editor producing content for a real business website. ';
		$s .= 'Write everything in ' . self::lang_name( $m['lang'] ) . ". Write naturally for people first, not for search engines.\n";
		if ( '' !== $b['business'] ) {
			$s .= 'Business: ' . $b['business'] . "\n";
		} else {
			$s .= 'Website: ' . get_bloginfo( 'name' ) . ' — ' . get_bloginfo( 'description' ) . "\n";
		}
		$aud = '' !== $m['audience'] ? $m['audience'] : $b['audience'];
		if ( '' !== $aud ) {
			$s .= 'Audience: ' . $aud . "\n";
		}
		$s .= 'Tone: ' . ( isset( $tones[ $b['tone'] ] ) ? $tones[ $b['tone'] ] : $tones['friendly'] ) . '. ';
		$s .= 'we' === $b['person'] ? "Speak as the business (\"we\").\n" : "Speak in first person singular (\"I\").\n";
		if ( '' !== $b['style'] ) {
			$s .= "House style notes:\n" . $b['style'] . "\n";
		}
		if ( '' !== $b['avoid'] ) {
			$s .= "Never use these words or make these claims:\n" . $b['avoid'] . "\n";
		}
		if ( '' !== $b['rules'] ) {
			$s .= "Extra rules from the editor:\n" . $b['rules'] . "\n";
		}
		$s .= "ACCURACY RULES (most important):\n";
		$s .= "- Never invent statistics, percentages, prices, costs, dates, durations, study results, awards, years of experience, numbers of customers, testimonials, quotes, names of people, credentials or medical/legal/financial claims.\n";
		$s .= "- When the text needs such a fact, write a placeholder in double square brackets saying what must be checked, e.g. [[REVIEW: current price of the service]] or [[REVIEW: recovery time confirmed by the doctor]]. The editor fills these in.\n";
		$s .= "- For health, psychology, legal or money topics: be cautious, avoid promises and guarantees, and advise consulting a qualified professional where relevant.\n";
		$s .= "- No fake internal or external links; do not add links at all.\n";
		$s .= "- Never use <h1>. Use the focus keyword naturally (no stuffing).\n";
		$s .= SSAI_Guidelines::prompt() . "\n";
		$s .= 'Reply only with valid JSON.';
		return $s;
	}

	private static function words_target( $m ) {
		$map = array( 'short' => 800, 'medium' => 1500, 'long' => 2500 );
		return isset( $map[ $m['length'] ] ) ? $map[ $m['length'] ] : 1500;
	}

	private static function brief_json( $m ) {
		$b = self::brand();
		$t = self::types();
		return array(
			'content_type'       => isset( $t[ $m['type'] ] ) ? $m['type'] : 'article',
			'topic'              => $m['topic'],
			'focus_keyword'      => $m['keyword'],
			'secondary_keywords' => SSAI_Content::split_keywords( $m['secondary'] ),
			'target_words'       => self::words_target( $m ),
			'editor_notes'       => $m['notes'],
			'call_to_action'     => trim( $b['cta_text'] ),
		);
	}

	/** Existing text of the page being refreshed (shortened). */
	private static function existing( $m ) {
		if ( ! $m['target'] || ! in_array( $m['type'], array( 'article', 'service' ), true ) ) {
			return null;
		}
		$d = SSAI_Content::data( $m['target'] );
		if ( ! $d ) {
			return null;
		}
		$heads = array();
		foreach ( $d['headings'] as $h ) {
			$heads[] = 'H' . $h['level'] . ': ' . $h['text'];
		}
		return array(
			'title'    => $d['title'],
			'headings' => $heads,
			'text'     => mb_substr( $d['text'], 0, max( 3000, (int) SSAI_Settings::get( 'max_chars' ) ) ),
		);
	}

	/* ================================================================ Rendering helpers */

	/** [[REVIEW: …]] → visible marker; also escapes nothing else (input already passed wp_kses_post). */
	public static function marks( $html ) {
		return preg_replace_callback(
			'/\[\[\s*(?:REVIEW|بررسی|CHECK)\s*:?\s*(.*?)\]\]/iu',
			function ( $mm ) {
				return '<mark class="ssai-review">[نیاز به بررسی: ' . esc_html( trim( wp_strip_all_tags( $mm[1] ) ) ) . ']</mark>';
			},
			$html
		);
	}

	public static function has_marks( $text ) {
		return false !== strpos( (string) $text, 'ssai-review' ) || false !== strpos( (string) $text, 'ssai-image-hint' ) || (bool) preg_match( '/\[\[\s*REVIEW/i', (string) $text );
	}

	private static function clean_html( $html ) {
		$html = wp_kses_post( (string) $html );
		$html = preg_replace( '/<h1(\b[^>]*)>(.*?)<\/h1>/is', '<h2$1>$2</h2>', $html );
		$html = preg_replace( '/<a\b[^>]*>(.*?)<\/a>/is', '$1', $html ); // No links from the AI.
		// Images are only the ones the editor planned in the outline: drop images, figures, media and image placeholders the model adds.
		$html = preg_replace( '/<figure\b[^>]*>.*?<\/figure>|<(picture|video|audio|iframe)\b[^>]*>.*?<\/\1>|<(img|source|embed)\b[^>]*\/?>/is', '', $html );
		$html = preg_replace( '/<(p|div)\b[^>]*>\s*(?:\[\[\s*(?:REVIEW|بررسی|CHECK)[^\]]*\]\]\s*)?(?:🖼|📷)?\s*(?:تصویر|عکس|اینفوگرافیک|نمودار|image|photo|picture|infographic)\s*(?:پیشنهادی|:|：|-)[^<]{0,300}<\/\1>/iu', '', $html );
		$html = preg_replace( '/\[\[\s*(?:REVIEW|بررسی|CHECK)\s*:?[^\]]*(?:تصویر|عکس|اینفوگرافیک|image|photo|picture|infographic)[^\]]*\]\]/iu', '', $html );
		$html = preg_replace( '/<(p|li)\b[^>]*>\s*<\/\1>/i', '', $html );
		return trim( self::marks( $html ) );
	}

	/** A chosen media-library image as HTML (same markup the image block saves). */
	private static function image_tag( $img ) {
		$id  = (int) $img['id'];
		$src = wp_get_attachment_image_url( $id, 'large' );
		if ( ! $src ) {
			return '';
		}
		$alt = '' !== $img['alt'] ? $img['alt'] : (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		return '<figure class="wp-block-image size-large"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . $id . '"/></figure>';
	}

	private static function image_hint( $img ) {
		if ( ! empty( $img['id'] ) ) {
			$tag = self::image_tag( $img );
			if ( '' !== $tag ) {
				return $tag;
			}
		}
		if ( empty( $img['desc'] ) ) {
			return '';
		}
		return '<p class="ssai-image-hint">🖼 تصویر پیشنهادی: ' . esc_html( $img['desc'] ) . ( ! empty( $img['alt'] ) ? ' — متن alt: «' . esc_html( $img['alt'] ) . '»' : '' ) . ' (این کادر را با تصویر جایگزین کنید)</p>';
	}

	/** Plain HTML of the whole piece (preview, classic content, Elementor post_content). */
	public static function html( $m ) {
		$out = '';
		foreach ( $m['outline'] as $i => $o ) {
			if ( (int) $o['level'] > 0 && '' !== trim( $o['heading'] ) ) {
				$tag  = 3 === (int) $o['level'] ? 'h3' : 'h2';
				$out .= '<' . $tag . '>' . esc_html( $o['heading'] ) . '</' . $tag . ">\n";
			}
			if ( ! empty( $o['image'] ) ) {
				$out .= self::image_hint( $o['image'] ) . "\n";
			}
			$out .= isset( $m['sections'][ $i ] ) ? self::clean_html( $m['sections'][ $i ] ) . "\n" : '<p><mark class="ssai-review">[نیاز به بررسی: این بخش هنوز نوشته نشده]</mark></p>' . "\n";
		}
		return $out;
	}

	/** Same content as Gutenberg blocks. */
	public static function blocks( $m ) {
		$out = '';
		foreach ( $m['outline'] as $i => $o ) {
			if ( (int) $o['level'] > 0 && '' !== trim( $o['heading'] ) ) {
				$lvl  = 3 === (int) $o['level'] ? 3 : 2;
				$out .= '<!-- wp:heading' . ( 3 === $lvl ? ' {"level":3}' : '' ) . " -->\n<h$lvl class=\"wp-block-heading\">" . esc_html( $o['heading'] ) . "</h$lvl>\n<!-- /wp:heading -->\n\n";
			}
			if ( ! empty( $o['image'] ) ) {
				$tag = ! empty( $o['image']['id'] ) ? self::image_tag( $o['image'] ) : '';
				if ( '' !== $tag ) {
					$out .= '<!-- wp:image {"id":' . (int) $o['image']['id'] . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n" . $tag . "\n<!-- /wp:image -->\n\n";
				} elseif ( ! empty( $o['image']['desc'] ) ) {
					$out .= "<!-- wp:paragraph {\"className\":\"ssai-image-hint\"} -->\n" . self::image_hint( $o['image'] ) . "\n<!-- /wp:paragraph -->\n\n";
				}
			}
			$out .= self::html_to_blocks( isset( $m['sections'][ $i ] ) ? self::clean_html( $m['sections'][ $i ] ) : '' ); // Re-cleaned: also fixes text written by older versions.
		}
		return $out;
	}

	public static function html_to_blocks( $html ) {
		$html = trim( (string) $html );
		if ( '' === $html ) {
			return '';
		}
		if ( ! class_exists( 'DOMDocument' ) ) {
			return "<!-- wp:freeform -->\n" . $html . "\n<!-- /wp:freeform -->\n\n";
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new DOMDocument();
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="ssai-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$root = $doc->getElementById( 'ssai-root' );
		$out  = '';
		$loose = '';
		$flush = function () use ( &$loose, &$out ) {
			if ( '' !== trim( wp_strip_all_tags( $loose ) ) ) {
				$out .= "<!-- wp:paragraph -->\n<p>" . trim( $loose ) . "</p>\n<!-- /wp:paragraph -->\n\n";
			}
			$loose = '';
		};
		foreach ( $root ? iterator_to_array( $root->childNodes ) : array() as $n ) {
			$x = $doc->saveHTML( $n );
			if ( XML_ELEMENT_NODE !== $n->nodeType || in_array( strtolower( $n->nodeName ), array( 'strong', 'b', 'em', 'i', 'mark', 'span', 'br', 'a' ), true ) ) {
				$loose .= $x;
				continue;
			}
			$flush();
			$tag = strtolower( $n->nodeName );
			if ( 'p' === $tag ) {
				$out .= "<!-- wp:paragraph -->\n" . $x . "\n<!-- /wp:paragraph -->\n\n";
			} elseif ( in_array( $tag, array( 'h2', 'h3', 'h4' ), true ) ) {
				$l    = (int) substr( $tag, 1 );
				$out .= '<!-- wp:heading' . ( 2 !== $l ? ' {"level":' . $l . '}' : '' ) . " -->\n<$tag class=\"wp-block-heading\">" . self::inner( $doc, $n ) . "</$tag>\n<!-- /wp:heading -->\n\n";
			} elseif ( 'ul' === $tag || 'ol' === $tag ) {
				$items = '';
				foreach ( $n->childNodes as $li ) {
					if ( XML_ELEMENT_NODE === $li->nodeType && 'li' === strtolower( $li->nodeName ) ) {
						$items .= "<!-- wp:list-item -->\n<li>" . self::inner( $doc, $li ) . "</li>\n<!-- /wp:list-item -->\n";
					}
				}
				$out .= '<!-- wp:list' . ( 'ol' === $tag ? ' {"ordered":true}' : '' ) . " -->\n<$tag class=\"wp-block-list\">\n" . $items . "</$tag>\n<!-- /wp:list -->\n\n";
			} elseif ( 'table' === $tag ) {
				$out .= "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $x . "</figure>\n<!-- /wp:table -->\n\n";
			} elseif ( 'blockquote' === $tag ) {
				$out .= "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . self::inner( $doc, $n ) . "</blockquote>\n<!-- /wp:quote -->\n\n";
			} else {
				$out .= "<!-- wp:html -->\n" . $x . "\n<!-- /wp:html -->\n\n";
			}
		}
		$flush();
		return $out;
	}

	private static function inner( $doc, $n ) {
		$h = '';
		foreach ( $n->childNodes as $c ) {
			$h .= $doc->saveHTML( $c );
		}
		return trim( $h );
	}

	/* ================================================================ Elementor */

	private static function el_id() {
		return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
	}

	/** Elementor pages and posts that can serve as a design template. */
	public static function templates() {
		if ( ! class_exists( 'SSAI_Elementor' ) || ! SSAI_Elementor::active() ) {
			return array();
		}
		$q = get_posts(
			array(
				'post_type'      => array( 'page', 'post', 'elementor_library' ),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 200,
				'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $q as $p ) {
			$out[ $p->ID ] = get_the_title( $p ) . ' (' . ( 'elementor_library' === $p->post_type ? 'قالب' : get_post_type_object( $p->post_type )->labels->singular_name ) . ')';
		}
		return $out;
	}

	/** Collect heading and text widgets in page order (references into $data); $ctx['parent'] = list holding the last text widget. */
	private static function el_slots( &$elements, &$slots, &$ctx ) {
		foreach ( $elements as $k => &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$t = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
			if ( 'heading' === $t || 'text-editor' === $t ) {
				$slots[]       = array( 'type' => $t, 'el' => &$el );
				$ctx['parent'] = &$elements;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::el_slots( $el['elements'], $slots, $ctx );
			}
		}
		unset( $el );
	}

	/**
	 * Copy the template's design and put the sections into its headings / text widgets in order.
	 * Extra sections are added after the last text widget; unused template texts are marked for review.
	 */
	private static function fill_elementor( $template_id, $m ) {
		$data = SSAI_Elementor::get_data( $template_id );
		if ( ! $data ) {
			return new WP_Error( 'ssai_tpl', 'صفحه نمونه المنتور داده‌ای ندارد.' );
		}
		$slots = array();
		$ctx   = array( 'parent' => null );
		self::el_slots( $data, $slots, $ctx );
		$parent =& $ctx['parent'];
		$pieces = array();
		if ( '' !== $m['title'] ) {
			$pieces[] = array( 'heading', $m['title'], 1 );
		}
		foreach ( $m['outline'] as $i => $o ) {
			if ( (int) $o['level'] > 0 && '' !== trim( $o['heading'] ) ) {
				$pieces[] = array( 'heading', $o['heading'], (int) $o['level'] );
			}
			$body = ( ! empty( $o['image'] ) ? self::image_hint( $o['image'] ) : '' ) . ( isset( $m['sections'][ $i ] ) ? self::clean_html( $m['sections'][ $i ] ) : '' );
			$pieces[] = array( 'text', $body, 0 );
		}
		$used  = 0;
		$si    = 0;
		$proto = array( 'heading' => null, 'text-editor' => null );
		foreach ( $pieces as $p ) {
			$want = 'heading' === $p[0] ? 'heading' : 'text-editor';
			while ( $si < count( $slots ) && $slots[ $si ]['type'] !== $want ) {
				$si++; // Keep the order; a slot of the other kind is left for the next piece.
			}
			if ( $si >= count( $slots ) ) {
				break;
			}
			$el =& $slots[ $si ]['el'];
			if ( 'heading' === $want ) {
				$el['settings']['title'] = esc_html( $p[1] );
				if ( 1 !== $p[2] && isset( $el['settings']['header_size'] ) && 'h1' === $el['settings']['header_size'] ) {
					$el['settings']['header_size'] = 'h2';
				}
				$proto['heading'] = $el;
			} else {
				$el['settings']['editor'] = $p[1];
				$proto['text-editor']     = $el;
			}
			unset( $el );
			$si++;
			$used++;
		}
		// Template texts that were not used.
		for ( $j = $si; $j < count( $slots ); $j++ ) {
			$el =& $slots[ $j ]['el'];
			if ( 'heading' === $slots[ $j ]['type'] ) {
				$el['settings']['title'] = '[نیاز به بررسی: تیتر اضافه قالب — حذف یا تکمیل شود]';
			} else {
				$el['settings']['editor'] = '<p><mark class="ssai-review">[نیاز به بررسی: متن اضافه قالب — حذف یا تکمیل شود]</mark></p>';
			}
			unset( $el );
		}
		// Pieces that did not fit: new widgets after the last text widget.
		$rest = array_slice( $pieces, $used );
		if ( $rest && null !== $parent ) {
			foreach ( $rest as $p ) {
				if ( 'heading' === $p[0] ) {
					$w = $proto['heading'] ? $proto['heading'] : array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'header_size' => 'h2' ), 'elements' => array() );
					$w['settings']['title']       = esc_html( $p[1] );
					$w['settings']['header_size'] = 3 === $p[2] ? 'h3' : 'h2';
				} else {
					$w = $proto['text-editor'] ? $proto['text-editor'] : array( 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array(), 'elements' => array() );
					$w['settings']['editor'] = $p[1];
				}
				$w['id']   = self::el_id();
				$parent[] = $w;
			}
		} elseif ( $rest ) {
			return new WP_Error( 'ssai_tpl', 'صفحه نمونه هیچ ویجت «تیتر» یا «ویرایشگر متن» ندارد؛ صفحه دیگری انتخاب کنید یا خروجی گوتنبرگ بگیرید.' );
		}
		unset( $parent );
		return $data;
	}

	/** Review markers and image hints stay visible to editors in previews and in the block editor. */
	private static function css() {
		return 'mark.ssai-review{background:#fcf0c3;color:#6b4f00;padding:0 3px;border-radius:3px}.ssai-image-hint{border:2px dashed #8c8f94;padding:10px;background:#f6f7f7;color:#50575e}';
	}

	public static function marker_css() {
		if ( is_singular() && current_user_can( 'edit_post', get_queried_object_id() ) && get_post_meta( get_queried_object_id(), self::ITEM_PM, true ) ) {
			echo '<style id="ssai-cs-marks">' . self::css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- static CSS.
		}
	}

	public static function editor_css() {
		wp_register_style( 'ssai-cs-editor', false, array(), SSAI_VERSION );
		wp_enqueue_style( 'ssai-cs-editor' );
		wp_add_inline_style( 'ssai-cs-editor', rtrim( '.editor-styles-wrapper ' . str_replace( '}', '} .editor-styles-wrapper ', self::css() ), ' .editor-styles-wrapper' ) );
	}

	/* ================================================================ Publishing guard */

	/** Drafts that still have review markers or image hints cannot be published or scheduled. */
	public static function guard_publish( $data, $postarr ) {
		if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) || empty( $postarr['ID'] ) ) {
			return $data;
		}
		$id = (int) $postarr['ID'];
		if ( ! get_post_meta( $id, self::ITEM_PM, true ) ) {
			return $data;
		}
		// Elementor saves the status before its new widget data; checking then would see the old text.
		if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'elementor_ajax' === $_REQUEST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $data;
		}
		$text = wp_unslash( $data['post_content'] ) . ( get_post_meta( $id, '_elementor_edit_mode', true ) ? (string) get_post_meta( $id, '_elementor_data', true ) : '' );
		if ( self::has_marks( $text ) ) {
			$data['post_status'] = 'draft';
			set_transient( 'ssai_cs_guard_' . get_current_user_id(), $id, 300 );
		}
		return $data;
	}

	public static function guard_notice() {
		$id = get_transient( 'ssai_cs_guard_' . get_current_user_id() );
		if ( ! $id ) {
			return;
		}
		delete_transient( 'ssai_cs_guard_' . get_current_user_id() );
		echo '<div class="notice notice-warning"><p><strong>Smart SEO AI:</strong> «' . esc_html( get_the_title( $id ) ) . '» منتشر نشد و پیش‌نویس ماند، چون هنوز علامت‌های «نیاز به بررسی» یا کادر «تصویر پیشنهادی» دارد. آن‌ها را تکمیل یا حذف کنید و دوباره منتشر کنید.</p></div>';
	}

	/* ================================================================ AJAX */

	private static function guard( $cap = self::CAP ) {
		check_ajax_referer( 'ssai', 'nonce' );
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
	}

	private static function lock() {
		$k = 'ssai_cs_lock_' . get_current_user_id();
		if ( get_transient( $k ) ) {
			wp_send_json_error( array( 'message' => 'درخواست قبلی هوش مصنوعی هنوز در حال انجام است؛ چند ثانیه صبر کنید.' ), 429 );
		}
		set_transient( $k, 1, 300 );
		add_action(
			'shutdown',
			function () use ( $k ) {
				delete_transient( $k );
			}
		);
		SSAI_AI_Client::$min_timeout = 150;
	}

	private static function req_item() {
		$id = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$m  = $id ? self::item( $id ) : null;
		if ( ! $m ) {
			wp_send_json_error( array( 'message' => 'مورد پیدا نشد.' ), 404 );
		}
		return $m;
	}

	private static function txt( $k, $multi = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification
		$v = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : '';
		return $multi ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
	}

	/** Item for the browser (adds preview HTML and flags). */
	private static function out( $m ) {
		foreach ( $m['outline'] as $i => $o ) {
			if ( ! empty( $o['image']['id'] ) ) {
				$m['outline'][ $i ]['image']['thumb'] = (string) wp_get_attachment_image_url( (int) $o['image']['id'], 'thumbnail' );
			}
		}
		$m['preview']   = $m['outline'] ? self::html( $m ) : '';
		$m['marks']     = substr_count( $m['preview'], 'ssai-review' );
		$m['written']   = count( $m['sections'] );
		$m['edit_url']  = $m['post_id'] ? get_edit_post_link( $m['post_id'], 'raw' ) : '';
		$m['view_url']  = $m['post_id'] ? get_permalink( $m['post_id'] ) : '';
		if ( $m['post_id'] && class_exists( 'SSAI_Elementor' ) && SSAI_Elementor::is_built( $m['post_id'] ) ) {
			$m['edit_url'] = SSAI_Elementor::edit_url( $m['post_id'] );
		}
		return $m;
	}

	public static function ajax_profile_save() {
		self::guard( 'manage_options' );
		$b = array();
		foreach ( array( 'business', 'audience', 'cta_text', 'avoid', 'rules', 'style' ) as $k ) {
			$b[ $k ] = mb_substr( self::txt( $k, true ), 0, 3000 );
		}
		$b['tone']    = in_array( self::txt( 'tone' ), array( 'formal', 'friendly', 'expert', 'simple' ), true ) ? self::txt( 'tone' ) : 'friendly';
		$b['person']  = 'i' === self::txt( 'person' ) ? 'i' : 'we';
		$b['cta_url'] = esc_url_raw( self::txt( 'cta_url' ) );
		update_option( self::BRAND, $b, false );
		wp_send_json_success( array( 'message' => 'پروفایل برند ذخیره شد.' ) );
	}

	/** Draft the brand profile from a few existing posts. */
	public static function ajax_profile_learn() {
		self::guard( 'manage_options' );
		$ids = isset( $_POST['ids'] ) ? array_slice( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) ), 0, 5 ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $ids ) {
			wp_send_json_error( array( 'message' => 'چند نوشته انتخاب کنید.' ) );
		}
		self::lock();
		$samples = array();
		foreach ( $ids as $id ) {
			$d = SSAI_Content::data( $id );
			if ( $d && current_user_can( 'read_post', $id ) ) {
				$samples[] = array( 'title' => $d['title'], 'text' => mb_substr( $d['text'], 0, 2500 ) );
			}
		}
		$sys  = 'You analyse the writing of a business website and describe its brand voice so new articles sound the same. Reply only with JSON. Write the values in ' . self::lang_name( '' ) . '. Do not invent facts about the business beyond what the texts say.';
		$user = 'Return JSON {"business":"one sentence: what the business is and does, only from the texts","audience":"who the texts are written for","tone":"formal|friendly|expert|simple","person":"we|i","style":"5-8 short bullet lines: sentence length, formality, how readers are addressed, typical structure, recurring phrases, things to keep"} for these samples: ' . wp_json_encode( $samples, JSON_UNESCAPED_UNICODE );
		$r    = SSAI_AI_Client::chat_json( $sys, $user );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$out = array();
		foreach ( array( 'business', 'audience', 'style' ) as $k ) {
			$v         = isset( $r[ $k ] ) ? $r[ $k ] : '';
			$out[ $k ] = sanitize_textarea_field( is_array( $v ) ? implode( "\n", array_map( 'strval', $v ) ) : (string) $v );
		}
		$out['tone']   = isset( $r['tone'] ) && in_array( $r['tone'], array( 'formal', 'friendly', 'expert', 'simple' ), true ) ? $r['tone'] : 'friendly';
		$out['person'] = isset( $r['person'] ) && 'i' === $r['person'] ? 'i' : 'we';
		wp_send_json_success( array( 'profile' => $out, 'message' => 'پیش‌نویس پروفایل آماده است؛ بررسی و ذخیره کنید.' ) );
	}

	/** Ideas without AI: Search Console gaps and pages worth refreshing. */
	public static function ajax_ideas() {
		self::guard();
		$src = self::txt( 'source' );
		wp_send_json_success( array( 'ideas' => 'refresh' === $src ? self::refresh_ideas() : self::gsc_ideas() ) );
	}

	/** Normalised titles and focus keywords of published content, to see which searches already have a page. */
	private static function known_topics() {
		$list = get_transient( 'ssai_cs_known' );
		if ( is_array( $list ) ) {
			return $list;
		}
		$list = array();
		foreach ( get_posts( array( 'post_type' => SSAI_Admin::post_types(), 'post_status' => 'publish', 'posts_per_page' => 2000, 'fields' => 'ids' ) ) as $id ) {
			$t = SSAI_Content::norm( get_the_title( $id ) . ' ' . implode( ' ', SSAI_Content::get_focus_keywords( $id ) ) );
			$list[ $id ] = $t;
		}
		set_transient( 'ssai_cs_known', $list, HOUR_IN_SECONDS );
		return $list;
	}

	private static function covered_by( $query, $known ) {
		$q     = SSAI_Content::norm( $query );
		$words = array_filter( preg_split( '/\s+/u', $q ), function ( $w ) {
			return mb_strlen( $w ) > 2;
		} );
		if ( ! $words ) {
			return 0;
		}
		$best = 0;
		$bid  = 0;
		foreach ( $known as $id => $t ) {
			if ( false !== mb_strpos( $t, $q ) ) {
				return $id;
			}
			$hit = 0;
			foreach ( $words as $w ) {
				if ( false !== mb_strpos( $t, $w ) ) {
					$hit++;
				}
			}
			if ( $hit > $best ) {
				$best = $hit;
				$bid  = $id;
			}
		}
		return $best >= max( 2, (int) ceil( count( $words ) * 0.75 ) ) ? $bid : 0;
	}

	public static function gsc_ideas() {
		$rows = array();
		$s    = class_exists( 'SSAI_GSC_Import' ) ? SSAI_GSC_Import::site() : null;
		if ( $s && ! empty( $s['queries'] ) ) {
			foreach ( $s['queries'] as $r ) {
				$rows[ $r['key'] ] = array( 'query' => $r['key'], 'impressions' => (int) $r['impressions'], 'clicks' => (int) $r['clicks'], 'position' => (float) $r['position'] );
			}
		}
		if ( class_exists( 'SSAI_GSC' ) && SSAI_GSC::connected() ) {
			$api = SSAI_GSC::site_queries();
			if ( ! is_wp_error( $api ) ) {
				foreach ( $api as $r ) {
					$rows[ $r['query'] ] = $r;
				}
			}
		}
		if ( ! $rows ) {
			return array();
		}
		$known = self::known_topics();
		$taken = array();
		foreach ( self::items() as $it ) {
			$taken[ SSAI_Content::norm( $it['keyword'] ) ] = true;
		}
		$out = array();
		foreach ( $rows as $r ) {
			if ( $r['impressions'] < 5 || $r['position'] <= 3 ) {
				continue;
			}
			$pid = self::covered_by( $r['query'], $known );
			if ( $pid && $r['position'] <= 8 ) {
				continue; // A page exists and already ranks well.
			}
			$score = $r['impressions'] * ( $pid ? 0.5 : 1 ) * ( $r['position'] > 10 ? 1 : 0.7 );
			$out[] = array(
				'query'       => $r['query'],
				'impressions' => $r['impressions'],
				'clicks'      => $r['clicks'],
				'position'    => round( $r['position'], 1 ),
				'page'        => $pid ? get_the_title( $pid ) : '',
				'page_id'     => $pid,
				'why'         => $pid ? 'صفحه مرتبط دارد ولی جایگاهش ' . number_format_i18n( $r['position'], 1 ) . ' است؛ مقاله اختصاصی یا به‌روزرسانی همان صفحه' : 'هیچ صفحه‌ای مخصوص این جستجو ندارید',
				'score'       => $score,
				'taken'       => isset( $taken[ SSAI_Content::norm( $r['query'] ) ] ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $out, 0, 40 );
	}

	public static function refresh_ideas() {
		$gsc   = class_exists( 'SSAI_GSC_Import' ) ? SSAI_GSC_Import::all_pages() : array();
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 150,
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			if ( (int) get_option( 'page_on_front' ) === $p->ID ) {
				continue;
			}
			$row     = SSAI_Admin::scan_row( $p );
			$reasons = array();
			$score   = 0;
			$age     = ( time() - strtotime( $p->post_modified_gmt . ' UTC' ) ) / DAY_IN_SECONDS;
			if ( $age > 365 ) {
				$reasons[] = sprintf( '%s ماه است به‌روز نشده', number_format_i18n( floor( $age / 30 ) ) );
				$score    += min( 3, $age / 365 );
			}
			if ( isset( $row['words'] ) && $row['words'] < 600 ) {
				$reasons[] = sprintf( 'فقط %s کلمه', number_format_i18n( $row['words'] ) );
				$score    += 2;
			}
			if ( isset( $row['score'] ) && $row['score'] < 60 ) {
				$reasons[] = sprintf( 'امتیاز سئو %s', number_format_i18n( $row['score'] ) );
				$score    += 1;
			}
			$k = SSAI_GSC_Import::url_key( get_permalink( $p ) );
			if ( isset( $gsc[ $k ]['prev_clicks'] ) && $gsc[ $k ]['prev_clicks'] >= 10 && $gsc[ $k ]['clicks'] < $gsc[ $k ]['prev_clicks'] * 0.75 ) {
				$reasons[] = sprintf( 'افت کلیک از %s به %s', number_format_i18n( $gsc[ $k ]['prev_clicks'] ), number_format_i18n( $gsc[ $k ]['clicks'] ) );
				$score    += 3;
			}
			if ( ! $reasons ) {
				continue;
			}
			$out[] = array(
				'id'      => $p->ID,
				'title'   => get_the_title( $p ),
				'type'    => 'page' === $p->post_type ? 'service' : 'article',
				'keyword' => SSAI_Content::get_focus_keyword( $p->ID ),
				'why'     => implode( '، ', $reasons ),
				'score'   => $score,
				'elementor' => class_exists( 'SSAI_Elementor' ) && SSAI_Elementor::is_built( $p->ID ),
			);
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);
		return array_slice( $out, 0, 40 );
	}

	public static function ajax_item_get() {
		self::guard();
		wp_send_json_success( array( 'item' => self::out( self::req_item() ) ) );
	}

	/** Create or update the brief and/or the edited outline. */
	public static function ajax_item_save() {
		self::guard();
		$id = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$m  = $id ? self::item( $id ) : wp_parse_args( array(), self::defaults() );
		if ( ! $m ) {
			wp_send_json_error( array( 'message' => 'مورد پیدا نشد.' ) );
		}
		$types = self::types();
		foreach ( array( 'topic', 'keyword', 'secondary', 'audience', 'notes', 'title', 'tax' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$m[ $k ] = self::txt( $k, 'notes' === $k );
			}
		}
		if ( isset( $_POST['images'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$m['images'] = min( 15, absint( $_POST['images'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		foreach ( array( 'category', 'template', 'target' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$m[ $k ] = absint( $_POST[ $k ] ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		}
		if ( isset( $_POST['type'] ) && isset( $types[ self::txt( 'type' ) ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$m['type'] = self::txt( 'type' );
		}
		if ( isset( $_POST['source'] ) && in_array( self::txt( 'source' ), array( 'manual', 'gsc', 'refresh' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$m['source'] = self::txt( 'source' );
		}
		if ( isset( $_POST['length'] ) && in_array( self::txt( 'length' ), array( 'short', 'medium', 'long' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$m['length'] = self::txt( 'length' );
		}
		if ( isset( $_POST['output'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$m['output'] = 'elementor' === self::txt( 'output' ) ? 'elementor' : 'gutenberg';
		}
		if ( isset( $_POST['post_type'] ) && in_array( self::txt( 'post_type' ), SSAI_Admin::post_types(), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$m['post_type'] = self::txt( 'post_type' );
		}
		if ( isset( $_POST['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$m['lang'] = sanitize_key( self::txt( 'lang' ) );
		}
		if ( isset( $_POST['date'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$d         = self::txt( 'date' );
			$m['date'] = preg_match( '/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $d ) ? $d : '';
		}
		if ( isset( $_POST['outline'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$new = json_decode( wp_unslash( $_POST['outline'] ), true ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $new ) ) {
				$old_sec  = $m['sections'];
				$old_head = array();
				foreach ( $m['outline'] as $i => $o ) {
					$old_head[ $i ] = $o['heading'];
				}
				$m['outline']  = self::clean_outline( $new );
				$m['sections'] = array();
				// Keep written text for sections whose heading did not change (matched by the old index).
				foreach ( $new as $i => $o ) {
					$oi = isset( $o['from'] ) ? (int) $o['from'] : -1;
					if ( $oi >= 0 && isset( $old_sec[ $oi ], $old_head[ $oi ], $m['outline'][ $i ] ) && $old_head[ $oi ] === $m['outline'][ $i ]['heading'] ) {
						$m['sections'][ $i ] = $old_sec[ $oi ];
					}
				}
				if ( 'idea' === $m['status'] && $m['outline'] ) {
					$m['status'] = 'outline';
				}
			}
		}
		if ( isset( $_POST['seo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$seo = json_decode( wp_unslash( $_POST['seo'] ), true ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			if ( is_array( $seo ) ) {
				$m['seo'] = self::clean_seo( $seo );
			}
		}
		if ( '' === trim( $m['topic'] ) && '' === trim( $m['keyword'] ) && ! $m['target'] ) {
			wp_send_json_error( array( 'message' => 'موضوع یا کلمه کلیدی را بنویسید.' ) );
		}
		$m = self::store( $m );
		if ( is_wp_error( $m ) ) {
			wp_send_json_error( array( 'message' => $m->get_error_message() ) );
		}
		wp_send_json_success( array( 'item' => self::out( self::item( $m['id'] ) ), 'message' => 'ذخیره شد.' ) );
	}

	private static function clean_outline( $list ) {
		$out = array();
		foreach ( (array) $list as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$lvl = isset( $o['level'] ) ? (int) $o['level'] : 2;
			$h   = isset( $o['heading'] ) ? sanitize_text_field( (string) $o['heading'] ) : '';
			if ( $lvl > 0 && '' === $h ) {
				continue;
			}
			$img = null;
			if ( ! empty( $o['image'] ) && is_array( $o['image'] ) ) {
				$aid = isset( $o['image']['id'] ) ? absint( $o['image']['id'] ) : 0;
				if ( $aid && ! wp_attachment_is_image( $aid ) ) {
					$aid = 0;
				}
				$desc = isset( $o['image']['desc'] ) ? sanitize_text_field( (string) $o['image']['desc'] ) : '';
				if ( '' !== $desc || $aid ) {
					$img = array(
						'desc' => $desc,
						'alt'  => isset( $o['image']['alt'] ) ? sanitize_text_field( (string) $o['image']['alt'] ) : '',
						'id'   => $aid,
					);
				}
			}
			$out[] = array(
				'level'   => max( 0, min( 3, $lvl ) ),
				'heading' => $lvl > 0 ? $h : 'مقدمه',
				'notes'   => isset( $o['notes'] ) ? sanitize_textarea_field( is_array( $o['notes'] ) ? implode( "\n", array_map( 'strval', $o['notes'] ) ) : (string) $o['notes'] ) : '',
				'image'   => $img,
			);
		}
		return array_slice( $out, 0, 25 );
	}

	private static function clean_seo( $s ) {
		$kw = isset( $s['keywords'] ) ? $s['keywords'] : array();
		$kw = is_array( $kw ) ? $kw : SSAI_Content::split_keywords( (string) $kw );
		return array(
			'seo_title' => isset( $s['seo_title'] ) ? sanitize_text_field( (string) $s['seo_title'] ) : '',
			'meta'      => isset( $s['meta'] ) ? sanitize_textarea_field( (string) $s['meta'] ) : ( isset( $s['meta_description'] ) ? sanitize_textarea_field( (string) $s['meta_description'] ) : '' ),
			'slug'      => isset( $s['slug'] ) ? sanitize_title( (string) $s['slug'] ) : '',
			'keywords'  => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', $kw ) ) ) ), 0, 6 ),
		);
	}

	public static function ajax_item_delete() {
		self::guard();
		$m = self::req_item();
		wp_delete_post( $m['id'], true );
		wp_send_json_success( array( 'message' => 'حذف شد (پیش‌نویس ساخته‌شده، اگر بود، دست نخورد).' ) );
	}

	/** Step 2: outline, title options and SEO fields in one request. */
	public static function ajax_outline() {
		self::guard();
		$m = self::req_item();
		if ( ! in_array( $m['type'], array( 'article', 'service' ), true ) ) {
			wp_send_json_error( array( 'message' => 'برای این نوع محتوا سرفصل لازم نیست.' ) );
		}
		self::lock();
		$brief = self::brief_json( $m );
		$ex    = self::existing( $m );
		$kind  = 'service' === $m['type']
			? 'a service landing page (what the service is, who it is for, benefits, how it works / steps, what affects the cost (no numbers), why choose this business, common questions, call to action)'
			: 'a helpful blog article that fully answers the search intent';
		$user  = 'Plan ' . $kind . ".\n";
		if ( $ex ) {
			$user .= "This is an UPDATE of an existing page: keep what is still good, fix outdated parts, fill gaps, and improve structure. Existing page:\n" . wp_json_encode( $ex, JSON_UNESCAPED_UNICODE ) . "\n";
		}
		$user .= "Brief:\n" . wp_json_encode( $brief, JSON_UNESCAPED_UNICODE ) . "\n";
		$user .= 'Return JSON {"titles":["3 different page titles (H1), each ≤ 65 characters, keyword near the start"],'
			. '"outline":[{"level":0,"heading":"Introduction","notes":"what the intro must say"},{"level":2,"heading":"H2 text","notes":"2-4 points this section must cover","image":{"desc":"what photo/illustration fits here (or omit image)","alt":"alt text"}},{"level":3,"heading":"optional H3 under the previous H2","notes":"..."}],'
			. '"seo":{"seo_title":"≤ 60 characters","meta_description":"120-155 characters with the keyword and a reason to click","slug":"short-slug","keywords":["focus keyword first","2-4 secondary keywords people really search"]}}. '
			. 'First outline item is the introduction (level 0). 5-9 H2 sections, H3 only when useful, a final section with a natural call to action. '
			. ( (int) $m['images'] > 0 ? 'Add "image" to EXACTLY ' . (int) $m['images'] . ' sections (the most visual ones, not the introduction); all other sections have no "image" key.' : 'Do not add "image" to any section.' ) . ' Headings must be specific (not generic like "Introduction" or "Conclusion").';
		$r = SSAI_AI_Client::chat_json( self::system( $m ), $user );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$outline = self::clean_outline( isset( $r['outline'] ) ? $r['outline'] : array() );
		if ( ! $outline ) {
			wp_send_json_error( array( 'message' => 'هوش مصنوعی سرفصل معتبری برنگرداند؛ دوباره امتحان کنید.' ) );
		}
		if ( 0 !== $outline[0]['level'] ) {
			array_unshift( $outline, array( 'level' => 0, 'heading' => 'مقدمه', 'notes' => '', 'image' => null ) );
		}
		// Never more images than the editor asked for (models do not always obey).
		$left = (int) $m['images'];
		foreach ( $outline as $i => $o ) {
			if ( ! empty( $o['image'] ) ) {
				if ( $left > 0 && 0 !== (int) $o['level'] ) {
					$left--;
				} else {
					$outline[ $i ]['image'] = null;
				}
			}
		}
		$titles = array();
		foreach ( isset( $r['titles'] ) && is_array( $r['titles'] ) ? $r['titles'] : array() as $t ) {
			$titles[] = sanitize_text_field( (string) $t );
		}
		$m['titles']   = array_slice( array_filter( $titles ), 0, 4 );
		$m['title']    = '' !== $m['title'] ? $m['title'] : ( $m['titles'] ? $m['titles'][0] : $m['topic'] );
		$m['outline']  = $outline;
		$m['sections'] = array();
		$m['seo']      = self::clean_seo( isset( $r['seo'] ) && is_array( $r['seo'] ) ? $r['seo'] : array() );
		if ( '' !== $m['keyword'] && ( ! $m['seo']['keywords'] || SSAI_Content::norm( $m['seo']['keywords'][0] ) !== SSAI_Content::norm( $m['keyword'] ) ) ) {
			array_unshift( $m['seo']['keywords'], $m['keyword'] );
			$m['seo']['keywords'] = array_slice( array_unique( $m['seo']['keywords'] ), 0, 6 );
		}
		$m['status'] = 'outline';
		$m           = self::store( $m );
		wp_send_json_success( array( 'item' => self::out( self::item( $m['id'] ) ), 'message' => 'سرفصل آماده است؛ ویرایش کنید و بعد «نوشتن متن» را بزنید.' ) );
	}

	/** Step 3: write one section (the browser calls this once per section). */
	public static function ajax_section() {
		self::guard();
		$m   = self::req_item();
		$idx = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : -1; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $m['outline'][ $idx ] ) ) {
			wp_send_json_error( array( 'message' => 'بخش پیدا نشد.' ) );
		}
		self::lock();
		$o     = $m['outline'][ $idx ];
		$count = count( $m['outline'] );
		$heads = array();
		foreach ( $m['outline'] as $i => $x ) {
			$heads[] = ( $i === $idx ? '>> ' : '' ) . ( $x['level'] ? 'H' . $x['level'] . ': ' : '' ) . $x['heading'];
		}
		$prev = '';
		if ( $idx > 0 && isset( $m['sections'][ $idx - 1 ] ) ) {
			$prev = mb_substr( wp_strip_all_tags( $m['sections'][ $idx - 1 ] ), -400 );
		}
		$per  = max( 80, (int) round( self::words_target( $m ) / max( 1, $count ) ) );
		$b    = self::brand();
		$last = $idx === $count - 1;
		$job  = array(
			'page_title'         => $m['title'],
			'focus_keyword'      => $m['keyword'],
			'secondary_keywords' => array_slice( $m['seo']['keywords'], 1 ),
			'full_outline'       => $heads,
			'write_this_section' => array(
				'heading' => 0 === (int) $o['level'] ? '(introduction – no heading)' : $o['heading'],
				'must_cover' => $o['notes'],
			),
			'approx_words'       => 0 === (int) $o['level'] ? min( 160, $per ) : $per,
			'previous_text_end'  => $prev,
			'editor_notes'       => $m['notes'],
		);
		if ( $last && '' !== trim( $b['cta_text'] ) ) {
			$job['end_with_call_to_action'] = $b['cta_text'];
		}
		$ex = self::existing( $m );
		if ( $ex ) {
			$job['existing_page_text_for_reference'] = mb_substr( $ex['text'], 0, 4000 );
		}
		$user = "Write ONLY the body of the marked section (do not repeat its heading, do not write other sections).\n"
			. 'Use <p>, and where it really helps <ul>/<ol>, <strong>, a small <table> or <h3>. Short paragraphs (2-4 sentences). The introduction must hook the reader and use the focus keyword in its first sentence or two. '
			. 'Do NOT add images, figures, image placeholders or notes like "image: …" – images are placed separately. Use [[REVIEW: …]] for any fact you cannot be sure of.' . "\n"
			. 'Return JSON {"html":"..."} for: ' . wp_json_encode( $job, JSON_UNESCAPED_UNICODE );
		$r = SSAI_AI_Client::chat_json( self::system( $m ), $user );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$html = isset( $r['html'] ) && is_string( $r['html'] ) ? self::clean_html( $r['html'] ) : '';
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			wp_send_json_error( array( 'message' => 'متن این بخش خالی برگشت؛ دوباره امتحان کنید.' ) );
		}
		if ( false === stripos( $html, '<p' ) && false === stripos( $html, '<ul' ) && false === stripos( $html, '<ol' ) && false === stripos( $html, '<table' ) ) {
			$html = wpautop( $html );
		}
		$m                    = self::item( $m['id'] ); // Re-read: another section may have been saved meanwhile.
		$m['sections'][ $idx ] = $html;
		ksort( $m['sections'] );
		$m['status'] = 'writing';
		$m           = self::store( $m );
		wp_send_json_success( array( 'html' => $html, 'item' => self::out( self::item( $m['id'] ) ) ) );
	}

	/** Step 4: create the draft (Gutenberg / Elementor) or update the refreshed page. */
	public static function ajax_save() {
		self::guard();
		$m = self::req_item();
		if ( ! in_array( $m['type'], array( 'article', 'service' ), true ) || ! $m['outline'] ) {
			wp_send_json_error( array( 'message' => 'ابتدا سرفصل و متن را بسازید.' ) );
		}
		if ( count( $m['sections'] ) < count( $m['outline'] ) ) {
			wp_send_json_error( array( 'message' => sprintf( 'هنوز %d بخش نوشته نشده است.', count( $m['outline'] ) - count( $m['sections'] ) ) ) );
		}
		// Refresh of an existing (non-Elementor) page: replace its content, with undo in the page history.
		if ( $m['target'] ) {
			if ( ! SSAI_Applier::can( $m['target'] ) ) {
				wp_send_json_error( array( 'message' => 'اجازه تغییر این صفحه را ندارید.' ), 403 );
			}
			if ( class_exists( 'SSAI_Elementor' ) && SSAI_Elementor::is_built( $m['target'] ) ) {
				wp_send_json_error( array( 'message' => 'این صفحه با المنتور ساخته شده؛ برای به‌روزرسانی آن از «گزارش سئو › بازنویسی محتوا» استفاده کنید، یا خروجی را به‌صورت پیش‌نویس جدید بگیرید (منبع را «موضوع خودم» کنید).' ) );
			}
			$r = SSAI_Applier::apply( $m['target'], 'content', self::blocks( $m ) );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			update_post_meta( $m['target'], self::ITEM_PM, $m['id'] );
			$m['post_id'] = $m['target'];
			$m['status']  = 'applied';
			self::store( $m );
			wp_send_json_success( array( 'item' => self::out( self::item( $m['id'] ) ), 'message' => 'محتوای صفحه به‌روز شد (در تاریخچه تغییرات صفحه قابل برگشت است). علامت‌های «نیاز به بررسی» را در ویرایشگر تکمیل کنید.' ) );
		}
		$pt = 'service' === $m['type'] && 'post' === $m['post_type'] ? 'page' : $m['post_type'];
		if ( ! current_user_can( get_post_type_object( $pt )->cap->edit_posts ) ) {
			wp_send_json_error( array( 'message' => 'اجازه ساخت این نوع محتوا را ندارید.' ), 403 );
		}
		$el = 'elementor' === $m['output'];
		if ( $el && ( ! $m['template'] || ! class_exists( 'SSAI_Elementor' ) || ! SSAI_Elementor::is_built( $m['template'] ) ) ) {
			wp_send_json_error( array( 'message' => 'صفحه نمونه المنتور را انتخاب کنید.' ) );
		}
		$arr = array(
			'post_type'    => $pt,
			'post_status'  => 'draft',
			'post_title'   => '' !== $m['title'] ? $m['title'] : $m['topic'],
			'post_content' => $el ? self::html( $m ) : self::blocks( $m ),
			'post_author'  => get_current_user_id(),
		);
		if ( '' !== $m['seo']['slug'] ) {
			$arr['post_name'] = $m['seo']['slug'];
		}
		if ( 'post' === $pt && $m['category'] ) {
			$arr['post_category'] = array( $m['category'] );
		}
		$existing = $m['post_id'] && get_post( $m['post_id'] ) && 'publish' !== get_post_status( $m['post_id'] ) ? $m['post_id'] : 0;
		if ( $existing ) {
			$arr['ID'] = $existing;
			$pid       = wp_update_post( wp_slash( $arr ), true );
		} else {
			$pid = wp_insert_post( wp_slash( $arr ), true );
		}
		if ( is_wp_error( $pid ) ) {
			wp_send_json_error( array( 'message' => $pid->get_error_message() ) );
		}
		update_post_meta( $pid, self::ITEM_PM, $m['id'] );
		if ( $el ) {
			$data = self::fill_elementor( $m['template'], $m );
			if ( is_wp_error( $data ) ) {
				wp_send_json_error( array( 'message' => $data->get_error_message() ) );
			}
			foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_wp_page_template', '_elementor_page_settings' ) as $k ) {
				$v = get_post_meta( $m['template'], $k, true );
				if ( '' !== $v && false !== $v ) {
					update_post_meta( $pid, $k, wp_slash( $v ) );
				}
			}
			if ( 'elementor_library' === get_post_type( $m['template'] ) ) {
				update_post_meta( $pid, '_elementor_template_type', 'page' === $pt ? 'wp-page' : 'wp-post' );
			}
			update_post_meta( $pid, '_elementor_edit_mode', 'builder' );
			SSAI_Elementor::save_data( $pid, $data );
		}
		// Chosen images: fill an empty alt in the library, and use the first one as featured image if none is set.
		foreach ( $m['outline'] as $o ) {
			if ( ! empty( $o['image']['id'] ) ) {
				$aid = (int) $o['image']['id'];
				if ( '' !== $o['image']['alt'] && '' === (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ) && current_user_can( 'edit_post', $aid ) ) {
					update_post_meta( $aid, '_wp_attachment_image_alt', $o['image']['alt'] );
				}
				if ( ! has_post_thumbnail( $pid ) ) {
					set_post_thumbnail( $pid, $aid );
				}
			}
		}
		if ( '' !== $m['seo']['seo_title'] ) {
			SSAI_Content::set_seo_title( $pid, $m['seo']['seo_title'] );
		}
		if ( '' !== $m['seo']['meta'] ) {
			SSAI_Content::set_meta_description( $pid, $m['seo']['meta'] );
		}
		$kws = $m['seo']['keywords'] ? $m['seo']['keywords'] : array_filter( array( $m['keyword'] ) );
		if ( $kws ) {
			SSAI_Content::set_focus_keyword( $pid, implode( ',', $kws ) );
		}
		if ( '' !== $m['lang'] ) {
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $pid, $m['lang'] );
			} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
				do_action( 'wpml_set_element_language_details', array( 'element_id' => $pid, 'element_type' => 'post_' . $pt, 'trid' => false, 'language_code' => $m['lang'] ) );
			}
		}
		$m['post_id'] = $pid;
		$m['status']  = 'draft';
		self::store( $m );
		$item = self::out( self::item( $m['id'] ) );
		wp_send_json_success( array( 'item' => $item, 'message' => 'پیش‌نویس ساخته شد. علامت‌های زرد «نیاز به بررسی» و کادرهای «تصویر پیشنهادی» را تکمیل کنید؛ تا آن‌ها باقی‌اند، انتشار و زمان‌بندی انجام نمی‌شود.' ) );
	}

	/** Approve and schedule (or publish now) a finished draft. */
	public static function ajax_schedule() {
		self::guard( 'publish_posts' );
		$m = self::req_item();
		if ( ! $m['post_id'] || ! get_post( $m['post_id'] ) ) {
			wp_send_json_error( array( 'message' => 'هنوز پیش‌نویسی ساخته نشده است.' ) );
		}
		$pid  = $m['post_id'];
		$post = get_post( $pid );
		if ( ! current_user_can( 'publish_post', $pid ) && ! current_user_can( 'edit_post', $pid ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$text = $post->post_content . ( get_post_meta( $pid, '_elementor_edit_mode', true ) ? (string) get_post_meta( $pid, '_elementor_data', true ) : '' );
		if ( self::has_marks( $text ) ) {
			wp_send_json_error( array( 'message' => 'پیش‌نویس هنوز علامت «نیاز به بررسی» یا کادر «تصویر پیشنهادی» دارد. آن‌ها را در ویرایشگر تکمیل یا حذف کنید و ذخیره کنید، بعد زمان‌بندی کنید.' ) );
		}
		$date = self::txt( 'date' );
		if ( 'now' === $date ) {
			$r = wp_update_post( array( 'ID' => $pid, 'post_status' => 'publish' ), true );
		} else {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $date ) ) {
				wp_send_json_error( array( 'message' => 'تاریخ و ساعت را انتخاب کنید.' ) );
			}
			$gmt = get_gmt_from_date( $date . ':00' );
			if ( strtotime( $gmt . ' UTC' ) <= time() + 60 ) {
				wp_send_json_error( array( 'message' => 'زمان انتشار باید در آینده باشد.' ) );
			}
			$r = wp_update_post(
				array(
					'ID'            => $pid,
					'post_status'   => 'future',
					'post_date'     => $date . ':00',
					'post_date_gmt' => $gmt,
					'edit_date'     => true,
				),
				true
			);
		}
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$st = get_post_status( $pid );
		if ( ! in_array( $st, array( 'future', 'publish' ), true ) ) {
			wp_send_json_error( array( 'message' => 'منتشر نشد؛ پیش‌نویس هنوز علامت بررسی دارد.' ) );
		}
		wp_send_json_success( array( 'item' => self::out( self::item( $m['id'] ) ), 'message' => 'publish' === $st ? 'منتشر شد.' : 'زمان‌بندی شد: ' . $date ) );
	}

	/** Suggest alt text for one section image (uses the section, the keyword and the chosen file). */
	public static function ajax_alt() {
		self::guard();
		$m   = self::req_item();
		$aid = isset( $_POST['att'] ) ? absint( $_POST['att'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$file = array();
		if ( $aid && wp_attachment_is_image( $aid ) ) {
			$a    = get_post( $aid );
			$file = array(
				'file_name' => wp_basename( (string) get_attached_file( $aid ) ),
				'title'     => $a ? $a->post_title : '',
				'caption'   => $a ? $a->post_excerpt : '',
				'current_alt' => (string) get_post_meta( $aid, '_wp_attachment_image_alt', true ),
			);
		}
		self::lock();
		$job  = array(
			'page_title'    => $m['title'],
			'focus_keyword' => $m['keyword'],
			'section'       => self::txt( 'heading' ),
			'what_the_image_shows' => self::txt( 'desc' ),
			'image_file'    => $file,
		);
		$user = 'Write the alt text for an image in this section: describe what the image shows in 6-14 words, specific and natural; include the focus keyword only if it truly fits; no "image of"/"picture of"; never claim things the image may not show. Return JSON {"alt":"..."} for: ' . wp_json_encode( $job, JSON_UNESCAPED_UNICODE );
		$r    = SSAI_AI_Client::chat_json( self::system( $m ), $user );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$alt = isset( $r['alt'] ) ? trim( sanitize_text_field( preg_replace( '/\[\[.*?\]\]/u', '', (string) $r['alt'] ) ) ) : '';
		if ( '' === $alt ) {
			wp_send_json_error( array( 'message' => 'پیشنهادی برنگشت؛ دوباره امتحان کنید.' ) );
		}
		wp_send_json_success( array( 'alt' => mb_substr( $alt, 0, 150 ) ) );
	}

	public static function ajax_calendar_save() {
		self::guard();
		$days = isset( $_POST['days'] ) ? array_values( array_unique( array_filter( array_map( 'intval', explode( ',', sanitize_text_field( wp_unslash( $_POST['days'] ) ) ) ), function ( $d ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $d >= 0 && $d <= 6;
		} ) ) ) : array();
		$time = self::txt( 'time' );
		$c    = array(
			'per_week' => max( 1, min( 14, absint( isset( $_POST['per_week'] ) ? $_POST['per_week'] : 2 ) ) ), // phpcs:ignore WordPress.Security.NonceVerification
			'days'     => $days ? $days : array( 6 ),
			'time'     => preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '10:00',
		);
		update_option( self::CAL, $c, false );
		wp_send_json_success( array( 'message' => 'برنامه انتشار ذخیره شد.' ) );
	}

	/** Give planned dates (next free publishing days) to items that have none. */
	public static function ajax_autofill() {
		self::guard();
		$c     = self::calendar();
		$busy  = array();
		$items = self::items();
		foreach ( $items as $it ) {
			if ( '' !== $it['date'] ) {
				$busy[ substr( $it['date'], 0, 10 ) ] = true;
			}
		}
		$tz   = wp_timezone();
		$day  = new DateTime( 'tomorrow', $tz );
		$week = array();
		$n    = 0;
		foreach ( array_reverse( $items ) as $it ) { // Oldest first.
			if ( '' !== $it['date'] || in_array( $it['status'], array( 'published', 'applied' ), true ) || ! in_array( $it['type'], array( 'article', 'service' ), true ) || $it['target'] ) {
				continue;
			}
			for ( $guard = 0; $guard < 400; $guard++ ) {
				$wk = $day->format( 'o-W' );
				$d  = $day->format( 'Y-m-d' );
				if ( in_array( (int) $day->format( 'w' ), $c['days'], true ) && empty( $busy[ $d ] ) && ( isset( $week[ $wk ] ) ? $week[ $wk ] : 0 ) < $c['per_week'] ) {
					break;
				}
				$day->modify( '+1 day' );
			}
			$it['date']           = $day->format( 'Y-m-d' ) . ' ' . $c['time'];
			$busy[ $day->format( 'Y-m-d' ) ] = true;
			$week[ $day->format( 'o-W' ) ]   = ( isset( $week[ $day->format( 'o-W' ) ] ) ? $week[ $day->format( 'o-W' ) ] : 0 ) + 1;
			self::store( $it );
			$n++;
		}
		wp_send_json_success( array( 'message' => $n ? sprintf( 'برای %d مورد تاریخ برنامه‌ریزی شد (فقط برنامه است؛ انتشار فقط بعد از تأیید شما).', $n ) : 'مورد بدون تاریخی نماند.' ) );
	}

	/* ------------------------------------------------------------ Short pieces: category intros, product descriptions */

	/** Products or categories for the short-content list. */
	public static function ajax_shorts() {
		self::guard();
		$kind = self::txt( 'kind' );
		$out  = array();
		if ( 'product' === $kind && post_type_exists( 'product' ) ) {
			$only = '1' === self::txt( 'thin' );
			foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft' ), 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
				$words = count( SSAI_Content::words( wp_strip_all_tags( $p->post_content ) ) );
				if ( $only && $words >= 150 && '' !== trim( $p->post_excerpt ) ) {
					continue;
				}
				$out[] = array( 'id' => $p->ID, 'title' => get_the_title( $p ), 'info' => sprintf( '%s کلمه%s', number_format_i18n( $words ), '' === trim( $p->post_excerpt ) ? '، بدون توضیح کوتاه' : '' ), 'undo' => (bool) get_post_meta( $p->ID, '_ssai_cs_prev', true ) );
			}
		} else {
			$tax = taxonomy_exists( 'product_cat' ) && 'product_cat' === self::txt( 'tax' ) ? 'product_cat' : 'category';
			foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 300 ) ) as $t ) {
				$words = count( SSAI_Content::words( wp_strip_all_tags( $t->description ) ) );
				$out[] = array( 'id' => $t->term_id, 'title' => $t->name, 'tax' => $tax, 'info' => sprintf( '%s مطلب، توضیح: %s کلمه', number_format_i18n( $t->count ), number_format_i18n( $words ) ), 'undo' => (bool) get_term_meta( $t->term_id, '_ssai_cs_prev', true ) );
			}
		}
		wp_send_json_success( array( 'rows' => $out ) );
	}

	/** Generate a category intro or a product description (preview only). */
	public static function ajax_short() {
		self::guard();
		$kind = self::txt( 'kind' );
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$m    = wp_parse_args( array( 'type' => 'product' === $kind ? 'product' : 'category', 'lang' => self::txt( 'lang' ), 'notes' => self::txt( 'notes', true ) ), self::defaults() );
		self::lock();
		if ( 'product' === $kind ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null;
			if ( ! $p || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( array( 'message' => 'محصول پیدا نشد یا دسترسی ندارید.' ) );
			}
			$attrs = array();
			foreach ( $p->get_attributes() as $a ) {
				$name = wc_attribute_label( $a->get_name() );
				$vals = $a->is_taxonomy() ? wc_get_product_terms( $id, $a->get_name(), array( 'fields' => 'names' ) ) : $a->get_options();
				$attrs[ $name ] = implode( '، ', array_map( 'strval', (array) $vals ) );
			}
			$cats  = wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'names' ) );
			$facts = array(
				'name'               => $p->get_name(),
				'categories'         => is_wp_error( $cats ) ? array() : $cats,
				'attributes'         => $attrs,
				'sku'                => $p->get_sku(),
				'weight'             => $p->get_weight(),
				'dimensions'         => wc_format_dimensions( $p->get_dimensions( false ) ),
				'current_short'      => mb_substr( wp_strip_all_tags( $p->get_short_description() ), 0, 1000 ),
				'current_long'       => mb_substr( wp_strip_all_tags( $p->get_description() ), 0, 3000 ),
				'focus_keyword'      => SSAI_Content::get_focus_keyword( $id ),
				'editor_notes'       => $m['notes'],
			);
			$user = 'Write a WooCommerce product description using ONLY the facts given (attributes, dimensions, current text). Never invent specifications, materials, sizes, warranties, certifications or performance numbers; if a buyer would need a fact that is missing, add [[REVIEW: …]]. '
				. 'Return JSON {"short":"2-3 sentence summary as <p> or a short <ul> of key points","long":"150-350 words: what it is, who it is for, key features and uses, care/usage tips if relevant; <p>, <h3>, <ul> allowed","seo_title":"≤ 60 characters","meta_description":"120-155 characters"} for: ' . wp_json_encode( $facts, JSON_UNESCAPED_UNICODE );
		} else {
			$tax = 'product_cat' === self::txt( 'tax' ) ? 'product_cat' : 'category';
			$t   = get_term( $id, $tax );
			if ( ! $t || is_wp_error( $t ) || ! current_user_can( get_taxonomy( $tax )->cap->edit_terms ) ) {
				wp_send_json_error( array( 'message' => 'دسته پیدا نشد یا دسترسی ندارید.' ) );
			}
			$titles = array();
			$q      = 'product_cat' === $tax ? get_posts( array( 'post_type' => 'product', 'posts_per_page' => 15, 'tax_query' => array( array( 'taxonomy' => $tax, 'terms' => $id ) ) ) ) : get_posts( array( 'category' => $id, 'posts_per_page' => 15 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			foreach ( $q as $p ) {
				$titles[] = get_the_title( $p );
			}
			$facts = array(
				'category'        => $t->name,
				'parent'          => $t->parent ? get_term( $t->parent, $tax )->name : '',
				'items_in_it'     => $titles,
				'current_text'    => mb_substr( wp_strip_all_tags( $t->description ), 0, 2000 ),
				'editor_notes'    => $m['notes'],
				'call_to_action'  => self::brand()['cta_text'],
			);
			$user = 'Write the introduction text shown at the top of a category page (' . ( 'product_cat' === $tax ? 'shop category' : 'blog category / topic hub' ) . '). 120-250 words: what this topic is, what the reader will find here (based on the item titles), how to choose / where to start, and a soft call to action. <p> and one short <ul> allowed. '
				. 'Return JSON {"long":"the html","meta_description":"120-155 characters"} for: ' . wp_json_encode( $facts, JSON_UNESCAPED_UNICODE );
		}
		$r = SSAI_AI_Client::chat_json( self::system( $m ), $user );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$res = array(
			'short' => isset( $r['short'] ) && is_string( $r['short'] ) ? self::clean_html( $r['short'] ) : '',
			'long'  => isset( $r['long'] ) && is_string( $r['long'] ) ? self::clean_html( $r['long'] ) : '',
			'seo_title' => isset( $r['seo_title'] ) ? sanitize_text_field( (string) $r['seo_title'] ) : '',
			'meta'  => isset( $r['meta_description'] ) ? sanitize_textarea_field( (string) $r['meta_description'] ) : '',
		);
		if ( '' === trim( wp_strip_all_tags( $res['long'] ) ) ) {
			wp_send_json_error( array( 'message' => 'متن خالی برگشت؛ دوباره امتحان کنید.' ) );
		}
		set_transient( 'ssai_cs_short_' . get_current_user_id() . '_' . $kind . '_' . $id, $res, DAY_IN_SECONDS );
		$res['marks'] = substr_count( $res['short'] . $res['long'], 'ssai-review' );
		wp_send_json_success( $res );
	}

	/** Apply the stored preview (never text sent by the browser); the old text is kept for undo. */
	public static function ajax_short_apply() {
		self::guard();
		$kind = self::txt( 'kind' );
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$res  = get_transient( 'ssai_cs_short_' . get_current_user_id() . '_' . $kind . '_' . $id );
		if ( ! is_array( $res ) ) {
			wp_send_json_error( array( 'message' => 'پیش‌نمایش منقضی شده؛ دوباره بسازید.' ) );
		}
		$strip = function ( $h ) {
			return trim( preg_replace( '/<mark class="ssai-review">\[نیاز به بررسی: (.*?)\]<\/mark>/u', '[[REVIEW: $1]]', $h ) );
		};
		if ( 'product' === $kind ) {
			if ( ! current_user_can( 'edit_post', $id ) || 'product' !== get_post_type( $id ) ) {
				wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
			}
			$p = get_post( $id );
			update_post_meta( $id, '_ssai_cs_prev', wp_slash( array( 'excerpt' => $p->post_excerpt, 'content' => $p->post_content, 'time' => time() ) ) );
			$arr = array( 'ID' => $id, 'post_content' => $res['long'] );
			if ( '' !== $res['short'] ) {
				$arr['post_excerpt'] = $res['short'];
			}
			$r = wp_update_post( wp_slash( $arr ), true );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			if ( '' !== $res['seo_title'] ) {
				SSAI_Content::set_seo_title( $id, $res['seo_title'] );
			}
			if ( '' !== $res['meta'] ) {
				SSAI_Content::set_meta_description( $id, $res['meta'] );
			}
			SSAI_Applier::purge_cache( $id );
		} else {
			$tax = 'product_cat' === self::txt( 'tax' ) ? 'product_cat' : 'category';
			if ( ! current_user_can( get_taxonomy( $tax )->cap->edit_terms ) ) {
				wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
			}
			$t = get_term( $id, $tax );
			if ( ! $t || is_wp_error( $t ) ) {
				wp_send_json_error( array( 'message' => 'دسته پیدا نشد.' ) );
			}
			update_term_meta( $id, '_ssai_cs_prev', wp_slash( array( 'content' => $t->description, 'time' => time() ) ) );
			$r = wp_update_term( $id, $tax, array( 'description' => $res['long'] ) );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			if ( '' !== $res['meta'] && class_exists( 'SSAI_SEO' ) ) {
				update_term_meta( $id, '_ssai_desc', sanitize_textarea_field( $res['meta'] ) );
			}
		}
		delete_transient( 'ssai_cs_short_' . get_current_user_id() . '_' . $kind . '_' . $id );
		wp_send_json_success( array( 'message' => 'اعمال شد' . ( substr_count( $res['short'] . $res['long'], 'ssai-review' ) ? '؛ علامت‌های زرد «نیاز به بررسی» را در ویرایشگر تکمیل کنید.' : '.' ) ) );
	}

	public static function ajax_short_undo() {
		self::guard();
		$kind = self::txt( 'kind' );
		$id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'product' === $kind ) {
			$prev = get_post_meta( $id, '_ssai_cs_prev', true );
			if ( ! is_array( $prev ) || ! current_user_can( 'edit_post', $id ) ) {
				wp_send_json_error( array( 'message' => 'نسخه قبلی پیدا نشد.' ) );
			}
			wp_update_post( wp_slash( array( 'ID' => $id, 'post_excerpt' => $prev['excerpt'], 'post_content' => $prev['content'] ) ) );
			delete_post_meta( $id, '_ssai_cs_prev' );
			SSAI_Applier::purge_cache( $id );
		} else {
			$tax  = 'product_cat' === self::txt( 'tax' ) ? 'product_cat' : 'category';
			$prev = get_term_meta( $id, '_ssai_cs_prev', true );
			if ( ! is_array( $prev ) || ! current_user_can( get_taxonomy( $tax )->cap->edit_terms ) ) {
				wp_send_json_error( array( 'message' => 'نسخه قبلی پیدا نشد.' ) );
			}
			wp_update_term( $id, $tax, array( 'description' => $prev['content'] ) );
			delete_term_meta( $id, '_ssai_cs_prev' );
		}
		wp_send_json_success( array( 'message' => 'متن قبلی برگردانده شد.' ) );
	}

	/* ================================================================ Screen */

	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'ideas'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'ideas'    => 'ایده‌ها',
			'write'    => 'تولید',
			'short'    => 'دسته‌ها و محصولات',
			'calendar' => 'تقویم',
			'brand'    => 'پروفایل برند',
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'ideas';
		wp_enqueue_script( 'ssai-studio', SSAI_URL . 'assets/studio.js', array( 'ssai-admin' ), SSAI_VERSION, true );
		$cats = array();
		foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) {
			$cats[ $c->term_id ] = $c->name;
		}
		$pts = array();
		foreach ( SSAI_Admin::post_types() as $pt ) {
			if ( 'product' !== $pt ) {
				$pts[ $pt ] = get_post_type_object( $pt )->labels->singular_name;
			}
		}
		wp_localize_script(
			'ssai-studio',
			'SSAI_CS',
			array(
				'types'     => self::types(),
				'statuses'  => self::statuses(),
				'cats'      => $cats,
				'postTypes' => $pts,
				'templates' => self::templates(),
				'langs'     => self::languages(),
				'woo'       => post_type_exists( 'product' ),
				'url'       => self::url(),
				'canPublish' => current_user_can( 'publish_posts' ),
			)
		);
		?>
		<div class="wrap ssai ssai-studio" dir="rtl">
			<h1>تولید محتوا</h1>
			<p class="description">هوش مصنوعی پیش‌نویس می‌سازد و شما تأیید می‌کنید؛ هیچ متنی بدون تأیید شما منتشر نمی‌شود. هر عدد، قیمت یا ادعایی که هوش مصنوعی نمی‌تواند بداند با <mark class="ssai-review">[نیاز به بررسی]</mark> علامت می‌خورد تا خودتان تکمیل کنید.</p>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $k => $l ) : ?>
					<a class="nav-tab <?php echo $k === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( $k ) ); ?>"><?php echo esc_html( $l ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div id="ssai-cs-msg"></div>
			<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
		</div>
		<?php
	}

	private static function tab_ideas() {
		$has_gsc = ( class_exists( 'SSAI_GSC_Import' ) && SSAI_GSC_Import::site() ) || ( class_exists( 'SSAI_GSC' ) && SSAI_GSC::connected() );
		?>
		<section class="ssai-card">
			<h2>موضوع خودم</h2>
			<form id="ssai-cs-new" class="ssai-cs-grid">
				<label>موضوع یا عنوان <input type="text" name="topic" class="regular-text" required placeholder="مثلاً: لمینت دندان چیست"></label>
				<label>کلمه کلیدی اصلی <input type="text" name="keyword" class="regular-text" placeholder="لمینت دندان"></label>
				<label>نوع <select name="type"><option value="article">مقاله وبلاگ</option><option value="service">صفحه خدمات</option></select></label>
				<button class="button button-primary">ساخت و رفتن به مرحله سرفصل</button>
			</form>
		</section>
		<section class="ssai-card">
			<h2>پیشنهاد از سرچ کنسول</h2>
			<?php if ( ! $has_gsc ) : ?>
				<p>داده سرچ کنسول وجود ندارد. <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-gsc' ) ); ?>">فایل خروجی کل سایت را وارد کنید یا API را وصل کنید</a>.</p>
			<?php else : ?>
				<p class="description">جستجوهایی که سایت شما در گوگل نمایش دارد ولی صفحه‌ای مخصوصشان ندارید یا جایگاهشان پایین است؛ به ترتیب فرصت.</p>
				<div class="ssai-cs-ideas" data-source="gsc"><p>در حال بارگذاری…</p></div>
			<?php endif; ?>
		</section>
		<section class="ssai-card">
			<h2>به‌روزرسانی مقاله‌های قدیمی</h2>
			<p class="description">نوشته‌های قدیمی، کم‌محتوا، با امتیاز پایین یا افت کلیک. به‌روزرسانی با حفظ آدرس انجام می‌شود و قابل برگشت است.</p>
			<div class="ssai-cs-ideas" data-source="refresh"><p>در حال بارگذاری…</p></div>
		</section>
		<?php
	}

	private static function tab_write() {
		$item = isset( $_GET['item'] ) ? absint( $_GET['item'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$st   = self::statuses();
		$ty   = self::types();
		$list = array_filter(
			self::items(),
			function ( $m ) {
				return in_array( $m['type'], array( 'article', 'service' ), true );
			}
		);
		?>
		<div class="ssai-cs-write">
			<aside class="ssai-card ssai-cs-queue">
				<h2>صف محتوا</h2>
				<?php if ( ! $list ) : ?>
					<p class="description">هنوز موردی نیست؛ از تب «ایده‌ها» شروع کنید.</p>
				<?php endif; ?>
				<ul>
				<?php foreach ( $list as $m ) : ?>
					<li class="<?php echo $m['id'] === $item ? 'is-current' : ''; ?>">
						<a href="<?php echo esc_url( self::url( 'write', array( 'item' => $m['id'] ) ) ); ?>"><?php echo esc_html( '' !== $m['title'] ? $m['title'] : $m['topic'] ); ?></a>
						<span class="ssai-cs-badge ssai-cs-st-<?php echo esc_attr( $m['status'] ); ?>"><?php echo esc_html( $st[ $m['status'] ] ); ?></span>
						<span class="description"><?php echo esc_html( $ty[ $m['type'] ] . ( $m['target'] ? ' (به‌روزرسانی)' : '' ) . ( '' !== $m['date'] ? ' — ' . $m['date'] : '' ) ); ?></span>
					</li>
				<?php endforeach; ?>
				</ul>
			</aside>
			<div id="ssai-cs-editor" data-item="<?php echo esc_attr( $item ); ?>">
				<?php if ( ! $item ) : ?>
					<div class="ssai-card"><p>یک مورد را از صف انتخاب کنید یا از تب «ایده‌ها» مورد جدید بسازید.</p></div>
				<?php else : ?>
					<div class="ssai-card"><p>در حال بارگذاری…</p></div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function tab_short() {
		$woo = post_type_exists( 'product' );
		?>
		<section class="ssai-card">
			<h2>متن‌های کوتاه با پیش‌نمایش</h2>
			<p class="description">برای این‌ها سرفصل لازم نیست: هر ردیف را تیک بزنید، «ساخت پیش‌نمایش» را بزنید، متن‌ها را ببینید و فقط موارد دلخواه را اعمال کنید. متن قبلی نگه داشته می‌شود و با «برگرداندن» برمی‌گردد. اطلاعات محصول فقط از ویژگی‌های ثبت‌شده آن نوشته می‌شود؛ مشخصات ناموجود علامت «نیاز به بررسی» می‌گیرد.</p>
			<p>
				<select id="ssai-cs-kind">
					<?php if ( $woo ) : ?><option value="product">توضیحات محصولات</option><option value="product_cat">متن معرفی دسته‌های محصول</option><?php endif; ?>
					<option value="category">متن معرفی دسته‌های وبلاگ</option>
				</select>
				<?php if ( $woo ) : ?><label><input type="checkbox" id="ssai-cs-thin" checked> فقط محصولات کم‌توضیح</label><?php endif; ?>
				<input type="text" id="ssai-cs-short-notes" class="regular-text" placeholder="یادداشت برای همه (اختیاری)، مثلاً: روی کیفیت مواد تأکید شود">
			</p>
			<div id="ssai-cs-shorts"><p>در حال بارگذاری…</p></div>
		</section>
		<?php
	}

	private static function tab_calendar() {
		$c     = self::calendar();
		$days  = array( 6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه' );
		$items = array_filter(
			self::items(),
			function ( $m ) {
				return in_array( $m['type'], array( 'article', 'service' ), true );
			}
		);
		$st    = self::statuses();
		$month = isset( $_GET['m'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['m'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['m'] ) ) : wp_date( 'Y-m' ); // phpcs:ignore WordPress.Security.NonceVerification
		$first = new DateTime( $month . '-01', wp_timezone() );
		$by    = array();
		foreach ( $items as $m ) {
			if ( '' !== $m['date'] ) {
				$by[ substr( $m['date'], 0, 10 ) ][] = $m;
			}
		}
		?>
		<section class="ssai-card">
			<h2>برنامه انتشار</h2>
			<form id="ssai-cs-cal" class="ssai-cs-grid">
				<label>تعداد در هفته <input type="number" name="per_week" min="1" max="14" value="<?php echo esc_attr( $c['per_week'] ); ?>"></label>
				<fieldset><legend>روزها</legend>
					<?php foreach ( $days as $d => $l ) : ?>
						<label><input type="checkbox" name="days" value="<?php echo esc_attr( $d ); ?>" <?php checked( in_array( $d, $c['days'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<label>ساعت <input type="time" name="time" value="<?php echo esc_attr( $c['time'] ); ?>"></label>
				<button class="button">ذخیره برنامه</button>
				<button type="button" class="button button-primary" id="ssai-cs-autofill">تاریخ‌دادن خودکار به موارد بی‌تاریخ</button>
			</form>
			<p class="description">تاریخ‌ها فقط «برنامه» هستند. مطلب فقط وقتی منتشر می‌شود که پیش‌نویس آن کامل باشد و شما در صفحه تولید «تأیید و زمان‌بندی» را بزنید.</p>
		</section>
		<section class="ssai-card">
			<h2>
				<a class="button button-small" href="<?php echo esc_url( self::url( 'calendar', array( 'm' => ( clone $first )->modify( '-1 month' )->format( 'Y-m' ) ) ) ); ?>">‹ ماه قبل</a>
				<?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?>
				<a class="button button-small" href="<?php echo esc_url( self::url( 'calendar', array( 'm' => ( clone $first )->modify( '+1 month' )->format( 'Y-m' ) ) ) ); ?>">ماه بعد ›</a>
			</h2>
			<div class="ssai-cs-month">
				<?php foreach ( array( 'شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه' ) as $h ) : ?>
					<div class="ssai-cs-dh"><?php echo esc_html( $h ); ?></div>
				<?php endforeach; ?>
				<?php
				$lead = ( (int) $first->format( 'w' ) + 1 ) % 7; // Week starts on Saturday.
				for ( $i = 0; $i < $lead; $i++ ) {
					echo '<div class="ssai-cs-day is-empty"></div>';
				}
				$days_in = (int) $first->format( 't' );
				for ( $d = 1; $d <= $days_in; $d++ ) {
					$key = $first->format( 'Y-m-' ) . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
					echo '<div class="ssai-cs-day' . ( wp_date( 'Y-m-d' ) === $key ? ' is-today' : '' ) . '"><span class="ssai-cs-dn">' . esc_html( number_format_i18n( $d ) ) . '</span>';
					foreach ( isset( $by[ $key ] ) ? $by[ $key ] : array() as $m ) {
						echo '<a class="ssai-cs-ev ssai-cs-st-' . esc_attr( $m['status'] ) . '" href="' . esc_url( self::url( 'write', array( 'item' => $m['id'] ) ) ) . '" title="' . esc_attr( $st[ $m['status'] ] ) . '">' . esc_html( mb_substr( '' !== $m['title'] ? $m['title'] : $m['topic'], 0, 40 ) ) . '</a>';
					}
					echo '</div>';
				}
				?>
			</div>
			<p class="ssai-cs-legend">
				<?php foreach ( $st as $k => $l ) : ?>
					<span class="ssai-cs-badge ssai-cs-st-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></span>
				<?php endforeach; ?>
			</p>
		</section>
		<?php
	}

	private static function tab_brand() {
		$b = self::brand();
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 60 ) );
		?>
		<section class="ssai-card">
			<h2>یادگیری از مقاله‌های من</h2>
			<p class="description">۳ تا ۵ نوشته را که لحنشان را دوست دارید انتخاب کنید؛ هوش مصنوعی فرم پایین را پر می‌کند و شما ویرایش و ذخیره می‌کنید.</p>
			<select id="ssai-cs-learn-ids" multiple size="8" style="min-width:420px">
				<?php foreach ( $posts as $p ) : ?>
					<option value="<?php echo esc_attr( $p->ID ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<p><button type="button" class="button" id="ssai-cs-learn">✦ تحلیل لحن با هوش مصنوعی</button> <span class="description">(با Ctrl چند مورد انتخاب کنید)</span></p>
		</section>
		<section class="ssai-card">
			<h2>پروفایل برند</h2>
			<form id="ssai-cs-brand" class="ssai-cs-form">
				<label>کسب‌وکار (چه کسی هستیم، چه کاری می‌کنیم) <textarea name="business" rows="2"><?php echo esc_textarea( $b['business'] ); ?></textarea></label>
				<label>مخاطب <textarea name="audience" rows="2"><?php echo esc_textarea( $b['audience'] ); ?></textarea></label>
				<label>لحن
					<select name="tone">
						<?php foreach ( array( 'friendly' => 'صمیمی و حرفه‌ای', 'formal' => 'رسمی', 'expert' => 'تخصصی', 'simple' => 'خیلی ساده' ) as $k => $l ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $b['tone'], $k ); ?>><?php echo esc_html( $l ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>راوی <select name="person"><option value="we" <?php selected( $b['person'], 'we' ); ?>>«ما» (مجموعه)</option><option value="i" <?php selected( $b['person'], 'i' ); ?>>«من» (شخص)</option></select></label>
				<label>دعوت به اقدام <input type="text" name="cta_text" value="<?php echo esc_attr( $b['cta_text'] ); ?>" placeholder="برای مشاوره و رزرو نوبت با ما تماس بگیرید"></label>
				<label>لینک دعوت به اقدام <input type="url" name="cta_url" dir="ltr" value="<?php echo esc_attr( $b['cta_url'] ); ?>" placeholder="https://…/contact/"></label>
				<label>کلمه‌ها و ادعاهای ممنوع <textarea name="avoid" rows="3" placeholder="مثلاً: «بدون درد»، «تضمینی»، «بهترین کلینیک»، نام رقبا"><?php echo esc_textarea( $b['avoid'] ); ?></textarea></label>
				<label>قانون‌های اضافه <textarea name="rules" rows="3" placeholder="مثلاً: همیشه به مشاوره حضوری ارجاع بده؛ از اصطلاحات انگلیسی کم استفاده کن"><?php echo esc_textarea( $b['rules'] ); ?></textarea></label>
				<label>یادداشت‌های سبک نوشتن <textarea name="style" rows="5"><?php echo esc_textarea( $b['style'] ); ?></textarea></label>
				<p><button class="button button-primary" <?php disabled( ! current_user_can( 'manage_options' ) ); ?>>ذخیره پروفایل</button></p>
			</form>
		</section>
		<?php
	}
}
