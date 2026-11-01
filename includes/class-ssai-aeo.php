<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AEO / GEO / AIO / SXO checks (third tab of the "Site overview" page).
 *
 * AEO  – content that answers questions directly (question headings, short answers, FAQ, lists).
 * GEO  – being usable as a source by AI engines (bot access, llms.txt, schema, trust signals, facts & citations).
 * AIO  – Google AI Overviews / AI Mode: uses normal Googlebot + the same signals.
 * SXO  – search experience: CTR vs. position, near-top-10 pages, speed, question searches.
 */
class SSAI_AEO {

	const OPT      = 'ssai_aeo_audit';
	const OPT_LLMS = 'ssai_llms_txt';
	const META_FAQ = '_ssai_faq';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve_llms' ), 0 );
		add_shortcode( 'ssai_faq', array( __CLASS__, 'faq_shortcode' ) );
		add_action( 'wp_ajax_ssai_aeo_faq', array( __CLASS__, 'ajax_faq' ) );
		add_action( 'wp_ajax_ssai_aeo_faq_save', array( __CLASS__, 'ajax_faq_save' ) );
		add_action( 'admin_post_ssai_aeo_action', array( __CLASS__, 'handle_action' ) );
	}

	/* ================================================================ AI crawlers */

	/** token => [ label, what it is used for, type: search|training|core ] */
	public static function bots() {
		return array(
			'Googlebot'          => array( 'Googlebot', 'جستجوی گوگل، AI Overviews و AI Mode', 'core' ),
			'Bingbot'            => array( 'Bingbot', 'Bing و Copilot (جستجوی ChatGPT هم تا حدی از Bing کمک می‌گیرد)', 'core' ),
			'OAI-SearchBot'      => array( 'OAI-SearchBot', 'نمایش و لینک شدن در جستجوی ChatGPT', 'search' ),
			'ChatGPT-User'       => array( 'ChatGPT-User', 'وقتی کاربر از ChatGPT می‌خواهد صفحه شما را باز کند', 'search' ),
			'Claude-SearchBot'   => array( 'Claude-SearchBot', 'نمایش در جستجوی Claude', 'search' ),
			'Claude-User'        => array( 'Claude-User', 'وقتی کاربر از Claude می‌خواهد صفحه شما را باز کند', 'search' ),
			'PerplexityBot'      => array( 'PerplexityBot', 'نمایش و لینک شدن در Perplexity', 'search' ),
			'Perplexity-User'    => array( 'Perplexity-User', 'باز کردن صفحه به درخواست کاربر Perplexity', 'search' ),
			'GPTBot'             => array( 'GPTBot', 'آموزش مدل‌های OpenAI', 'training' ),
			'ClaudeBot'          => array( 'ClaudeBot', 'آموزش مدل‌های Anthropic', 'training' ),
			'Google-Extended'    => array( 'Google-Extended', 'آموزش Gemini (روی رتبه و AI Overviews اثری ندارد)', 'training' ),
			'Applebot-Extended'  => array( 'Applebot-Extended', 'آموزش Apple Intelligence', 'training' ),
			'CCBot'              => array( 'CCBot', 'Common Crawl – داده عمومی که بسیاری از مدل‌ها و Open PageRank از آن استفاده می‌کنند', 'training' ),
			'meta-externalagent' => array( 'meta-externalagent', 'آموزش مدل‌های Meta', 'training' ),
		);
	}

	/** Parse robots.txt into groups: [ [ agents[], rules[ [allow(bool), path] ] ] ]. */
	public static function robots_groups( $body ) {
		$groups = array();
		$cur    = null;
		$last   = '';
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$k             = strtolower( $k );
			if ( 'user-agent' === $k ) {
				if ( 'user-agent' !== $last || null === $cur ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$cur      = count( $groups ) - 1;
				}
				$groups[ $cur ]['agents'][] = strtolower( $v );
			} elseif ( ( 'allow' === $k || 'disallow' === $k ) && null !== $cur ) {
				if ( '' !== $v ) {
					$groups[ $cur ]['rules'][] = array( 'allow' === $k, $v );
				}
			}
			$last = $k;
		}
		return $groups;
	}

	/** RFC 9309: most specific group, longest matching rule, allow wins a tie. */
	public static function robots_allowed( $groups, $token, $path = '/' ) {
		$token = strtolower( $token );
		$rules = null;
		foreach ( $groups as $g ) {
			if ( in_array( $token, $g['agents'], true ) ) {
				$rules = array_merge( (array) $rules, $g['rules'] );
			}
		}
		if ( null === $rules ) {
			foreach ( $groups as $g ) {
				if ( in_array( '*', $g['agents'], true ) ) {
					$rules = array_merge( (array) $rules, $g['rules'] );
				}
			}
		}
		if ( ! $rules ) {
			return array( true, null === $rules ? 'none' : 'star' );
		}
		$best = -1;
		$ok   = true;
		foreach ( $rules as $r ) {
			$re = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $r[1], '#' ) ) . '#';
			if ( preg_match( $re, $path ) ) {
				$len = strlen( $r[1] );
				if ( $len > $best || ( $len === $best && $r[0] ) ) {
					$best = $len;
					$ok   = $r[0];
				}
			}
		}
		$specific = false;
		foreach ( $groups as $g ) {
			if ( in_array( $token, $g['agents'], true ) ) {
				$specific = true;
			}
		}
		return array( $ok, $specific ? 'own' : 'star' );
	}

	private static function get( $url, $ua = '', $max = 600000 ) {
		$args = array(
			'timeout'             => 10,
			'redirection'         => 3,
			'sslverify'           => false,
			'limit_response_size' => $max,
		);
		if ( $ua ) {
			$args['user-agent'] = $ua;
		} else {
			$args['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
		}
		$r = wp_remote_get( $url, $args );
		if ( is_wp_error( $r ) ) {
			return array( 0, '' );
		}
		return array( (int) wp_remote_retrieve_response_code( $r ), (string) wp_remote_retrieve_body( $r ) );
	}

	/* ================================================================ Schema */

	/** JSON-LD types and a few properties that matter for AI engines. */
	public static function schema( $html ) {
		$out = array(
			'types'    => array(),
			'org'      => false,
			'address'  => false,
			'phone'    => false,
			'sameas'   => false,
			'article'  => false,
			'author'   => false,
			'modified' => false,
			'faq'      => false,
			'crumbs'   => false,
		);
		if ( ! preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			return $out;
		}
		$walk = function ( $node ) use ( &$walk, &$out ) {
			if ( ! is_array( $node ) ) {
				return;
			}
			if ( isset( $node['@type'] ) ) {
				foreach ( (array) $node['@type'] as $t ) {
					$t                  = (string) $t;
					$out['types'][ $t ] = true;
					if ( preg_match( '/Organization|LocalBusiness|Dentist|Medical|Clinic|Physician|Store|Corporation|Hospital|Dentistry/i', $t ) ) {
						$out['org'] = true;
						if ( ! empty( $node['address'] ) ) {
							$out['address'] = true;
						}
						if ( ! empty( $node['telephone'] ) ) {
							$out['phone'] = true;
						}
						if ( ! empty( $node['sameAs'] ) ) {
							$out['sameas'] = true;
						}
					}
					if ( preg_match( '/Article|BlogPosting|NewsArticle|MedicalWebPage/i', $t ) ) {
						$out['article'] = true;
						if ( ! empty( $node['author'] ) ) {
							$out['author'] = true;
						}
						if ( ! empty( $node['dateModified'] ) ) {
							$out['modified'] = true;
						}
					}
					if ( 'FAQPage' === $t ) {
						$out['faq'] = true;
					}
					if ( 'BreadcrumbList' === $t ) {
						$out['crumbs'] = true;
					}
				}
			}
			foreach ( $node as $v ) {
				if ( is_array( $v ) ) {
					$walk( $v );
				}
			}
		};
		foreach ( $m[1] as $json ) {
			$walk( json_decode( trim( html_entity_decode( $json ) ), true ) );
		}
		$out['types'] = array_keys( $out['types'] );
		return $out;
	}

	/* ================================================================ Content (AEO) */

	public static function is_question( $t ) {
		$t = trim( wp_strip_all_tags( (string) $t ) );
		return (bool) preg_match( '/[؟?]\s*$/u', $t )
			|| (bool) preg_match( '/^(چرا|چطور|چطوری|چگونه|چه|چی|چیست|آیا|کدام|چقدر|چند|کی|کجا|what|how|why|when|which|who|where|can|is|are|does|do|should)\b/iu', $t )
			|| (bool) preg_match( '/(چیست|چیه|چگونه است|چقدر است|چه کسانی|چه زمانی|کدام است)\s*[؟?]?$/u', $t );
	}

	/** Search queries that show a question/information intent. */
	public static function is_question_query( $q ) {
		return self::is_question( $q )
			|| (bool) preg_match( '/(^|\s)(چطور|چگونه|چرا|چیست|چیه|آیا|کدام|چقدر|چند|هزینه|قیمت|مقایسه|تفاوت|فرق|بهترین|عوارض|مزایا|معایب|مدت|مراقبت|بعد از|قبل از|درد|علت|علائم|روش)/u', $q )
			|| (bool) preg_match( '/\b(vs|how|what|why|cost|price|best|benefits|side effects)\b/i', $q );
	}

	/**
	 * @return array { score 0-100, miss[], q, answered, faq }
	 */
	public static function page_signals( $post_id, $d ) {
		$html  = $d['html'];
		$parts = preg_split( '/<h([2-4])\b[^>]*>(.*?)<\/h\1>/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$qh    = 0;
		$ans   = 0;
		$faq   = false;
		// $parts: [ before, level, heading, section, level, heading, section… ].
		for ( $i = 2; $i < count( $parts ); $i += 3 ) {
			$h = trim( wp_strip_all_tags( $parts[ $i ] ) );
			if ( preg_match( '/سوالات متداول|سؤالات متداول|پرسش(‌|\s)?های(\s)?(متداول|رایج)|سوالات رایج|پرسش و پاسخ|FAQ/iu', $h ) ) {
				$faq = true;
			}
			if ( self::is_question( $h ) ) {
				$qh++;
				$sec = isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '';
				$p   = preg_match( '/<p\b[^>]*>(.*?)<\/p>/is', $sec, $pm ) ? $pm[1] : wp_strip_all_tags( $sec );
				$n   = SSAI_Content::words( SSAI_Content::html_to_text( $p ) );
				if ( $n >= 12 && $n <= 90 ) {
					$ans++;
				}
			}
		}
		if ( ! $faq && ( preg_match( '/rank-math-faq|schema-faq|wp-block-yoast-faq|ssai-faq|e-n-accordion|elementor-accordion|elementor-toggle/i', $html ) || has_shortcode( (string) get_post_field( 'post_content', $post_id ), 'ssai_faq' ) || get_post_meta( $post_id, self::META_FAQ, true ) ) ) {
			$faq = true;
		}
		$text     = SSAI_Content::html_to_text( $html );
		$intro    = isset( $d['paragraphs'][0] ) ? SSAI_Content::words( $d['paragraphs'][0] ) : 0;
		$lists    = (bool) preg_match( '/<(ul|ol|table)\b/i', $html );
		$numbers  = preg_match_all( '/[0-9۰-۹]+([.,٫][0-9۰-۹]+)?\s*(٪|%|درصد|سال|ماه|روز|ساعت|دقیقه|تومان|ریال|عدد|نفر|mm|cm|kg|ml)/u', $text );
		$cite     = $d['external_links'] > 0;
		$modified = strtotime( get_post_field( 'post_modified_gmt', $post_id ) . ' UTC' );
		$fresh    = $modified && $modified > time() - 365 * DAY_IN_SECONDS;
		$deep     = $d['words'] >= 500;

		$checks = array(
			array( $qh >= 2, 15, 'تیترهای سؤالی (مثل «ایمپلنت چقدر طول می‌کشد؟»)' ),
			array( $ans >= 1, 15, 'پاسخ کوتاه و مستقیم (۱۵ تا ۸۰ کلمه) بلافاصله زیر تیتر سؤالی' ),
			array( $faq, 15, 'بخش پرسش‌های متداول' ),
			array( $intro >= 20 && $intro <= 90, 10, 'پاراگراف اول خلاصه و پاسخ اصلی صفحه' ),
			array( $lists, 10, 'فهرست یا جدول (موتورهای پاسخ راحت‌تر نقلش می‌کنند)' ),
			array( $numbers >= 2, 10, 'عدد و داده مشخص (مدت، درصد، تعداد…)' ),
			array( $cite, 10, 'لینک به منبع معتبر بیرونی' ),
			array( $fresh, 10, 'به‌روزرسانی در ۱۲ ماه اخیر' ),
			array( $deep, 5, 'محتوای کافی (حداقل ۵۰۰ کلمه)' ),
		);
		$score = 0;
		$miss  = array();
		foreach ( $checks as $c ) {
			if ( $c[0] ) {
				$score += $c[1];
			} else {
				$miss[] = $c[2];
			}
		}
		return array(
			'score'    => $score,
			'miss'     => $miss,
			'q'        => $qh,
			'answered' => $ans,
			'faq'      => $faq,
		);
	}

	/* ================================================================ Audit */

	public static function cached() {
		$c = get_option( self::OPT );
		return is_array( $c ) ? $c : null;
	}

	/** Rough average CTR by position (organic, desktop+mobile). Only used to spot outliers. */
	private static function expected_ctr( $pos ) {
		$t = array( 1 => 0.27, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.055, 6 => 0.045, 7 => 0.035, 8 => 0.03, 9 => 0.025, 10 => 0.02 );
		$p = max( 1, (int) round( $pos ) );
		return isset( $t[ $p ] ) ? $t[ $p ] : 0.01;
	}

	public static function audit() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore
		}
		$ids  = get_posts(
			array(
				'post_type'      => SSAI_Admin::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1000,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$home = (int) get_option( 'page_on_front' );
		$out  = array(
			'time'  => time(),
			'pages' => array(),
		);

		// 1. Content signals per page.
		$titles = array();
		foreach ( $ids as $id ) {
			$d = SSAI_Content::data( $id );
			if ( ! $d ) {
				continue;
			}
			$s                   = self::page_signals( $id, $d );
			$out['pages'][ $id ] = $s;
			$titles[ $id ]       = strtolower( get_post_field( 'post_name', $id ) . ' ' . get_the_title( $id ) );
		}

		// 2. Robots.txt + firewall test for AI crawlers.
		list( $rc, $rbody ) = self::get( home_url( '/robots.txt' ) );
		$groups             = 200 === $rc ? self::robots_groups( $rbody ) : array();
		$base               = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		list( $hc )         = self::get( home_url( '/' ), '', 50000 );
		$out['home_code']   = $hc;
		$out['bots']        = array();
		$uas                = array(
			'OAI-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot',
			'GPTBot'        => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.1; +https://openai.com/gptbot',
			'ClaudeBot'     => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
			'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
		);
		foreach ( self::bots() as $token => $b ) {
			list( $ok, $src ) = self::robots_allowed( $groups, $token, $base ? $base : '/' );
			$fw               = null;
			if ( isset( $uas[ $token ] ) ) {
				list( $fw ) = self::get( home_url( '/' ), $uas[ $token ], 50000 );
			}
			$out['bots'][ $token ] = array(
				'ok'  => $ok,
				'src' => $src,
				'fw'  => $fw,
			);
		}
		$out['robots_code'] = $rc;

		// 3. llms.txt.
		list( $lc, $lbody ) = self::get( home_url( '/llms.txt' ), '', 20000 );
		$out['llms']        = 200 === $lc && '' !== trim( $lbody ) && false === stripos( $lbody, '<html' );

		// 4. Schema on the home page and up to 15 important pages (most clicks first).
		$gsc   = SSAI_GSC_Import::site();
		$top   = array();
		$sxo   = array(
			'low_ctr'  => array(),
			'striking' => array(),
			'questions' => array(),
		);
		if ( $gsc && ! empty( $gsc['pages'] ) ) {
			$pages = $gsc['pages'];
			uasort(
				$pages,
				function ( $a, $b ) {
					return $b['clicks'] - $a['clicks'];
				}
			);
			foreach ( $pages as $p ) {
				$pid = SSAI_GSC_Import::find_post( $p['key'] );
				if ( $pid && count( $top ) < 15 ) {
					$top[] = $pid;
				}
				$pos = (float) $p['position'];
				if ( $p['impressions'] >= 100 && $pos > 0 && $pos <= 10.5 && $p['ctr'] < 0.5 * self::expected_ctr( $pos ) ) {
					$sxo['low_ctr'][] = array( 'id' => $pid, 'url' => $p['key'], 'imp' => $p['impressions'], 'ctr' => $p['ctr'], 'pos' => $pos, 'exp' => self::expected_ctr( $pos ) );
				}
				if ( $p['impressions'] >= 100 && $pos > 7.5 && $pos <= 20 ) {
					$sxo['striking'][] = array( 'id' => $pid, 'url' => $p['key'], 'imp' => $p['impressions'], 'clicks' => $p['clicks'], 'pos' => $pos );
				}
			}
			$cmp = function ( $a, $b ) {
				return $b['imp'] - $a['imp'];
			};
			usort( $sxo['low_ctr'], $cmp );
			usort( $sxo['striking'], $cmp );
			$sxo['low_ctr']  = array_slice( $sxo['low_ctr'], 0, 20 );
			$sxo['striking'] = array_slice( $sxo['striking'], 0, 20 );
			foreach ( isset( $gsc['queries'] ) ? $gsc['queries'] : array() as $q ) {
				if ( self::is_question_query( $q['key'] ) ) {
					$sxo['questions'][] = array( 'q' => $q['key'], 'imp' => $q['impressions'], 'clicks' => $q['clicks'], 'pos' => (float) $q['position'] );
				}
			}
			usort( $sxo['questions'], $cmp );
			$sxo['questions'] = array_slice( $sxo['questions'], 0, 40 );
			$sxo['gsc_range'] = isset( $gsc['range'] ) ? $gsc['range'] : '';
		}
		$out['sxo'] = $sxo;
		if ( ! $top ) {
			$top = array_slice( $ids, 0, 15 );
		}
		if ( $home ) {
			$top = array_values( array_unique( array_merge( array( $home ), $top ) ) );
		}
		$out['schema']   = array();
		$out['home_tel'] = false;
		$out['viewport'] = null;
		$out['home_url'] = home_url( '/' );
		$home_html       = '';
		foreach ( array_slice( $top, 0, 16 ) as $pid ) {
			list( $c, $html ) = self::get( get_permalink( $pid ) );
			if ( 200 !== $c ) {
				continue;
			}
			$out['schema'][ $pid ] = self::schema( $html );
			if ( $pid === $home ) {
				$home_html = $html;
			}
		}
		if ( ! $home ) {
			list( , $home_html ) = self::get( home_url( '/' ) );
			$out['schema'][0]    = self::schema( $home_html );
		}
		if ( '' !== $home_html ) {
			$out['home_tel'] = (bool) preg_match( '/href=["\']tel:|(\+98|0098|\b0)[\s\-]?[0-9۰-۹]{2,3}[\s\-]?[0-9۰-۹]{3,4}[\s\-]?[0-9۰-۹]{4}/u', $home_html );
			$out['viewport'] = (bool) preg_match( '/<meta[^>]+name=["\']viewport["\']/i', $home_html );
		}

		// 5. Trust pages (E-E-A-T).
		$find = function ( $re ) use ( $titles ) {
			foreach ( $titles as $id => $t ) {
				if ( preg_match( $re, rawurldecode( $t ) ) ) {
					return $id;
				}
			}
			return 0;
		};
		$priv           = (int) get_option( 'wp_page_for_privacy_policy' );
		$out['trust']   = array(
			'about'   => $find( '/about|درباره/iu' ),
			'contact' => $find( '/contact|تماس/iu' ),
			'privacy' => ( $priv && 'publish' === get_post_status( $priv ) ) ? $priv : $find( '/privacy|حریم|قوانین|terms/iu' ),
		);
		$authors        = get_users(
			array(
				'has_published_posts' => array( 'post' ),
				'fields'              => array( 'ID', 'display_name' ),
			)
		);
		$out['authors'] = array();
		foreach ( $authors as $u ) {
			$out['authors'][] = array(
				'name' => $u->display_name,
				'bio'  => '' !== trim( (string) get_user_meta( $u->ID, 'description', true ) ),
				'id'   => (int) $u->ID,
			);
		}
		update_option( self::OPT, $out, false );
		return $out;
	}

	/* ================================================================ llms.txt */

	public static function llms() {
		$l = get_option( self::OPT_LLMS );
		return wp_parse_args(
			is_array( $l ) ? $l : array(),
			array(
				'on'   => false,
				'text' => '',
			)
		);
	}

	/** Draft llms.txt in the official markdown shape: # name, > summary, ## sections with links. */
	public static function llms_draft() {
		$desc  = get_bloginfo( 'description' );
		$home  = (int) get_option( 'page_on_front' );
		$t     = '# ' . get_bloginfo( 'name' ) . "\n\n";
		$hdesc = $home ? SSAI_Content::get_meta_description( $home ) : '';
		$t    .= '> ' . ( $hdesc ? $hdesc : ( $desc ? $desc : get_bloginfo( 'name' ) ) ) . "\n\n";
		$gsc   = SSAI_GSC_Import::all_pages();
		$rank  = array();
		foreach ( SSAI_Admin::post_types() as $pt ) {
			$ids = get_posts(
				array(
					'post_type'      => $pt,
					'post_status'    => 'publish',
					'posts_per_page' => 200,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				if ( SSAI_SEO::post_noindex( $id ) ) {
					continue;
				}
				$k           = SSAI_GSC_Import::url_key( get_permalink( $id ) );
				$rank[ $id ] = isset( $gsc[ $k ] ) ? (int) $gsc[ $k ]['clicks'] : 0;
			}
		}
		arsort( $rank );
		$by_type = array();
		foreach ( array_slice( array_keys( $rank ), 0, 40, true ) as $id ) {
			$by_type[ get_post_type( $id ) ][] = $id;
		}
		foreach ( $by_type as $pt => $list ) {
			$obj = get_post_type_object( $pt );
			$t  .= '## ' . ( $obj ? $obj->labels->name : $pt ) . "\n\n";
			foreach ( $list as $id ) {
				$md = SSAI_Content::get_meta_description( $id );
				$t .= '- [' . wp_strip_all_tags( get_the_title( $id ) ) . '](' . get_permalink( $id ) . ')' . ( $md ? ': ' . $md : '' ) . "\n";
			}
			$t .= "\n";
		}
		return trim( $t ) . "\n";
	}

	public static function serve_llms() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$base = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
		if ( $base . '/llms.txt' !== $path ) {
			return;
		}
		$l = self::llms();
		if ( ! $l['on'] || '' === trim( $l['text'] ) ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $l['text']; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text file written by an admin.
		exit;
	}

	/* ================================================================ FAQ (AI + shortcode) */

	/** HTML allowed inside FAQ answers (Google accepts these in FAQPage answers too). */
	public static function faq_tags() {
		return array(
			'a'      => array( 'href' => true, 'target' => true, 'rel' => true ),
			'b'      => array(),
			'strong' => array(),
			'i'      => array(),
			'em'     => array(),
			'br'     => array(),
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
	}

	public static function get_faq( $post_id ) {
		$f = get_post_meta( $post_id, self::META_FAQ, true );
		return is_array( $f ) ? $f : array();
	}

	public static function faq_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0, 'title' => 'سوالات متداول' ), $atts, 'ssai_faq' );
		$id   = $atts['id'] ? (int) $atts['id'] : get_the_ID();
		$faq  = self::get_faq( $id );
		if ( ! $faq ) {
			return '';
		}
		$h   = '<section class="ssai-faq">' . ( $atts['title'] ? '<h2>' . esc_html( $atts['title'] ) . '</h2>' : '' );
		$ld  = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array(),
		);
		foreach ( $faq as $f ) {
			$h                  .= '<details class="ssai-faq-item"><summary><h3 style="display:inline;font-size:inherit;margin:0">' . esc_html( $f['q'] ) . '</h3></summary><div class="ssai-faq-a">' . wpautop( wp_kses( $f['a'], self::faq_tags() ) ) . '</div></details>';
			$ld['mainEntity'][] = array(
				'@type'          => 'Question',
				'name'           => $f['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_kses( $f['a'], self::faq_tags() ),
				),
			);
		}
		$h .= '</section>';
		$h .= '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>';
		return $h;
	}

	public static function ajax_faq() {
		check_ajax_referer( 'ssai', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'دسترسی ندارید.' ), 403 );
		}
		$d = SSAI_Content::data( $post_id );
		if ( ! $d ) {
			wp_send_json_error( array( 'message' => 'صفحه پیدا نشد.' ) );
		}
		$sq = SSAI_Analyzer::search_queries( $post_id, false );
		$qs = array();
		foreach ( $sq['rows'] as $r ) {
			if ( self::is_question_query( $r['q'] ) ) {
				$qs[] = $r['q'];
			}
		}
		foreach ( $sq['rows'] as $r ) {
			if ( count( $qs ) >= 25 ) {
				break;
			}
			if ( ! in_array( $r['q'], $qs, true ) ) {
				$qs[] = $r['q'];
			}
		}
		$lang   = 'en' === SSAI_Settings::get( 'language' ) ? 'English' : 'Persian (Farsi)';
		$system = 'You are an SEO expert specialised in Answer Engine Optimization (AEO) and Generative Engine Optimization (GEO). '
			. 'Write a FAQ section for the given web page that answer engines (Google AI Overviews, ChatGPT, Perplexity) can quote. '
			. 'Rules: 4 to 6 questions; each question phrased the way real people search (use the real search queries when they fit); '
			. 'each answer 40-80 words, starts with the direct answer in the first sentence, then one or two supporting sentences. '
			. 'Use ONLY facts that appear in the page text. Never invent prices, durations, statistics, guarantees, medical claims or results. '
			. 'If the page does not contain the facts for a question, skip that question. Do not repeat questions already answered as headings on the page. '
			. 'Write in the same language as the page (' . $lang . '). Respond ONLY with JSON: {"faq":[{"q":"","a":""}]}' . "\n\n" . SSAI_Guidelines::prompt();
		$heads  = implode( ' | ', wp_list_pluck( $d['headings'], 'text' ) );
		$text   = mb_substr( SSAI_Content::html_to_text( $d['html'] ), 0, (int) SSAI_Settings::get( 'max_chars' ) );
		$user   = 'Title: ' . get_the_title( $post_id ) . "\nKeywords: " . implode( ', ', $d['keywords'] ) . "\nExisting headings: " . $heads
			. "\nReal Google searches for this page/site: " . ( $qs ? implode( ' | ', $qs ) : '(none)' ) . "\n\nPage text:\n" . $text;
		$r      = SSAI_AI_Client::chat_json( $system, $user );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$items = array();
		foreach ( isset( $r['faq'] ) && is_array( $r['faq'] ) ? $r['faq'] : array() as $f ) {
			if ( ! empty( $f['q'] ) && ! empty( $f['a'] ) ) {
				$items[] = array(
					'q' => sanitize_text_field( (string) $f['q'] ),
					'a' => sanitize_textarea_field( (string) $f['a'] ),
				);
			}
		}
		if ( ! $items ) {
			wp_send_json_error( array( 'message' => 'هوش مصنوعی پرسشی پیشنهاد نداد (احتمالاً متن صفحه اطلاعات کافی ندارد).' ) );
		}
		wp_send_json_success(
			array(
				'items'     => array_slice( $items, 0, 8 ),
				'existing'  => self::get_faq( $post_id ),
				'elementor' => SSAI_Elementor::is_built( $post_id ),
			)
		);
	}

	public static function ajax_faq_save() {
		check_ajax_referer( 'ssai', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! SSAI_Applier::can( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'شما اجازه اعمال تغییرات را ندارید.' ), 403 );
		}
		$raw   = isset( $_POST['items'] ) ? json_decode( wp_unslash( $_POST['items'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised below.
		$items = array();
		foreach ( is_array( $raw ) ? $raw : array() as $f ) {
			$q = isset( $f['q'] ) ? sanitize_text_field( (string) $f['q'] ) : '';
			$a = isset( $f['a'] ) ? trim( wp_kses( (string) $f['a'], self::faq_tags() ) ) : '';
			if ( '' !== $q && '' !== $a ) {
				$items[] = array(
					'q' => $q,
					'a' => $a,
				);
			}
		}
		if ( ! $items ) {
			delete_post_meta( $post_id, self::META_FAQ );
			wp_send_json_success( array( 'message' => 'پرسش‌وپاسخ این صفحه حذف شد.' ) );
		}
		update_post_meta( $post_id, self::META_FAQ, wp_slash( $items ) );
		$msg = sprintf( '%d پرسش ذخیره شد.', count( $items ) );
		if ( SSAI_Elementor::is_built( $post_id ) ) {
			$msg .= ' این صفحه با المنتور ساخته شده: در المنتور یک ویجت «کد کوتاه» (Shortcode) هر جا که می‌خواهید بگذارید و [ssai_faq] را در آن بنویسید.';
		} elseif ( ! has_shortcode( (string) get_post_field( 'post_content', $post_id ), 'ssai_faq' ) ) {
			$content = (string) get_post_field( 'post_content', $post_id );
			$block   = false !== strpos( $content, '<!-- wp:' ) ? "\n\n<!-- wp:shortcode -->\n[ssai_faq]\n<!-- /wp:shortcode -->" : "\n\n[ssai_faq]";
			$r       = SSAI_Applier::apply( $post_id, 'content', $content . $block );
			if ( is_wp_error( $r ) ) {
				wp_send_json_error( array( 'message' => $r->get_error_message() ) );
			}
			$msg .= ' بخش «سوالات متداول» به انتهای صفحه اضافه شد (از تاریخچه تغییرات قابل بازگشت است).';
		}
		SSAI_Applier::purge_cache( $post_id );
		delete_transient( 'ssai_row_' . $post_id );
		wp_send_json_success( array( 'message' => $msg ) );
	}

	/* ================================================================ Actions */

	public static function handle_action() {
		check_admin_referer( 'ssai_aeo_action' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی ندارید.' );
		}
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$url = SSAI_Site::url( array( 'tab' => 'ai' ) );
		$msg = '';
		if ( 'audit' === $do ) {
			self::audit();
			$msg = 'بررسی AEO / GEO / SXO انجام شد.';
		} elseif ( 'llms_save' === $do ) {
			$text = isset( $_POST['llms_text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['llms_text'] ) ) : '';
			update_option(
				self::OPT_LLMS,
				array(
					'on'   => ! empty( $_POST['llms_on'] ) && '' !== trim( $text ),
					'text' => mb_substr( $text, 0, 50000 ),
				),
				false
			);
			$msg = ! empty( $_POST['llms_on'] ) ? 'llms.txt ذخیره و منتشر شد.' : 'llms.txt ذخیره شد (منتشر نشده).';
		} elseif ( 'llms_draft' === $do ) {
			$l         = self::llms();
			$l['text'] = self::llms_draft();
			update_option( self::OPT_LLMS, $l, false );
			$msg = 'پیش‌نویس llms.txt ساخته شد؛ بازبینی کنید و بعد منتشر کنید.';
		}
		set_transient(
			'ssai_site_notice_' . get_current_user_id(),
			array(
				'msg'  => $msg ? $msg : 'عمل نامعتبر.',
				'type' => $msg ? 'success' : 'error',
			),
			60
		);
		wp_safe_redirect( $url );
		exit;
	}

	/* ================================================================ Render */

	private static function button( $do, $label, $primary = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ssai-inline-form">';
		echo '<input type="hidden" name="action" value="ssai_aeo_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'ssai_aeo_action' );
		echo '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function tile( $label, $value, $sub = '', $cls = '' ) {
		echo '<div class="ssai-tile' . ( $cls ? ' ssai-tile-' . esc_attr( $cls ) : '' ) . '"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( $value ) . '</strong>' . ( $sub ? '<span class="description">' . wp_kses_post( $sub ) . '</span>' : '' ) . '</div>';
	}

	private static function row( $ok, $text, $detail = '' ) {
		$cls = true === $ok ? 'good' : ( null === $ok ? 'warning' : 'error' );
		echo '<li class="ssai-' . esc_attr( $cls ) . '"><span class="ssai-dot"></span><div class="ssai-li-body"><div>' . wp_kses_post( $text ) . '</div>' . ( $detail ? '<div class="ssai-hint">' . wp_kses_post( $detail ) . '</div>' : '' ) . '</div></li>';
	}

	private static function plink( $id, $url = '' ) {
		if ( $id ) {
			return '<a href="' . esc_url( SSAI_Report::url( $id ) ) . '">' . esc_html( get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ) . '</a>';
		}
		return '<span dir="ltr">' . esc_html( rawurldecode( $url ) ) . '</span>';
	}

	private static function lvl( $s ) {
		return $s >= 70 ? 'good' : ( $s >= 40 ? 'warning' : 'error' );
	}

	public static function render() {
		$a     = self::cached();
		$admin = current_user_can( 'manage_options' );
		?>
		<section class="ssai-card">
			<h2>بهینه‌سازی برای موتورهای پاسخ و هوش مصنوعی</h2>
			<ul class="ssai-tips">
				<li><strong>AEO</strong> (Answer Engine Optimization): محتوایی که به سؤال کاربر مستقیم و کوتاه جواب بدهد تا در «پاسخ‌ها»، فیچر اسنیپت و دستیارهای صوتی نقل شود.</li>
				<li><strong>GEO</strong> (Generative Engine Optimization): این‌که ChatGPT، Perplexity، Claude و Gemini بتوانند سایت شما را بخوانند، به آن اعتماد کنند و به آن لینک بدهند.</li>
				<li><strong>AIO</strong> (AI Overviews / AI Mode گوگل): با همان Googlebot و همان سیگنال‌های سئو کار می‌کند؛ ساختار پاسخ‌محور و Schema کمک می‌کنند.</li>
				<li><strong>SXO</strong> (Search Experience Optimization): بعد از دیده شدن، کاربر کلیک کند و راضی بماند – نرخ کلیک، سرعت، و پاسخ به نیت جستجو.</li>
			</ul>
			<p class="description">هیچ ابزار رایگانی نشان نمی‌دهد ChatGPT یا AI Overviews چند بار از سایت شما نقل کرده‌اند؛ گوگل نمایش‌های AI Overviews را جدا از بقیه در سرچ کنسول نمی‌دهد. این بخش عوامل قابل‌اندازه‌گیری را بررسی می‌کند.</p>
			<?php
			if ( $admin ) {
				self::button( 'audit', $a ? 'بررسی دوباره' : 'شروع بررسی AEO / GEO / SXO', true );
			}
			if ( $a ) {
				echo ' <span class="description">آخرین بررسی: ' . esc_html( wp_date( 'Y/m/d H:i', $a['time'] ) ) . '</span>';
			}
			?>
		</section>
		<?php
		if ( ! $a ) {
			return;
		}
		$pages  = $a['pages'];
		$avg    = $pages ? (int) round( array_sum( wp_list_pluck( $pages, 'score' ) ) / count( $pages ) ) : 0;
		$bots   = self::bots();
		$search = 0;
		$sallow = 0;
		foreach ( $a['bots'] as $t => $b ) {
			if ( 'training' !== $bots[ $t ][2] ) {
				$search++;
				if ( $b['ok'] && ( null === $b['fw'] || $b['fw'] < 400 || $a['home_code'] >= 400 ) ) {
					$sallow++;
				}
			}
		}
		$sch      = $a['schema'];
		$home_sch = $sch ? reset( $sch ) : null;
		$faq_n    = count( array_filter( wp_list_pluck( $pages, 'faq' ) ) );
		?>
		<section class="ssai-card">
			<h2>خلاصه</h2>
			<div class="ssai-tiles">
				<?php
				self::tile( 'امتیاز محتوای پاسخ‌محور (AEO)', number_format_i18n( $avg ), 'میانگین ' . number_format_i18n( count( $pages ) ) . ' صفحه', self::lvl( $avg ) );
				self::tile( 'دسترسی ربات‌های جستجو و پاسخ', number_format_i18n( $sallow ) . ' از ' . number_format_i18n( $search ), 'GEO / AIO', $sallow === $search ? 'good' : 'error' );
				self::tile( 'صفحه‌های دارای پرسش‌های متداول', number_format_i18n( $faq_n ), 'از ' . number_format_i18n( count( $pages ) ) . ' صفحه', $faq_n ? '' : 'warning' );
				self::tile( 'Schema سازمان/کسب‌وکار در صفحه اصلی', $home_sch && $home_sch['org'] ? 'دارد' : 'ندارد', $home_sch && $home_sch['org'] ? ( $home_sch['address'] && $home_sch['phone'] ? 'با آدرس و تلفن' : 'آدرس یا تلفن ندارد' ) : 'مهم برای نتایج محلی و هوش مصنوعی', $home_sch && $home_sch['org'] && $home_sch['address'] && $home_sch['phone'] ? 'good' : 'warning' );
				self::tile( 'llms.txt', $a['llms'] ? 'دارد' : 'ندارد', 'اختیاری – پایین صفحه' );
				?>
			</div>
		</section>

		<section class="ssai-card">
			<h2>دسترسی ربات‌های هوش مصنوعی (GEO / AIO)</h2>
			<?php if ( 200 !== (int) $a['robots_code'] ) : ?>
				<p class="description">robots.txt خوانده نشد (کد <?php echo esc_html( $a['robots_code'] ); ?>)؛ یعنی هیچ رباتی از طریق robots.txt منع نشده است.</p>
			<?php endif; ?>
			<div class="ssai-table-wrap"><table class="widefat striped">
				<thead><tr><th>ربات</th><th>کاربرد</th><th>robots.txt</th><th>تست فایروال از سرور</th></tr></thead>
				<tbody>
				<?php
				foreach ( $bots as $t => $b ) {
					$r = $a['bots'][ $t ];
					if ( $r['ok'] ) {
						$rb = '<span class="ssai-pos ssai-risk-good">مجاز</span>' . ( 'own' === $r['src'] ? ' <span class="description">(قانون اختصاصی)</span>' : '' );
					} else {
						$rb = '<span class="ssai-pos ssai-risk-' . ( 'training' === $b[2] ? 'warning' : 'error' ) . '">بسته</span>';
					}
					$fw = '—';
					if ( null !== $r['fw'] ) {
						if ( 0 === $r['fw'] ) {
							$fw = '<span class="description">پاسخی نیامد</span>';
						} elseif ( $r['fw'] >= 400 && $a['home_code'] < 400 ) {
							$fw = '<span class="ssai-pos ssai-risk-error">مسدود (' . (int) $r['fw'] . ')</span>';
						} else {
							$fw = '<span class="ssai-pos ssai-risk-good">باز (' . (int) $r['fw'] . ')</span>';
						}
					}
					$type = 'training' === $b[2] ? ' <span class="description">(آموزش مدل)</span>' : '';
					echo '<tr><td dir="ltr" style="text-align:right">' . esc_html( $b[0] ) . '</td><td>' . esc_html( $b[1] ) . wp_kses_post( $type ) . '</td><td>' . wp_kses_post( $rb ) . '</td><td>' . wp_kses_post( $fw ) . '</td></tr>';
				}
				?>
				</tbody>
			</table></div>
			<p class="description">ربات‌های «جستجو» (OAI-SearchBot، Claude-SearchBot، PerplexityBot و…) باید باز باشند تا سایت در پاسخ‌های هوش مصنوعی نقل و لینک شود. بستن ربات‌های «آموزش مدل» (GPTBot، ClaudeBot، Google-Extended…) انتخاب شخصی است و روی دیده شدن در جستجوی گوگل اثری ندارد. ستون فایروال از سرور خودتان تست می‌شود؛ اگر سایت پشت Cloudflare یا فایروال میزبان است و «مسدود» نشان داد، تنظیم «Block AI bots» را بررسی کنید.</p>
		</section>

		<section class="ssai-card">
			<h2>داده ساختاریافته (Schema)</h2>
			<ul class="ssai-list">
				<?php
				if ( $home_sch ) {
					self::row( $home_sch['org'], $home_sch['org'] ? 'صفحه اصلی Schema سازمان/کسب‌وکار دارد.' : 'صفحه اصلی Schema سازمان یا کسب‌وکار محلی (Organization / LocalBusiness / Dentist…) ندارد.', 'این اطلاعات به گوگل و موتورهای هوش مصنوعی می‌گوید شما چه کسی هستید. در Rank Math: «عنوان‌ها و متا › Local SEO» را فعال و نوع کسب‌وکار، آدرس، تلفن و ساعت کاری را کامل کنید.' );
					if ( $home_sch['org'] ) {
						self::row( $home_sch['address'] && $home_sch['phone'] ? true : null, 'آدرس و تلفن در Schema: ' . ( $home_sch['address'] ? 'آدرس ✓' : 'آدرس ✗' ) . ' — ' . ( $home_sch['phone'] ? 'تلفن ✓' : 'تلفن ✗' ) );
						self::row( $home_sch['sameas'] ? true : null, $home_sch['sameas'] ? 'لینک شبکه‌های اجتماعی و پروفایل‌ها (sameAs) ثبت شده است.' : 'لینک پروفایل‌های رسمی (اینستاگرام، گوگل‌مپ، نشان…) در sameAs نیست.', 'به موتورهای هوش مصنوعی کمک می‌کند بفهمند این پروفایل‌ها مال یک کسب‌وکارند.' );
					}
					self::row( $a['home_tel'] ? true : null, $a['home_tel'] ? 'شماره تماس در صفحه اصلی دیده می‌شود.' : 'شماره تماس در HTML صفحه اصلی پیدا نشد.' );
				}
				$art_need = 0;
				$art_bad  = array();
				$crumbs   = 0;
				foreach ( $sch as $pid => $s ) {
					if ( $s['crumbs'] ) {
						$crumbs++;
					}
					if ( $pid && 'post' === get_post_type( $pid ) ) {
						$art_need++;
						if ( ! $s['article'] || ! $s['author'] || ! $s['modified'] ) {
							$art_bad[] = $pid;
						}
					}
				}
				self::row( $crumbs > 0 ? true : null, sprintf( 'BreadcrumbList در %s از %s صفحه بررسی‌شده.', number_format_i18n( $crumbs ), number_format_i18n( count( $sch ) ) ) );
				if ( $art_need ) {
					self::row( $art_bad ? null : true, $art_bad ? sprintf( '%s نوشته Schema مقاله با نویسنده و تاریخ به‌روزرسانی ندارند.', number_format_i18n( count( $art_bad ) ) ) : 'نوشته‌ها Schema مقاله با نویسنده و تاریخ دارند.', $art_bad ? implode( '، ', array_map( array( __CLASS__, 'plink' ), $art_bad ) ) : '' );
				}
				?>
			</ul>
			<details><summary>نوع Schema هر صفحه بررسی‌شده</summary>
				<table class="widefat striped"><tbody>
				<?php
				foreach ( $sch as $pid => $s ) {
					echo '<tr><td>' . ( $pid ? wp_kses_post( self::plink( $pid ) ) : 'صفحه اصلی' ) . '</td><td dir="ltr" style="text-align:right">' . esc_html( $s['types'] ? implode( ', ', $s['types'] ) : '—' ) . '</td></tr>';
				}
				?>
				</tbody></table>
			</details>
		</section>

		<section class="ssai-card">
			<h2>اعتماد و تخصص (E-E-A-T)</h2>
			<p class="description">موتورهای هوش مصنوعی و گوگل برای موضوعات پزشکی و مالی (YMYL) به این‌ها حساس‌ترند.</p>
			<ul class="ssai-list">
				<?php
				$tr = $a['trust'];
				self::row( (bool) $tr['about'], $tr['about'] ? 'صفحه «درباره ما»: ' . self::plink( $tr['about'] ) : 'صفحه «درباره ما» پیدا نشد.', 'سابقه، مدارک، تجربه و افراد پشت کسب‌وکار را معرفی کنید (مثلاً شماره نظام پزشکی و تخصص).' );
				self::row( (bool) $tr['contact'], $tr['contact'] ? 'صفحه «تماس با ما»: ' . self::plink( $tr['contact'] ) : 'صفحه «تماس با ما» پیدا نشد.' );
				self::row( $tr['privacy'] ? true : null, $tr['privacy'] ? 'صفحه حریم خصوصی/قوانین: ' . self::plink( $tr['privacy'] ) : 'صفحه حریم خصوصی یا قوانین پیدا نشد.' );
				foreach ( $a['authors'] as $u ) {
					self::row( $u['bio'] ? true : null, 'نویسنده «' . esc_html( $u['name'] ) . '»: ' . ( $u['bio'] ? 'زندگی‌نامه دارد.' : 'زندگی‌نامه (بیوگرافی) ندارد.' ), $u['bio'] ? '' : '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . $u['id'] ) ) . '">افزودن در پروفایل کاربر</a> – تخصص و سابقه نویسنده را بنویسید.' );
				}
				?>
			</ul>
		</section>

		<?php self::render_pages( $pages ); ?>
		<?php self::render_sxo( $a ); ?>
		<?php self::render_llms( $a ); ?>
		<?php
	}

	private static function render_pages( $pages ) {
		uasort(
			$pages,
			function ( $x, $y ) {
				return $x['score'] - $y['score'];
			}
		);
		?>
		<section class="ssai-card">
			<h2>محتوای پاسخ‌محور، صفحه به صفحه (AEO)</h2>
			<p class="description">ضعیف‌ترین صفحه‌ها اول آمده‌اند. «ساخت پرسش‌های متداول» با هوش مصنوعی و بر اساس متن صفحه و جستجوهای واقعی سرچ کنسول پیشنهاد می‌دهد؛ تا شما تأیید نکنید چیزی تغییر نمی‌کند.</p>
			<div class="ssai-table-wrap"><table class="widefat striped ssai-aeo-table">
				<thead><tr><th>صفحه</th><th>امتیاز</th><th>تیتر سؤالی / پاسخ مستقیم</th><th>کمبودها</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $pages as $id => $p ) : ?>
					<tr>
						<td><?php echo wp_kses_post( self::plink( $id ) ); ?></td>
						<td><span class="ssai-pos ssai-risk-<?php echo esc_attr( self::lvl( $p['score'] ) ); ?>"><?php echo esc_html( number_format_i18n( $p['score'] ) ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $p['q'] ) . ' / ' . number_format_i18n( $p['answered'] ) ); ?></td>
						<td class="ssai-hint"><?php echo esc_html( $p['miss'] ? implode( ' • ', $p['miss'] ) : 'کامل' ); ?></td>
						<td>
							<?php if ( SSAI_Applier::can( $id ) ) : ?>
								<button type="button" class="ssai-fix-btn ssai-faq-btn" data-id="<?php echo esc_attr( $id ); ?>"><?php echo $p['faq'] && self::get_faq( $id ) ? 'ویرایش پرسش‌های متداول' : '✨ ساخت پرسش‌های متداول'; ?></button>
							<?php endif; ?>
						</td>
					</tr>
					<tr class="ssai-faq-row" id="ssai-faq-<?php echo esc_attr( $id ); ?>" hidden><td colspan="5"></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</section>
		<?php
	}

	private static function render_sxo( $a ) {
		$sxo   = $a['sxo'];
		$front = (int) get_option( 'page_on_front' );
		$psi   = $front ? SSAI_Health::psi( $front ) : array();
		$m     = isset( $psi['mobile'] ) ? $psi['mobile'] : null;
		?>
		<section class="ssai-card">
			<h2>تجربه جستجو (SXO)</h2>
			<div class="ssai-tiles">
				<?php
				if ( $m && isset( $m['scores']['performance'] ) ) {
					self::tile( 'سرعت موبایل صفحه اصلی', number_format_i18n( $m['scores']['performance'] ), wp_date( 'Y/m/d', $m['time'] ), $m['scores']['performance'] >= 90 ? 'good' : ( $m['scores']['performance'] >= 50 ? 'warning' : 'error' ) );
					foreach ( array( 'lcp' => 'LCP', 'inp' => 'INP', 'cls' => 'CLS' ) as $k => $lbl ) {
						if ( ! empty( $m['field'][ $k ]['text'] ) ) {
							$c = $m['field'][ $k ]['cat'];
							self::tile( $lbl . ' (کاربران واقعی)', $m['field'][ $k ]['text'], '', 'fast' === $c ? 'good' : ( 'slow' === $c ? 'error' : 'warning' ) );
						}
					}
				} else {
					self::tile( 'سرعت موبایل صفحه اصلی', '—', $front ? '<a href="' . esc_url( SSAI_Report::url( $front ) ) . '">از گزارش صفحه اصلی بگیرید</a>' : '' );
				}
				self::tile( 'سازگاری با موبایل (viewport)', null === $a['viewport'] ? '—' : ( $a['viewport'] ? 'دارد' : 'ندارد' ), '', $a['viewport'] ? 'good' : 'error' );
				?>
			</div>
			<?php if ( ! isset( $sxo['gsc_range'] ) ) : ?>
				<p class="description">برای تحلیل نرخ کلیک و جستجوهای سؤالی، خروجی کل سایت سرچ کنسول را از <a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-gsc' ) ); ?>">این‌جا</a> وارد کنید.</p>
				<?php
				echo '</section>';
				return;
			endif;
			?>
			<p class="description">بازه داده سرچ کنسول: <?php echo esc_html( $sxo['gsc_range'] ); ?></p>

			<h3>صفحه‌های صفحه اول گوگل با نرخ کلیک خیلی پایین</h3>
			<p class="description">دیده می‌شوند ولی کلیک نمی‌گیرند؛ معمولاً عنوان و توضیحات متا جذاب نیست یا پاسخ کامل در خود گوگل/AI Overview داده شده. در گزارش صفحه با دکمه‌های هوش مصنوعی عنوان و متا را اصلاح کنید. «انتظار» میانگین تقریبی کلیک برای آن جایگاه است.</p>
			<?php if ( $sxo['low_ctr'] ) : ?>
				<div class="ssai-table-wrap"><table class="widefat striped"><thead><tr><th>صفحه</th><th>جایگاه</th><th>نمایش</th><th>نرخ کلیک</th><th>انتظار</th></tr></thead><tbody>
				<?php foreach ( $sxo['low_ctr'] as $r ) : ?>
					<tr><td><?php echo wp_kses_post( self::plink( $r['id'], $r['url'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $r['pos'], 1 ) ); ?></td><td><?php echo esc_html( number_format_i18n( $r['imp'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( 100 * $r['ctr'], 1 ) ); ?>٪</td><td><?php echo esc_html( '≈' . number_format_i18n( 100 * $r['exp'], 1 ) ); ?>٪</td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php else : ?>
				<p>✓ موردی نیست.</p>
			<?php endif; ?>

			<h3>نزدیک به صفحه اول (جایگاه ۸ تا ۲۰)</h3>
			<p class="description">کمترین تلاش برای بیشترین نتیجه: محتوا را کامل‌تر کنید، پرسش‌های متداول اضافه کنید و از صفحه‌های قوی‌تر سایت به این‌ها لینک بدهید.</p>
			<?php if ( $sxo['striking'] ) : ?>
				<div class="ssai-table-wrap"><table class="widefat striped"><thead><tr><th>صفحه</th><th>جایگاه</th><th>نمایش</th><th>کلیک</th></tr></thead><tbody>
				<?php foreach ( $sxo['striking'] as $r ) : ?>
					<tr><td><?php echo wp_kses_post( self::plink( $r['id'], $r['url'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $r['pos'], 1 ) ); ?></td><td><?php echo esc_html( number_format_i18n( $r['imp'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $r['clicks'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php else : ?>
				<p>✓ موردی نیست.</p>
			<?php endif; ?>

			<h3>سؤال‌ها و جستجوهای اطلاعاتی مردم (ایده برای AEO)</h3>
			<p class="description">این‌ها جستجوهای واقعی است که سایت شما برایشان دیده شده. هر کدام را با یک تیتر سؤالی و پاسخ کوتاه در صفحه مرتبط جواب بدهید.</p>
			<?php if ( $sxo['questions'] ) : ?>
				<div class="ssai-table-wrap"><table class="widefat striped"><thead><tr><th>جستجو</th><th>نمایش</th><th>کلیک</th><th>جایگاه</th></tr></thead><tbody>
				<?php foreach ( $sxo['questions'] as $r ) : ?>
					<tr><td><?php echo esc_html( $r['q'] ); ?></td><td><?php echo esc_html( number_format_i18n( $r['imp'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $r['clicks'] ) ); ?></td><td><?php echo esc_html( number_format_i18n( $r['pos'], 1 ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table></div>
			<?php else : ?>
				<p class="description">جستجوی سؤالی در داده وارد شده پیدا نشد.</p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_llms( $a ) {
		$l     = self::llms();
		$admin = current_user_can( 'manage_options' );
		?>
		<section class="ssai-card">
			<h2>llms.txt (اختیاری)</h2>
			<p class="description">llms.txt یک فهرست ساده از مهم‌ترین صفحه‌های سایت برای مدل‌های زبانی است. پیشنهادی نوظهور است: گوگل اعلام کرده از آن استفاده نمی‌کند و استفاده بقیه سرویس‌ها هم تأیید نشده؛ ضرری ندارد ولی انتظار اثر زیادی هم نداشته باشید.</p>
			<p>وضعیت فعلی: <?php echo $a['llms'] ? '<strong>' . ( $l['on'] ? 'منتشر شده توسط افزونه' : 'فایل llms.txt روی سایت وجود دارد' ) . '</strong> — <a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">مشاهده</a>' : 'ندارد'; ?></p>
			<?php if ( $admin ) : ?>
				<?php self::button( 'llms_draft', $l['text'] ? 'ساخت دوباره پیش‌نویس از صفحه‌های سایت' : 'ساخت پیش‌نویس از صفحه‌های سایت' ); ?>
				<?php if ( '' !== $l['text'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="ssai_aeo_action"><input type="hidden" name="do" value="llms_save">
						<?php wp_nonce_field( 'ssai_aeo_action' ); ?>
						<textarea name="llms_text" rows="14" class="large-text code" dir="auto"><?php echo esc_textarea( $l['text'] ); ?></textarea>
						<p><label><input type="checkbox" name="llms_on" value="1" <?php checked( $l['on'] ); ?>> منتشر شود در <code dir="ltr"><?php echo esc_html( home_url( '/llms.txt' ) ); ?></code></label></p>
						<button class="button button-primary">ذخیره</button>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}
}
