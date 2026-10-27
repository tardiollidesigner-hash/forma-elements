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

function forma_elements_enqueue_ui() {
	wp_enqueue_style( 'forma-elements-ui', forma_elements_asset_url( 'css/forma-ui.css' ), [], FORMA_ELEMENTS_VERSION );
	forma_elements_enqueue_brand_script();
}

function forma_elements_enqueue_editor_ui() {
	wp_enqueue_style( 'forma-elements-ui', forma_elements_asset_url( 'css/forma-ui.css' ), [], FORMA_ELEMENTS_VERSION );
	wp_enqueue_style( 'forma-elements-editor', forma_elements_asset_url( 'css/editor.css' ), [ 'forma-elements-ui' ], FORMA_ELEMENTS_VERSION );
}

function forma_elements_enqueue_brand_script() {
	wp_enqueue_script( 'forma-elements-brand', forma_elements_asset_url( 'js/brand.js' ), [], FORMA_ELEMENTS_VERSION, true );
	wp_localize_script( 'forma-elements-brand', 'formaElementsBrand', [
		'logoUrl' => forma_elements_asset_url( 'img/forma-logo.svg' ),
		'tag'     => 'Elements',
	] );
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
