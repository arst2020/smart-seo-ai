<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Avada Builder (Fusion) pages: content lives in [fusion_*] shortcodes inside post_content.
 * Reading: shortcodes → plain HTML (strip_shortcodes() would drop the text inside them).
 * Rewriting: only the text inside Title and Text elements changes, so the layout stays intact.
 */
class SSAI_Avada {

	const TEXT_RX = '/\[(fusion_title|fusion_text)((?:\s[^\]]*)?)\](.*?)\[\/\1\]/s';

	/** Avada theme or Avada Builder plugin is active. */
	public static function active() {
		return defined( 'FUSION_BUILDER_VERSION' ) || defined( 'AVADA_VERSION' ) || 'avada' === strtolower( get_template() );
	}

	public static function is_built( $post_id ) {
		return false !== strpos( (string) get_post_field( 'post_content', $post_id ), '[fusion_builder_container' );
	}

	public static function edit_url( $post_id ) {
		return add_query_arg( 'fb-edit', '1', get_permalink( $post_id ) );
	}

	public static function html( $post_id ) {
		return self::to_html( (string) get_post_field( 'post_content', $post_id ) );
	}

	private static function atts( $s ) {
		$a = shortcode_parse_atts( trim( (string) $s ) );
		return is_array( $a ) ? $a : array();
	}

	/** Avada shortcodes → the HTML the page shows (headings, text, links, images, lists, toggles). */
	public static function to_html( $c ) {
		$c = preg_replace_callback(
			'/\[fusion_title((?:\s[^\]]*)?)\](.*?)\[\/fusion_title\]/s',
			function ( $m ) {
				$a = self::atts( $m[1] );
				$l = isset( $a['size'] ) ? max( 1, min( 6, (int) $a['size'] ) ) : 2;
				return "\n<h$l>" . trim( $m[2] ) . "</h$l>\n";
			},
			$c
		);
		$c = preg_replace_callback(
			'/\[fusion_text((?:\s[^\]]*)?)\](.*?)\[\/fusion_text\]/s',
			function ( $m ) {
				$t = trim( $m[2] );
				return "\n" . ( false === stripos( $t, '<p' ) ? wpautop( $t ) : $t ) . "\n";
			},
			$c
		);
		$c = preg_replace_callback(
			'/\[fusion_button((?:\s[^\]]*)?)\](.*?)\[\/fusion_button\]/s',
			function ( $m ) {
				$a = self::atts( $m[1] );
				return ' <a href="' . esc_url( isset( $a['link'] ) ? $a['link'] : '#' ) . '">' . trim( $m[2] ) . '</a> ';
			},
			$c
		);
		$c = preg_replace_callback(
			'/\[fusion_imageframe((?:\s[^\]]*)?)\](.*?)\[\/fusion_imageframe\]/s',
			function ( $m ) {
				$a   = self::atts( $m[1] );
				$src = trim( wp_strip_all_tags( $m[2] ) );
				if ( preg_match( '/<img\b/i', $m[2] ) ) {
					return $m[2];
				}
				return '' === $src ? '' : '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( isset( $a['alt'] ) ? $a['alt'] : '' ) . '">';
			},
			$c
		);
		$c = preg_replace_callback(
			'/\[fusion_gallery_image((?:\s[^\]]*)?)\](?:.*?\[\/fusion_gallery_image\])?/s',
			function ( $m ) {
				$a = self::atts( $m[1] );
				return ! empty( $a['image'] ) ? '<img src="' . esc_url( $a['image'] ) . '" alt="">' : '';
			},
			$c
		);
		$c = preg_replace_callback(
			'/\[(fusion_toggle|fusion_content_box|fusion_tab)((?:\s[^\]]*)?)\](.*?)\[\/\1\]/s',
			function ( $m ) {
				$a = self::atts( $m[2] );
				$t = isset( $a['title'] ) ? $a['title'] : '';
				return ( '' !== $t ? "\n<h3>" . esc_html( $t ) . "</h3>\n" : '' ) . wpautop( trim( $m[3] ) );
			},
			$c
		);
		$c = preg_replace( '/\[fusion_li_item[^\]]*\](.*?)\[\/fusion_li_item\]/s', '<li>$1</li>', $c );
		$c = preg_replace( '/\[fusion_checklist[^\]]*\]/', '<ul>', $c );
		$c = str_replace( '[/fusion_checklist]', '</ul>', $c );
		// Layout shortcodes and anything else from Avada: keep only what is inside.
		$c = preg_replace( '/\[\/?(fusion_|awb_)[^\]]*\]/', '', $c );
		return trim( $c );
	}

	/** Editable texts in page order: [ id, label, html ]. */
	public static function text_items( $post_id ) {
		$c     = (string) get_post_field( 'post_content', $post_id );
		$items = array();
		if ( preg_match_all( self::TEXT_RX, $c, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $i => $x ) {
				if ( '' === trim( wp_strip_all_tags( $x[3] ) ) ) {
					continue;
				}
				$a       = self::atts( $x[2] );
				$items[] = array(
					'id'    => 'avada' . $i . ':' . $x[1],
					'label' => 'fusion_title' === $x[1] ? 'تیتر H' . ( isset( $a['size'] ) ? (int) $a['size'] : 2 ) : 'متن',
					'html'  => trim( $x[3] ),
				);
			}
		}
		return $items;
	}

	/**
	 * Replace texts by id (from text_items). Titles keep only inline markup.
	 *
	 * @return int number of changed elements
	 */
	public static function apply_text( $post_id, $changes ) {
		$c     = (string) get_post_field( 'post_content', $post_id );
		$i     = -1;
		$count = 0;
		$new   = preg_replace_callback(
			self::TEXT_RX,
			function ( $m ) use ( &$i, &$count, $changes ) {
				$i++;
				$id = 'avada' . $i . ':' . $m[1];
				if ( ! isset( $changes[ $id ] ) ) {
					return $m[0];
				}
				$v = (string) $changes[ $id ];
				if ( 'fusion_title' === $m[1] ) {
					$v = wp_kses( $v, array( 'strong' => array(), 'em' => array(), 'b' => array(), 'i' => array(), 'span' => array( 'class' => array() ) ) );
				}
				$v = preg_replace( '/\[(\/?)(fusion_|awb_)/', '&#91;$1$2', $v ); // Text must not open or close an Avada element.
				$count++;
				return '[' . $m[1] . $m[2] . ']' . $v . '[/' . $m[1] . ']';
			},
			$c
		);
		if ( $count ) {
			$r = wp_update_post( wp_slash( array( 'ID' => (int) $post_id, 'post_content' => $new ) ), true );
			if ( is_wp_error( $r ) ) {
				return 0;
			}
		}
		return $count;
	}
}
