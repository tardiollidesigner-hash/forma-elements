<?php
/**
 * Identidade visual Forma UI: logo "forma" + "Elements", cores, fonte e editor sempre escuro.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function forma_elements_init_ui() {
	add_action( 'admin_enqueue_scripts', 'forma_elements_enqueue_ui' );
	add_action( 'elementor/editor/after_enqueue_styles', 'forma_elements_enqueue_editor_ui' );
	add_action( 'elementor/editor/after_enqueue_scripts', 'forma_elements_enqueue_brand_script' );

	// O Forma UI é escuro: o editor ignora a escolha claro/automático e a opção sai das preferências.
	add_filter( 'get_user_metadata', 'forma_elements_force_dark_editor', 10, 3 );
	add_action( 'elementor/element/editor-preferences/preferences/before_section_end', 'forma_elements_remove_theme_control' );
}

function forma_elements_asset_url( $path ) {
	return plugins_url( 'assets/' . $path, FORMA_ELEMENTS_FILE );
}

/** Versão para o cache do navegador: na cópia de desenvolvimento muda a cada alteração do arquivo. */
function forma_elements_asset_version( $path ) {
	if ( '0.0.0-dev' === FORMA_ELEMENTS_VERSION ) {
		return (string) filemtime( FORMA_ELEMENTS_PATH . 'assets/' . $path );
	}
	return FORMA_ELEMENTS_VERSION;
}

function forma_elements_enqueue_ui() {
	wp_enqueue_style( 'forma-elements-ui', forma_elements_asset_url( 'css/forma-ui.css' ), [], forma_elements_asset_version( 'css/forma-ui.css' ) );
	forma_elements_enqueue_brand_script();
}

function forma_elements_enqueue_editor_ui() {
	wp_enqueue_style( 'forma-elements-ui', forma_elements_asset_url( 'css/forma-ui.css' ), [], forma_elements_asset_version( 'css/forma-ui.css' ) );
	wp_enqueue_style( 'forma-elements-editor', forma_elements_asset_url( 'css/editor.css' ), [ 'forma-elements-ui' ], forma_elements_asset_version( 'css/editor.css' ) );
}

function forma_elements_enqueue_brand_script() {
	wp_enqueue_script( 'forma-elements-brand', forma_elements_asset_url( 'js/brand.js' ), [], forma_elements_asset_version( 'js/brand.js' ), true );
	wp_localize_script( 'forma-elements-brand', 'formaElementsBrand', [
		'logoSvg' => forma_elements_logo_svg(),
		'tag'     => 'Elements',
	] );
}

/** Logo "forma" como SVG embutido (sem imagem externa que possa falhar ao carregar). */
function forma_elements_logo_svg() {
	$svg = (string) file_get_contents( FORMA_ELEMENTS_PATH . 'assets/img/forma-logo.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return trim( str_replace( '<svg ', '<svg class="forma-brand__logo" role="img" aria-label="Forma" ', $svg ) );
}

function forma_elements_force_dark_editor( $value, $object_id, $meta_key ) {
	if ( 'elementor_preferences' !== $meta_key ) {
		return $value;
	}

	// Lê o valor salvo sem passar de novo por este filtro.
	remove_filter( 'get_user_metadata', 'forma_elements_force_dark_editor', 10 );
	$preferences = get_user_meta( $object_id, $meta_key, true );
	add_filter( 'get_user_metadata', 'forma_elements_force_dark_editor', 10, 3 );

	$preferences             = is_array( $preferences ) ? $preferences : [];
	$preferences['ui_theme'] = 'dark';

	// get_metadata() espera uma lista de valores quando o filtro responde.
	return [ $preferences ];
}

function forma_elements_remove_theme_control( $element ) {
	$element->remove_control( 'ui_theme' );
}
