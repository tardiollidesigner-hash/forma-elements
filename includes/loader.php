<?php
/**
 * Carrega o Elementor e o PRO Elements que vêm dentro do pacote.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugins que não podem ficar ativos junto com o Forma Elements:
 * eles declaram as mesmas classes e derrubariam o site.
 */
function forma_elements_conflicting_plugins() {
	$known = [
		'elementor/elementor.php'         => 'Elementor',
		'elementor-pro/elementor-pro.php' => 'Elementor Pro',
		'pro-elements/pro-elements.php'   => 'PRO Elements',
	];

	$active = (array) get_option( 'active_plugins', [] );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
	}

	$conflicts = [];
	foreach ( $known as $basename => $name ) {
		// O arquivo precisa existir: o modo de compatibilidade "finge" esses caminhos, mas eles não são reais.
		if ( in_array( $basename, $active, true ) && file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
			$conflicts[] = $name;
		}
	}

	return $conflicts;
}

/**
 * @return bool true quando o Elementor e o PRO Elements foram carregados pelo pacote.
 */
function forma_elements_load_bundle() {
	static $loaded = null;
	if ( null !== $loaded ) {
		return $loaded;
	}

	$conflicts = forma_elements_conflicting_plugins();
	if ( $conflicts || defined( 'ELEMENTOR_VERSION' ) ) {
		if ( ! $conflicts ) {
			$conflicts[] = 'Elementor';
		}
		forma_elements_admin_notice(
			sprintf(
				/* translators: %s: lista de plugins. */
				__( 'O Forma Elements está pausado porque estes plugins estão ativos: %s. O Forma Elements já inclui o Elementor e o PRO Elements. Desative-os e o Forma Elements volta a funcionar, sem perder nenhuma página.', 'forma-elements' ),
				implode( ', ', $conflicts )
			)
		);
		return $loaded = false;
	}

	$elementor = FORMA_ELEMENTS_PATH . 'bundled/elementor/elementor.php';
	$pro       = FORMA_ELEMENTS_PATH . 'bundled/pro-elements/pro-elements.php';

	if ( ! file_exists( $elementor ) || ! file_exists( $pro ) ) {
		forma_elements_admin_notice( __( 'O Forma Elements está incompleto (pasta bundled/ ausente). Instale o .zip gerado na página de releases do GitHub, não o código-fonte.', 'forma-elements' ) );
		return $loaded = false;
	}

	// A ordem importa: o PRO Elements verifica o Elementor assim que é incluído.
	// O atualizador próprio do PRO Elements é trocado por uma classe vazia no build (scripts/build.py).
	require $elementor;
	require $pro;

	return $loaded = true;
}

/**
 * Repassa a ativação para o Elementor e o PRO Elements, que registram os ganchos
 * deles com o caminho interno (forma-elements/bundled/...). Sem isso o Elementor
 * não cria o kit padrão nem as opções iniciais.
 */
function forma_elements_on_activate( $network_wide ) {
	if ( ! forma_elements_load_bundle() ) {
		return;
	}
	forma_elements_forward_hook( 'activate_', $network_wide );

	// Já ativa em português (ou no idioma do site); depois o cron mantém as traduções em dia.
	forma_elements_install_translations();
}

function forma_elements_on_deactivate( $network_wide ) {
	if ( ! forma_elements_load_bundle() ) {
		return;
	}
	forma_elements_forward_hook( 'deactivate_', $network_wide );
}

function forma_elements_forward_hook( $prefix, $network_wide ) {
	foreach ( [ 'ELEMENTOR_PLUGIN_BASE', 'ELEMENTOR_PRO_PLUGIN_BASE' ] as $constant ) {
		if ( defined( $constant ) ) {
			do_action( $prefix . constant( $constant ), $network_wide );
		}
	}
}

function forma_elements_admin_notice( $message ) {
	$print = function () use ( $message ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf( '<div class="notice notice-error"><p><strong>Forma Elements:</strong> %s</p></div>', esc_html( $message ) );
	};
	add_action( 'admin_notices', $print );
	add_action( 'network_admin_notices', $print );
}
