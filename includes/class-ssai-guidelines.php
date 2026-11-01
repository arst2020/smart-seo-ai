<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Search guidelines given to the AI with every request.
 * - A built-in summary (editable in the settings, so it can be updated when Google changes something).
 * - Optionally, the latest ranking/spam updates from Google's official Search Status Dashboard (checked weekly).
 */
class SSAI_Guidelines {

	const OPTION_UPDATES = 'ssai_google_updates';
	const CRON           = 'ssai_fetch_google_updates';
	const FEED           = 'https://status.search.google.com/incidents.json';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'fetch' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		$on = 'on' === SSAI_Settings::get( 'auto_updates' );
		$ts = wp_next_scheduled( self::CRON );
		if ( $on && ! $ts ) {
			wp_schedule_event( time() + 300, 'weekly', self::CRON );
		} elseif ( ! $on && $ts ) {
			wp_unschedule_event( $ts, self::CRON );
		}
	}

	public static function deactivate() {
		$ts = wp_next_scheduled( self::CRON );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON );
		}
	}

	/** Built-in summary of Google's current guidance (as of late 2026). */
	public static function default_text() {
		return implode(
			"\n",
			array(
				'- Helpful, reliable, people-first content: original information, written for users (not for search engines). Show E-E-A-T (experience, expertise, authoritativeness, trust).',
				'- YMYL topics (health, medicine, dentistry, money, legal): be accurate and cautious, no guarantees, no unverifiable medical claims, no invented statistics; name qualified professionals where relevant.',
				'- AI-assisted content is fine when it is accurate and useful; mass-producing low-value pages is "scaled content abuse" (spam).',
				'- No keyword stuffing: use the keyword and natural synonyms/related terms. No hidden text, doorway pages or misleading titles.',
				'- Title links: unique, descriptive, about 50-60 characters, main keyword near the beginning, brand name at the end, no clickbait, no keyword repetition.',
				'- Meta description: unique, accurate summary of THIS page (about 120-160 characters) with a clear benefit or call to action; Google may rewrite it.',
				'- One clear H1, then a logical H2/H3 structure that matches what users look for.',
				'- Image alt text describes what the image shows (plus the topic when natural), never a list of keywords.',
				'- URLs: short, readable, descriptive words separated by hyphens.',
				'- Internal links with descriptive anchor text; link to authoritative sources when useful.',
				'- Structured data must match visible content. FAQ rich results are only shown for well-known government and health websites; HowTo rich results are discontinued.',
				'- Page experience: mobile friendly, fast (Core Web Vitals: LCP, INP, CLS), HTTPS, no intrusive pop-ups.',
				'- Local businesses: consistent name, address and phone (NAP), service area and opening hours; keep the Google Business Profile in sync.',
			)
		);
	}

	public static function updates() {
		$u = get_option( self::OPTION_UPDATES );
		return is_array( $u ) ? $u : array(
			'time'  => 0,
			'items' => array(),
			'error' => '',
		);
	}

	/**
	 * Download the latest ranking-related incidents from Google's Search Status Dashboard.
	 *
	 * @return true|WP_Error
	 */
	public static function fetch() {
		$res = wp_remote_get( self::FEED, array( 'timeout' => 20 ) );
		$out = self::updates();
		$out['time'] = time();
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			$out['error'] = is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res );
			update_option( self::OPTION_UPDATES, $out, false );
			return new WP_Error( 'ssai_feed', $out['error'] );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			$out['error'] = 'قالب پاسخ نامعتبر بود.';
			update_option( self::OPTION_UPDATES, $out, false );
			return new WP_Error( 'ssai_feed', $out['error'] );
		}
		$items = array();
		foreach ( $data as $inc ) {
			if ( ! is_array( $inc ) ) {
				continue;
			}
			$title = '';
			foreach ( array( 'external_desc', 'title', 'description', 'name' ) as $k ) {
				if ( ! empty( $inc[ $k ] ) && is_string( $inc[ $k ] ) ) {
					$title = $inc[ $k ];
					break;
				}
			}
			$service = isset( $inc['service_name'] ) ? (string) $inc['service_name'] : '';
			// Keep ranking updates only (core, spam, reviews, helpful content…), not crawling/serving outages.
			if ( '' === $title || ( '' !== $service && false === stripos( $service, 'rank' ) && ! preg_match( '/update/i', $title ) ) ) {
				continue;
			}
			$begin   = isset( $inc['begin'] ) ? strtotime( (string) $inc['begin'] ) : 0;
			$items[] = array(
				'title' => sanitize_text_field( $title ),
				'date'  => $begin ? gmdate( 'Y-m-d', $begin ) : '',
			);
		}
		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		$out['items'] = array_slice( $items, 0, 8 );
		$out['error'] = '';
		update_option( self::OPTION_UPDATES, $out, false );
		return true;
	}

	/** Text block added to every AI prompt. */
	public static function prompt() {
		$text = trim( (string) SSAI_Settings::get( 'guidelines' ) );
		if ( '' === $text ) {
			$text = self::default_text();
		}
		$out = "Follow Google Search's current guidelines:\n" . $text;
		$u   = self::updates();
		if ( 'on' === SSAI_Settings::get( 'auto_updates' ) && ! empty( $u['items'] ) ) {
			$lines = array();
			foreach ( $u['items'] as $it ) {
				$lines[] = '- ' . $it['date'] . ': ' . $it['title'];
			}
			$out .= "\nRecent official Google Search ranking updates (keep the advice consistent with them):\n" . implode( "\n", $lines );
		}
		return $out;
	}
}
