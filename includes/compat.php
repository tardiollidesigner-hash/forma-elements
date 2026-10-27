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
}

function forma_elements_emulate_active_plugins( $plugins ) {
	if ( forma_elements_is_plugin_management_screen() ) {
		return $plugins;
	}

	return array_values( array_unique( array_merge( (array) $plugins, FORMA_ELEMENTS_EMULATED_PLUGINS ) ) );
}

/**
 * Nas telas que gerenciam plugins a lista de ativos tem que ser a real. Senão o WordPress acusaria
 * "o arquivo do plugin não existe" e, com o Elementor original instalado (desativado), mostraria
 * ele como ativo. Os addons não dependem dessas telas para funcionar.
 */
function forma_elements_is_plugin_management_screen() {
	return is_admin()
		&& isset( $GLOBALS['pagenow'] )
		&& in_array( $GLOBALS['pagenow'], [ 'plugins.php', 'plugin-install.php', 'plugin-editor.php', 'update.php', 'update-core.php' ], true );
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
