<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version history: read from the "== Changelog ==" section of readme.txt, shown on its own page,
 * and summarised once after each update.
 */
class SSAI_Changelog {

	const SLUG = 'smart-seo-ai-changelog';
	const SEEN = 'ssai_seen_version';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
		add_action( 'admin_notices', array( __CLASS__, 'whats_new' ) );
	}

	/**
	 * @param string $text readme.txt content.
	 * @return array version => [ 'date' => 'Y-m-d'|'', 'items' => string[] ] (newest first).
	 */
	public static function parse( $text ) {
		$text = (string) $text;
		$p    = strpos( $text, '== Changelog ==' );
		if ( false === $p ) {
			return array();
		}
		$out = array();
		$cur = null;
		foreach ( preg_split( '/\r?\n/', substr( $text, $p + 15 ) ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^==[^=]/', $line ) ) {
				break; // Next section.
			}
			if ( preg_match( '/^=\s*v?(\d+(?:\.\d+)+)\s*(?:[—–-]\s*(\d{4}-\d{2}-\d{2}))?\s*=$/u', $line, $m ) ) {
				$cur         = $m[1];
				$out[ $cur ] = array( 'date' => isset( $m[2] ) ? $m[2] : '', 'items' => array() );
			} elseif ( null !== $cur && preg_match( '/^[-*]\s*(.+)$/u', $line, $m ) ) {
				$out[ $cur ]['items'][] = $m[1];
			}
		}
		return $out;
	}

	public static function local() {
		$f = SSAI_DIR . 'readme.txt';
		return is_readable( $f ) ? self::parse( (string) file_get_contents( $f ) ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public static function menu() {
		add_submenu_page( 'smart-seo-ai', 'تاریخچه نسخه‌ها', 'تاریخچه نسخه‌ها', 'ssai_pages', self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function date_fa( $d ) {
		return '' === $d ? '' : wp_date( 'j F Y', strtotime( $d . ' 12:00:00 UTC' ) );
	}

	/** One-time summary after the plugin was updated. */
	public static function whats_new() {
		if ( ! current_user_can( 'ssai_pages' ) || ! SSAI_UI::ours() ) {
			return;
		}
		$seen = (string) get_option( self::SEEN, '' );
		if ( SSAI_VERSION === $seen ) {
			return;
		}
		update_option( self::SEEN, SSAI_VERSION, false );
		if ( '' === $seen ) {
			return; // First install: nothing to compare with.
		}
		$items = array();
		foreach ( self::local() as $v => $c ) {
			if ( version_compare( $v, $seen, '>' ) && version_compare( $v, SSAI_VERSION, '<=' ) ) {
				foreach ( $c['items'] as $it ) {
					$items[] = $it;
				}
			}
		}
		if ( ! $items ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible" dir="rtl"><p><strong>Smart SEO AI به نسخه ' . esc_html( SSAI_VERSION ) . ' به‌روز شد.</strong> تغییرات:</p><ul style="list-style:disc;padding-right:20px">';
		foreach ( array_slice( $items, 0, 8 ) as $it ) {
			echo '<li>' . esc_html( $it ) . '</li>';
		}
		echo '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">همه تاریخچه نسخه‌ها ←</a></p></div>';
	}

	public static function page() {
		if ( ! current_user_can( 'ssai_pages' ) ) {
			return;
		}
		$log = self::local();
		?>
		<div class="wrap ssai" dir="rtl">
			<h1>تاریخچه نسخه‌ها</h1>
			<p class="description">همه تغییرات افزونه، از جدید به قدیم. نسخه نصب‌شده: <strong><?php echo esc_html( SSAI_VERSION ); ?></strong>.
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=smart-seo-ai-updates' ) ); ?>">بررسی نسخه جدید ←</a>
				<?php endif; ?>
			</p>
			<?php if ( ! $log ) : ?>
				<div class="ssai-card"><p>تاریخچه‌ای پیدا نشد.</p></div>
			<?php endif; ?>
			<?php foreach ( $log as $v => $c ) : ?>
				<div class="ssai-card">
					<h2 style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
						<span dir="ltr">نسخه <?php echo esc_html( $v ); ?></span>
						<?php if ( SSAI_VERSION === $v ) : ?><span class="ssai-pos ssai-risk-good">نصب‌شده</span><?php endif; ?>
						<span class="description" style="font-weight:400;font-size:13px"><?php echo esc_html( self::date_fa( $c['date'] ) ); ?></span>
					</h2>
					<ul style="list-style:disc;padding-right:20px;margin:0">
						<?php foreach ( $c['items'] as $it ) : ?>
							<li><?php echo esc_html( $it ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
