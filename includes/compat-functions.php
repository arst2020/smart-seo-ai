<?php
/**
 * Breadcrumb functions of Rank Math / Yoast, so themes that call them keep working after
 * those plugins are deactivated. Loaded only when neither plugin is active.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'rank_math_the_breadcrumbs' ) ) {
	function rank_math_the_breadcrumbs( $args = array() ) { // phpcs:ignore
		echo SSAI_SEO::breadcrumb_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built with esc_* inside.
	}
}

if ( ! function_exists( 'rank_math_get_breadcrumbs' ) ) {
	function rank_math_get_breadcrumbs( $args = array() ) { // phpcs:ignore
		return SSAI_SEO::breadcrumb_html();
	}
}

if ( ! function_exists( 'yoast_breadcrumb' ) ) {
	function yoast_breadcrumb( $before = '', $after = '', $display = true ) { // phpcs:ignore
		$html = $before . SSAI_SEO::breadcrumb_html() . $after;
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
			return '';
		}
		return $html;
	}
}
