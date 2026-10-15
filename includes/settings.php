<?php
/**
 * Réglages du plugin, stockés dans l'option WordPress SAITO_SL_OPTION.
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs par défaut des réglages.
 *
 * @return array
 */
function saito_sl_default_settings() {
	return array(
		'apikey'        => '',
		'results_count' => 4,
		'sheet_url'     => '',
		'sync_auto'     => 0,
		'jsonld'        => 1,
		'own_brand'     => '',
		'alert_email'   => (string) get_option( 'admin_email' ),
		'theme'         => 'light',
		'filters'       => 1,
		'brands'        => '',
		'highlight'     => '',
	);
}

/**
 * Réglages courants, complétés par les valeurs par défaut.
 *
 * @return array
 */
function saito_sl_get_settings() {
	$saved = get_option( SAITO_SL_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, saito_sl_default_settings() );
}

/**
 * Nettoie les réglages soumis par le formulaire (callback de register_setting).
 *
 * @param mixed $input Valeurs brutes.
 * @return array
 */
function saito_sl_sanitize_settings( $input ) {
	$input    = is_array( $input ) ? $input : array();
	$current  = saito_sl_get_settings();
	$defaults = saito_sl_default_settings();
	$clean    = array();

	// Clé MapTiler : caractères alphanumériques uniquement.
	$clean['apikey'] = isset( $input['apikey'] ) ? preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $input['apikey'] ) : $current['apikey'];

	$count                  = isset( $input['results_count'] ) ? absint( $input['results_count'] ) : $defaults['results_count'];
	$clean['results_count'] = max( 1, min( 20, $count ? $count : $defaults['results_count'] ) );

	$clean['sheet_url'] = '';
	if ( ! empty( $input['sheet_url'] ) ) {
		$url = saito_sl_normalize_sheet_url( trim( (string) $input['sheet_url'] ) );
		if ( $url ) {
			$clean['sheet_url'] = $url;
		} elseif ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( SAITO_SL_OPTION, 'saito_sl_sheet_url', __( 'L\'adresse de la feuille Google Sheets doit être une URL https valide.', 'saito-store-locator' ) );
		}
	}

	$clean['sync_auto'] = ! empty( $input['sync_auto'] ) && '' !== $clean['sheet_url'] ? 1 : 0;
	$clean['jsonld']    = ! empty( $input['jsonld'] ) ? 1 : 0;
	$clean['own_brand'] = isset( $input['own_brand'] ) ? sanitize_text_field( $input['own_brand'] ) : $current['own_brand'];

	$clean['alert_email'] = '';
	if ( ! empty( $input['alert_email'] ) ) {
		$clean['alert_email'] = (string) sanitize_email( $input['alert_email'] );
		if ( '' === $clean['alert_email'] && function_exists( 'add_settings_error' ) ) {
			add_settings_error( SAITO_SL_OPTION, 'saito_sl_alert_email', __( 'L\'adresse e-mail d\'alerte n\'est pas valide.', 'saito-store-locator' ) );
		}
	}
	$clean['filters']   = ! empty( $input['filters'] ) ? 1 : 0;
	$brands             = isset( $input['brands'] ) ? preg_split( '/\r\n|\r|\n/', (string) $input['brands'] ) : array();
	$brands             = array_slice( array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $brands ) ) ) ), 0, 30 );
	$clean['brands']    = implode( "\n", $brands );
	$clean['highlight'] = isset( $input['highlight'] ) ? sanitize_text_field( $input['highlight'] ) : $current['highlight'];
	$clean['theme']     = isset( $input['theme'] ) && in_array( $input['theme'], array( 'dark', 'light' ), true ) ? $input['theme'] : $defaults['theme'];

	return $clean;
}

/**
 * Libellé du service associé aux magasins mis en avant (colonne icone = « signature »),
 * ex. « Conseil personnalisé ». Vide : marqueur distinct, sans service affiché.
 *
 * @return string
 */
function saito_sl_highlight_label() {
	$settings = saito_sl_get_settings();
	return trim( (string) $settings['highlight'] );
}

/**
 * Transforme une URL Google Sheets (édition ou publication) en URL d'export CSV.
 *
 * @param string $url URL saisie.
 * @return string URL https d'export, ou chaîne vide si invalide.
 */
function saito_sl_normalize_sheet_url( $url ) {
	$url = esc_url_raw( $url, array( 'https' ) );
	if ( '' === $url || 0 !== strpos( $url, 'https://' ) ) {
		return '';
	}

	// Lien « Publier sur le web » (/d/e/<ID>/pub) : forcer le format CSV.
	if ( preg_match( '#^https://docs\.google\.com/spreadsheets/d/e/[A-Za-z0-9_-]+/pub#', $url ) ) {
		if ( false === strpos( $url, 'output=csv' ) ) {
			$url = add_query_arg( 'output', 'csv', remove_query_arg( 'output', $url ) );
		}
		return $url;
	}

	// Lien d'édition https://docs.google.com/spreadsheets/d/<ID>/edit#gid=<GID>  →  export CSV.
	if ( preg_match( '#^https://docs\.google\.com/spreadsheets/d/([A-Za-z0-9_-]{20,})#', $url, $m ) ) {
		$gid = '0';
		if ( preg_match( '/[#&?]gid=(\d+)/', $url, $g ) ) {
			$gid = $g[1];
		}
		return 'https://docs.google.com/spreadsheets/d/' . $m[1] . '/export?format=csv&gid=' . $gid;
	}

	return $url;
}

/**
 * Reprogramme la synchronisation quand les réglages changent.
 */
add_action( 'update_option_' . SAITO_SL_OPTION, 'saito_sl_reschedule_sync' );
add_action( 'add_option_' . SAITO_SL_OPTION, 'saito_sl_reschedule_sync' );
