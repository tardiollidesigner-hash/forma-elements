<?php
/**
 * Compatibilidade com addons de Elementor (ElementsKit, Royal Addons, Essential Addons...).
 *
 * Os addons procuram o Elementor de três jeitos, e cada um é atendido aqui:
 * 1. Classes/constantes/hooks (`did_action( 'elementor/loaded' )`): já funcionam, o código é o original.
 * 2. `is_plugin_active( 'elementor/elementor.php' )`: o caminho é "fingido" como ativo.
 * 3. Cabeçalho "Requires Plugins: elementor" (WordPress 6.5+): a dependência aponta para o Forma Elements.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FORMA_ELEMENTS_EMULATED_PLUGINS = [
	'elementor/elementor.php',
	'elementor-pro/elementor-pro.php',
	'pro-elements/pro-elements.php',
];

function forma_elements_init_compat() {
	add_filter( 'option_active_plugins', 'forma_elements_emulate_active_plugins' );
	add_filter( 'pre_update_option_active_plugins', 'forma_elements_clean_active_plugins' );
	add_filter( 'wp_plugin_dependencies_slug', 'forma_elements_map_dependency_slug' );

	add_action( 'all_admin_notices', 'forma_elements_start_plugin_validation', PHP_INT_MAX );
	add_action( 'pre_current_active_plugins', 'forma_elements_end_plugin_validation', 0 );
}

function forma_elements_emulate_active_plugins( $plugins ) {
	if ( forma_elements_is_validating_plugins() ) {
		return $plugins;
	}

	return array_values( array_unique( array_merge( (array) $plugins, FORMA_ELEMENTS_EMULATED_PLUGINS ) ) );
}

/**
 * A tela Plugins confere se cada plugin ativo existe (validate_active_plugins()) depois dos avisos
 * do painel e antes de desenhar a lista. Só nesse trecho os caminhos fingidos ficam de fora; senão o
 * WordPress desativaria "elementor/elementor.php" com o erro "o arquivo do plugin não existe".
 * Nos avisos dos addons, que vêm antes, o Elementor continua aparecendo como ativo.
 */
function forma_elements_is_validating_plugins( $set = null ) {
	static $validating = false;
	if ( null !== $set ) {
		$validating = $set;
	}
	return $validating && isset( $GLOBALS['pagenow'] ) && 'plugins.php' === $GLOBALS['pagenow'];
}

function forma_elements_start_plugin_validation() {
	forma_elements_is_validating_plugins( true );
}

function forma_elements_end_plugin_validation() {
	forma_elements_is_validating_plugins( false );
}

/**
 * Nunca grava os caminhos fingidos no banco e mantém o Forma Elements como
 * primeiro plugin a carregar, antes de qualquer addon.
 */
function forma_elements_clean_active_plugins( $plugins ) {
	$plugins = array_values( array_diff( (array) $plugins, FORMA_ELEMENTS_EMULATED_PLUGINS ) );

	$index = array_search( FORMA_ELEMENTS_BASENAME, $plugins, true );
	if ( false !== $index ) {
		array_splice( $plugins, $index, 1 );
		array_unshift( $plugins, FORMA_ELEMENTS_BASENAME );
	}

	return $plugins;
}

function forma_elements_map_dependency_slug( $slug ) {
	if ( in_array( $slug, [ 'elementor', 'elementor-pro', 'pro-elements' ], true ) ) {
		return dirname( FORMA_ELEMENTS_BASENAME );
	}
	return $slug;
}
