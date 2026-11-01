<?php
/**
 * Plugin Name:       Smart SEO AI Assistant
 * Description:       ساخت NFPSMART — افزونه کامل سئو با هوش مصنوعی رایگان: عنوان و متا، Schema (کسب‌وکار، مقاله، محصول ووکامرس)، نقشه سایت، ریدایرکت و ۴۰۴، Breadcrumb، بررسی محتوا، AEO/GEO، سرچ کنسول و بک‌لینک؛ سازگار با المنتور و WPML/Polylang؛ جایگزین Rank Math با انتقال اطلاعات. همه تغییرات فقط با تأیید مدیر.
 * Version:           2.3.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ARST (NFPSMART)
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-seo-ai
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SSAI_VERSION', '2.3.3' );
define( 'SSAI_FILE', __FILE__ );
define( 'SSAI_DIR', plugin_dir_path( __FILE__ ) );
define( 'SSAI_URL', plugin_dir_url( __FILE__ ) );

require_once SSAI_DIR . 'includes/class-ssai-settings.php';
require_once SSAI_DIR . 'includes/class-ssai-content.php';
require_once SSAI_DIR . 'includes/class-ssai-ai-client.php';
require_once SSAI_DIR . 'includes/class-ssai-analyzer.php';
require_once SSAI_DIR . 'includes/class-ssai-applier.php';
require_once SSAI_DIR . 'includes/class-ssai-admin.php';
require_once SSAI_DIR . 'includes/class-ssai-frontend.php';
require_once SSAI_DIR . 'includes/class-ssai-elementor.php';
require_once SSAI_DIR . 'includes/class-ssai-guidelines.php';
require_once SSAI_DIR . 'includes/class-ssai-gsc.php';
require_once SSAI_DIR . 'includes/class-ssai-report.php';
require_once SSAI_DIR . 'includes/class-ssai-gsc-import.php';
require_once SSAI_DIR . 'includes/class-ssai-health.php';
require_once SSAI_DIR . 'includes/class-ssai-site.php';
require_once SSAI_DIR . 'includes/class-ssai-aeo.php';
require_once SSAI_DIR . 'includes/class-ssai-seo.php';
require_once SSAI_DIR . 'includes/class-ssai-schema.php';
require_once SSAI_DIR . 'includes/class-ssai-sitemap.php';
require_once SSAI_DIR . 'includes/class-ssai-redirects.php';
require_once SSAI_DIR . 'includes/class-ssai-permalinks.php';
require_once SSAI_DIR . 'includes/class-ssai-migrate.php';
require_once SSAI_DIR . 'includes/class-ssai-seo-admin.php';
require_once SSAI_DIR . 'includes/class-ssai-dashboard.php';
require_once SSAI_DIR . 'includes/class-ssai-schema-builder.php';
require_once SSAI_DIR . 'includes/class-ssai-linkfix.php';
require_once SSAI_DIR . 'includes/class-ssai-ahrefs.php';
require_once SSAI_DIR . 'includes/class-ssai-bulk-schema.php';

register_activation_hook(
	__FILE__,
	function () {
		SSAI_Settings::activate();
		SSAI_Redirects::install();
		update_option( 'ssai_flush_rules', 1, true );
	}
);
register_deactivation_hook( __FILE__, array( 'SSAI_Guidelines', 'deactivate' ) );

add_action(
	'plugins_loaded',
	function () {
		SSAI_Settings::init();
		SSAI_Admin::init();
		// SSAI_Frontend (simple meta output of 1.x) is replaced by SSAI_SEO.
		SSAI_Elementor::init();
		SSAI_Guidelines::init();
		SSAI_GSC::init();
		SSAI_Report::init();
		SSAI_GSC_Import::init();
		SSAI_Health::init();
		SSAI_Site::init();
		SSAI_AEO::init();
		SSAI_SEO::init();
		SSAI_Schema::init();
		SSAI_Sitemap::init();
		SSAI_Redirects::init();
		SSAI_Permalinks::init();
		SSAI_Migrate::init();
		SSAI_SEO_Admin::init();
		SSAI_Schema_Builder::init();
		SSAI_Linkfix::init();
		SSAI_Ahrefs::init();
		SSAI_Bulk_Schema::init();
	}
);
