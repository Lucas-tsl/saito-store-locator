<?php
/**
 * Fournisseur du plan du site natif de WordPress : /wp-sitemap-magasins-1.xml.
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Liste les URLs des fiches magasin.
 */
class Saito_SL_Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * Constructeur.
	 */
	public function __construct() {
		$this->name        = 'magasins';
		$this->object_type = 'magasins';
	}

	/**
	 * URLs d'une page du plan.
	 *
	 * @param int    $page_num       Numéro de page.
	 * @param string $object_subtype Sous-type (inutilisé).
	 * @return array
	 */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$urls = array_slice( saito_sl_store_urls(), ( $page_num - 1 ) * 2000, 2000 );
		return array_map(
			function ( $url ) {
				return array( 'loc' => $url );
			},
			$urls
		);
	}

	/**
	 * Nombre de pages du plan.
	 *
	 * @param string $object_subtype Sous-type (inutilisé).
	 * @return int
	 */
	public function get_max_num_pages( $object_subtype = '' ) {
		$count = count( saito_sl_store_urls() );
		return $count ? (int) ceil( $count / 2000 ) : 0;
	}
}
