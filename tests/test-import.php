<?php
/**
 * Import CSV : colonnes, validation, doublons, formats Excel et Google Sheets.
 *
 * @package SaitoStoreLocator
 */

require __DIR__ . '/bootstrap.php';

/**
 * Construit un CSV au format de l'export.
 *
 * @param array $rows Lignes.
 * @return string
 */
function saito_sl_test_csv( array $rows ) {
	$lines = array( 'id_store,active,name,city,postcode,address1,latitude,longitude,country,icone' );
	foreach ( $rows as $r ) {
		$lines[] = implode( ',', $r );
	}
	return implode( "\n", $lines ) . "\n";
}

$r = saito_sl_import_csv(
	saito_sl_test_csv(
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
saito_sl_assert( '2 magasins valides sur 5 lignes', 2, count( $r['stores'] ) );
saito_sl_assert( 'ligne inactive écartée', 1, $r['stats']['inactive'] );
saito_sl_assert( 'ligne sans position et doublon ignorés', 2, $r['stats']['skipped'] );
saito_sl_assert( 'zéro initial du code postal restauré', '01000', $r['stores'][1]['postcode'] );
saito_sl_assert( 'virgule décimale acceptée', 46.2, $r['stores'][1]['lat'] );
saito_sl_assert( 'icône normalisée', 'signature', $r['stores'][1]['icone'] );
saito_sl_assert( 'raison explicite pour le magasin étranger sans position', true, false !== strpos( $r['issues'][0]['message'] . $r['issues'][1]['message'], 'France' ) );

$r = saito_sl_import_csv( "name;latitude;longitude\n<b>Été</b>;48,1;2,1\n", false );
saito_sl_assert( 'séparateur point-virgule et balises HTML retirées', array( 1, 'Été' ), array( count( $r['stores'] ), $r['stores'][0]['name'] ) );

$r = saito_sl_import_csv( mb_convert_encoding( "nom;latitude;longitude;ville\nCafé des Arts;45,76;4,83;Lyon\n", 'Windows-1252', 'UTF-8' ), false );
saito_sl_assert( 'CSV Excel en Windows-1252, en-têtes français', array( 'Café des Arts', 'Lyon' ), array( $r['stores'][0]['name'], $r['stores'][0]['city'] ) );
$r = saito_sl_import_csv( "\xEF\xBB\xBFname,latitude,longitude\nÉpicerie,45.76,4.83\n", false );
saito_sl_assert( 'CSV UTF-8 avec BOM', 'Épicerie', $r['stores'][0]['name'] );

$r = saito_sl_import_csv( "name,latitude,longitude,enseigne,services\nInstitut Rose,48.1,2.1,Partenaire,\"Soins visage, Épilation ; Soins visage\"\n", false );
saito_sl_assert( 'colonnes enseigne et services lues (doublons retirés)', array( 'Partenaire', array( 'Soins visage', 'Épilation' ) ), array( $r['stores'][0]['brand'], $r['stores'][0]['services'] ) );

$r = saito_sl_import_csv( "name,latitude,longitude,city,horaires,telephone\nBoutique,48.1,2.1,Paris,\"Mardi-Samedi 10h-19h\",0142000000\n", false );
saito_sl_assert( 'colonne horaires importée', array( 'boutique', 'Mardi-Samedi 10h-19h', array( array( '10:00', '19:00' ) ) ), array( $r['stores'][0]['slug'], $r['stores'][0]['hours_text'], $r['stores'][0]['hours'][1] ) );

$r = saito_sl_import_csv( "name,latitude,longitude\nNulle part,0,0\nTrop loin,95,2\n", false );
saito_sl_assert( 'coordonnées nulles ou hors limites rejetées', array( 0, 2 ), array( count( $r['stores'] ), $r['stats']['skipped'] ) );

$r = saito_sl_import_csv( '<!DOCTYPE html><html>Connexion Google</html>', false );
saito_sl_assert( 'page de connexion Google détectée', true, false !== strpos( $r['fatal'], 'page web' ) );
$r = saito_sl_import_csv( "ville,cp\nParis,75001\n", false );
saito_sl_assert( 'en-tête invalide bloquant', true, false !== strpos( $r['fatal'], 'Colonnes obligatoires' ) );
$r = saito_sl_import_csv( '', false );
saito_sl_assert( 'fichier vide bloquant', 'Le fichier est vide.', $r['fatal'] );

saito_sl_done();
