<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the meta description on the site when no other SEO plugin does it.
 */
class SSAI_Frontend {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'meta_description' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 20 );
	}

	public static function meta_description() {
		if ( 'auto' !== SSAI_Settings::get( 'output_meta' ) || '' !== SSAI_Content::seo_plugin() || ! is_singular() ) {
			return;
		}
		$desc = (string) get_post_meta( get_queried_object_id(), SSAI_Content::META_DESC, true );
		if ( '' === trim( $desc ) ) {
			return;
		}
		echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
	}

	/** Use the SEO title for <title> when no other SEO plugin handles it. */
	public static function document_title( $title ) {
		if ( 'auto' !== SSAI_Settings::get( 'output_meta' ) || '' !== SSAI_Content::seo_plugin() || ! is_singular() ) {
			return $title;
		}
		$t = (string) get_post_meta( get_queried_object_id(), SSAI_Content::META_TITLE, true );
		return '' !== trim( $t ) ? $t : $title;
	}
}
