<?php
/**
 * Atualizações automáticas a partir das releases do GitHub.
 *
 * O WordPress pergunta periodicamente se há versão nova; esta biblioteca responde
 * olhando a última release em FORMA_ELEMENTS_REPO_URL e baixa o forma-elements.zip anexado nela.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function forma_elements_init_updater() {
	$library = FORMA_ELEMENTS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
	if ( ! file_exists( $library ) ) {
		return; // Cópia de desenvolvimento: a biblioteca entra no pacote na hora do build.
	}

	require_once $library;

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		FORMA_ELEMENTS_REPO_URL,
		FORMA_ELEMENTS_FILE,
		dirname( FORMA_ELEMENTS_BASENAME )
	);
	$checker->getVcsApi()->enableReleaseAssets( '/^forma-elements\.zip$/' );
}
