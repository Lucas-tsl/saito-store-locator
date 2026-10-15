<?php
/**
 * Stockage des magasins hors du dossier du plugin (wp-content/uploads/saito-store-locator/),
 * pour que les données survivent aux mises à jour et réinstallations du plugin.
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

define( 'SAITO_SL_STORES_FILE', 'stores.json' );
define( 'SAITO_SL_MAX_BACKUPS', 10 );

/**
 * Dossier de stockage (chemin et URL), créé et protégé au besoin.
 *
 * @return array{dir:string,url:string}
 */
function saito_sl_storage() {
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'saito-store-locator/';
	$url     = trailingslashit( set_url_scheme( $uploads['baseurl'] ) ) . 'saito-store-locator/';

	if ( ! is_dir( $dir . 'backups' ) ) {
		wp_mkdir_p( $dir . 'backups' );
		// Pas de listing du dossier ; les sauvegardes ne sont pas servies.
		saito_sl_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" );
		saito_sl_put_contents( $dir . 'backups/index.php', "<?php\n// Silence is golden.\n" );
		saito_sl_put_contents( $dir . 'backups/.htaccess', "Require all denied\nDeny from all\n" );
	}

	return array(
		'dir' => $dir,
		'url' => $url,
	);
}

/**
 * Écriture atomique (fichier temporaire puis renommage) pour ne jamais laisser un fichier à moitié écrit.
 *
 * @param string $path    Chemin final.
 * @param string $content Contenu.
 * @return bool
 */
function saito_sl_put_contents( $path, $content ) {
	$tmp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';
	if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false;
	}
	if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		return false;
	}
	return true;
}

/**
 * Chemin du fichier public des magasins.
 *
 * @return string
 */
function saito_sl_stores_path() {
	$storage = saito_sl_storage();
	return $storage['dir'] . SAITO_SL_STORES_FILE;
}

/**
 * URL publique du fichier des magasins, versionnée pour invalider le cache navigateur.
 *
 * @return string
 */
function saito_sl_stores_url() {
	$storage = saito_sl_storage();
	$path    = $storage['dir'] . SAITO_SL_STORES_FILE;
	$version = file_exists( $path ) ? filemtime( $path ) : SAITO_SL_VERSION;
	return add_query_arg( 'v', $version, $storage['url'] . SAITO_SL_STORES_FILE );
}

/**
 * Liste des magasins actifs (format normalisé).
 *
 * @return array
 */
function saito_sl_get_stores() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$cache = saito_sl_read_stores_file( saito_sl_stores_path() );
	return $cache;
}

/**
 * Lit un fichier JSON de magasins.
 *
 * @param string $path Chemin.
 * @return array
 */
function saito_sl_read_stores_file( $path ) {
	if ( ! is_readable( $path ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return is_array( $data ) ? $data : array();
}

/**
 * Enregistre une nouvelle liste de magasins, en sauvegardant la précédente.
 *
 * @param array  $stores Magasins normalisés.
 * @param string $source Origine (upload, sync, restauration, migration).
 * @return true|WP_Error
 */
function saito_sl_save_stores( array $stores, $source ) {
	if ( empty( $stores ) ) {
		return new WP_Error( 'saito_sl_empty', __( 'Aucun magasin valide : la liste actuelle a été conservée.', 'saito-store-locator' ) );
	}

	$json = wp_json_encode( array_values( $stores ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( false === $json ) {
		return new WP_Error( 'saito_sl_json', __( 'Impossible d\'encoder les magasins en JSON.', 'saito-store-locator' ) );
	}

	$path = saito_sl_stores_path();
	if ( file_exists( $path ) ) {
		$storage = saito_sl_storage();
		$base    = $storage['dir'] . 'backups/stores-' . gmdate( 'Ymd-His' );
		$backup  = $base . '.json';
		for ( $n = 2; file_exists( $backup ); $n++ ) {
			$backup = $base . '-' . $n . '.json'; // Deux enregistrements dans la même seconde.
		}
		if ( ! copy( $path, $backup ) ) {
			return new WP_Error( 'saito_sl_backup', __( 'Impossible de sauvegarder la liste actuelle : import annulé.', 'saito-store-locator' ) );
		}
		saito_sl_prune_backups();
	}

	if ( ! saito_sl_put_contents( $path, $json ) ) {
		return new WP_Error( 'saito_sl_write', __( 'Impossible d\'écrire le fichier des magasins (droits d\'écriture sur wp-content/uploads ?).', 'saito-store-locator' ) );
	}

	update_option(
		'saito_sl_meta',
		array(
			'updated_at' => time(),
			'count'      => count( $stores ),
			'source'     => $source,
		),
		false
	);

	return true;
}

/**
 * Sauvegardes disponibles, de la plus récente à la plus ancienne.
 *
 * @return array<int,array{file:string,time:int,count:int}>
 */
function saito_sl_list_backups() {
	$storage = saito_sl_storage();
	$files   = glob( $storage['dir'] . 'backups/stores-*.json' );
	$list    = array();
	foreach ( (array) $files as $file ) {
		$name = basename( $file );
		if ( ! preg_match( '/^stores-(\d{8}-\d{6})(?:-(\d+))?\.json$/', $name, $m ) ) {
			continue;
		}
		$date   = DateTime::createFromFormat( 'Ymd-His', $m[1], new DateTimeZone( 'UTC' ) );
		$list[] = array(
			'file'  => $name,
			'time'  => $date ? $date->getTimestamp() : filemtime( $file ),
			'seq'   => isset( $m[2] ) ? (int) $m[2] : 1,
			'count' => count( saito_sl_read_stores_file( $file ) ),
		);
	}
	usort(
		$list,
		function ( $a, $b ) {
			return ( $b['time'] - $a['time'] ) ?: ( $b['seq'] - $a['seq'] );
		}
	);
	return $list;
}

/**
 * Supprime les sauvegardes au-delà de SAITO_SL_MAX_BACKUPS.
 */
function saito_sl_prune_backups() {
	$storage = saito_sl_storage();
	foreach ( array_slice( saito_sl_list_backups(), SAITO_SL_MAX_BACKUPS ) as $old ) {
		wp_delete_file( $storage['dir'] . 'backups/' . $old['file'] );
	}
}

/**
 * Restaure une sauvegarde (la liste actuelle est elle-même sauvegardée avant).
 *
 * @param string $file Nom de fichier de la sauvegarde.
 * @return true|WP_Error
 */
function saito_sl_restore_backup( $file ) {
	if ( ! preg_match( '/^stores-\d{8}-\d{6}(?:-\d+)?\.json$/', (string) $file ) ) {
		return new WP_Error( 'saito_sl_backup_name', __( 'Sauvegarde invalide.', 'saito-store-locator' ) );
	}
	$storage = saito_sl_storage();
	$stores  = saito_sl_read_stores_file( $storage['dir'] . 'backups/' . $file );
	if ( empty( $stores ) ) {
		return new WP_Error( 'saito_sl_backup_missing', __( 'Cette sauvegarde est introuvable ou vide.', 'saito-store-locator' ) );
	}
	return saito_sl_save_stores( $stores, 'restore' );
}
