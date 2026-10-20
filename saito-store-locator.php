<?php
/**
 * Plugin Name: Saito Store Locator
 * Plugin URI: https://github.com/Lucas-tsl/saito-store-locator
 * Description: Carte des points de vente : recherche par ville, code postal, nom ou position, fiches magasin indexables, import CSV et synchronisation Google Sheets.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Troteseil Lucas
 * Author URI: https://github.com/Lucas-tsl
 * Text Domain: saito-store-locator
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

define( 'SAITO_SL_VERSION', '1.0.0' );
define( 'SAITO_SL_FILE', __FILE__ );
define( 'SAITO_SL_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAITO_SL_URL', plugin_dir_url( __FILE__ ) );
define( 'SAITO_SL_OPTION', 'saito_sl_settings' );
define( 'SAITO_SL_CAP', 'manage_options' );
define( 'SAITO_SL_CRON_HOOK', 'saito_sl_daily_sync' );

// Menu « Saito » commun aux plugins Saito (voir includes/saito-hub/hub.php) :
// les pages du store locator y apparaissent en sous-menus.
require_once SAITO_SL_DIR . 'includes/saito-hub/load.php';
add_filter( 'saito_hub_plugins', 'saito_sl_register_in_saito_hub' );

/**
 * Déclaration auprès du menu Saito (tableau de bord, documentation).
 *
 * @param array $plugins Plugins Saito actifs.
 * @return array
 */
function saito_sl_register_in_saito_hub( $plugins ) {
	$plugins['store-locator'] = array(
		'version'      => SAITO_SL_VERSION,
		'settings_url' => admin_url( 'admin.php?page=saito-store-locator' ),
		'menu_slugs'   => array( 'saito-store-locator', 'saito-store-locator-settings' ),
	);
	return $plugins;
}

require_once SAITO_SL_DIR . 'includes/settings.php';
require_once SAITO_SL_DIR . 'includes/store.php';
require_once SAITO_SL_DIR . 'includes/storage.php';
require_once SAITO_SL_DIR . 'includes/import.php';
require_once SAITO_SL_DIR . 'includes/sync.php';
require_once SAITO_SL_DIR . 'includes/frontend.php';
require_once SAITO_SL_DIR . 'includes/seo.php';

if ( is_admin() ) {
	require_once SAITO_SL_DIR . 'includes/admin.php';
}

register_activation_hook( __FILE__, 'saito_sl_activate' );
register_deactivation_hook( __FILE__, 'saito_sl_deactivate' );

/**
 * Activation : crée les réglages par défaut et programme la synchronisation si besoin.
 */
function saito_sl_activate() {
	if ( false === get_option( SAITO_SL_OPTION, false ) ) {
		add_option( SAITO_SL_OPTION, saito_sl_default_settings() );
	}
	saito_sl_reschedule_sync();
}

/**
 * Désactivation : retire la tâche planifiée. Les données (réglages, magasins) sont conservées.
 */
function saito_sl_deactivate() {
	wp_clear_scheduled_hook( SAITO_SL_CRON_HOOK );
}
