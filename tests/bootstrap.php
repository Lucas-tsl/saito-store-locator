<?php
/**
 * Amorce des tests sans WordPress (pas de PHPUnit) : remplaçants minimaux des fonctions
 * WordPress utilisées par les fonctions testées, puis chargement des modules du plugin.
 * Exécuté par la CI via `php tests/test-*.php`.
 *
 * @package SaitoStoreLocator
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'SAITO_SL_VERSION', 'test' );
define( 'SAITO_SL_DIR', dirname( __DIR__ ) . '/' );
define( 'SAITO_SL_URL', 'https://example.com/wp-content/plugins/saito-store-locator/' );
define( 'SAITO_SL_OPTION', 'saito_sl_settings' );
define( 'SAITO_SL_CAP', 'manage_options' );
define( 'SAITO_SL_CRON_HOOK', 'saito_sl_daily_sync' );

$GLOBALS['saito_sl_test_options'] = array(
	'admin_email' => 'admin@example.com',
	'blogname'    => 'Boutique Exemple',
);

function __( $text ) {
	return $text;
}
function _n( $single, $plural, $number ) {
	return 1 === (int) $number ? $single : $plural;
}
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['saito_sl_test_options'] ) ? $GLOBALS['saito_sl_test_options'][ $name ] : $default;
}
function update_option( $name, $value ) {
	$GLOBALS['saito_sl_test_options'][ $name ] = $value;
	return true;
}
function get_bloginfo() {
	return get_option( 'blogname' );
}
function home_url( $path = '' ) {
	return 'https://example.com' . $path;
}
function get_permalink( $id ) {
	return 'https://example.com/points-de-vente/';
}
function wp_specialchars_decode( $text ) {
	return htmlspecialchars_decode( (string) $text, ENT_QUOTES );
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function absint( $value ) {
	return abs( (int) $value );
}
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text ) ) );
}
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $text ) ) );
}
function sanitize_textarea_field( $text ) {
	return trim( wp_strip_all_tags( (string) $text ) );
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_email( $email ) {
	return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
}
function remove_accents( $text ) {
	return strtr(
		(string) $text,
		array(
			'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
			'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ÿ' => 'y',
			'À' => 'A', 'Â' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Î' => 'I',
			'Ï' => 'I', 'Ô' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
		)
	);
}
function sanitize_title( $title ) {
	$title = strtolower( remove_accents( wp_strip_all_tags( (string) $title ) ) );
	return trim( preg_replace( '/[^a-z0-9]+/', '-', $title ), '-' );
}
function esc_url_raw( $url, $protocols = array( 'http', 'https' ) ) {
	$scheme = strtolower( (string) parse_url( (string) $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, $protocols, true ) ? (string) $url : '';
}
function add_query_arg( $key, $value, $url ) {
	$url = remove_query_arg( $key, $url );
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value;
}
function remove_query_arg( $key, $url ) {
	return preg_replace( '/([?&])' . preg_quote( $key, '/' ) . '=[^&#]*&?/', '$1', $url );
}
function wp_html_excerpt( $text, $length, $more = '' ) {
	return mb_strlen( $text ) > $length ? mb_substr( $text, 0, $length ) . $more : $text;
}

require SAITO_SL_DIR . 'includes/settings.php';
require SAITO_SL_DIR . 'includes/store.php';
require SAITO_SL_DIR . 'includes/import.php';
require SAITO_SL_DIR . 'includes/frontend.php';
require SAITO_SL_DIR . 'includes/seo.php';

$GLOBALS['saito_sl_failures'] = 0;

/**
 * Vérifie une égalité stricte et affiche le résultat.
 *
 * @param string $label    Libellé.
 * @param mixed  $expected Valeur attendue.
 * @param mixed  $actual   Valeur obtenue.
 */
function saito_sl_assert( $label, $expected, $actual ) {
	if ( $expected !== $actual ) {
		++$GLOBALS['saito_sl_failures'];
		fwrite( STDERR, sprintf( "FAIL: %s (attendu %s, obtenu %s)\n", $label, var_export( $expected, true ), var_export( $actual, true ) ) );
		return;
	}
	echo "OK: {$label}\n";
}

/**
 * Termine le test avec un code de sortie non nul en cas d'échec.
 */
function saito_sl_done() {
	if ( $GLOBALS['saito_sl_failures'] ) {
		fwrite( STDERR, $GLOBALS['saito_sl_failures'] . " échec(s)\n" );
		exit( 1 );
	}
	echo "Tous les tests sont passés.\n";
}
