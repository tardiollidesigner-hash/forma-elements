<?php
/**
 * Plugin Name: Forma Elements
 * Description: Construtor visual completo: Elementor + PRO Elements em um único plugin, com atualizações automáticas.
 * Version: 0.0.0-dev
 * Author: Forma
 * Requires PHP: 7.4
 * Requires at least: 6.8
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: forma-elements
 * Update URI: https://github.com/tardiollidesigner-hash/forma-elements
 *
 * Forma Elements inclui o Elementor (https://wordpress.org/plugins/elementor/)
 * e o PRO Elements (https://github.com/proelements/proelements), ambos GPL.
 * O código deles é distribuído sem alterações dentro da pasta bundled/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A versão de desenvolvimento abaixo é trocada pela real quando o robô monta o pacote.
define( 'FORMA_ELEMENTS_VERSION', '0.0.0-dev' );
define( 'FORMA_ELEMENTS_FILE', __FILE__ );
define( 'FORMA_ELEMENTS_BASENAME', plugin_basename( __FILE__ ) );
define( 'FORMA_ELEMENTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FORMA_ELEMENTS_REPO_URL', 'https://github.com/tardiollidesigner-hash/forma-elements/' );

require_once FORMA_ELEMENTS_PATH . 'includes/updater.php';
require_once FORMA_ELEMENTS_PATH . 'includes/loader.php';
require_once FORMA_ELEMENTS_PATH . 'includes/compat.php';
require_once FORMA_ELEMENTS_PATH . 'includes/branding.php';
require_once FORMA_ELEMENTS_PATH . 'includes/ui.php';
require_once FORMA_ELEMENTS_PATH . 'includes/i18n.php';
require_once FORMA_ELEMENTS_PATH . 'includes/admin.php';

// O atualizador roda sempre, mesmo se houver conflito, para o site nunca ficar preso numa versão.
forma_elements_init_updater();

if ( forma_elements_load_bundle() ) {
	forma_elements_init_compat();
	forma_elements_init_branding();
	forma_elements_init_ui();
	forma_elements_init_i18n();
	forma_elements_init_admin();
}

register_activation_hook( __FILE__, 'forma_elements_on_activate' );
register_deactivation_hook( __FILE__, 'forma_elements_on_deactivate' );
