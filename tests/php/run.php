<?php
/**
 * Tests du back-office (import CSV, synchronisation, URL Google Sheets, géocodage).
 *
 * À lancer UNIQUEMENT sur un site de test (le test de synchronisation remplace la liste des magasins) :
 *   SAITO_SL_TESTS=1 wp eval-file tests/php/run.php
 *
 * Les appels HTTP sont simulés via le filtre pre_http_request : aucun accès réseau.
 *
 * @package SaitoStoreLocator
 */

if ( ! defined( 'ABSPATH' ) || '1' !== getenv( 'SAITO_SL_TESTS' ) ) {
	fwrite( STDERR, "Lancer avec SAITO_SL_TESTS=1 wp eval-file tests/php/run.php (site de test uniquement).\n" );
	exit( 1 );
}

$GLOBALS['saito_sl_failures'] = 0;
$GLOBALS['saito_sl_checks']   = 0;

function saito_sl_assert( $condition, $label ) {
	++$GLOBALS['saito_sl_checks'];
	if ( $condition ) {
		echo "  ok   $label\n";
	} else {
		++$GLOBALS['saito_sl_failures'];
		echo "  FAIL $label\n";
	}
}

/** Simule la prochaine réponse HTTP. */
function saito_sl_mock_http( $code, $body ) {
	remove_all_filters( 'pre_http_request' );
	add_filter(
		'pre_http_request',
		function () use ( $code, $body ) {
			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array(
					'code'    => $code,
					'message' => 'Mock',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
	);
}

function saito_sl_csv( array $rows ) {
	$lines = array( 'id_store,active,name,city,postcode,address1,latitude,longitude,country,icone' );
	foreach ( $rows as $r ) {
		$lines[] = implode( ',', $r );
	}
	return implode( "\n", $lines ) . "\n";
}

// Données de départ : enseignes et magasins de démonstration (tests/fixtures/demo-stores.csv).
update_option( SAITO_SL_OPTION, array_merge( saito_sl_get_settings(), array( 'brands' => "Maison Dupont\nAtelier Martin" ) ) );
$saito_sl_demo = saito_sl_import_csv( (string) file_get_contents( __DIR__ . '/../fixtures/demo-stores.csv' ), false );
saito_sl_save_stores( $saito_sl_demo['stores'], 'upload' );

echo "URL Google Sheets\n";
saito_sl_assert(
	'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/export?format=csv&gid=42' === saito_sl_normalize_sheet_url( 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=42' ),
	'lien d\'édition converti en export CSV avec le bon onglet'
);
saito_sl_assert(
	false !== strpos( saito_sl_normalize_sheet_url( 'https://docs.google.com/spreadsheets/d/e/2PACX-1vABC/pubhtml' ), 'output=csv' ),
	'lien « publier sur le web » forcé en CSV'
);
saito_sl_assert( '' === saito_sl_normalize_sheet_url( 'http://example.com/a.csv' ), 'URL http refusée' );
saito_sl_assert( '' === saito_sl_normalize_sheet_url( 'javascript:alert(1)' ), 'URL javascript: refusée' );

echo "Réglages\n";
$clean = saito_sl_sanitize_settings(
	array(
		'apikey'        => 'abc"><script>',
		'results_count' => '500',
	)
);
saito_sl_assert( 'abcscript' === $clean['apikey'], 'clé API nettoyée' );
saito_sl_assert( 20 === $clean['results_count'], 'nombre de résultats borné à 20' );
saito_sl_assert( 'light' === $clean['theme'], 'thème clair par défaut' );
saito_sl_assert( 'dark' === saito_sl_sanitize_settings( array( 'theme' => 'dark' ) )['theme'], 'thème sombre accepté' );
saito_sl_assert( 'light' === saito_sl_sanitize_settings( array( 'theme' => '"><script>' ) )['theme'], 'thème inconnu rejeté' );

echo "Import CSV\n";
$r = saito_sl_import_csv(
	saito_sl_csv(
		array(
			array( '1', '1', 'Magasin A', 'Paris', '75001', '1 rue A', '48.86', '2.34', 'France', '' ),
			array( '2', 'oui', 'Magasin B', 'Bourg', '1000', '2 rue B', '"46,20"', '"5,22"', '', 'Signature' ),
			array( '3', '0', 'Fermé', 'Paris', '75002', '', '48.87', '2.35', '', '' ),
			array( '4', '1', 'Sans position', 'Bruxelles', '1000', '', '', '', 'Belgique', '' ),
			array( '5', '1', 'Magasin A', 'Paris', '75001', '1 rue A', '48.86', '2.34', 'France', '' ),
		)
	),
	false
);
saito_sl_assert( 2 === count( $r['stores'] ), '2 magasins valides sur 5 lignes' );
saito_sl_assert( 1 === $r['stats']['inactive'], 'ligne inactive écartée' );
saito_sl_assert( 2 === $r['stats']['skipped'], 'ligne sans position et doublon ignorés' );
saito_sl_assert( '01000' === $r['stores'][1]['postcode'], 'zéro initial du code postal restauré' );
saito_sl_assert( 46.2 === $r['stores'][1]['lat'], 'virgule décimale acceptée' );
saito_sl_assert( 'signature' === $r['stores'][1]['icone'], 'icône normalisée' );
saito_sl_assert( false !== strpos( $r['issues'][0]['message'] . $r['issues'][1]['message'], 'France' ), 'raison explicite pour le magasin étranger sans position' );

$r = saito_sl_import_csv( "name;latitude;longitude\n<b>Été</b>;48,1;2,1\n", false );
saito_sl_assert( 1 === count( $r['stores'] ) && 'Été' === $r['stores'][0]['name'], 'séparateur point-virgule et balises HTML retirées' );
$r = saito_sl_import_csv( "name,latitude,longitude,enseigne,services\nInstitut Rose,48.1,2.1,Partenaire,\"Soins visage, Épilation ; Soins visage\"\n", false );
saito_sl_assert( 'Partenaire' === $r['stores'][0]['brand'] && array( 'Soins visage', 'Épilation' ) === $r['stores'][0]['services'], 'colonnes enseigne et services lues (doublons retirés)' );
$clean = saito_sl_sanitize_settings( array( 'brands' => "Maison Dupont\r\n<b>Atelier Martin</b>\n\nMaison Dupont" ) );
saito_sl_assert( "Maison Dupont\nAtelier Martin" === $clean['brands'], 'liste des enseignes nettoyée' );
$r = saito_sl_import_csv( "<!DOCTYPE html><html>Connexion Google</html>", false );
saito_sl_assert( false !== strpos( $r['fatal'], 'page web' ), 'page de connexion Google détectée' );
$r = saito_sl_import_csv( "ville,cp\nParis,75001\n", false );
saito_sl_assert( false !== strpos( $r['fatal'], 'Colonnes obligatoires' ), 'en-tête invalide bloquant' );

echo "Mise en forme des magasins\n";
saito_sl_assert( 'Maison Dupont Le Bouscat' === saito_sl_title_case( 'MAISON DUPONT LE BOUSCAT' ), 'nom en majuscules remis en casse lisible' );
saito_sl_assert( "Villenave d'Ornon" === saito_sl_title_case( 'VILLENAVE D ORNON' ) && 'Saint-Jean-de-Luz' === saito_sl_title_case( 'SAINT-JEAN-DE-LUZ' ), 'règles françaises (élision, mots composés)' );
saito_sl_assert( 'Talence CC' === saito_sl_title_case( 'TALENCE CC' ), 'sigles conservés en majuscules' );
$st = array( 'name' => 'MAISON DUPONT PESSAC', 'brand' => '', 'city' => 'PESSAC', 'id' => '1' );
saito_sl_assert( 'Maison Dupont' === saito_sl_store_brand( $st ) && 'Pessac' === saito_sl_store_short_title( $st ), 'enseigne détectée et nom court' );
saito_sl_assert( '05 56 08 09 10' === saito_sl_format_phone( '0556080910' ) && '+32 2 123 45 67' === saito_sl_format_phone( '+32 2 123 45 67' ), 'téléphone mis en forme' );
$h = saito_sl_parse_hours( "Lun au ven 9h-12h 14h-19h | Sam 9h30-18h; Dimanche fermé" );
saito_sl_assert( array( array( '09:00', '12:00' ), array( '14:00', '19:00' ) ) === $h[0] && array( array( '09:30', '18:00' ) ) === $h[5] && array() === $h[6], 'horaires avec coupure lus jour par jour' );
saito_sl_assert( null === saito_sl_parse_hours( 'Ouvert selon saison' ), 'horaires libres non interprétés (affichés tels quels)' );
$list = array( array( 'name' => 'Magasin Été', 'city' => 'Lyon', 'id' => '1' ), array( 'name' => 'MAGASIN ÉTÉ', 'city' => 'Paris', 'id' => '2' ), array( 'name' => 'Magasin Été', 'city' => 'Paris', 'id' => '3' ) );
saito_sl_assign_slugs( $list );
saito_sl_assert( array( 'magasin-ete', 'magasin-ete-paris', 'magasin-ete-2' ) === array_column( $list, 'slug' ), 'identifiants d\'URL uniques et lisibles' );
$r = saito_sl_import_csv( "name,latitude,longitude,city,horaires,telephone\nBoutique,48.1,2.1,Paris,\"Mardi-Samedi 10h-19h\",0142000000\n", false );
saito_sl_assert( 'boutique' === $r['stores'][0]['slug'] && 'Mardi-Samedi 10h-19h' === $r['stores'][0]['hours_text'] && array( array( '10:00', '19:00' ) ) === $r['stores'][0]['hours'][1], 'colonne horaires importée' );
$desc = saito_sl_store_seo_description( array( 'name' => 'MAISON DUPONT PESSAC', 'brand' => '', 'address1' => '1 av. Eiffel', 'address2' => '', 'postcode' => '33600', 'city' => 'PESSAC', 'country' => 'France', 'hours_text' => 'Lun-Sam 9h-19h', 'phone' => '0556000000' ) );
saito_sl_assert( false !== strpos( $desc, '33600 Pessac' ) && false !== strpos( $desc, 'Lun-Sam 9h-19h' ) && false !== strpos( $desc, '05 56 00 00 00' ), 'description SEO de la fiche (adresse, horaires, téléphone)' );

$schema = saito_sl_store_schema( array( 'name' => 'MAISON DUPONT PESSAC', 'brand' => '', 'slug' => 'maison-dupont-pessac', 'address1' => '1 av. Eiffel', 'address2' => '', 'postcode' => '33600', 'city' => 'PESSAC', 'country' => 'France', 'lat' => 44.78, 'lng' => -0.63, 'phone' => '', 'website' => '', 'icone' => '' ) );
saito_sl_assert( isset( $schema['makesOffer']['itemOffered']['brand']['name'] ) && saito_sl_own_brand() === $schema['makesOffer']['itemOffered']['brand']['name'] && 'Maison Dupont' === $schema['brand']['name'], 'données structurées : le revendeur vend la marque « ' . saito_sl_own_brand() . ' »' );

echo "Géocodage (réponse simulée)\n";
saito_sl_mock_http( 200, wp_json_encode( array( 'features' => array( array( 'geometry' => array( 'coordinates' => array( 6.1294, 45.8992 ) ), 'properties' => array( 'score' => 0.92 ) ) ) ) ) );
$r = saito_sl_import_csv( saito_sl_csv( array( array( '9', '1', 'Annecy test ' . wp_generate_password( 6, false ), 'Annecy', '74000', '1 rue Royale ' . wp_rand(), '', '', 'France', '' ) ) ), true );
saito_sl_assert( 1 === count( $r['stores'] ) && 45.8992 === $r['stores'][0]['lat'] && 1 === $r['stats']['geocoded'], 'position calculée depuis l\'adresse' );
saito_sl_assert( 'warning' === $r['issues'][0]['level'], 'avertissement « vérifiez la position »' );

echo "Synchronisation planifiée\n";
$before = saito_sl_read_stores_file( saito_sl_stores_path() );
update_option( SAITO_SL_OPTION, array_merge( saito_sl_get_settings(), array( 'sheet_url' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/export?format=csv&gid=0' ) ) );

saito_sl_mock_http( 200, saito_sl_csv( array( array( '1', '1', 'Seul magasin', 'Paris', '75001', '', '48.86', '2.34', '', '' ) ) ) );
saito_sl_cron_sync();
$status = get_option( 'saito_sl_sync_status' );
saito_sl_assert( ! $status['ok'] && false !== strpos( $status['message'], 'bloquée par sécurité' ), 'feuille tronquée : synchronisation bloquée' );

$GLOBALS['saito_sl_mails'] = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['saito_sl_mails'][] = $atts;
		return true;
	},
	10,
	2
);
delete_option( 'saito_sl_last_alert' );
update_option( SAITO_SL_OPTION, array_merge( saito_sl_get_settings(), array( 'alert_email' => 'alerte@example.com' ) ) );
saito_sl_mock_http( 403, 'Forbidden' );
saito_sl_cron_sync();
saito_sl_cron_sync();
saito_sl_assert( 1 === count( $GLOBALS['saito_sl_mails'] ) && 'alerte@example.com' === $GLOBALS['saito_sl_mails'][0]['to'] && false !== strpos( $GLOBALS['saito_sl_mails'][0]['message'], 'partagée' ), 'échec : une seule alerte e-mail par jour, avec la raison' );
saito_sl_mock_http( 403, 'Forbidden' );
saito_sl_cron_sync();
$status = get_option( 'saito_sl_sync_status' );
saito_sl_assert( ! $status['ok'] && false !== strpos( $status['message'], 'partagée' ), 'feuille privée : message explicite' );

$rows = array();
foreach ( $before as $i => $s ) {
	$rows[] = array( $s['id'], '1', '"' . str_replace( '"', '""', $s['name'] ) . '"', '"' . $s['city'] . '"', $s['postcode'], '"' . str_replace( '"', '""', $s['address1'] ) . '"', $s['lat'], $s['lng'], '"' . $s['country'] . '"', $s['icone'] );
}
saito_sl_mock_http( 200, saito_sl_csv( $rows ) );
saito_sl_cron_sync();
$status = get_option( 'saito_sl_sync_status' );
saito_sl_assert( $status['ok'], 'feuille complète : synchronisation appliquée (' . $status['message'] . ')' );

remove_all_filters( 'pre_http_request' );
echo "\n{$GLOBALS['saito_sl_checks']} vérifications, {$GLOBALS['saito_sl_failures']} échec(s)\n";
exit( $GLOBALS['saito_sl_failures'] ? 1 : 0 );
