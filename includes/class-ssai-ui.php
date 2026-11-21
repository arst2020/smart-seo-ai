<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern look (cards, Vazirmatn font) and the light / dark switch for the plugin's own screens.
 */
class SSAI_UI {

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
	}

	public static function ours() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return 0 === strpos( $page, 'smart-seo-ai' );
	}

	public static function enqueue() {
		if ( ! self::ours() ) {
			return;
		}
		wp_enqueue_style( 'ssai-admin', SSAI_URL . 'assets/admin.css', array(), SSAI_VERSION );
		wp_enqueue_style( 'ssai-theme', SSAI_URL . 'assets/theme.css', array( 'ssai-admin' ), SSAI_VERSION );
		// In the <head>, so the dark class is set before the page is painted.
		wp_enqueue_script( 'ssai-theme', SSAI_URL . 'assets/theme.js', array(), SSAI_VERSION, false );
	}

	public static function body_class( $c ) {
		return self::ours() ? $c . ' ssai-page' : $c;
	}

	/** Small inline icons for KPI tiles. */
	public static function icon( $name ) {
		$p = array(
			'score'  => '<path d="M12 2l3 6.5 7 .8-5.2 4.8 1.5 7L12 17.6 5.7 21l1.5-7L2 9.3l7-.8z"/>',
			'click'  => '<path d="M9 9l10.5 3.5-4 1.5-1.5 4z"/><path d="M5 3v4M3 5h4"/>',
			'eye'    => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
			'ai'     => '<path d="M12 3l1.8 4.6L18 9l-4.2 1.4L12 15l-1.8-4.6L6 9l4.2-1.4z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
			'speed'  => '<path d="M12 14l4-4"/><path d="M3.3 17a9 9 0 1117.4 0"/>',
			'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
			'warn'   => '<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/>',
			'link'   => '<path d="M10 13a5 5 0 007 0l3-3a5 5 0 00-7-7l-1 1"/><path d="M14 11a5 5 0 00-7 0l-3 3a5 5 0 007 7l1-1"/>',
			'trend'  => '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
		);
		return isset( $p[ $name ] ) ? '<span class="ssai-tile-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p[ $name ] . '</svg></span>' : '';
	}
}
