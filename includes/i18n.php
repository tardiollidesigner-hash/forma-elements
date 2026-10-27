<?php
/**
 * Tradução automática.
 *
 * O WordPress só baixa traduções de plugins que ele enxerga como instalados, e o Elementor
 * dentro do pacote não aparece para ele. Aqui o Forma Elements faz esse papel: baixa os pacotes
 * oficiais de tradução do Elementor (translate.wordpress.org) para o idioma do site e mantém
 * atualizados a cada versão nova.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FORMA_ELEMENTS_TRANSLATIONS_CRON  = 'forma_elements_install_translations';
const FORMA_ELEMENTS_TRANSLATIONS_STATE = 'forma_elements_translations_state';

function forma_elements_init_i18n() {
	add_filter( 'load_script_textdomain_relative_path', 'forma_elements_script_relative_path', 1 );

	// O PRO Elements não tem tradução pública: o robô traduz os textos dele (scripts/translate.py) e o
	// build grava em bundled/languages/. Isto só registra a pasta; o WordPress carrega quando precisar.
	load_plugin_textdomain( 'elementor-pro', false, dirname( FORMA_ELEMENTS_BASENAME ) . '/bundled/languages' );

	add_filter( 'gettext_elementor-pro', 'forma_elements_pro_fallback', 5, 2 );
	add_filter( 'gettext_with_context_elementor-pro', 'forma_elements_pro_fallback_with_context', 5, 3 );
	add_filter( 'ngettext_elementor-pro', 'forma_elements_pro_fallback_plural', 5, 4 );

	add_action( 'admin_init', 'forma_elements_maybe_schedule_translations' );
	add_action( FORMA_ELEMENTS_TRANSLATIONS_CRON, 'forma_elements_install_translations' );
}

/**
 * As traduções do editor (JavaScript) são arquivos nomeados pelo caminho do script dentro do plugin.
 * No pacote o caminho ganha "bundled/elementor/" na frente, então o arquivo certo nunca seria achado.
 */
function forma_elements_script_relative_path( $relative_path ) {
	return preg_replace( '#^bundled/(elementor|pro-elements)/#', '', (string) $relative_path );
}

/** Traduções do PRO Elements para o editor (JavaScript), no formato do wp.i18n. */
function forma_elements_pro_script_translations() {
	static $translations = [];
	$locale = determine_locale();
	if ( ! isset( $translations[ $locale ] ) ) {
		$file                     = FORMA_ELEMENTS_PATH . "bundled/languages/elementor-pro-$locale-js.json";
		$translations[ $locale ] = is_readable( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : [];
	}
	return $translations[ $locale ];
}

/**
 * O que ainda faltar no PRO Elements, mas existir igual no Elementor ("Título", "Estilo", "Avançado"...),
 * usa a tradução do Elementor.
 */
function forma_elements_pro_fallback( $translation, $text ) {
	if ( $translation !== $text ) {
		return $translation;
	}
	return translate( $text, 'elementor' );
}

function forma_elements_pro_fallback_with_context( $translation, $text, $context ) {
	if ( $translation !== $text ) {
		return $translation;
	}
	return translate_with_gettext_context( $text, $context, 'elementor' );
}

function forma_elements_pro_fallback_plural( $translation, $single, $plural, $number ) {
	if ( $translation !== $single && $translation !== $plural ) {
		return $translation;
	}
	return _n( $single, $plural, $number, 'elementor' ); // phpcs:ignore WordPress.WP.I18n
}

/** Idiomas em uso no site (do site e dos usuários), sem o inglês, que não precisa de pacote. */
function forma_elements_translation_locales() {
	$locales = array_merge( [ get_locale() ], get_available_languages() );
	return array_values( array_diff( array_unique( $locales ), [ 'en_US', '' ] ) );
}

/**
 * Agenda a instalação quando muda a versão do Elementor ou um idioma, e revisa a cada 12 horas
 * (os tradutores do WordPress.org atualizam os pacotes o tempo todo).
 */
function forma_elements_maybe_schedule_translations() {
	$state = ELEMENTOR_VERSION . '|' . implode( ',', forma_elements_translation_locales() );
	if ( get_option( FORMA_ELEMENTS_TRANSLATIONS_STATE ) === $state && get_transient( FORMA_ELEMENTS_TRANSLATIONS_STATE ) ) {
		return;
	}
	if ( ! wp_next_scheduled( FORMA_ELEMENTS_TRANSLATIONS_CRON ) ) {
		wp_schedule_single_event( time(), FORMA_ELEMENTS_TRANSLATIONS_CRON );
	}
}

/**
 * @return array[] Pacotes do Elementor disponíveis no WordPress.org para a versão incluída.
 */
function forma_elements_available_translations() {
	$response = wp_remote_get(
		add_query_arg(
			[
				'slug'    => 'elementor',
				'version' => ELEMENTOR_VERSION,
			],
			'https://api.wordpress.org/translations/plugins/1.0/'
		),
		[ 'timeout' => 15 ]
	);

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	return isset( $body['translations'] ) && is_array( $body['translations'] ) ? $body['translations'] : [];
}

function forma_elements_install_translations() {
	$locales   = forma_elements_translation_locales();
	$available = $locales ? forma_elements_available_translations() : [];
	if ( null === $available ) {
		return; // Sem internet agora: tenta de novo na próxima visita ao painel.
	}

	$installed = wp_get_installed_translations( 'plugins' );
	$packs     = [];

	foreach ( $available as $translation ) {
		if ( ! in_array( $translation['language'], $locales, true ) ) {
			continue;
		}

		$current = $installed['elementor'][ $translation['language'] ]['PO-Revision-Date'] ?? '';
		if ( $current && strtotime( $current ) >= strtotime( $translation['updated'] ) ) {
			continue;
		}

		$packs[] = (object) [
			'type'       => 'plugin',
			'slug'       => 'elementor',
			'language'   => $translation['language'],
			'version'    => $translation['version'],
			'updated'    => $translation['updated'],
			'package'    => $translation['package'],
			'autoupdate' => true,
		];
	}

	if ( $packs ) {
		// No cron as funções de arquivo/atualização do painel não vêm carregadas.
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$upgrader->bulk_upgrade( $packs, [ 'clear_update_cache' => false ] );
	}

	update_option( FORMA_ELEMENTS_TRANSLATIONS_STATE, ELEMENTOR_VERSION . '|' . implode( ',', $locales ), false );
	set_transient( FORMA_ELEMENTS_TRANSLATIONS_STATE, 1, 12 * HOUR_IN_SECONDS );
}
