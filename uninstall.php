<?php
/**
 * Désinstallation : supprime les réglages, la tâche planifiée, les caches et le dossier
 * des magasins (wp-content/uploads/saito-store-locator/). Appelé par WordPress
 * uniquement à la suppression du plugin (pas à sa désactivation).
 *
 * @package SaitoStoreLocator
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Réglages partagés de Saito > Outils : supprimés seulement avec le dernier plugin Saito.
require __DIR__ . '/includes/saito-hub/uninstall.php';

wp_clear_scheduled_hook( 'saito_sl_daily_sync' );

foreach ( array( 'saito_sl_settings', 'saito_sl_meta', 'saito_sl_sync_status', 'saito_sl_last_alert', 'saito_sl_page_id' ) as $saito_sl_option ) {
	delete_option( $saito_sl_option );
}

// Caches de géocodage, imports en attente et messages (transients préfixés saito_sl_).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- nettoyage ponctuel à la désinstallation, sans API dédiée.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_saito_sl_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_saito_sl_' ) . '%'
	)
);

// Fichier des magasins et sauvegardes.
$saito_sl_uploads = wp_upload_dir( null, false );
$saito_sl_dir     = trailingslashit( $saito_sl_uploads['basedir'] ) . 'saito-store-locator';
if ( is_dir( $saito_sl_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $saito_sl_dir, true );
	}
}
