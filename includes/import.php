<?php
/**
 * Lecture et validation du fichier CSV des magasins.
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

define( 'SAITO_SL_MAX_CSV_BYTES', 5 * MB_IN_BYTES );
define( 'SAITO_SL_MAX_GEOCODE', 50 );

/**
 * Correspondance entre les en-têtes acceptés (sans accents, en minuscules) et les champs internes.
 *
 * @return array<string,string>
 */
function saito_sl_header_aliases() {
	return array(
		'id_store'     => 'id',
		'id'           => 'id',
		'active'       => 'active',
		'actif'        => 'active',
		'name'         => 'name',
		'nom'          => 'name',
		'address1'     => 'address1',
		'adresse'      => 'address1',
		'adresse1'     => 'address1',
		'address2'     => 'address2',
		'adresse2'     => 'address2',
		'complement'   => 'address2',
		'postcode'     => 'postcode',
		'code postal'  => 'postcode',
		'code_postal'  => 'postcode',
		'cp'           => 'postcode',
		'city'         => 'city',
		'ville'        => 'city',
		'country'      => 'country',
		'pays'         => 'country',
		'phone'        => 'phone',
		'telephone'    => 'phone',
		'tel'          => 'phone',
		'website'      => 'website',
		'site'         => 'website',
		'site web'     => 'website',
		'url'          => 'website',
		'latitude'     => 'lat',
		'lat'          => 'lat',
		'longitude'    => 'lng',
		'lng'          => 'lng',
		'lon'          => 'lng',
		'icone'        => 'icone',
		'icon'         => 'icone',
		'type'         => 'icone',
		'enseigne'     => 'brand',
		'brand'        => 'brand',
		'marque'       => 'brand',
		'services'     => 'services',
		'service'      => 'services',
		'horaires'     => 'hours',
		'horaire'      => 'hours',
		'hours'        => 'hours',
		'hours_text'   => 'hours',
		'opening hours' => 'hours',
	);
}

/**
 * Normalise un en-tête de colonne : minuscules, sans accents ni BOM, espaces réduits.
 *
 * @param string $header En-tête brut.
 * @return string
 */
function saito_sl_normalize_header( $header ) {
	$header = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header );
	$header = strtolower( remove_accents( trim( $header ) ) );
	return preg_replace( '/\s+/', ' ', $header );
}

/**
 * Convertit un CSV (contenu texte) en lignes associatives.
 *
 * @param string $content Contenu du fichier.
 * @return array{rows:array,issues:array,fatal:string}
 */
function saito_sl_parse_csv( $content ) {
	$result = array(
		'rows'   => array(),
		'issues' => array(),
		'fatal'  => '',
	);

	$content = (string) $content;
	if ( strlen( $content ) > SAITO_SL_MAX_CSV_BYTES ) {
		$result['fatal'] = __( 'Le fichier dépasse 5 Mo.', 'saito-store-locator' );
		return $result;
	}

	// Excel enregistre souvent en Windows-1252 : conversion en UTF-8.
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
		$content = mb_convert_encoding( $content, 'UTF-8', 'Windows-1252' );
	}
	$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );

	if ( '' === trim( $content ) ) {
		$result['fatal'] = __( 'Le fichier est vide.', 'saito-store-locator' );
		return $result;
	}
	if ( preg_match( '/^\s*<(!doctype|html)/i', $content ) ) {
		$result['fatal'] = __( 'Le contenu reçu est une page web et non un CSV : vérifiez que la feuille Google Sheets est partagée en lecture (« Tous les utilisateurs disposant du lien »).', 'saito-store-locator' );
		return $result;
	}

	// Détection du séparateur sur la première ligne (Google Sheets : virgule, Excel FR : point-virgule).
	$first_line = strtok( $content, "\n" );
	$delimiter  = ',';
	$best       = 0;
	foreach ( array( ',', ';', "\t" ) as $candidate ) {
		$count = substr_count( (string) $first_line, $candidate );
		if ( $count > $best ) {
			$best      = $count;
			$delimiter = $candidate;
		}
	}

	$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $handle, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	rewind( $handle );

	$raw_headers = fgetcsv( $handle, 0, $delimiter, '"', '' );
	if ( ! is_array( $raw_headers ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$result['fatal'] = __( 'Impossible de lire la ligne d\'en-tête.', 'saito-store-locator' );
		return $result;
	}

	$aliases = saito_sl_header_aliases();
	$columns = array();
	foreach ( $raw_headers as $index => $raw ) {
		$key = saito_sl_normalize_header( $raw );
		if ( isset( $aliases[ $key ] ) && ! in_array( $aliases[ $key ], $columns, true ) ) {
			$columns[ $index ] = $aliases[ $key ];
		}
	}

	$missing = array_diff( array( 'name', 'lat', 'lng' ), $columns );
	if ( $missing ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$labels          = array(
			'name' => 'name',
			'lat'  => 'latitude',
			'lng'  => 'longitude',
		);
		/* translators: %s: liste des colonnes manquantes. */
		$result['fatal'] = sprintf( __( 'Colonnes obligatoires absentes de la 1ère ligne : %s. La première ligne du fichier ne doit pas être modifiée.', 'saito-store-locator' ), implode( ', ', array_intersect_key( $labels, array_flip( $missing ) ) ) );
		return $result;
	}

	$header_count = count( $raw_headers );
	$line         = 1;
	while ( ( $data = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) !== false ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
		++$line;
		if ( array( null ) === $data || '' === trim( implode( '', $data ) ) ) {
			continue; // Ligne vide.
		}
		if ( count( $data ) > $header_count ) {
			$result['issues'][] = saito_sl_issue( $line, isset( $data[0] ) ? $data[0] : '', __( 'Ligne ignorée : plus de colonnes que l\'en-tête (virgule ou guillemet en trop ?).', 'saito-store-locator' ) );
			continue;
		}
		$row = array( '_line' => $line );
		foreach ( $columns as $index => $field ) {
			$row[ $field ] = isset( $data[ $index ] ) ? $data[ $index ] : '';
		}
		$result['rows'][] = $row;
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	return $result;
}

/**
 * Crée une remarque de rapport d'import.
 *
 * @param int    $line    Numéro de ligne dans le fichier.
 * @param string $name    Nom du magasin.
 * @param string $message Message.
 * @param string $level   error (ligne ignorée) ou warning (ligne importée).
 * @return array
 */
function saito_sl_issue( $line, $name, $message, $level = 'error' ) {
	return array(
		'line'    => (int) $line,
		'name'    => sanitize_text_field( (string) $name ),
		'message' => $message,
		'level'   => $level,
	);
}

/**
 * Normalise et valide des lignes (issues du CSV ou de l'ancien stores.json).
 *
 * @param array $rows    Lignes associatives (clés internes ou anciennes clés du JSON 1.3.x).
 * @param bool  $geocode Géocoder les adresses sans coordonnées.
 * @return array{stores:array,issues:array,stats:array}
 */
function saito_sl_import_rows( array $rows, $geocode = true ) {
	$aliases  = saito_sl_header_aliases();
	$stores   = array();
	$issues   = array();
	$seen     = array();
	$stats    = array(
		'rows'     => count( $rows ),
		'imported' => 0,
		'inactive' => 0,
		'skipped'  => 0,
		'geocoded' => 0,
	);
	$geocodes = 0;

	foreach ( $rows as $i => $raw ) {
		if ( ! is_array( $raw ) ) {
			continue;
		}
		// Accepte les clés de l'ancien format (id_store, latitude…) comme les clés internes.
		$row = array();
		foreach ( $raw as $key => $value ) {
			$norm         = saito_sl_normalize_header( $key );
			$field        = isset( $aliases[ $norm ] ) ? $aliases[ $norm ] : $key;
			if ( is_array( $value ) ) {
				// Liste déjà enregistrée (services) ; les horaires calculés sont recalculés depuis le texte.
				$value = 'services' === $field ? implode( ', ', array_filter( $value, 'is_scalar' ) ) : '';
			}
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value && isset( $row[ $field ] ) && '' !== $row[ $field ] ) {
				continue; // Ne pas écraser une valeur déjà lue par un alias vide.
			}
			$row[ $field ] = $value;
		}
		$line = isset( $raw['_line'] ) ? (int) $raw['_line'] : $i + 2;
		$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';

		if ( isset( $row['active'] ) && '' !== $row['active'] && ! in_array( strtolower( remove_accents( $row['active'] ) ), array( '1', 'oui', 'yes', 'true', 'vrai', 'x' ), true ) ) {
			++$stats['inactive'];
			continue;
		}

		if ( '' === $name ) {
			$issues[] = saito_sl_issue( $line, '', __( 'Ligne ignorée : nom du magasin manquant.', 'saito-store-locator' ) );
			++$stats['skipped'];
			continue;
		}

		$store = array(
			'id'       => sanitize_text_field( isset( $row['id'] ) ? $row['id'] : '' ),
			'name'     => $name,
			'address1' => sanitize_text_field( isset( $row['address1'] ) ? $row['address1'] : '' ),
			'address2' => sanitize_text_field( isset( $row['address2'] ) ? $row['address2'] : '' ),
			'postcode' => sanitize_text_field( isset( $row['postcode'] ) ? $row['postcode'] : '' ),
			'city'     => sanitize_text_field( isset( $row['city'] ) ? $row['city'] : '' ),
			'country'  => sanitize_text_field( isset( $row['country'] ) ? $row['country'] : '' ),
			'phone'    => sanitize_text_field( isset( $row['phone'] ) ? $row['phone'] : '' ),
			'website'  => isset( $row['website'] ) ? esc_url_raw( $row['website'], array( 'http', 'https' ) ) : '',
			'lat'      => null,
			'lng'      => null,
			'icone'    => sanitize_key( isset( $row['icone'] ) ? $row['icone'] : '' ),
			'brand'    => sanitize_text_field( isset( $row['brand'] ) ? $row['brand'] : '' ),
			'services' => saito_sl_split_services( isset( $row['services'] ) ? $row['services'] : '' ),
			'hours_text' => isset( $row['hours'] ) ? trim( sanitize_textarea_field( str_replace( '|', "\n", $row['hours'] ) ) ) : '',
			'hours'    => null,
			'slug'     => '',
		);
		if ( '' !== $store['hours_text'] ) {
			$store['hours'] = saito_sl_parse_hours( $store['hours_text'] );
			if ( null === $store['hours'] ) {
				$issues[] = saito_sl_issue( $line, $name, __( 'Horaires affichés tels quels (format non reconnu pour le calcul « Ouvert maintenant ») : ', 'saito-store-locator' ) . $store['hours_text'], 'warning' );
			}
		}

		$is_france = saito_sl_is_france( $store['country'] );

		// Code postal français à 4 chiffres (zéro initial perdu par le tableur).
		if ( $is_france && preg_match( '/^\d{4}$/', $store['postcode'] ) ) {
			$store['postcode'] = '0' . $store['postcode'];
		}

		$lat = saito_sl_parse_coord( isset( $row['lat'] ) ? $row['lat'] : '', 90 );
		$lng = saito_sl_parse_coord( isset( $row['lng'] ) ? $row['lng'] : '', 180 );

		if ( null === $lat || null === $lng ) {
			$address = trim( $store['address1'] . ' ' . $store['postcode'] . ' ' . $store['city'] );
			$found   = null;
			if ( $geocode && $is_france && '' !== trim( $store['city'] . $store['postcode'] ) && $geocodes < SAITO_SL_MAX_GEOCODE ) {
				++$geocodes;
				$found = saito_sl_geocode( $address );
			}
			if ( ! $found ) {
				$reason   = $is_france ? __( 'Ligne ignorée : coordonnées manquantes ou invalides, et adresse introuvable automatiquement.', 'saito-store-locator' ) : __( 'Ligne ignorée : coordonnées manquantes ou invalides (géocodage automatique disponible uniquement pour la France).', 'saito-store-locator' );
				$issues[] = saito_sl_issue( $line, $name, $reason );
				++$stats['skipped'];
				continue;
			}
			list( $lat, $lng ) = $found;
			++$stats['geocoded'];
			/* translators: 1: latitude, 2: longitude. */
			$issues[] = saito_sl_issue( $line, $name, sprintf( __( 'Coordonnées calculées automatiquement à partir de l\'adresse (%1$s, %2$s) : vérifiez la position sur la carte.', 'saito-store-locator' ), $lat, $lng ), 'warning' );
		}

		$store['lat'] = $lat;
		$store['lng'] = $lng;

		$key = strtolower( $name ) . '|' . $lat . '|' . $lng;
		if ( isset( $seen[ $key ] ) ) {
			$issues[] = saito_sl_issue( $line, $name, __( 'Ligne ignorée : doublon de la ligne ', 'saito-store-locator' ) . $seen[ $key ] . '.' );
			++$stats['skipped'];
			continue;
		}
		$seen[ $key ] = $line;

		if ( '' === $store['postcode'] || '' === $store['city'] ) {
			$issues[] = saito_sl_issue( $line, $name, __( 'Code postal ou ville manquant : le magasin ne sera pas trouvé par la recherche sur sa commune.', 'saito-store-locator' ), 'warning' );
		}

		$stores[] = $store;
		++$stats['imported'];
	}

	saito_sl_assign_slugs( $stores );

	return array(
		'stores' => $stores,
		'issues' => $issues,
		'stats'  => $stats,
	);
}

/**
 * Lit et valide un CSV complet.
 *
 * @param string $content Contenu du fichier.
 * @param bool   $geocode Géocoder les adresses sans coordonnées.
 * @return array{stores:array,issues:array,stats:array,fatal:string}
 */
function saito_sl_import_csv( $content, $geocode = true ) {
	$parsed = saito_sl_parse_csv( $content );
	if ( $parsed['fatal'] ) {
		return array(
			'stores' => array(),
			'issues' => $parsed['issues'],
			'stats'  => array(
				'rows'     => 0,
				'imported' => 0,
				'inactive' => 0,
				'skipped'  => count( $parsed['issues'] ),
				'geocoded' => 0,
			),
			'fatal'  => $parsed['fatal'],
		);
	}

	$result                     = saito_sl_import_rows( $parsed['rows'], $geocode );
	$result['issues']           = array_merge( $parsed['issues'], $result['issues'] );
	$result['stats']['rows']   += count( $parsed['issues'] );
	$result['stats']['skipped'] += count( $parsed['issues'] );
	$result['fatal']            = 0 === count( $result['stores'] ) ? __( 'Aucun magasin valide dans le fichier.', 'saito-store-locator' ) : '';

	usort(
		$result['issues'],
		function ( $a, $b ) {
			return $a['line'] - $b['line'];
		}
	);

	return $result;
}

/**
 * Découpe la colonne « services » (séparateurs : virgule, point-virgule, barre verticale).
 *
 * @param string|array $value Valeur brute.
 * @return string[]
 */
function saito_sl_split_services( $value ) {
	$parts = is_array( $value ) ? $value : preg_split( '/[,;|]/', (string) $value );
	$parts = array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) $parts ) ) );
	return array_slice( array_values( array_unique( $parts ) ), 0, 10 );
}

/**
 * Le pays est-il la France (ou non renseigné) ?
 *
 * @param string $country Pays.
 * @return bool
 */
function saito_sl_is_france( $country ) {
	$country = strtolower( remove_accents( trim( (string) $country ) ) );
	return in_array( $country, array( '', 'france', 'fr', 'fra' ), true );
}

/**
 * Convertit une coordonnée (« 48,85 » ou « 48.85 ») en nombre, ou null si invalide.
 *
 * @param string $value Valeur brute.
 * @param int    $max   Valeur absolue maximale (90 ou 180).
 * @return float|null
 */
function saito_sl_parse_coord( $value, $max ) {
	$value = str_replace( array( ',', ' ' ), array( '.', '' ), trim( (string) $value ) );
	if ( '' === $value || ! is_numeric( $value ) ) {
		return null;
	}
	$number = (float) $value;
	if ( abs( $number ) > $max || 0.0 === $number ) {
		return null;
	}
	return round( $number, 7 );
}

/**
 * Géocode une adresse française via le service public de la Géoplateforme (IGN).
 *
 * @param string $address Adresse complète.
 * @return array{0:float,1:float}|null [lat, lng]
 */
function saito_sl_geocode( $address ) {
	$address = trim( preg_replace( '/\s+/', ' ', $address ) );
	if ( strlen( $address ) < 3 ) {
		return null;
	}

	$cache_key = 'saito_sl_geo_' . md5( strtolower( $address ) );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached ? $cached : null;
	}

	$url      = add_query_arg(
		array(
			'q'     => rawurlencode( $address ),
			'limit' => 1,
		),
		'https://data.geopf.fr/geocodage/search'
	);
	$response = wp_safe_remote_get( $url, array( 'timeout' => 6 ) );
	$found    = array();

	if ( ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response ) ) {
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['features'][0]['geometry']['coordinates'] ) ) {
			$score = isset( $body['features'][0]['properties']['score'] ) ? (float) $body['features'][0]['properties']['score'] : 0;
			if ( $score >= 0.5 ) {
				$coords = $body['features'][0]['geometry']['coordinates'];
				$found  = array( round( (float) $coords[1], 7 ), round( (float) $coords[0], 7 ) );
			}
		}
	} elseif ( is_wp_error( $response ) ) {
		return null; // Erreur réseau : ne pas mettre en cache.
	}

	set_transient( $cache_key, $found, 30 * DAY_IN_SECONDS );
	return $found ? $found : null;
}
