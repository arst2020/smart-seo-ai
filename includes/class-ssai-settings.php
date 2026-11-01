<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings (stored in one option).
 */
class SSAI_Settings {

	const OPTION = 'ssai_settings';

	/**
	 * Supported AI providers. Model names are only defaults – they can be changed in settings.
	 */
	public static function providers() {
		return array(
			'gemini'     => array(
				'label' => 'Google Gemini (رایگان با محدودیت روزانه)',
				'base'  => 'https://generativelanguage.googleapis.com/v1beta',
				'model' => 'gemini-2.5-flash',
				'key'   => true,
				'help'  => 'https://aistudio.google.com/apikey',
			),
			'groq'       => array(
				'label' => 'Groq (رایگان با محدودیت، بسیار سریع)',
				'base'  => 'https://api.groq.com/openai/v1',
				'model' => 'openai/gpt-oss-120b',
				'key'   => true,
				'help'  => 'https://console.groq.com/keys',
			),
			'openrouter' => array(
				'label' => 'OpenRouter (مدل‌های دارای پسوند :free)',
				'base'  => 'https://openrouter.ai/api/v1',
				'model' => 'openrouter/free',
				'key'   => true,
				'help'  => 'https://openrouter.ai/keys',
			),
			'cloudflare' => array(
				'label' => 'Cloudflare Workers AI (رایگان: روزانه ۱۰ هزار نورون)',
				'base'  => 'https://api.cloudflare.com/client/v4/accounts/{account}/ai/v1',
				'model' => '@cf/openai/gpt-oss-120b',
				'key'   => true,
				'help'  => 'https://dash.cloudflare.com/profile/api-tokens',
			),
			'mistral'    => array(
				'label' => 'Mistral (رایگان با محدودیت، بدون کارت)',
				'base'  => 'https://api.mistral.ai/v1',
				'model' => 'mistral-small-latest',
				'key'   => true,
				'help'  => 'https://console.mistral.ai/api-keys',
			),
			'deepseek'   => array(
				'label' => 'DeepSeek (پولی ولی بسیار ارزان)',
				'base'  => 'https://api.deepseek.com',
				'model' => 'deepseek-chat',
				'key'   => true,
				'help'  => 'https://platform.deepseek.com/api_keys',
			),
			'ollama'     => array(
				'label' => 'Ollama (روی سرور خودتان، کاملاً رایگان)',
				'base'  => 'http://localhost:11434/v1',
				'model' => 'qwen2.5:7b',
				'key'   => false,
				'help'  => 'https://ollama.com',
			),
			'custom'     => array(
				'label' => 'سرویس دلخواه سازگار با OpenAI API',
				'base'  => '',
				'model' => '',
				'key'   => false,
				'help'  => '',
			),
		);
	}

	public static function defaults() {
		return array(
			'provider'    => 'gemini',
			'api_key'     => '',
			'model'       => '',
			'base_url'    => '',
			'language'    => 'fa',
			'apply_cap'   => 'manage_options',
			'output_meta' => 'auto',
			'timeout'     => 90,
			'max_chars'   => 12000,
			'guidelines'  => '',
			'auto_updates' => 'on',
			'psi_key'      => '',
			'indexnow'     => 'off',
			'indexnow_key' => '',
			'opr_key'      => '',
			'moz_token'    => '',
			'cf_account'   => '', // Cloudflare Account ID (32 hex characters).
			'backup'       => array(), // provider => array( on, key, model ) – used when the main service fails.
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function activate() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	public static function register() {
		register_setting(
			'ssai_settings_group',
			self::OPTION,
			array(
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function get( $key = null ) {
		$o = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( null === $key ) {
			return $o;
		}
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	/** API key – a constant in wp-config.php takes priority (safer than the database). */
	public static function api_key() {
		if ( defined( 'SSAI_API_KEY' ) && SSAI_API_KEY ) {
			return SSAI_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	public static function provider_info() {
		$all = self::providers();
		$p   = self::get( 'provider' );
		return isset( $all[ $p ] ) ? $all[ $p ] : $all['gemini'];
	}

	public static function model() {
		$m = trim( (string) self::get( 'model' ) );
		if ( '' === $m ) {
			$info = self::provider_info();
			$m    = $info['model'];
		}
		return $m;
	}

	public static function base_url() {
		$b = trim( (string) self::get( 'base_url' ) );
		if ( '' === $b ) {
			$info = self::provider_info();
			$b    = $info['base'];
		}
		return untrailingslashit( self::fill_account( $b ) );
	}

	/** Puts the Cloudflare Account ID into a base URL template ('' when it is missing). */
	public static function fill_account( $base ) {
		if ( false === strpos( $base, '{account}' ) ) {
			return $base;
		}
		$a = (string) self::get( 'cf_account' );
		return '' === $a ? '' : str_replace( '{account}', $a, $base );
	}

	/** Services that can be backups, cheapest/free first. */
	public static function backup_ids() {
		return array( 'gemini', 'groq', 'cloudflare', 'mistral', 'openrouter', 'deepseek' );
	}

	/**
	 * Ordered list of services to try: the main one, then enabled backups that have a key.
	 *
	 * @return array[] provider, label, key, model, base, needs_key
	 */
	public static function chain() {
		$all  = self::providers();
		$main = self::get( 'provider' );
		$info = self::provider_info();
		$out  = array(
			array(
				'provider'  => $main,
				'label'     => $info['label'],
				'key'       => self::api_key(),
				'model'     => self::model(),
				'base'      => self::base_url(),
				'needs_key' => (bool) $info['key'],
			),
		);
		if ( $info['key'] && '' === $out[0]['key'] ) {
			$out = array();
		}
		$b = (array) self::get( 'backup' );
		foreach ( self::backup_ids() as $id ) {
			if ( $id === $main || empty( $b[ $id ]['on'] ) || empty( $b[ $id ]['key'] ) || '' === self::fill_account( $all[ $id ]['base'] ) ) {
				continue;
			}
			$out[] = array(
				'provider'  => $id,
				'label'     => preg_replace( '/\s*\(.*$/u', '', $all[ $id ]['label'] ),
				'key'       => $b[ $id ]['key'],
				'model'     => '' !== $b[ $id ]['model'] ? $b[ $id ]['model'] : $all[ $id ]['model'],
				'base'      => self::fill_account( $all[ $id ]['base'] ),
				'needs_key' => true,
			);
		}
		return $out;
	}

	public static function sanitize( $in ) {
		$old = self::get();
		$out = self::defaults();
		$in  = is_array( $in ) ? $in : array();

		$out['provider'] = ( isset( $in['provider'] ) && array_key_exists( $in['provider'], self::providers() ) ) ? $in['provider'] : 'gemini';

		$key = isset( $in['api_key'] ) ? trim( sanitize_text_field( $in['api_key'] ) ) : '';
		if ( ! empty( $in['clear_key'] ) ) {
			$out['api_key'] = '';
		} elseif ( '' === $key ) {
			$out['api_key'] = $old['api_key']; // Empty field = keep the saved key.
		} else {
			$out['api_key'] = $key;
		}

		// Backup services (each with its own key; empty key field = keep the saved one).
		$ob  = isset( $old['backup'] ) ? (array) $old['backup'] : array();
		$ib  = isset( $in['backup'] ) && is_array( $in['backup'] ) ? $in['backup'] : array();
		$out['backup'] = array();
		foreach ( self::backup_ids() as $id ) {
			$row = isset( $ib[ $id ] ) && is_array( $ib[ $id ] ) ? $ib[ $id ] : array();
			$k   = isset( $row['key'] ) ? trim( sanitize_text_field( $row['key'] ) ) : '';
			$k   = ! empty( $row['clear'] ) ? '' : ( '' === $k ? ( isset( $ob[ $id ]['key'] ) ? $ob[ $id ]['key'] : '' ) : $k );
			$out['backup'][ $id ] = array(
				'on'    => isset( $ib[ $id ] ) ? ( ! empty( $row['on'] ) ? 1 : 0 ) : ( isset( $ob[ $id ]['on'] ) ? (int) $ob[ $id ]['on'] : 0 ),
				'key'   => $k,
				'model' => isset( $row['model'] ) ? sanitize_text_field( $row['model'] ) : ( isset( $ob[ $id ]['model'] ) ? $ob[ $id ]['model'] : '' ),
			);
		}
		// Switching the main service: keep the previous key as that service's backup, and reuse a saved backup key.
		if ( $out['provider'] !== $old['provider'] && '' === $key && empty( $in['clear_key'] ) ) {
			if ( '' !== $old['api_key'] && isset( $out['backup'][ $old['provider'] ] ) && '' === $out['backup'][ $old['provider'] ]['key'] ) {
				$out['backup'][ $old['provider'] ] = array( 'on' => 1, 'key' => $old['api_key'], 'model' => (string) $old['model'] );
			}
			$out['api_key'] = isset( $out['backup'][ $out['provider'] ] ) ? $out['backup'][ $out['provider'] ]['key'] : '';
		}

		$out['model']       = isset( $in['model'] ) ? sanitize_text_field( $in['model'] ) : '';
		if ( $out['provider'] !== $old['provider'] && $out['model'] === (string) $old['model'] ) {
			$out['model'] = ''; // Model names belong to one service; use the new service's default.
		}
		$out['base_url']    = isset( $in['base_url'] ) ? esc_url_raw( trim( $in['base_url'] ) ) : '';
		$out['language']    = ( isset( $in['language'] ) && in_array( $in['language'], array( 'fa', 'en' ), true ) ) ? $in['language'] : 'fa';
		$out['apply_cap']   = ( isset( $in['apply_cap'] ) && in_array( $in['apply_cap'], array( 'manage_options', 'edit_others_posts' ), true ) ) ? $in['apply_cap'] : 'manage_options';
		$out['output_meta'] = ( isset( $in['output_meta'] ) && in_array( $in['output_meta'], array( 'auto', 'off' ), true ) ) ? $in['output_meta'] : 'auto';
		$out['timeout']     = max( 10, min( 300, (int) ( isset( $in['timeout'] ) ? $in['timeout'] : 60 ) ) );
		$out['max_chars']   = max( 2000, min( 60000, (int) ( isset( $in['max_chars'] ) ? $in['max_chars'] : 12000 ) ) );
		$g                  = isset( $in['guidelines'] ) ? trim( sanitize_textarea_field( $in['guidelines'] ) ) : '';
		// Saving the unchanged built-in text keeps "default" mode, so future plugin updates can refresh it.
		$out['guidelines']   = ( $g === trim( SSAI_Guidelines::default_text() ) ) ? '' : mb_substr( $g, 0, 6000 );
		$out['auto_updates'] = ( isset( $in['auto_updates'] ) && 'on' === $in['auto_updates'] ) ? 'on' : 'off';
		// PageSpeed needs a plain Google "API key" (AIza…, 39 characters) – not a service-account JSON.
		$psi = isset( $in['psi_key'] ) ? trim( (string) $in['psi_key'] ) : '';
		if ( '' === $psi || preg_match( '/^AIza[0-9A-Za-z_\-]{35}$/', $psi ) ) {
			$out['psi_key'] = $psi;
		} else {
			$out['psi_key'] = $old['psi_key'];
			add_settings_error(
				self::OPTION,
				'ssai_psi_key',
				false !== strpos( $psi, '{' ) || false !== strpos( $psi, 'private_key' )
					? 'کلید PageSpeed ذخیره نشد: متنی که وارد کردید فایل JSON حساب سرویس است. PageSpeed یک «API key» ساده لازم دارد که با AIza شروع می‌شود (Credentials › Create credentials › API key).'
					: 'کلید PageSpeed ذخیره نشد: کلید معتبر گوگل با AIza شروع می‌شود و ۳۹ حرف دارد.'
			);
		}
		$out['indexnow']     = ( isset( $in['indexnow'] ) && 'on' === $in['indexnow'] ) ? 'on' : 'off';
		$out['opr_key']      = isset( $in['opr_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-\.]/', '', (string) $in['opr_key'] ) : '';
		$mt                  = isset( $in['moz_token'] ) ? trim( (string) $in['moz_token'] ) : '';
		$out['moz_token']    = '' === $mt ? $old['moz_token'] : ( ! empty( $in['moz_clear'] ) ? '' : preg_replace( '/[^A-Za-z0-9_\-\.=+\/:]/', '', $mt ) );
		if ( ! empty( $in['moz_clear'] ) ) {
			$out['moz_token'] = '';
		}
		// IndexNow key: 32 hex characters, created once and kept.
		$cf = isset( $in['cf_account'] ) ? strtolower( preg_replace( '/[^A-Fa-f0-9]/', '', (string) $in['cf_account'] ) ) : (string) $old['cf_account'];
		$out['cf_account'] = 32 === strlen( $cf ) ? $cf : '';
		if ( function_exists( 'add_settings_error' ) && isset( $in['cf_account'] ) && '' !== trim( (string) $in['cf_account'] ) && '' === $out['cf_account'] ) {
			add_settings_error( self::OPTION, 'ssai_cf', 'Account ID کلادفلر ذخیره نشد: باید ۳۲ کاراکتر (عدد و a تا f) باشد، نه نام توکن. آن را از آدرس داشبورد بردارید: dash.cloudflare.com/<Account ID>/…' );
		}
		// A Cloudflare URL typed into "custom service" becomes the Cloudflare service (keeps the key).
		if ( 'custom' === $out['provider'] && preg_match( '#api\.cloudflare\.com/client/v4/accounts/([a-f0-9]{32})/ai#i', $out['base_url'], $m ) ) {
			$out['provider']   = 'cloudflare';
			$out['cf_account'] = strtolower( $m[1] );
			$out['base_url']   = '';
		}

		$out['indexnow_key'] = ! empty( $old['indexnow_key'] ) ? $old['indexnow_key'] : ( 'on' === $out['indexnow'] ? bin2hex( random_bytes( 16 ) ) : '' );

		return $out;
	}
}
