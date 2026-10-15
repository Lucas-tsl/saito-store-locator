<?php
/**
 * Mise en forme des magasins : casse, enseigne, téléphone, horaires, identifiants d'URL, SEO.
 *
 * @package SaitoStoreLocator
 */

require __DIR__ . '/bootstrap.php';

update_option( SAITO_SL_OPTION, array( 'brands' => "Maison Dupont\nAtelier Martin" ) );

saito_sl_assert( 'nom en majuscules remis en casse lisible', 'Maison Dupont Le Bouscat', saito_sl_title_case( 'MAISON DUPONT LE BOUSCAT' ) );
saito_sl_assert( 'élision sans apostrophe', "Villenave d'Ornon", saito_sl_title_case( 'VILLENAVE D ORNON' ) );
saito_sl_assert( 'mots composés', 'Saint-Jean-de-Luz', saito_sl_title_case( 'SAINT-JEAN-DE-LUZ' ) );
saito_sl_assert( 'sigles conservés en majuscules', 'Talence CC', saito_sl_title_case( 'TALENCE CC' ) );
saito_sl_assert( 'casse mixte inchangée', 'iBoutique Lyon', saito_sl_title_case( 'iBoutique Lyon' ) );

$store = array(
	'name'  => 'MAISON DUPONT PESSAC',
	'brand' => '',
	'city'  => 'PESSAC',
	'id'    => '1',
);
saito_sl_assert( 'enseigne détectée depuis le nom', 'Maison Dupont', saito_sl_store_brand( $store ) );
saito_sl_assert( 'nom court sans l\'enseigne', 'Pessac', saito_sl_store_short_title( $store ) );
saito_sl_assert( 'colonne enseigne prioritaire', 'Réseau Sud', saito_sl_store_brand( array_merge( $store, array( 'brand' => 'Réseau Sud' ) ) ) );

saito_sl_assert( 'téléphone français mis en forme', '05 56 08 09 10', saito_sl_format_phone( '0556080910' ) );
saito_sl_assert( 'téléphone international inchangé', '+32 2 123 45 67', saito_sl_format_phone( '+32 2 123 45 67' ) );

$hours = saito_sl_parse_hours( 'Lun au ven 9h-12h 14h-19h | Sam 9h30-18h; Dimanche fermé' );
saito_sl_assert( 'horaires avec coupure (lundi)', array( array( '09:00', '12:00' ), array( '14:00', '19:00' ) ), $hours[0] );
saito_sl_assert( 'horaires du samedi', array( array( '09:30', '18:00' ) ), $hours[5] );
saito_sl_assert( 'dimanche fermé', array(), $hours[6] );
saito_sl_assert( 'tous les jours', array( array( '10:00', '20:00' ) ), saito_sl_parse_hours( 'Tous les jours 10h-20h' )[3] );
saito_sl_assert( 'plage de jours qui passe par dimanche', array( array( '10:00', '18:00' ) ), saito_sl_parse_hours( 'Sam-Lun 10:00-18:00' )[6] );
saito_sl_assert( 'horaires libres non interprétés', null, saito_sl_parse_hours( 'Ouvert selon saison' ) );
saito_sl_assert( 'heure de fermeture avant l\'ouverture refusée', null, saito_sl_parse_hours( 'Lundi 19h-9h' ) );

$list = array(
	array( 'name' => 'Magasin Été', 'city' => 'Lyon', 'id' => '1' ),
	array( 'name' => 'MAGASIN ÉTÉ', 'city' => 'Paris', 'id' => '2' ),
	array( 'name' => 'Magasin Été', 'city' => 'Paris', 'id' => '3' ),
);
saito_sl_assign_slugs( $list );
saito_sl_assert( 'identifiants d\'URL uniques et lisibles', array( 'magasin-ete', 'magasin-ete-paris', 'magasin-ete-2' ), array_column( $list, 'slug' ) );

$full = array(
	'name'       => 'MAISON DUPONT PESSAC',
	'brand'      => '',
	'slug'       => 'maison-dupont-pessac',
	'address1'   => '1 av. Eiffel',
	'address2'   => '',
	'postcode'   => '33600',
	'city'       => 'PESSAC',
	'country'    => 'France',
	'lat'        => 44.78,
	'lng'        => -0.63,
	'hours_text' => 'Lun-Sam 9h-19h',
	'hours'      => saito_sl_parse_hours( 'Lun-Sam 9h-19h' ),
	'phone'      => '0556000000',
	'website'    => '',
	'icone'      => '',
);
$desc = saito_sl_store_seo_description( $full );
saito_sl_assert( 'description SEO : adresse, horaires et téléphone', true, false !== strpos( $desc, '33600 Pessac' ) && false !== strpos( $desc, 'Lun-Sam 9h-19h' ) && false !== strpos( $desc, '05 56 00 00 00' ) );
saito_sl_assert( 'titre SEO avec la ville', 'Maison Dupont Pessac : adresse, horaires et téléphone', saito_sl_store_seo_title( $full ) );

$schema = saito_sl_store_schema( $full );
saito_sl_assert( 'schema.org : le revendeur vend la marque du site', 'Boutique Exemple', $schema['makesOffer']['itemOffered']['brand']['name'] );
saito_sl_assert( 'schema.org : enseigne du magasin', 'Maison Dupont', $schema['brand']['name'] );
saito_sl_assert( 'schema.org : horaires détaillés (6 jours)', 6, count( $schema['openingHoursSpecification'] ) );
saito_sl_assert( 'schema.org : pas de catégorie de produit imposée', false, isset( $schema['makesOffer']['itemOffered']['category'] ) );

saito_sl_done();
