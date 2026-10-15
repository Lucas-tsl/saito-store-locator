<?php
/**
 * Réglages : URL Google Sheets et nettoyage des valeurs saisies.
 *
 * @package SaitoStoreLocator
 */

require __DIR__ . '/bootstrap.php';

saito_sl_assert(
	'lien d\'édition converti en export CSV avec le bon onglet',
	'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/export?format=csv&gid=42',
	saito_sl_normalize_sheet_url( 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=42' )
);
saito_sl_assert( 'lien « publier sur le web » forcé en CSV', true, false !== strpos( saito_sl_normalize_sheet_url( 'https://docs.google.com/spreadsheets/d/e/2PACX-1vABC/pubhtml' ), 'output=csv' ) );
saito_sl_assert( 'URL http refusée', '', saito_sl_normalize_sheet_url( 'http://example.com/a.csv' ) );
saito_sl_assert( 'URL javascript: refusée', '', saito_sl_normalize_sheet_url( 'javascript:alert(1)' ) );

$clean = saito_sl_sanitize_settings(
	array(
		'apikey'        => 'abc"><script>',
		'results_count' => '500',
		'highlight'     => '<b>Conseil</b> personnalisé',
	)
);
saito_sl_assert( 'clé API nettoyée', 'abcscript', $clean['apikey'] );
saito_sl_assert( 'nombre de résultats borné à 20', 20, $clean['results_count'] );
saito_sl_assert( 'thème clair par défaut', 'light', $clean['theme'] );
saito_sl_assert( 'libellé mis en avant nettoyé', 'Conseil personnalisé', $clean['highlight'] );
saito_sl_assert( 'thème sombre accepté', 'dark', saito_sl_sanitize_settings( array( 'theme' => 'dark' ) )['theme'] );
saito_sl_assert( 'thème inconnu rejeté', 'light', saito_sl_sanitize_settings( array( 'theme' => '"><script>' ) )['theme'] );
saito_sl_assert( 'liste des enseignes nettoyée et dédoublonnée', "Maison Dupont\nAtelier Martin", saito_sl_sanitize_settings( array( 'brands' => "Maison Dupont\r\n<b>Atelier Martin</b>\n\nMaison Dupont" ) )['brands'] );
saito_sl_assert( 'synchronisation automatique impossible sans feuille', 0, saito_sl_sanitize_settings( array( 'sync_auto' => '1' ) )['sync_auto'] );

$defaults = saito_sl_default_settings();
saito_sl_assert( 'aucune marque ni enseigne par défaut', array( '', '' ), array( $defaults['own_brand'], $defaults['brands'] ) );
saito_sl_assert( 'alertes envoyées à l\'administrateur du site par défaut', 'admin@example.com', $defaults['alert_email'] );
saito_sl_assert( 'marque vendue : nom du site si le réglage est vide', 'Boutique Exemple', saito_sl_own_brand() );

saito_sl_done();
