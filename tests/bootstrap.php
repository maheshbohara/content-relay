<?php
/**
 * PHPUnit bootstrap: loads plugin classes with the few WordPress functions they call shimmed.
 *
 * Unit tests cover pure logic only (content scanning/editing, signing, fingerprints, matching). Anything that
 * needs a database is exercised by the end-to-end scenarios in the spec (§17), against the local clone.
 *
 * @package ContentRelay
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Stands in for WordPress core's constant in unit tests.
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's wp_json_encode().
	 *
	 * @param mixed $value Value to encode.
	 * @param int   $flags json_encode() flags.
	 * @param int   $depth Maximum depth.
	 * @return string|false
	 */
	function wp_json_encode( $value, $flags = 0, $depth = 512 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		return json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This is the wp_json_encode() shim itself.
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's esc_html().
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's wp_parse_url().
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- This is the wp_parse_url() shim itself.
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's untrailingslashit().
	 *
	 * @param string $value Value.
	 * @return string
	 */
	function untrailingslashit( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's wp_mkdir_p().
	 *
	 * @param string $target Directory.
	 * @return bool
	 */
	function wp_mkdir_p( $target ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		return is_dir( $target ) || mkdir( $target, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- This is the wp_mkdir_p() shim itself.
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's wp_delete_file().
	 *
	 * @param string $file File.
	 */
	function wp_delete_file( $file ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- This is the wp_delete_file() shim itself.
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's get_option(), backed by $GLOBALS['content_relay_test_options'].
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Value when the option is absent.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		return $GLOBALS['content_relay_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's update_option().
	 *
	 * @param string    $option   Option name.
	 * @param mixed     $value    Value.
	 * @param bool|null $autoload Ignored.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Shim of a WordPress core function.
		$GLOBALS['content_relay_test_options'][ $option ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only option store.
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's apply_filters(): no callbacks are registered, so the value passes through.
	 *
	 * @param string $hook_name Hook.
	 * @param mixed  $value     Value.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Shim of a WordPress core function.
		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Unit-test stand-in for WordPress core's do_action(): records the hook in $GLOBALS['content_relay_test_actions'].
	 *
	 * @param string $hook_name Hook.
	 * @param mixed  ...$args   Arguments.
	 */
	function do_action( $hook_name, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Shim of a WordPress core function.
		$GLOBALS['content_relay_test_actions'][] = array( $hook_name, $args ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test-only action log.
	}
}

require_once dirname( __DIR__ ) . '/includes/class-autoloader.php';
\ContentRelay\Autoloader::register();
