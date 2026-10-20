<?php
/**
 * Administration : import CSV avec aperçu, synchronisation Google Sheets, sauvegardes, réglages.
 *
 * Toutes les actions passent par admin-post.php avec vérification des droits et d'un nonce.
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

define( 'SAITO_SL_PAGE', 'saito-store-locator' );
define( 'SAITO_SL_SETTINGS_PAGE', 'saito-store-locator-settings' );

add_action( 'admin_menu', 'saito_sl_admin_menu' );
add_action( 'admin_init', 'saito_sl_register_settings' );
add_action( 'admin_enqueue_scripts', 'saito_sl_admin_assets' );
add_action( 'admin_notices', 'saito_sl_admin_notices' );
add_filter( 'plugin_action_links_' . plugin_basename( SAITO_SL_FILE ), 'saito_sl_action_links' );

foreach ( array( 'upload', 'sync_now', 'apply', 'cancel', 'restore', 'export' ) as $saito_sl_action ) {
	add_action( 'admin_post_saito_sl_' . $saito_sl_action, 'saito_sl_handle_' . $saito_sl_action );
}

/**
 * Menus d'administration.
 */
function saito_sl_admin_menu() {
	// Sous le menu « Saito » commun ; les slugs ne changent pas, les liens existants restent valables.
	add_submenu_page( saito_hub_menu_slug(), __( 'Points de vente', 'saito-store-locator' ), __( 'Points de vente', 'saito-store-locator' ), SAITO_SL_CAP, SAITO_SL_PAGE, 'saito_sl_render_stores_page' );
	add_submenu_page( saito_hub_menu_slug(), __( 'Paramètres des points de vente', 'saito-store-locator' ), __( 'Paramètres des points de vente', 'saito-store-locator' ), SAITO_SL_CAP, SAITO_SL_SETTINGS_PAGE, 'saito_sl_render_settings_page' );
}

/**
 * Lien « Réglages » dans la liste des extensions.
 *
 * @param array $links Liens.
 * @return array
 */
function saito_sl_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . SAITO_SL_PAGE ) ) . '">' . esc_html__( 'Magasins', 'saito-store-locator' ) . '</a>' );
	return $links;
}

/**
 * Déclare l'option via l'API Settings (nonce et droits gérés par options.php).
 */
function saito_sl_register_settings() {
	register_setting(
		'saito_sl',
		SAITO_SL_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'saito_sl_sanitize_settings',
			'default'           => saito_sl_default_settings(),
		)
	);
}

/**
 * Styles et sélecteur de couleur sur les pages du plugin uniquement.
 *
 * @param string $hook Page courante.
 */
function saito_sl_admin_assets( $hook ) {
	if ( false === strpos( (string) $hook, SAITO_SL_PAGE ) ) {
		return;
	}
	wp_enqueue_style( 'saito-sl-admin', SAITO_SL_URL . 'assets/css/admin.css', array(), SAITO_SL_VERSION );
}

/* -------------------------------------------------------------------------
 * Messages
 * ---------------------------------------------------------------------- */

/**
 * Mémorise un message à afficher après redirection.
 *
 * @param string $type    success|error|warning|info.
 * @param string $message Message (texte brut).
 */
function saito_sl_flash( $type, $message ) {
	set_transient( 'saito_sl_notice_' . get_current_user_id(), array( $type, $message ), 5 * MINUTE_IN_SECONDS );
}

/**
 * Affiche les messages sur les pages du plugin.
 */
function saito_sl_admin_notices() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || false === strpos( (string) $screen->id, SAITO_SL_PAGE ) || ! current_user_can( SAITO_SL_CAP ) ) {
		return;
	}

	$key    = 'saito_sl_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( is_array( $notice ) ) {
		delete_transient( $key );
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
	}

	$settings = saito_sl_get_settings();
	if ( '' === $settings['apikey'] && SAITO_SL_SETTINGS_PAGE === ( isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture de la page affichée.
		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'La carte utilise les tuiles d\'OpenStreetMap, prévues pour un usage modéré. Pour un site très fréquenté, renseignez une clé MapTiler (offre gratuite disponible).', 'saito-store-locator' )
		);
	}

	$status = get_option( 'saito_sl_sync_status' );
	if ( is_array( $status ) && empty( $status['ok'] ) && ! empty( $settings['sync_auto'] ) ) {
		printf( '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>', esc_html__( 'Échec de la dernière synchronisation automatique :', 'saito-store-locator' ), esc_html( $status['message'] ) );
	}
}

/* -------------------------------------------------------------------------
 * Actions (admin-post.php)
 * ---------------------------------------------------------------------- */

/**
 * Vérifie droits et nonce pour une action.
 *
 * @param string $action Nom de l'action.
 */
function saito_sl_guard( $action ) {
	if ( ! current_user_can( SAITO_SL_CAP ) ) {
		wp_die( esc_html__( 'Vous n\'avez pas les droits suffisants pour effectuer cette action.', 'saito-store-locator' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'saito_sl_' . $action );
}

/**
 * Redirige vers la page des magasins.
 */
function saito_sl_redirect() {
	wp_safe_redirect( admin_url( 'admin.php?page=' . SAITO_SL_PAGE ) );
	exit;
}

/**
 * Mémorise un import en attente de validation.
 *
 * @param array  $result Résultat de saito_sl_import_csv().
 * @param string $source upload|sync.
 * @param string $label  Nom du fichier ou de la source.
 */
function saito_sl_set_pending( array $result, $source, $label ) {
	$result['source'] = $source;
	$result['label']  = $label;
	$result['time']   = time();
	set_transient( 'saito_sl_pending_' . get_current_user_id(), $result, HOUR_IN_SECONDS );
}

/**
 * Import en attente de l'utilisateur courant.
 *
 * @return array|null
 */
function saito_sl_get_pending() {
	$pending = get_transient( 'saito_sl_pending_' . get_current_user_id() );
	return is_array( $pending ) ? $pending : null;
}

/**
 * Upload d'un CSV : analyse puis affichage du rapport (rien n'est encore appliqué).
 */
function saito_sl_handle_upload() {
	saito_sl_guard( 'upload' );

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing -- nonce vérifié par saito_sl_guard(), fichier vérifié ci-dessous et contenu validé par saito_sl_import_csv().
	$file = isset( $_FILES['csv_file'] ) ? $_FILES['csv_file'] : null;

	if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === $file['error'] ) {
		saito_sl_flash( 'error', __( 'Choisissez un fichier CSV avant de cliquer sur « Analyser le fichier ».', 'saito-store-locator' ) );
		saito_sl_redirect();
	}
	if ( UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		saito_sl_flash( 'error', __( 'Le fichier n\'a pas pu être envoyé (code ', 'saito-store-locator' ) . (int) $file['error'] . ').' );
		saito_sl_redirect();
	}
	$name = sanitize_file_name( wp_unslash( $file['name'] ) );
	if ( ! in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), array( 'csv', 'txt' ), true ) ) {
		saito_sl_flash( 'error', __( 'Le fichier n\'est pas au format CSV.', 'saito-store-locator' ) );
		saito_sl_redirect();
	}
	if ( $file['size'] > SAITO_SL_MAX_CSV_BYTES ) {
		saito_sl_flash( 'error', __( 'Le fichier dépasse 5 Mo.', 'saito-store-locator' ) );
		saito_sl_redirect();
	}

	// Lecture directe depuis le fichier temporaire : rien n'est conservé dans la médiathèque.
	$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$result  = saito_sl_import_csv( (string) $content );
	saito_sl_set_pending( $result, 'upload', $name );
	saito_sl_redirect();
}

/**
 * Téléchargement de la feuille Google Sheets puis affichage du rapport.
 */
function saito_sl_handle_sync_now() {
	saito_sl_guard( 'sync_now' );

	$content = saito_sl_fetch_sheet();
	if ( is_wp_error( $content ) ) {
		saito_sl_flash( 'error', $content->get_error_message() );
		saito_sl_set_sync_status( false, $content->get_error_message() );
		saito_sl_redirect();
	}
	saito_sl_set_pending( saito_sl_import_csv( $content ), 'sync', 'Google Sheets' );
	saito_sl_redirect();
}

/**
 * Validation de l'import en attente.
 */
function saito_sl_handle_apply() {
	saito_sl_guard( 'apply' );

	$pending = saito_sl_get_pending();
	if ( ! $pending ) {
		saito_sl_flash( 'error', __( 'L\'analyse a expiré : relancez l\'import.', 'saito-store-locator' ) );
		saito_sl_redirect();
	}
	if ( ! empty( $pending['fatal'] ) || empty( $pending['stores'] ) ) {
		saito_sl_flash( 'error', __( 'Cet import contient une erreur bloquante et ne peut pas être appliqué.', 'saito-store-locator' ) );
		saito_sl_redirect();
	}

	$saved = saito_sl_save_stores( $pending['stores'], $pending['source'] );
	delete_transient( 'saito_sl_pending_' . get_current_user_id() );

	if ( is_wp_error( $saved ) ) {
		saito_sl_flash( 'error', $saved->get_error_message() );
	} else {
		/* translators: %d: nombre de magasins. */
		$message = sprintf( __( '%d magasins sont maintenant en ligne. L\'ancienne liste a été sauvegardée.', 'saito-store-locator' ), count( $pending['stores'] ) );
		saito_sl_flash( 'success', $message );
		if ( 'sync' === $pending['source'] ) {
			saito_sl_set_sync_status( true, $message );
		}
	}
	saito_sl_redirect();
}

/**
 * Abandon de l'import en attente.
 */
function saito_sl_handle_cancel() {
	saito_sl_guard( 'cancel' );
	delete_transient( 'saito_sl_pending_' . get_current_user_id() );
	saito_sl_flash( 'info', __( 'Import annulé : la liste en ligne n\'a pas été modifiée.', 'saito-store-locator' ) );
	saito_sl_redirect();
}

/**
 * Restauration d'une sauvegarde.
 */
function saito_sl_handle_restore() {
	saito_sl_guard( 'restore' );
	$file   = isset( $_POST['backup'] ) ? sanitize_file_name( wp_unslash( $_POST['backup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce vérifié par saito_sl_guard().
	$result = saito_sl_restore_backup( $file );
	if ( is_wp_error( $result ) ) {
		saito_sl_flash( 'error', $result->get_error_message() );
	} else {
		saito_sl_flash( 'success', __( 'La sauvegarde a été restaurée. La liste remplacée a elle-même été sauvegardée.', 'saito-store-locator' ) );
	}
	saito_sl_redirect();
}

/**
 * Export de la liste en ligne au format CSV (réimportable).
 */
function saito_sl_handle_export() {
	saito_sl_guard( 'export' );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="magasins-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM pour Excel.
	fputcsv( $out, array( 'id_store', 'active', 'name', 'city', 'postcode', 'address1', 'latitude', 'longitude', 'phone', 'address2', 'country', 'icone', 'website', 'enseigne', 'services' ), ',', '"', '' );
	foreach ( saito_sl_get_stores() as $s ) {
		fputcsv( $out, array( $s['id'], '1', $s['name'], $s['city'], $s['postcode'], $s['address1'], $s['lat'], $s['lng'], $s['phone'], $s['address2'], $s['country'], $s['icone'], isset( $s['website'] ) ? $s['website'] : '', isset( $s['brand'] ) ? $s['brand'] : '', isset( $s['services'] ) ? implode( ', ', (array) $s['services'] ) : '' ), ',', '"', '' );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

/* -------------------------------------------------------------------------
 * Pages
 * ---------------------------------------------------------------------- */

/**
 * Formulaire POST vers admin-post.php avec nonce.
 *
 * @param string $action  Action.
 * @param string $label   Libellé du bouton.
 * @param string $class   Classes du bouton.
 * @param array  $fields  Champs cachés supplémentaires.
 * @param string $confirm Message de confirmation (optionnel).
 */
function saito_sl_action_button( $action, $label, $class = 'button', array $fields = array(), $confirm = '' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="saito-sl-inline-form"';
	if ( $confirm ) {
		echo ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"';
	}
	echo '>';
	echo '<input type="hidden" name="action" value="' . esc_attr( 'saito_sl_' . $action ) . '">';
	wp_nonce_field( 'saito_sl_' . $action );
	foreach ( $fields as $name => $value ) {
		echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
	}
	echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
}

/**
 * Date lisible dans le fuseau du site.
 *
 * @param int $timestamp Horodatage.
 * @return string
 */
function saito_sl_format_date( $timestamp ) {
	/* translators: 1: date, 2: heure. */
	return sprintf( __( '%1$s à %2$s', 'saito-store-locator' ), wp_date( get_option( 'date_format' ), (int) $timestamp ), wp_date( get_option( 'time_format' ), (int) $timestamp ) );
}

/**
 * Balises autorisées dans les textes d'aide traduits.
 *
 * @return array
 */
function saito_sl_help_tags() {
	return array(
		'code'   => array(),
		'strong' => array(),
		'a'      => array( 'href' => array() ),
	);
}

/**
 * Page « Magasins ».
 */
function saito_sl_render_stores_page() {
	if ( ! current_user_can( SAITO_SL_CAP ) ) {
		return;
	}
	$pending  = saito_sl_get_pending();
	$settings = saito_sl_get_settings();
	$meta     = get_option( 'saito_sl_meta', array() );
	$count    = count( saito_sl_get_stores() );
	$sources  = array(
		'upload'  => __( 'import CSV', 'saito-store-locator' ),
		'sync'    => __( 'synchronisation Google Sheets', 'saito-store-locator' ),
		'restore' => __( 'restauration d\'une sauvegarde', 'saito-store-locator' ),
	);
	?>
	<div class="wrap saito-sl-admin">
		<h1><?php esc_html_e( 'Points de vente — Magasins', 'saito-store-locator' ); ?></h1>

		<div class="saito-sl-summary">
			<p class="saito-sl-summary__count">
				<?php
				/* translators: %s: nombre de magasins en ligne. */
				echo wp_kses( sprintf( _n( '<strong>%s</strong> magasin en ligne', '<strong>%s</strong> magasins en ligne', $count, 'saito-store-locator' ), esc_html( number_format_i18n( $count ) ) ), saito_sl_help_tags() );
				?>
			</p>
			<?php if ( ! empty( $meta['updated_at'] ) ) : ?>
				<p>
					<?php
					$source = isset( $meta['source'], $sources[ $meta['source'] ] ) ? $sources[ $meta['source'] ] : '';
					if ( $source ) {
						/* translators: 1: date de mise à jour, 2: origine (import CSV, synchronisation…). */
						echo esc_html( sprintf( __( 'Dernière mise à jour le %1$s (%2$s).', 'saito-store-locator' ), saito_sl_format_date( $meta['updated_at'] ), $source ) );
					} else {
						/* translators: %s: date de mise à jour. */
						echo esc_html( sprintf( __( 'Dernière mise à jour le %s.', 'saito-store-locator' ), saito_sl_format_date( $meta['updated_at'] ) ) );
					}
					?>
				</p>
			<?php endif; ?>
			<?php if ( $count ) : ?>
				<?php saito_sl_action_button( 'export', __( 'Télécharger la liste en CSV', 'saito-store-locator' ), 'button button-small' ); ?>
			<?php endif; ?>
		</div>

		<?php if ( $pending ) : ?>
			<?php saito_sl_render_preview( $pending, $count ); ?>
		<?php else : ?>

			<div class="saito-sl-card">
				<h2><?php esc_html_e( 'Synchroniser avec Google Sheets', 'saito-store-locator' ); ?></h2>
				<?php if ( $settings['sheet_url'] ) : ?>
					<p><?php esc_html_e( 'Récupère directement la feuille des magasins, sans téléchargement manuel. Un rapport s\'affiche avant toute mise en ligne.', 'saito-store-locator' ); ?></p>
					<?php
					$status = get_option( 'saito_sl_sync_status' );
					if ( is_array( $status ) ) {
						printf(
							'<p class="saito-sl-sync-status %1$s">%2$s</p>',
							esc_attr( $status['ok'] ? 'is-ok' : 'is-error' ),
							/* translators: 1: date, 2: résultat de la synchronisation. */
							esc_html( sprintf( __( 'Dernière synchronisation le %1$s : %2$s', 'saito-store-locator' ), saito_sl_format_date( $status['time'] ), $status['message'] ) )
						);
					}
					if ( $settings['sync_auto'] ) {
						$next = wp_next_scheduled( SAITO_SL_CRON_HOOK );
						echo '<p>' . esc_html(
							$next
								/* translators: %s: date de la prochaine synchronisation. */
								? sprintf( __( 'Synchronisation automatique quotidienne activée — prochaine le %s.', 'saito-store-locator' ), saito_sl_format_date( $next ) )
								: __( 'Synchronisation automatique quotidienne activée.', 'saito-store-locator' )
						) . '</p>';
					}
					saito_sl_action_button( 'sync_now', __( 'Synchroniser maintenant', 'saito-store-locator' ), 'button button-primary' );
					?>
				<?php else : ?>
					<p>
						<?php
						echo wp_kses(
							sprintf(
								/* translators: %s: adresse de la page des paramètres. */
								__( 'Renseignez l\'adresse de la feuille dans les <a href="%s">paramètres</a> pour mettre à jour les magasins en un clic, voire automatiquement chaque jour.', 'saito-store-locator' ),
								esc_url( admin_url( 'admin.php?page=' . SAITO_SL_SETTINGS_PAGE ) )
							),
							saito_sl_help_tags()
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<div class="saito-sl-card">
				<h2><?php esc_html_e( 'Importer un fichier CSV', 'saito-store-locator' ); ?></h2>
				<ol>
					<li><?php echo wp_kses( __( 'Ouvrez le tableur des magasins et faites vos ajouts/modifications. <strong>Ne modifiez jamais la 1ère ligne</strong> (noms des colonnes).', 'saito-store-locator' ), saito_sl_help_tags() ); ?></li>
					<li><?php esc_html_e( 'Exportez-le au format CSV (Google Sheets : Fichier > Télécharger > Valeurs séparées par des virgules).', 'saito-store-locator' ); ?></li>
					<li><?php esc_html_e( 'Choisissez ce fichier ci-dessous puis cliquez sur « Analyser le fichier ».', 'saito-store-locator' ); ?></li>
					<li><?php esc_html_e( 'Vérifiez le rapport, puis validez la mise en ligne.', 'saito-store-locator' ); ?></li>
				</ol>
				<p class="description"><?php esc_html_e( 'Colonnes reconnues : id_store, active, name, address1, address2, postcode, city, country, phone, website, latitude, longitude, icone (valeurs « signature » ou « rouge »), enseigne, services (séparés par des virgules), horaires (ex. « Lundi-Samedi 10h-19h ; Dimanche fermé »). Si la latitude/longitude d\'un magasin français est vide, elle est calculée à partir de l\'adresse.', 'saito-store-locator' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="saito_sl_upload">
					<?php wp_nonce_field( 'saito_sl_upload' ); ?>
					<input type="file" name="csv_file" accept=".csv,text/csv" required>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Analyser le fichier', 'saito-store-locator' ); ?></button>
				</form>
			</div>

			<?php saito_sl_render_backups(); ?>

			<div class="saito-sl-card">
				<h2><?php esc_html_e( 'Intégration', 'saito-store-locator' ); ?></h2>
				<p>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %d: nombre de magasins affichés par défaut. */
							__( 'Ajoutez le shortcode <code>[saito_store_locator]</code> dans une page. Options : <code>[saito_store_locator results="6"]</code> pour afficher 6 magasins au lieu de %d.', 'saito-store-locator' ),
							(int) $settings['results_count']
						),
						saito_sl_help_tags()
					);
					?>
				</p>
				<p><?php echo wp_kses( __( 'Le shortcode <code>[saito_store_locator_list]</code> affiche la liste complète des magasins en HTML, par pays et par ville (utile pour le référencement).', 'saito-store-locator' ), saito_sl_help_tags() ); ?></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Rapport d'import avant validation.
 *
 * @param array $pending Import en attente.
 * @param int   $current Nombre de magasins actuellement en ligne.
 */
function saito_sl_render_preview( array $pending, $current ) {
	$stats  = $pending['stats'];
	$errors = array_filter(
		$pending['issues'],
		function ( $i ) {
			return 'error' === $i['level'];
		}
	);
	$new    = count( $pending['stores'] );
	?>
	<div class="saito-sl-card saito-sl-preview">
		<?php /* translators: %s: nom du fichier ou « Google Sheets ». */ ?>
		<h2><?php echo esc_html( sprintf( __( 'Rapport d\'import — %s', 'saito-store-locator' ), $pending['label'] ) ); ?></h2>

		<?php if ( ! empty( $pending['fatal'] ) ) : ?>
			<div class="notice notice-error inline"><p><strong><?php echo esc_html( $pending['fatal'] ); ?></strong> <?php esc_html_e( 'La liste en ligne n\'a pas été modifiée.', 'saito-store-locator' ); ?></p></div>
		<?php endif; ?>

		<ul class="saito-sl-stats">
			<?php /* translators: %d: nombre de magasins. */ ?>
			<li><?php echo wp_kses( sprintf( __( '<strong>%d</strong> magasins prêts à être mis en ligne', 'saito-store-locator' ), (int) $new ), saito_sl_help_tags() ); ?></li>
			<?php /* translators: %d: nombre de magasins inactifs. */ ?>
			<li><?php echo wp_kses( sprintf( __( '<strong>%d</strong> inactifs (colonne active ≠ 1), non affichés', 'saito-store-locator' ), (int) $stats['inactive'] ), saito_sl_help_tags() ); ?></li>
			<?php /* translators: %d: nombre de lignes ignorées. */ ?>
			<li class="<?php echo $stats['skipped'] ? 'is-error' : ''; ?>"><?php echo wp_kses( sprintf( __( '<strong>%d</strong> lignes ignorées', 'saito-store-locator' ), (int) $stats['skipped'] ), saito_sl_help_tags() ); ?></li>
			<?php /* translators: %d: nombre de positions calculées. */ ?>
			<li><?php echo wp_kses( sprintf( __( '<strong>%d</strong> positions calculées depuis l\'adresse', 'saito-store-locator' ), (int) $stats['geocoded'] ), saito_sl_help_tags() ); ?></li>
		</ul>

		<?php if ( $current && $new && $new < $current * SAITO_SL_SYNC_MIN_RATIO ) : ?>
			<?php /* translators: 1: nombre de magasins dans le fichier, 2: nombre de magasins en ligne. */ ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html( sprintf( __( 'Attention : ce fichier contient %1$d magasins contre %2$d actuellement en ligne. Vérifiez qu\'il est complet avant de valider.', 'saito-store-locator' ), $new, $current ) ); ?></p></div>
		<?php endif; ?>

		<?php if ( $pending['issues'] ) : ?>
			<?php /* translators: %d: nombre de remarques. */ ?>
			<h3><?php echo esc_html( sprintf( __( 'Détail (%d)', 'saito-store-locator' ), count( $pending['issues'] ) ) ); ?></h3>
			<table class="widefat striped saito-sl-issues">
				<thead><tr><th><?php esc_html_e( 'Ligne', 'saito-store-locator' ); ?></th><th><?php esc_html_e( 'Magasin', 'saito-store-locator' ); ?></th><th><?php esc_html_e( 'Problème', 'saito-store-locator' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $pending['issues'], 0, 300 ) as $issue ) : ?>
					<tr class="is-<?php echo esc_attr( $issue['level'] ); ?>">
						<td><?php echo (int) $issue['line']; ?></td>
						<td><?php echo esc_html( $issue['name'] ); ?></td>
						<td><?php echo esc_html( $issue['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $new ) : ?>
			<?php /* translators: 1: nombre de magasins affichés, 2: nombre total. */ ?>
			<h3><?php echo esc_html( sprintf( __( 'Aperçu (%1$d sur %2$d)', 'saito-store-locator' ), min( 10, $new ), $new ) ); ?></h3>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Nom', 'saito-store-locator' ); ?></th><th><?php esc_html_e( 'Adresse', 'saito-store-locator' ); ?></th><th><?php esc_html_e( 'Pays', 'saito-store-locator' ); ?></th><th><?php esc_html_e( 'Position', 'saito-store-locator' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( array_slice( $pending['stores'], 0, 10 ) as $s ) : ?>
					<tr>
						<td><?php echo esc_html( $s['name'] ); ?></td>
						<td><?php echo esc_html( trim( $s['address1'] . ', ' . $s['postcode'] . ' ' . $s['city'], ', ' ) ); ?></td>
						<td><?php echo esc_html( $s['country'] ); ?></td>
						<td><?php echo esc_html( $s['lat'] . ', ' . $s['lng'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p class="saito-sl-actions">
			<?php
			if ( empty( $pending['fatal'] ) && $new ) {
				/* translators: %d: nombre de magasins. */
				$label = $errors ? sprintf( __( 'Mettre en ligne les %d magasins valides', 'saito-store-locator' ), $new ) : sprintf( __( 'Mettre en ligne les %d magasins', 'saito-store-locator' ), $new );
				saito_sl_action_button( 'apply', $label, 'button button-primary' );
			}
			saito_sl_action_button( 'cancel', __( 'Annuler', 'saito-store-locator' ), 'button' );
			?>
		</p>
	</div>
	<?php
}

/**
 * Liste des sauvegardes restaurables.
 */
function saito_sl_render_backups() {
	$backups = saito_sl_list_backups();
	?>
	<div class="saito-sl-card">
		<h2><?php esc_html_e( 'Sauvegardes', 'saito-store-locator' ); ?></h2>
		<?php if ( ! $backups ) : ?>
			<?php /* translators: %d: nombre de versions conservées. */ ?>
			<p><?php echo esc_html( sprintf( __( 'Aucune sauvegarde pour l\'instant. La liste en ligne est sauvegardée automatiquement avant chaque import (%d dernières versions conservées).', 'saito-store-locator' ), (int) SAITO_SL_MAX_BACKUPS ) ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Remplacée le', 'saito-store-locator' ); ?></th><th><?php esc_html_e( 'Magasins', 'saito-store-locator' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $backups as $backup ) : ?>
					<tr>
						<td><?php echo esc_html( saito_sl_format_date( $backup['time'] ) ); ?></td>
						<td><?php echo (int) $backup['count']; ?></td>
						<td><?php saito_sl_action_button( 'restore', __( 'Restaurer cette version', 'saito-store-locator' ), 'button button-small', array( 'backup' => $backup['file'] ), __( 'Remplacer la liste en ligne par cette sauvegarde ?', 'saito-store-locator' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Page « Paramètres » (API Settings : nonce et droits vérifiés par options.php).
 */
function saito_sl_render_settings_page() {
	if ( ! current_user_can( SAITO_SL_CAP ) ) {
		return;
	}
	$s    = saito_sl_get_settings();
	$name = SAITO_SL_OPTION;
	?>
	<div class="wrap saito-sl-admin">
		<h1><?php esc_html_e( 'Points de vente — Paramètres', 'saito-store-locator' ); ?></h1>
		<?php settings_errors(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'saito_sl' ); ?>

			<h2 class="title"><?php esc_html_e( 'Carte', 'saito-store-locator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="saito-sl-apikey"><?php esc_html_e( 'Clé API MapTiler (facultative)', 'saito-store-locator' ); ?></label></th>
					<td>
						<input type="text" id="saito-sl-apikey" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[apikey]" value="<?php echo esc_attr( $s['apikey'] ); ?>" autocomplete="off">
						<p class="description"><?php esc_html_e( 'Sans clé, la carte utilise les tuiles d\'OpenStreetMap. Avec une clé (compte gratuit sur maptiler.com), elle utilise celles de MapTiler, adaptées aux sites très fréquentés. La clé est visible publiquement dans les pages du site (c\'est normal pour une carte) : limitez-la à votre domaine dans votre compte MapTiler (Account > API keys > Allowed HTTP origins).', 'saito-store-locator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="saito-sl-theme"><?php esc_html_e( 'Thème', 'saito-store-locator' ); ?></label></th>
					<td>
						<select id="saito-sl-theme" name="<?php echo esc_attr( $name ); ?>[theme]">
							<option value="light" <?php selected( $s['theme'], 'light' ); ?>><?php esc_html_e( 'Clair : fond blanc, texte noir (recommandé)', 'saito-store-locator' ); ?></option>
							<option value="dark" <?php selected( $s['theme'], 'dark' ); ?>><?php esc_html_e( 'Sombre : fond noir, texte blanc', 'saito-store-locator' ); ?></option>
						</select>
						<p class="description"><?php echo wp_kses( __( 'Le store locator n\'utilise que du noir, du blanc et des gris : boutons et marqueurs suivent le thème. Le thème clair reprend la police de votre site et se fond dans la page. Modifiable page par page : <code>[saito_store_locator theme="dark"]</code>.', 'saito-store-locator' ), saito_sl_help_tags() ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="saito-sl-results"><?php esc_html_e( 'Magasins affichés après une recherche', 'saito-store-locator' ); ?></label></th>
					<td><input type="number" id="saito-sl-results" min="1" max="20" class="small-text" name="<?php echo esc_attr( $name ); ?>[results_count]" value="<?php echo (int) $s['results_count']; ?>"></td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Filtres', 'saito-store-locator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Filtres par enseigne et service', 'saito-store-locator' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[filters]" value="1" <?php checked( $s['filters'] ); ?>> <?php esc_html_e( 'Afficher les filtres au-dessus de la carte', 'saito-store-locator' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="saito-sl-brands"><?php esc_html_e( 'Enseignes', 'saito-store-locator' ); ?></label></th>
					<td>
						<textarea id="saito-sl-brands" class="large-text" rows="5" name="<?php echo esc_attr( $name ); ?>[brands]"><?php echo esc_textarea( $s['brands'] ); ?></textarea>
						<p class="description"><?php echo wp_kses( __( 'Une enseigne par ligne. Un magasin dont le nom commence par une enseigne lui est rattaché (ex. « MAISON DUPONT PESSAC » → Maison Dupont). Les autres sont regroupés dans « Autres ». Une colonne <code>enseigne</code> dans le fichier est prioritaire. Les services viennent de la colonne <code>services</code> (séparés par des virgules).', 'saito-store-locator' ), saito_sl_help_tags() ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="saito-sl-highlight"><?php esc_html_e( 'Magasins mis en avant', 'saito-store-locator' ); ?></label></th>
					<td>
						<input type="text" id="saito-sl-highlight" class="regular-text" name="<?php echo esc_attr( $name ); ?>[highlight]" value="<?php echo esc_attr( $s['highlight'] ); ?>" placeholder="<?php esc_attr_e( 'Conseil personnalisé', 'saito-store-locator' ); ?>">
						<p class="description"><?php echo wp_kses( __( 'Les magasins dont la colonne <code>icone</code> vaut « signature » ont un marqueur distinct. Indiquez ici le service qu\'ils proposent : il est ajouté à leur fiche et aux filtres. Laisser vide pour n\'afficher que le marqueur.', 'saito-store-locator' ), saito_sl_help_tags() ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Google Sheets', 'saito-store-locator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="saito-sl-sheet"><?php esc_html_e( 'Adresse de la feuille', 'saito-store-locator' ); ?></label></th>
					<td>
						<input type="url" id="saito-sl-sheet" class="large-text code" name="<?php echo esc_attr( $name ); ?>[sheet_url]" value="<?php echo esc_attr( $s['sheet_url'] ); ?>" placeholder="https://docs.google.com/spreadsheets/d/…/edit#gid=0">
						<p class="description"><?php esc_html_e( 'Collez simplement l\'adresse de la feuille (copiée depuis le navigateur). Elle doit être partagée en lecture : Partager > Accès général > « Tous les utilisateurs disposant du lien ». L\'onglet utilisé est celui affiché dans l\'adresse (gid).', 'saito-store-locator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Synchronisation automatique', 'saito-store-locator' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[sync_auto]" value="1" <?php checked( $s['sync_auto'] ); ?>> <?php esc_html_e( 'Mettre à jour les magasins chaque jour depuis la feuille', 'saito-store-locator' ); ?></label>
						<p class="description"><?php esc_html_e( 'Par sécurité, la mise à jour automatique est bloquée si la feuille contient moins de la moitié des magasins actuellement en ligne.', 'saito-store-locator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="saito-sl-alert"><?php esc_html_e( 'E-mail d\'alerte', 'saito-store-locator' ); ?></label></th>
					<td>
						<input type="email" id="saito-sl-alert" class="regular-text" name="<?php echo esc_attr( $name ); ?>[alert_email]" value="<?php echo esc_attr( $s['alert_email'] ); ?>">
						<p class="description"><?php esc_html_e( 'Prévenu si la synchronisation automatique échoue (feuille inaccessible, colonnes modifiées, feuille vidée…). Au plus un e-mail par jour. Laisser vide pour ne pas être alerté.', 'saito-store-locator' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Référencement', 'saito-store-locator' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Données structurées', 'saito-store-locator' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[jsonld]" value="1" <?php checked( $s['jsonld'] ); ?>> <?php esc_html_e( 'Décrire les magasins aux moteurs de recherche (JSON-LD schema.org/Store) sur la page du store locator', 'saito-store-locator' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="saito-sl-own-brand"><?php esc_html_e( 'Marque vendue', 'saito-store-locator' ); ?></label></th>
					<td>
						<input type="text" id="saito-sl-own-brand" class="regular-text" name="<?php echo esc_attr( $name ); ?>[own_brand]" value="<?php echo esc_attr( $s['own_brand'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>">
						<?php /* translators: %s: marque vendue. */ ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Chaque revendeur est déclaré aux moteurs de recherche comme vendant les produits de cette marque (offre schema.org) : c\'est ce qui permet de répondre à « où acheter %s près de chez moi ». Vide : nom du site.', 'saito-store-locator' ), saito_sl_own_brand() ) ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Enregistrer', 'saito-store-locator' ) ); ?>
		</form>
	</div>
	<?php
}
