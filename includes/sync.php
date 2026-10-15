<?php
/**
 * Synchronisation avec une feuille Google Sheets (export CSV), manuelle ou quotidienne.
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

/**
 * En dessous de ce ratio (nouveaux / actuels), la synchronisation automatique refuse d'appliquer
 * la nouvelle liste : protège contre une feuille vidée ou tronquée par erreur.
 */
define( 'SAITO_SL_SYNC_MIN_RATIO', 0.5 );

add_action( SAITO_SL_CRON_HOOK, 'saito_sl_cron_sync' );

/**
 * (Re)programme la synchronisation quotidienne selon les réglages.
 */
function saito_sl_reschedule_sync() {
	wp_clear_scheduled_hook( SAITO_SL_CRON_HOOK );
	$settings = saito_sl_get_settings();
	if ( ! empty( $settings['sync_auto'] ) && ! empty( $settings['sheet_url'] ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SAITO_SL_CRON_HOOK );
	}
}

/**
 * Télécharge le CSV de la feuille configurée.
 *
 * @return string|WP_Error
 */
function saito_sl_fetch_sheet() {
	$settings = saito_sl_get_settings();
	if ( empty( $settings['sheet_url'] ) ) {
		return new WP_Error( 'saito_sl_no_url', __( 'Aucune feuille Google Sheets n\'est configurée (onglet Paramètres).', 'saito-store-locator' ) );
	}

	$response = wp_safe_remote_get(
		$settings['sheet_url'],
		array(
			'timeout'             => 20,
			'redirection'         => 5,
			'limit_response_size' => SAITO_SL_MAX_CSV_BYTES + 1,
		)
	);
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'saito_sl_http', __( 'Impossible de joindre la feuille Google Sheets : ', 'saito-store-locator' ) . $response->get_error_message() );
	}
	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		$hint = in_array( $code, array( 401, 403, 404 ), true ) ? ' ' . __( 'Vérifiez que la feuille est partagée en lecture (« Tous les utilisateurs disposant du lien »).', 'saito-store-locator' ) : '';
		/* translators: %d: code de réponse HTTP. */
		return new WP_Error( 'saito_sl_http_code', sprintf( __( 'La feuille Google Sheets a répondu avec le code %d.', 'saito-store-locator' ), $code ) . $hint );
	}
	return wp_remote_retrieve_body( $response );
}

/**
 * Enregistre l'état de la dernière synchronisation.
 *
 * @param bool   $ok      Succès.
 * @param string $message Message.
 */
function saito_sl_set_sync_status( $ok, $message ) {
	update_option(
		'saito_sl_sync_status',
		array(
			'time'    => time(),
			'ok'      => (bool) $ok,
			'message' => $message,
		),
		false
	);
}

/**
 * Échec de la synchronisation automatique : état enregistré et alerte par e-mail
 * (au plus une par 24 h, renvoyée dès la première erreur qui suit un succès).
 *
 * @param string $message Message.
 */
function saito_sl_sync_failed( $message ) {
	saito_sl_set_sync_status( false, $message );

	$settings = saito_sl_get_settings();
	$to       = $settings['alert_email'];
	$last     = (int) get_option( 'saito_sl_last_alert', 0 );
	if ( ! $to || ( $last && time() - $last < DAY_IN_SECONDS ) ) {
		return;
	}
	/* translators: %s: nom du site. */
	$subject = sprintf( __( '[%s] Échec de la synchronisation des magasins', 'saito-store-locator' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	$body    = __( 'La mise à jour automatique des magasins depuis la feuille Google Sheets a échoué.', 'saito-store-locator' ) . "\n\n"
		/* translators: %s: raison de l'échec. */
		. sprintf( __( 'Raison : %s', 'saito-store-locator' ), $message ) . "\n\n"
		. __( 'La liste actuellement en ligne n\'a pas été modifiée.', 'saito-store-locator' ) . "\n\n"
		/* translators: %s: adresse de la page d'administration des magasins. */
		. sprintf( __( 'Voir le détail et relancer la synchronisation : %s', 'saito-store-locator' ), admin_url( 'admin.php?page=saito-store-locator' ) ) . "\n\n"
		. __( 'Vous recevrez au plus une alerte par jour tant que le problème persiste.', 'saito-store-locator' ) . "\n";
	if ( wp_mail( $to, $subject, $body ) ) {
		update_option( 'saito_sl_last_alert', time(), false );
	}
}

/**
 * Synchronisation planifiée : applique directement la feuille si elle est saine.
 */
function saito_sl_cron_sync() {
	$content = saito_sl_fetch_sheet();
	if ( is_wp_error( $content ) ) {
		saito_sl_sync_failed( $content->get_error_message() );
		return;
	}

	$result = saito_sl_import_csv( $content );
	if ( $result['fatal'] ) {
		saito_sl_sync_failed( $result['fatal'] . ' ' . __( 'La liste actuelle a été conservée.', 'saito-store-locator' ) );
		return;
	}

	$current = count( saito_sl_get_stores() );
	$new     = count( $result['stores'] );
	if ( $current > 0 && $new < $current * SAITO_SL_SYNC_MIN_RATIO ) {
		saito_sl_sync_failed(
			/* translators: 1: nombre de magasins dans la feuille, 2: nombre de magasins en ligne. */
			sprintf( __( 'La feuille ne contient plus que %1$d magasins valides contre %2$d actuellement : synchronisation automatique bloquée par sécurité. Vérifiez la feuille puis lancez « Synchroniser maintenant » pour valider manuellement.', 'saito-store-locator' ), $new, $current )
		);
		return;
	}

	$saved = saito_sl_save_stores( $result['stores'], 'sync' );
	if ( is_wp_error( $saved ) ) {
		saito_sl_sync_failed( $saved->get_error_message() );
		return;
	}

	$skipped = $result['stats']['skipped'];
	delete_option( 'saito_sl_last_alert' );
	if ( $skipped ) {
		/* translators: 1: nombre de magasins importés, 2: nombre de lignes ignorées. */
		$status = sprintf( __( '%1$d magasins importés, %2$d lignes ignorées (lancez « Synchroniser maintenant » pour voir le détail).', 'saito-store-locator' ), $new, $skipped );
	} else {
		/* translators: %d: nombre de magasins importés. */
		$status = sprintf( __( '%d magasins importés.', 'saito-store-locator' ), $new );
	}
	saito_sl_set_sync_status( true, $status );
}
