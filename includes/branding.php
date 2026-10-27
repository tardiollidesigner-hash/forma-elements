<?php
/**
 * Troca o nome "Elementor" / "PRO Elements" por "Forma Elements" nos textos da interface.
 *
 * Só textos de tela: classes, hooks e nomes internos continuam os originais,
 * senão os addons deixam de funcionar.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function forma_elements_brand_map() {
	// strtr() troca sempre o trecho mais longo primeiro, então "Elementor Pro" não vira "Forma Elements Pro".
	return apply_filters( 'forma_elements/brand_map', [
		'PRO Elements'  => 'Forma Elements',
		'Pro Elements'  => 'Forma Elements',
		'Elementor Pro' => 'Forma Elements',
		'Elementor'     => 'Forma Elements',
	] );
}

function forma_elements_rebrand( $text ) {
	if ( ! is_string( $text ) || ( false === strpos( $text, 'Elementor' ) && false === stripos( $text, 'Pro Elements' ) ) ) {
		return $text;
	}
	return strtr( $text, forma_elements_brand_map() );
}

function forma_elements_init_branding() {
	foreach ( [ 'elementor', 'elementor-pro', 'pro-elements' ] as $domain ) {
		add_filter( "gettext_{$domain}", 'forma_elements_rebrand' );
		add_filter( "gettext_with_context_{$domain}", 'forma_elements_rebrand' );
		add_filter( "ngettext_{$domain}", 'forma_elements_rebrand' );
		add_filter( "ngettext_with_context_{$domain}", 'forma_elements_rebrand' );
	}

	// Textos do editor (JavaScript): com arquivo de tradução e, sem ele, pela lista gerada no build.
	add_filter( 'load_script_translations', 'forma_elements_rebrand_script_translations', 10, 4 );
	add_filter( 'pre_load_script_translations', 'forma_elements_fallback_script_translations', 10, 4 );
}

function forma_elements_is_brand_domain( $domain ) {
	return in_array( $domain, [ 'elementor', 'elementor-pro', 'pro-elements' ], true );
}

function forma_elements_rebrand_script_translations( $translations, $file, $handle, $domain ) {
	if ( ! forma_elements_is_brand_domain( $domain ) ) {
		return $translations;
	}
	return forma_elements_branded_script_translations( $translations, $domain );
}

/**
 * O WordPress chama este filtro com $file = false quando o script não tem arquivo de tradução
 * (site em inglês, por exemplo). Aí os textos com a marca vêm só da lista do build.
 */
function forma_elements_fallback_script_translations( $translations, $file, $handle, $domain ) {
	if ( null !== $translations || false !== $file || ! forma_elements_is_brand_domain( $domain ) ) {
		return $translations;
	}
	$branded = forma_elements_branded_script_translations( '', $domain );
	return '' === $branded ? null : $branded;
}

/**
 * Textos do JavaScript que citam a marca, listados por scripts/build.py (bundled/brand-strings.json).
 */
function forma_elements_brand_strings( $domain ) {
	static $strings = null;
	if ( null === $strings ) {
		$file    = FORMA_ELEMENTS_PATH . 'bundled/brand-strings.json';
		$strings = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : [];
	}
	return $strings[ $domain ] ?? [];
}

/**
 * Troca a marca nas traduções de um script e acrescenta os textos com a marca que ainda não
 * têm tradução. O wp.i18n junta as traduções de um mesmo domínio, então a lista vai uma vez por página.
 *
 * @return string JSON de traduções, ou '' quando não há nada a enviar.
 */
function forma_elements_branded_script_translations( $json, $domain ) {
	static $sent = [];

	// Traduções feitas pelo robô (hoje só o PRO Elements tem), entram junto com a lista da marca.
	$extra = 'elementor-pro' === $domain ? forma_elements_pro_script_translations() : [];

	$data = $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $data ) ) {
		if ( ! empty( $sent[ $domain ] ) ) {
			return '';
		}
		$data = [
			'locale_data' => [
				'messages' => [
					'' => $extra[''] ?? [
						'domain'       => 'messages',
						'lang'         => determine_locale(),
						'plural-forms' => 'nplurals=2; plural=(n != 1);',
					],
				],
			],
		];
	}

	$key      = isset( $data['locale_data'][ $domain ] ) ? $domain : 'messages';
	$messages = isset( $data['locale_data'][ $key ] ) && is_array( $data['locale_data'][ $key ] ) ? $data['locale_data'][ $key ] : [];

	foreach ( $messages as $id => $values ) {
		if ( '' !== $id && is_array( $values ) ) {
			$messages[ $id ] = array_map( 'forma_elements_rebrand', $values );
		}
	}

	if ( empty( $sent[ $domain ] ) ) {
		$sent[ $domain ] = true;
		foreach ( $extra as $id => $values ) {
			if ( '' !== $id && ! isset( $messages[ $id ] ) ) {
				$messages[ $id ] = array_map( 'forma_elements_rebrand', (array) $values );
			}
		}
		foreach ( forma_elements_brand_strings( $domain ) as $id ) {
			if ( ! isset( $messages[ $id ] ) ) {
				// Chaves com contexto vêm como "contexto\x04texto".
				$separator       = strpos( $id, "\x04" );
				$text            = false === $separator ? $id : substr( $id, $separator + 1 );
				$messages[ $id ] = [ forma_elements_rebrand( $text ) ];
			}
		}
	}

	$data['locale_data'][ $key ] = $messages;
	return wp_json_encode( $data );
}
