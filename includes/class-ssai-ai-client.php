<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal client for Gemini and any OpenAI-compatible API (Groq, OpenRouter, Ollama, ...).
 */
class SSAI_AI_Client {

	/**
	 * Send a prompt and get the text answer.
	 *
	 * @return string|WP_Error
	 */
	/** Provider that answered the last successful request (differs from the main one after a fallback). */
	public static $used = '';

	/** Long jobs (full rewrites) raise this so slow free models get enough time. */
	public static $min_timeout = 0;

	/**
	 * Main service first, then the enabled backup services. A backup is tried when the previous one is
	 * out of quota, unpaid, region-blocked, has a bad key or is down; such a service then rests for a while.
	 */
	public static function chat( $system, $user, $json = true ) {
		$cands = SSAI_Settings::chain();
		if ( ! $cands ) {
			return new WP_Error( 'ssai_no_key', 'کلید API هوش مصنوعی در تنظیمات افزونه وارد نشده است.' );
		}
		// Services resting after a failure go to the end of the queue (still used if nothing else works).
		$ready = array();
		$rest  = array();
		foreach ( $cands as $c ) {
			if ( get_transient( 'ssai_cool_' . $c['provider'] ) ) {
				$rest[] = $c;
			} else {
				$ready[] = $c;
			}
		}
		$cands  = array_merge( $ready, $rest );
		$errors = array();
		foreach ( $cands as $c ) {
			$r = self::call( $c, $system, $user, $json );
			if ( ! is_wp_error( $r ) && $json && null === self::parse_json( $r ) ) {
				$r = new WP_Error( 'ssai_bad_json', 'پاسخ قالب JSON معتبر نداشت («' . mb_substr( trim( wp_strip_all_tags( $r ) ), 0, 80 ) . '…»).', array( 'next' => true ) );
			}
			if ( ! is_wp_error( $r ) ) {
				self::$used = $c['provider'];
				update_option( 'ssai_ai_last', array( 'provider' => $c['provider'], 'model' => $c['model'], 'time' => time(), 'fallback' => $c['provider'] !== SSAI_Settings::get( 'provider' ) ), false );
				return $r;
			}
			$data   = $r->get_error_data();
			$errors[] = ( count( $cands ) > 1 ? $c['label'] . ': ' : '' ) . $r->get_error_message();
			if ( ! empty( $data['next'] ) ) {
				continue; // Bad or cut answer: the next service may do better; no rest needed.
			}
			if ( empty( $data['retry'] ) ) {
				break; // A problem a backup service would not solve (e.g. blocked content).
			}
			set_transient( 'ssai_cool_' . $c['provider'], 1, (int) $data['retry'] );
		}
		$code = ( $r instanceof WP_Error && in_array( $r->get_error_code(), array( 'ssai_bad_json', 'ssai_cut' ), true ) && 1 === count( $errors ) ) ? $r->get_error_code() : 'ssai_api';
		return new WP_Error( $code, count( $errors ) > 1 ? 'هیچ‌کدام از سرویس‌ها پاسخ نداد — ' . implode( ' | ', $errors ) : $errors[0] );
	}

	/**
	 * One request to one service.
	 *
	 * @param array $c provider, label, key, model, base, needs_key.
	 * @return string|WP_Error Error data 'retry' = seconds to rest this service before trying it again.
	 */
	public static function call( $c, $system, $user, $json = true ) {
		$provider = $c['provider'];
		$model    = $c['model'];
		$base     = untrailingslashit( $c['base'] );
		$key      = $c['key'];
		$timeout  = max( (int) SSAI_Settings::get( 'timeout' ), (int) self::$min_timeout );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $timeout + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		if ( $c['needs_key'] && '' === $key ) {
			return new WP_Error( 'ssai_no_key', 'کلید API وارد نشده است.', array( 'retry' => 60 ) );
		}
		if ( '' === $base || '' === $model ) {
			return new WP_Error( 'ssai_no_model', 'آدرس سرویس یا نام مدل در تنظیمات مشخص نیست.', array( 'retry' => 60 ) );
		}

		if ( 'gemini' === $provider ) {
			$url  = $base . '/models/' . rawurlencode( $model ) . ':generateContent';
			$body = array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $user ) ),
					),
				),
				'generationConfig'  => array( 'temperature' => 0.4 ),
			);
			if ( $json ) {
				$body['generationConfig']['responseMimeType'] = 'application/json';
			}
			$headers = array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $key,
			);
		} else {
			$url  = $base . '/chat/completions';
			$body = array(
				'model'       => $model,
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
				'temperature' => 0.4,
			);
			// gpt-oss "thinks" before answering; low effort is plenty for SEO text and several times faster.
			if ( false !== stripos( $model, 'gpt-oss' ) && in_array( $provider, array( 'groq', 'cloudflare' ), true ) ) {
				$body['reasoning_effort'] = 'low';
			}
			// Some services (Cloudflare: 256) cut answers short by default, which breaks the JSON.
			if ( in_array( $provider, array( 'cloudflare', 'custom', 'deepseek', 'mistral' ), true ) ) {
				$body['max_tokens'] = 8192;
			}
			if ( $json && in_array( $provider, array( 'groq', 'ollama', 'deepseek', 'mistral' ), true ) ) {
				$body['response_format'] = array( 'type' => 'json_object' );
			}
			$headers = array( 'Content-Type' => 'application/json' );
			if ( '' !== $key ) {
				$headers['Authorization'] = 'Bearer ' . $key;
			}
			if ( 'openrouter' === $provider ) {
				$headers['HTTP-Referer'] = home_url();
				$headers['X-Title']      = get_bloginfo( 'name' );
			}
		}

		$res = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
				'timeout' => $timeout,
			)
		);
		if ( is_wp_error( $res ) ) {
			$m = $res->get_error_message();
			if ( false !== stripos( $m, 'timed out' ) ) {
				return new WP_Error( 'ssai_http', sprintf( 'سرویس در %d ثانیه جواب نداد (مدل کند است یا متن طولانی). «مهلت پاسخ» را در تنظیمات بیشتر کنید، مدل سریع‌تری انتخاب کنید یا یک سرویس پشتیبان فعال کنید.', $timeout ), array( 'next' => true ) );
			}
			return new WP_Error( 'ssai_http', 'اتصال به سرویس هوش مصنوعی ناموفق بود: ' . $m, array( 'retry' => 300 ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = '';
			if ( is_array( $data ) && isset( $data['error'] ) ) {
				$msg = is_array( $data['error'] ) ? ( isset( $data['error']['message'] ) ? $data['error']['message'] : wp_json_encode( $data['error'] ) ) : (string) $data['error'];
			}
			if ( '' === $msg ) {
				$msg = mb_substr( wp_strip_all_tags( $raw ), 0, 300 );
			}
			$retry = $code >= 500 ? 300 : 0;
			if ( 429 === $code ) {
				$retry = 900;
				$msg = 'سقف استفاده رایگان سرویس پر شده؛ کمی بعد دوباره تلاش کنید. (' . $msg . ')';
			} elseif ( 403 === $code && ! is_array( $data ) && false !== stripos( $raw, '<html' ) ) {
				$retry = 3600;
				$msg = 'سرویس، درخواست‌های آدرس IP سرور سایت را نمی‌پذیرد (احتمالاً سرور در منطقه‌ای است که این سرویس پشتیبانی نمی‌کند). مشکل از کلید نیست.';
			} elseif ( preg_match( '/region|country|location|not available in your|sanction|unsupported_country/i', $msg ) ) {
				$retry = 3600;
				$msg = 'این سرویس یا مدل از کشوری که سرور سایت در آن است پشتیبانی نمی‌کند؛ مشکل از کلید نیست. (' . $msg . ')';
			} elseif ( 402 === $code && 'deepseek' === $provider ) {
				$retry = 3600;
				$msg = 'موجودی حساب DeepSeek تمام شده است؛ در platform.deepseek.com حساب را شارژ کنید. (' . $msg . ')';
			} elseif ( 402 === $code ) {
				$retry = 3600;
				$msg = 'اعتبار حساب کافی نیست؛ این مدل رایگان نیست. در OpenRouter مدلی با پسوند :free یا openrouter/free را انتخاب کنید. (' . $msg . ')';
			} elseif ( 401 === $code || 403 === $code ) {
				$retry = 3600;
				$msg = 'کلید API نامعتبر است یا دسترسی ندارد. (' . $msg . ')';
			}
			if ( 404 === $code || ( 400 === $code && preg_match( '/model|api[ _]?key/i', $msg ) ) ) {
				$retry = 3600; // Wrong / retired model name.
				// Retired model: use the replacement the service names (or Gemini's "latest" alias) and remember it.
				if ( empty( $c['retired'] ) && preg_match( '/no longer available|not found|deprecated|retired|decommission/i', $msg ) ) {
					$to = preg_match( '#use (?:models/)?([A-Za-z0-9][\w.:/-]{1,80}[A-Za-z0-9])#i', $msg, $mm ) ? $mm[1] : ( 'gemini' === $provider ? 'gemini-flash-latest' : '' );
					if ( '' !== $to && $to !== $model ) {
						$c2            = $c;
						$c2['model']   = $to;
						$c2['retired'] = true;
						$r2            = self::call( $c2, $system, $user, $json );
						if ( ! is_wp_error( $r2 ) ) {
							SSAI_Settings::replace_model( $provider, $model, $to );
						}
						return $r2;
					}
				}
			}
			return new WP_Error( 'ssai_api', 'خطای سرویس هوش مصنوعی (' . $code . '): ' . $msg, $retry ? array( 'retry' => $retry ) : null );
		}

		$text = '';
		if ( 'gemini' === $provider ) {
			if ( ! empty( $data['candidates'][0]['content']['parts'] ) ) {
				foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
					if ( isset( $part['text'] ) ) {
						$text .= $part['text'];
					}
				}
			} elseif ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
				return new WP_Error( 'ssai_blocked', 'درخواست توسط Gemini مسدود شد: ' . $data['promptFeedback']['blockReason'] );
			}
		} elseif ( isset( $data['choices'][0]['message']['content'] ) ) {
			$text = (string) $data['choices'][0]['message']['content'];
		}
		if ( isset( $data['choices'][0]['finish_reason'] ) && 'length' === $data['choices'][0]['finish_reason'] && $json ) {
			return new WP_Error( 'ssai_cut', 'پاسخ مدل به سقف طول رسید و ناقص ماند (finish_reason=length). مدلی با خروجی بلندتر انتخاب کنید یا «حداکثر طول متن ارسالی» را کم کنید.', array( 'retry' => 0, 'next' => true ) );
		}

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'ssai_empty', 'پاسخ خالی از سرویس هوش مصنوعی دریافت شد.', array( 'retry' => 120 ) );
		}
		return $text;
	}

	/**
	 * Same as chat() but returns a decoded JSON array.
	 *
	 * @return array|WP_Error
	 */
	public static function chat_json( $system, $user ) {
		$text = self::chat( $system, $user, true );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$data = self::parse_json( $text );
		if ( null === $data ) {
			return new WP_Error( 'ssai_bad_json', 'پاسخ هوش مصنوعی قالب JSON معتبر نداشت. دوباره امتحان کنید یا مدل دیگری انتخاب کنید.' );
		}
		return $data;
	}

	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			return $data;
		}
		$s = strpos( $text, '{' );
		$e = strrpos( $text, '}' );
		if ( false !== $s && false !== $e && $e > $s ) {
			$data = json_decode( substr( $text, $s, $e - $s + 1 ), true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return null;
	}
}
