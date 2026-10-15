<?php
/**
 * Mise en forme des magasins : enseigne, nom lisible, horaires, téléphone, identifiant d'URL.
 *
 * Les mêmes règles sont appliquées côté navigateur (assets/js/storelocator.js).
 *
 * @package SaitoStoreLocator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Jours de la semaine (index 0 = lundi).
 *
 * @return string[]
 */
function saito_sl_day_names() {
	return array(
		__( 'Lundi', 'saito-store-locator' ),
		__( 'Mardi', 'saito-store-locator' ),
		__( 'Mercredi', 'saito-store-locator' ),
		__( 'Jeudi', 'saito-store-locator' ),
		__( 'Vendredi', 'saito-store-locator' ),
		__( 'Samedi', 'saito-store-locator' ),
		__( 'Dimanche', 'saito-store-locator' ),
	);
}

/**
 * Met en casse lisible un texte saisi en MAJUSCULES (« MAISON DUPONT LE BOUSCAT » → « Maison Dupont Le Bouscat »).
 * Un texte déjà en minuscules/majuscules mélangées est laissé tel quel.
 *
 * @param string $text Texte.
 * @return string
 */
function saito_sl_title_case( $text ) {
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	if ( '' === $text || preg_match( '/\p{Ll}/u', $text ) ) {
		return $text;
	}
	$keep_upper = array( 'cc', 'ri', 'sas', 'zac', 'zi', 'za', 'cv' );
	$elided     = array( 'd', 'l' );
	$small      = array( 'de', 'du', 'des', 'la', 'le', 'les', 'et', 'en', 'sur', 'sous', 'aux', 'au', 'a', 'lès', 'les' );
	$parts      = preg_split( "/([\\s\\-'’]+)/u", $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	$out        = '';
	$prev_delim = '';
	$skip       = -1;
	foreach ( $parts as $i => $part ) {
		if ( 1 === $i % 2 ) {
			if ( $i === $skip ) {
				continue; // Espace remplacée par l'apostrophe.
			}
			$out       .= $part;
			$prev_delim = $part;
			continue;
		}
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $part, 'UTF-8' ) : strtolower( $part );
		$next  = isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '';
		if ( in_array( $lower, $keep_upper, true ) ) {
			$word = strtoupper( $lower );
		} elseif ( $i > 0 && in_array( $lower, $elided, true ) && preg_match( "/['’]/u", $next ) ) {
			$word = $lower; // d'Ornon, l'Isle.
		} elseif ( ( $i > 0 || 'l' === $lower ) && in_array( $lower, $elided, true ) && ' ' === $next && isset( $parts[ $i + 2 ] ) && preg_match( '/^[aeiouyhàâéèêëîïôûü]/iu', $parts[ $i + 2 ] ) ) {
			$word = ( 0 === $i ? strtoupper( $lower ) : $lower ) . "'"; // « D ORNON » saisi sans apostrophe → d'Ornon.
			$skip = $i + 1;
		} elseif ( $i > 0 && '-' === trim( $prev_delim ) && in_array( $lower, $small, true ) ) {
			$word = $lower; // Saint-Jean-de-Luz.
		} elseif ( 'st' === $lower || 'ste' === $lower ) {
			$word = ucfirst( $lower );
		} else {
			$first = function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $lower, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_substr( $lower, 1, null, 'UTF-8' ) : ucfirst( $lower );
			$word  = $first;
		}
		$out .= $word;
	}
	return $out;
}

/**
 * Liste des enseignes déclarées dans les réglages.
 *
 * @return string[]
 */
function saito_sl_brands() {
	$settings = saito_sl_get_settings();
	return array_values( array_filter( array_map( 'trim', explode( "\n", (string) $settings['brands'] ) ) ) );
}

/**
 * Enseigne du magasin : colonne « enseigne », sinon début du nom, sinon chaîne vide.
 *
 * @param array $store Magasin.
 * @return string
 */
function saito_sl_store_brand( array $store ) {
	if ( ! empty( $store['brand'] ) ) {
		return $store['brand'];
	}
	$name = strtolower( remove_accents( $store['name'] ) );
	foreach ( saito_sl_brands() as $brand ) {
		if ( '' !== $brand && 0 === strpos( $name, strtolower( remove_accents( $brand ) ) ) ) {
			return $brand;
		}
	}
	return '';
}

/**
 * Nom complet lisible (« Maison Dupont Le Bouscat »).
 *
 * @param array $store Magasin.
 * @return string
 */
function saito_sl_store_title( array $store ) {
	return saito_sl_title_case( $store['name'] );
}

/**
 * Nom sans l'enseigne (« Le Bouscat »), pour les fiches où l'enseigne est affichée à part.
 *
 * @param array $store Magasin.
 * @return string
 */
function saito_sl_store_short_title( array $store ) {
	$brand = saito_sl_store_brand( $store );
	$title = saito_sl_store_title( $store );
	if ( $brand && 0 === stripos( remove_accents( $title ), remove_accents( $brand ) ) ) {
		$rest = trim( substr( $title, strlen( $brand ) ) );
		if ( '' !== $rest && strlen( $rest ) > 1 ) {
			return $rest;
		}
	}
	return $title;
}

/**
 * Téléphone français lisible (« 0556000000 » → « 05 56 00 00 00 »).
 *
 * @param string $phone Téléphone.
 * @return string
 */
function saito_sl_format_phone( $phone ) {
	$digits = preg_replace( '/\D/', '', (string) $phone );
	if ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
		return trim( chunk_split( $digits, 2, ' ' ) );
	}
	return trim( (string) $phone );
}

/**
 * Lit des horaires saisis librement, par exemple :
 *   « Lundi-Samedi 10h-19h ; Dimanche fermé »
 *   « Lun au ven 9h30-12h30 14h-19h | Sam 10:00-18:00 »
 *   « Tous les jours 10h-20h »
 * Segments séparés par « ; », « | » ou un retour à la ligne.
 *
 * @param string $text Horaires.
 * @return array|null 7 listes de créneaux [ouverture, fermeture] (lundi en premier), ou null si illisible.
 */
function saito_sl_parse_hours( $text ) {
	$text = strtolower( remove_accents( trim( (string) $text ) ) );
	if ( '' === $text ) {
		return null;
	}
	$text = str_replace( array( '–', '—', '−' ), '-', $text );

	$day_index = array(
		'lundi'    => 0,
		'mardi'    => 1,
		'mercredi' => 2,
		'jeudi'    => 3,
		'vendredi' => 4,
		'samedi'   => 5,
		'dimanche' => 6,
		'lun'      => 0,
		'mar'      => 1,
		'mer'      => 2,
		'jeu'      => 3,
		'ven'      => 4,
		'sam'      => 5,
		'dim'      => 6,
	);
	$day_re    = '(lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche|lun|mar|mer|jeu|ven|sam|dim)\.?';
	$week      = array_fill( 0, 7, array() );
	$found     = false;

	foreach ( preg_split( '/[;|\n]+/', $text ) as $segment ) {
		$segment = trim( $segment );
		if ( '' === $segment ) {
			continue;
		}

		$days = array();
		if ( preg_match( '/tous les jours|7\s*j\s*\/\s*7|7j7/', $segment ) ) {
			$days = range( 0, 6 );
		} elseif ( preg_match_all( '/\b' . $day_re . '(?:\s*(?:-|au|a)\s*' . $day_re . ')?/', $segment, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$start = $day_index[ $m[1] ];
				if ( ! empty( $m[2] ) ) {
					$end = $day_index[ $m[2] ];
					for ( $d = $start, $guard = 0; $guard < 7; $d = ( $d + 1 ) % 7, $guard++ ) {
						$days[] = $d;
						if ( $d === $end ) {
							break;
						}
					}
				} else {
					$days[] = $start;
				}
			}
		}
		if ( ! $days ) {
			return null; // Jours introuvables : on se contente d'afficher le texte.
		}

		if ( preg_match( '/ferme/', $segment ) ) {
			foreach ( $days as $d ) {
				$week[ $d ] = array();
			}
			$found = true;
			continue;
		}

		$slots = array();
		if ( preg_match_all( '/(\d{1,2})\s*(?:[h:](\d{2})?)?\s*(?:-|au|a)\s*(\d{1,2})\s*(?:[h:](\d{2})?)?/', $segment, $times, PREG_SET_ORDER ) ) {
			foreach ( $times as $t ) {
				$open  = array( (int) $t[1], isset( $t[2] ) && '' !== $t[2] ? (int) $t[2] : 0 );
				$close = array( (int) $t[3], isset( $t[4] ) && '' !== $t[4] ? (int) $t[4] : 0 );
				if ( $open[0] > 24 || $close[0] > 24 || $open[1] > 59 || $close[1] > 59 ) {
					return null;
				}
				$o = sprintf( '%02d:%02d', $open[0], $open[1] );
				$c = sprintf( '%02d:%02d', $close[0], $close[1] );
				if ( $c <= $o ) {
					return null;
				}
				$slots[] = array( $o, $c );
			}
		}
		if ( ! $slots ) {
			return null;
		}
		foreach ( array_unique( $days ) as $d ) {
			$week[ $d ] = array_merge( $week[ $d ], $slots );
		}
		$found = true;
	}

	return $found ? $week : null;
}

/**
 * Attribue à chaque magasin un identifiant d'URL unique (« maison-dupont-le-bouscat »).
 *
 * @param array $stores Magasins (modifiés).
 */
function saito_sl_assign_slugs( array &$stores ) {
	$seen = array();
	foreach ( $stores as &$store ) {
		$base = sanitize_title( $store['name'] );
		if ( '' === $base ) {
			$base = 'magasin-' . sanitize_title( $store['id'] );
		}
		$slug = $base;
		if ( isset( $seen[ $slug ] ) && $store['city'] ) {
			$slug = $base . '-' . sanitize_title( $store['city'] );
		}
		for ( $n = 2; isset( $seen[ $slug ] ); $n++ ) {
			$slug = $base . '-' . $n;
		}
		$seen[ $slug ]  = true;
		$store['slug'] = $slug;
	}
	unset( $store );
}

/**
 * Magasin correspondant à un identifiant d'URL.
 *
 * @param string $slug Identifiant.
 * @return array|null
 */
function saito_sl_find_store( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug ) {
		return null;
	}
	foreach ( saito_sl_get_stores() as $store ) {
		if ( isset( $store['slug'] ) && $store['slug'] === $slug ) {
			return $store;
		}
	}
	return null;
}

/**
 * Adresse d'une ligne (« 7 avenue de la Libération, 33110 Le Bouscat »).
 *
 * @param array $store Magasin.
 * @return string
 */
function saito_sl_store_address( array $store ) {
	$city = saito_sl_title_case( $store['city'] );
	$line = array_filter( array( $store['address1'], $store['address2'], trim( $store['postcode'] . ' ' . $city ) ) );
	if ( ! saito_sl_is_france( $store['country'] ) ) {
		$line[] = $store['country'];
	}
	return implode( ', ', $line );
}
