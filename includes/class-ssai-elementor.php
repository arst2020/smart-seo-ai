<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor support: reads/writes the page structure stored in _elementor_data
 * and adds the SEO panel inside the Elementor editor.
 */
class SSAI_Elementor {

	const META = '_elementor_data';

	public static function init() {
		add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'editor_assets' ) );
	}

	public static function active() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/** Is this post built with Elementor? (data is read even if Elementor is deactivated) */
	public static function is_built( $post_id ) {
		return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) && '' !== (string) get_post_meta( $post_id, self::META, true );
	}

	public static function edit_url( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' );
	}

	public static function get_data( $post_id ) {
		$raw  = get_post_meta( $post_id, self::META, true );
		$data = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	public static function get_raw( $post_id ) {
		$raw = get_post_meta( $post_id, self::META, true );
		return is_array( $raw ) ? wp_json_encode( $raw ) : (string) $raw;
	}

	/** Save the raw JSON string (as Elementor does) and clear Elementor caches. */
	public static function save_raw( $post_id, $json ) {
		update_post_meta( $post_id, self::META, wp_slash( $json ) );
		delete_post_meta( $post_id, '_elementor_element_cache' );
		delete_post_meta( $post_id, '_elementor_css' );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		// The Elementor editor loads an autosave instead of the saved page whenever the autosave
		// is newer than the post. Mark the post as modified now so the editor shows our change
		// (and a later "Update" click does not bring the old autosaved text back).
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', 1 ),
			),
			array( 'ID' => (int) $post_id )
		);
		clean_post_cache( $post_id );
	}

	public static function save_data( $post_id, $data ) {
		self::save_raw( $post_id, wp_json_encode( $data ) );
	}

	/* ------------------------------------------------------------ Reading */

	/** Value of an Elementor v4 (atomic) prop: {"$$type":"…","value":…}. */
	private static function atomic_text( $v ) {
		if ( is_string( $v ) ) {
			return $v;
		}
		if ( is_array( $v ) && array_key_exists( 'value', $v ) ) {
			$val = $v['value'];
			if ( is_string( $val ) ) {
				return $val;
			}
			if ( is_array( $val ) ) {
				foreach ( array( 'content', 'text', 'html' ) as $k ) {
					if ( isset( $val[ $k ] ) ) {
						return self::atomic_text( $val[ $k ] );
					}
				}
			}
		}
		return '';
	}

	private static function atomic_image( $v ) {
		$src = isset( $v['value']['src']['value'] ) ? $v['value']['src']['value'] : array();
		$id  = isset( $src['id']['value'] ) ? (int) $src['id']['value'] : 0;
		$url = isset( $src['url']['value'] ) ? (string) $src['url']['value'] : '';
		if ( $id && '' === $url ) {
			$url = (string) wp_get_attachment_url( $id );
		}
		return array( $id, $url );
	}

	private static function img_tag( $url, $id, $alt_fallback = '' ) {
		if ( '' === $url ) {
			return '';
		}
		$alt = $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : (string) $alt_fallback;
		$cls = $id ? ' class="wp-image-' . (int) $id . '"' : '';
		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '"' . $cls . '>';
	}

	private static function link_tag( $link, $text = '' ) {
		if ( is_array( $link ) && ! empty( $link['url'] ) ) {
			return '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $text ? $text : $link['url'] ) . '</a>';
		}
		return '';
	}

	/**
	 * Builds an HTML approximation of the page (headings, text, images, links) from the
	 * Elementor structure. Used for analysis only – nothing is rendered on the site.
	 */
	public static function html( $post_id ) {
		$out = '';
		self::walk_html( self::get_data( $post_id ), $out );
		return $out;
	}

	private static function walk_html( $elements, &$out ) {
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$s    = isset( $el['settings'] ) && is_array( $el['settings'] ) ? $el['settings'] : array();
			$type = isset( $el['widgetType'] ) ? $el['widgetType'] : ( isset( $el['elType'] ) ? $el['elType'] : '' );

			switch ( $type ) {
				case 'heading':
					$tag = isset( $s['header_size'] ) && preg_match( '/^h[1-6]$/', $s['header_size'] ) ? $s['header_size'] : 'h2';
					if ( ! empty( $s['title'] ) ) {
						$out .= "<{$tag}>" . $s['title'] . "</{$tag}>\n";
					}
					break;
				case 'text-editor':
					if ( ! empty( $s['editor'] ) ) {
						$out .= ( false === stripos( $s['editor'], '<p' ) ? wpautop( $s['editor'] ) : $s['editor'] ) . "\n";
					}
					break;
				case 'image':
					if ( ! empty( $s['image']['url'] ) ) {
						$img  = self::img_tag( $s['image']['url'], isset( $s['image']['id'] ) ? (int) $s['image']['id'] : 0, isset( $s['image']['alt'] ) ? $s['image']['alt'] : '' );
						$out .= ( ! empty( $s['link']['url'] ) ? '<a href="' . esc_url( $s['link']['url'] ) . '">' . $img . '</a>' : $img ) . "\n";
					}
					break;
				case 'image-box':
				case 'icon-box':
					if ( ! empty( $s['image']['url'] ) ) {
						$out .= self::img_tag( $s['image']['url'], isset( $s['image']['id'] ) ? (int) $s['image']['id'] : 0, isset( $s['image']['alt'] ) ? $s['image']['alt'] : '' ) . "\n";
					}
					$tag = isset( $s['title_size'] ) && preg_match( '/^h[1-6]$/', $s['title_size'] ) ? $s['title_size'] : 'h3';
					if ( ! empty( $s['title_text'] ) ) {
						$out .= "<{$tag}>" . $s['title_text'] . "</{$tag}>\n";
					}
					if ( ! empty( $s['description_text'] ) ) {
						$out .= '<p>' . $s['description_text'] . "</p>\n";
					}
					$out .= self::link_tag( isset( $s['link'] ) ? $s['link'] : null );
					break;
				case 'button':
					$out .= self::link_tag( isset( $s['link'] ) ? $s['link'] : null, isset( $s['text'] ) ? $s['text'] : '' ) . "\n";
					break;
				case 'icon-list':
					if ( ! empty( $s['icon_list'] ) && is_array( $s['icon_list'] ) ) {
						$out .= '<ul>';
						foreach ( $s['icon_list'] as $li ) {
							$t    = isset( $li['text'] ) ? $li['text'] : '';
							$out .= '<li>' . ( ! empty( $li['link']['url'] ) ? self::link_tag( $li['link'], wp_strip_all_tags( $t ) ) : $t ) . '</li>';
						}
						$out .= "</ul>\n";
					}
					break;
				case 'accordion':
				case 'toggle':
				case 'tabs':
					if ( ! empty( $s['tabs'] ) && is_array( $s['tabs'] ) ) {
						foreach ( $s['tabs'] as $tab ) {
							if ( ! empty( $tab['tab_title'] ) ) {
								$out .= '<h3>' . $tab['tab_title'] . "</h3>\n";
							}
							if ( ! empty( $tab['tab_content'] ) ) {
								$out .= '<p>' . $tab['tab_content'] . "</p>\n";
							}
						}
					}
					break;
				case 'testimonial':
					if ( ! empty( $s['testimonial_content'] ) ) {
						$out .= '<p>' . $s['testimonial_content'] . "</p>\n";
					}
					break;
				case 'html':
					if ( ! empty( $s['html'] ) ) {
						$out .= $s['html'] . "\n";
					}
					break;
				case 'e-heading':
					$tag = isset( $s['tag'] ) ? self::atomic_text( $s['tag'] ) : 'h2';
					$tag = preg_match( '/^h[1-6]$/', $tag ) ? $tag : 'h2';
					$t   = isset( $s['title'] ) ? self::atomic_text( $s['title'] ) : '';
					if ( '' !== $t ) {
						$out .= "<{$tag}>" . $t . "</{$tag}>\n";
					}
					break;
				case 'e-paragraph':
					$t = isset( $s['paragraph'] ) ? self::atomic_text( $s['paragraph'] ) : '';
					if ( '' !== $t ) {
						$out .= '<p>' . $t . "</p>\n";
					}
					break;
				case 'e-image':
					if ( isset( $s['image'] ) ) {
						list( $id, $url ) = self::atomic_image( $s['image'] );
						$out             .= self::img_tag( $url, $id ) . "\n";
					}
					break;
			}

			if ( ! empty( $el['elements'] ) ) {
				self::walk_html( $el['elements'], $out );
			}
		}
	}

	/* ------------------------------------------------------------ Image alt */

	/**
	 * Set alt for every occurrence of an image (by URL) in the Elementor data.
	 *
	 * @return array|null [ old_alt, attachment_id ] or null if not found.
	 */
	public static function set_image_alt( $post_id, $src, $alt ) {
		$data   = self::get_data( $post_id );
		$found  = false;
		$old    = '';
		$att_id = 0;
		self::walk_alt( $data, $src, $alt, $found, $old, $att_id );
		if ( ! $att_id ) {
			$att_id = (int) attachment_url_to_postid( $src );
		}
		if ( ! $found && ! $att_id ) {
			return null;
		}
		if ( $att_id ) {
			$old = (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true );
			if ( current_user_can( 'edit_post', $att_id ) ) {
				// Elementor shows the media-library alt for images that have an attachment id.
				update_post_meta( $att_id, '_wp_attachment_image_alt', wp_slash( $alt ) );
			}
		}
		if ( $found ) {
			self::save_data( $post_id, $data );
		}
		return array( $old, $att_id );
	}

	private static function walk_alt( &$elements, $src, $alt, &$found, &$old, &$att_id ) {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				$s = &$el['settings'];
				// Classic image / image-box / icon-box widgets.
				if ( isset( $s['image']['url'] ) && is_string( $s['image']['url'] ) && $s['image']['url'] === $src ) {
					$found = true;
					$old   = isset( $s['image']['alt'] ) ? (string) $s['image']['alt'] : '';
					if ( ! empty( $s['image']['id'] ) ) {
						$att_id = (int) $s['image']['id'];
					}
					$s['image']['alt'] = $alt; // Used by Elementor for images inserted from URL.
				}
				// Atomic e-image.
				if ( isset( $el['widgetType'] ) && 'e-image' === $el['widgetType'] && isset( $s['image'] ) ) {
					list( $id, $url ) = self::atomic_image( $s['image'] );
					if ( $url === $src && $id ) {
						$found  = true;
						$att_id = $id;
					}
				}
				// <img> tags inside text / HTML widgets.
				foreach ( array( 'editor', 'html', 'description_text', 'testimonial_content' ) as $k ) {
					if ( isset( $s[ $k ] ) && is_string( $s[ $k ] ) && false !== strpos( $s[ $k ], '<img' ) ) {
						$o     = null;
						$aid   = 0;
						$new   = SSAI_Applier::replace_img_alt( $s[ $k ], $src, $alt, $o, $aid );
						if ( null !== $o ) {
							$found  = true;
							$old    = $o;
							$s[ $k ] = $new;
							if ( $aid ) {
								$att_id = $aid;
							}
						}
					}
				}
				unset( $s );
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_alt( $el['elements'], $src, $alt, $found, $old, $att_id );
			}
		}
		unset( $el );
	}

	/* ------------------------------------------------------------ Text widgets (rewrite) */

	/** Editable text pieces: [ id, el_id, key, label, text ]. Atomic (v4) widgets are read-only. */
	public static function text_items( $post_id ) {
		$items = array();
		self::walk_items( self::get_data( $post_id ), $items );
		return $items;
	}

	private static function walk_items( $elements, &$items ) {
		$map = array(
			'heading'     => array( 'title' => 'تیتر' ),
			'text-editor' => array( 'editor' => 'متن' ),
			'icon-box'    => array(
				'title_text'       => 'عنوان باکس',
				'description_text' => 'توضیح باکس',
			),
			'image-box'   => array(
				'title_text'       => 'عنوان باکس',
				'description_text' => 'توضیح باکس',
			),
			'testimonial' => array( 'testimonial_content' => 'نظر مشتری' ),
		);
		foreach ( (array) $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$type = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
			if ( isset( $map[ $type ], $el['id'] ) ) {
				foreach ( $map[ $type ] as $key => $label ) {
					if ( isset( $el['settings'][ $key ] ) && is_string( $el['settings'][ $key ] ) && '' !== trim( wp_strip_all_tags( $el['settings'][ $key ] ) ) ) {
						$label_full = $label;
						if ( 'heading' === $type ) {
							$label_full .= ' ' . strtoupper( isset( $el['settings']['header_size'] ) ? $el['settings']['header_size'] : 'h2' );
						}
						$items[] = array(
							'id'    => $el['id'] . ':' . $key,
							'label' => $label_full,
							'html'  => $el['settings'][ $key ],
						);
					}
				}
			}
			if ( ! empty( $el['elements'] ) ) {
				self::walk_items( $el['elements'], $items );
			}
		}
	}

	/**
	 * Replace text pieces. $changes = [ 'elId:key' => 'new html' ].
	 *
	 * @return int number of changed pieces
	 */
	public static function apply_text( $post_id, $changes ) {
		$data  = self::get_data( $post_id );
		$count = 0;
		self::walk_apply( $data, $changes, $count );
		if ( $count ) {
			self::save_data( $post_id, $data );
		}
		return $count;
	}

	private static function walk_apply( &$elements, $changes, &$count ) {
		foreach ( $elements as &$el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( isset( $el['id'] ) && isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				foreach ( $el['settings'] as $key => $val ) {
					$k = $el['id'] . ':' . $key;
					if ( isset( $changes[ $k ] ) && is_string( $val ) ) {
						$el['settings'][ $key ] = $changes[ $k ];
						$count++;
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk_apply( $el['elements'], $changes, $count );
			}
		}
		unset( $el );
	}

	/* ------------------------------------------------------------ Editor panel */

	public static function editor_assets() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		SSAI_Admin::enqueue( $post_id, true );
	}
}
