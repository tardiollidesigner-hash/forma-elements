<?php
/**
 * Painel do Forma Elements: tela "Visão geral" no lugar da Home do Elementor (que é uma vitrine dos
 * serviços pagos deles), sem item "Upgrade", com rodapé e link de configurações próprios.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const FORMA_ELEMENTS_ADMIN_PAGE = 'forma-elements';
const FORMA_ELEMENTS_ELEMENTOR_HOME = 'elementor-home';

function forma_elements_init_admin() {
	add_filter( 'elementor_one/upgrade_available', '__return_false' );
	add_action( 'admin_menu', 'forma_elements_register_admin_page', PHP_INT_MAX );
	add_action( 'load-toplevel_page_' . FORMA_ELEMENTS_ELEMENTOR_HOME, 'forma_elements_redirect_elementor_home' );
	add_filter( 'admin_body_class', 'forma_elements_admin_body_class' );
	add_filter( 'admin_footer_text', 'forma_elements_admin_footer_text', PHP_INT_MAX );
	add_filter( 'plugin_action_links_' . FORMA_ELEMENTS_BASENAME, 'forma_elements_plugin_action_links' );
	add_action( 'admin_enqueue_scripts', 'forma_elements_enqueue_admin_theme' );
	add_action( 'in_admin_header', 'forma_elements_clean_overview_notices', PHP_INT_MAX );
	add_filter( 'admin_title', 'forma_elements_admin_title' );

	// Avisos do Elementor no painel: propaganda de produtos deles, pedido de avaliação, coleta de dados.
	add_filter( 'elementor/core/admin/notices', '__return_empty_array', PHP_INT_MAX );
	add_action( 'admin_head', 'forma_elements_remove_elementor_notices' );
	add_action( 'wp_dashboard_setup', 'forma_elements_remove_elementor_dashboard_widgets', PHP_INT_MAX );
}

function forma_elements_remove_elementor_notices() {
	global $wp_filter;
	if ( empty( $wp_filter['admin_notices'] ) ) {
		return;
	}
	foreach ( $wp_filter['admin_notices']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'];
			if ( is_array( $function ) && $function[0] instanceof \Elementor\Core\Admin\Admin_Notices ) {
				remove_action( 'admin_notices', $function, $priority );
			}
		}
	}
}

/** Widgets do Elementor no Painel do WordPress (notícias e ofertas deles). */
function forma_elements_remove_elementor_dashboard_widgets() {
	global $wp_meta_boxes;
	foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? [] ) as $context => $priorities ) {
		foreach ( (array) $priorities as $boxes ) {
			foreach ( (array) $boxes as $id => $box ) {
				if ( false !== stripos( (string) $id, 'elementor' ) || 0 === strpos( (string) $id, 'e-dashboard' ) ) {
					remove_meta_box( $id, 'dashboard', $context );
				}
			}
		}
	}
}

/** A "Visão geral" fica limpa, sem avisos de outros plugins (a Home do Elementor fazia o mesmo). */
function forma_elements_clean_overview_notices() {
	if ( forma_elements_is_admin_page() ) {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
	}
}

function forma_elements_admin_title( $title ) {
	if ( forma_elements_is_admin_page() ) {
		return __( 'Visão geral', 'forma-elements' ) . ' ‹ Forma Elements ‹ ' . get_bloginfo( 'name' );
	}
	return $title;
}

function forma_elements_admin_url() {
	return admin_url( 'admin.php?page=' . FORMA_ELEMENTS_ADMIN_PAGE );
}

function forma_elements_register_admin_page() {
	global $submenu;

	if ( ! isset( $submenu[ FORMA_ELEMENTS_ELEMENTOR_HOME ] ) ) {
		return;
	}

	add_submenu_page(
		FORMA_ELEMENTS_ELEMENTOR_HOME,
		__( 'Visão geral', 'forma-elements' ),
		__( 'Visão geral', 'forma-elements' ),
		'edit_posts',
		FORMA_ELEMENTS_ADMIN_PAGE,
		'forma_elements_render_admin_page'
	);

	// "Visão geral" assume o lugar da Home (primeiro item = destino do clique no menu principal).
	$items = array_values( array_filter(
		$submenu[ FORMA_ELEMENTS_ELEMENTOR_HOME ],
		function ( $item ) {
			return ! in_array( $item[2], [ FORMA_ELEMENTS_ADMIN_PAGE, 'elementor-one-upgrade', 'admin.php?page=' . FORMA_ELEMENTS_ELEMENTOR_HOME, FORMA_ELEMENTS_ELEMENTOR_HOME ], true );
		}
	) );
	array_unshift( $items, [ __( 'Visão geral', 'forma-elements' ), 'edit_posts', FORMA_ELEMENTS_ADMIN_PAGE ] );
	$submenu[ FORMA_ELEMENTS_ELEMENTOR_HOME ] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
}

function forma_elements_redirect_elementor_home() {
	wp_safe_redirect( forma_elements_admin_url() );
	exit;
}

function forma_elements_is_admin_page() {
	return is_admin() && isset( $_GET['page'] ) && FORMA_ELEMENTS_ADMIN_PAGE === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification
}

/** Telas do construtor no painel: a nossa e as do Elementor com a navegação lateral dele. */
function forma_elements_admin_body_class( $classes ) {
	if ( forma_elements_is_admin_page() ) {
		$classes .= ' forma-elements-admin forma-elements-overview';
	}
	return $classes;
}

function forma_elements_admin_footer_text( $text ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$is_builder_screen = forma_elements_is_admin_page()
		|| ( $screen && ( false !== strpos( $screen->id, 'elementor' ) || false !== strpos( (string) $screen->post_type, 'elementor' ) ) );

	if ( ! $is_builder_screen ) {
		return $text;
	}
	return sprintf( 'Forma Elements %s', esc_html( FORMA_ELEMENTS_VERSION ) );
}

function forma_elements_plugin_action_links( $links ) {
	array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( forma_elements_admin_url() ), esc_html__( 'Configurações', 'forma-elements' ) ) );
	return $links;
}

function forma_elements_enqueue_admin_theme() {
	if ( forma_elements_is_admin_page() || false !== strpos( (string) ( get_current_screen()->id ?? '' ), 'elementor' )
		|| false !== strpos( (string) ( get_current_screen()->post_type ?? '' ), 'elementor' ) ) {
		wp_enqueue_style( 'forma-elements-admin', forma_elements_asset_url( 'css/admin.css' ), [ 'forma-elements-ui' ], forma_elements_asset_version( 'css/admin.css' ) );
	}
}

/** Ícones de traço 1,5 px em grade 24 (mesmo padrão do UI Kit Forma). */
function forma_elements_icon( $name ) {
	$paths = [
		'page'     => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
		'layout'   => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 9v11"/>',
		'stack'    => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
		'window'   => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8 10h8M8 14h5"/>',
		'inbox'    => '<path d="M3 13h5l1.5 3h5L16 13h5"/><path d="M5 5h14l2 8v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5z"/>',
		'type'     => '<path d="M5 6V4h14v2M12 4v16M9 20h6"/>',
		'star'     => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
		'code'     => '<path d="m8 8-4 4 4 4M16 8l4 4-4 4M14 5l-4 14"/>',
		'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		'tool'     => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.4-.6-.6-2.4z"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'chevron'  => '<path d="m9 6 6 6-6 6"/>',
		'check'    => '<path d="m5 12 5 5 9-10"/>',
	];
	return '<svg class="forma-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $paths[ $name ] ?? '' ) . '</svg>';
}

function forma_elements_brand_html() {
	return '<span class="forma-brand forma-brand--large">' . forma_elements_logo_svg() . '<span class="forma-brand__tag">Elements</span></span>';
}

function forma_elements_render_admin_page() {
	$new_page = class_exists( '\Elementor\Core\Documents_Manager' )
		? \Elementor\Core\Documents_Manager::get_create_new_post_url( 'page' )
		: admin_url( 'post-new.php?post_type=page' );

	$actions = [
		[ 'layout', __( 'Construtor de temas', 'forma-elements' ), __( 'Cabeçalho, rodapé, páginas de arquivo e 404.', 'forma-elements' ), admin_url( 'admin.php?page=elementor-app#/site-editor' ) ],
		[ 'stack', __( 'Modelos salvos', 'forma-elements' ), __( 'Seções e páginas para reutilizar.', 'forma-elements' ), admin_url( 'edit.php?post_type=elementor_library&tabs_group=library' ) ],
		[ 'window', __( 'Pop-ups', 'forma-elements' ), __( 'Janelas com gatilhos e condições.', 'forma-elements' ), admin_url( 'edit.php?post_type=elementor_library&tabs_group=popup&elementor_library_type=popup' ) ],
		[ 'inbox', __( 'Envios de formulário', 'forma-elements' ), __( 'Mensagens recebidas pelos formulários.', 'forma-elements' ), admin_url( 'admin.php?page=e-form-submissions' ) ],
		[ 'type', __( 'Fontes personalizadas', 'forma-elements' ), __( 'Fontes próprias da marca.', 'forma-elements' ), admin_url( 'edit.php?post_type=elementor_font' ) ],
		[ 'star', __( 'Ícones personalizados', 'forma-elements' ), __( 'Conjuntos de ícones enviados.', 'forma-elements' ), admin_url( 'edit.php?post_type=elementor_icons' ) ],
		[ 'code', __( 'Código personalizado', 'forma-elements' ), __( 'Pixels, meta tags e scripts.', 'forma-elements' ), admin_url( 'edit.php?post_type=elementor_snippet' ) ],
		[ 'settings', __( 'Configurações', 'forma-elements' ), __( 'Integrações, desempenho e recursos.', 'forma-elements' ), admin_url( 'admin.php?page=elementor-settings' ) ],
		[ 'tool', __( 'Ferramentas', 'forma-elements' ), __( 'Regenerar arquivos, versões e manutenção.', 'forma-elements' ), admin_url( 'admin.php?page=elementor-tools' ) ],
	];

	$locale       = determine_locale();
	$translations = 'en_US' === $locale || glob( WP_LANG_DIR . "/plugins/elementor-$locale*" );
	$status       = [
		[ __( 'Construtor carregado', 'forma-elements' ), defined( 'ELEMENTOR_VERSION' ) && defined( 'ELEMENTOR_PRO_VERSION' ), __( 'Recursos grátis e Pro ativos.', 'forma-elements' ) ],
		[ __( 'Tradução instalada', 'forma-elements' ), (bool) $translations, sprintf( /* translators: %s: idioma */ __( 'Idioma do site: %s.', 'forma-elements' ), $locale ) ],
		[ __( 'Atualizações automáticas', 'forma-elements' ), file_exists( FORMA_ELEMENTS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php' ), __( 'Versões novas aparecem em Painel > Atualizações.', 'forma-elements' ) ],
	];
	?>
	<div class="forma-app">
		<header class="forma-app__header">
			<?php echo forma_elements_brand_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<span class="forma-chip">v<?php echo esc_html( FORMA_ELEMENTS_VERSION ); ?></span>
		</header>

		<div class="forma-app__title">
			<h1><?php esc_html_e( 'Visão geral', 'forma-elements' ); ?></h1>
			<p><?php esc_html_e( 'Crie páginas, monte o tema do site e acesse tudo do construtor em um só lugar.', 'forma-elements' ); ?></p>
		</div>

		<section class="forma-card forma-hero">
			<div>
				<h2><?php esc_html_e( 'Comece a criar', 'forma-elements' ); ?></h2>
				<p><?php esc_html_e( 'Abra o editor visual numa página nova ou monte as partes globais do site, como cabeçalho e rodapé.', 'forma-elements' ); ?></p>
			</div>
			<div class="forma-actions">
				<a class="forma-btn forma-btn--primary" href="<?php echo esc_url( $new_page ); ?>"><?php echo forma_elements_icon( 'plus' ); // phpcs:ignore ?><?php esc_html_e( 'Criar página', 'forma-elements' ); ?></a>
				<a class="forma-btn" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page' ) ); ?>"><?php esc_html_e( 'Ver páginas', 'forma-elements' ); ?></a>
			</div>
		</section>

		<div class="forma-grid">
			<section class="forma-card">
				<h2><?php esc_html_e( 'Ações rápidas', 'forma-elements' ); ?></h2>
				<ul class="forma-list">
					<?php foreach ( $actions as list( $icon, $title, $description, $url ) ) : ?>
						<li>
							<a class="forma-row" href="<?php echo esc_url( $url ); ?>">
								<span class="forma-row__icon"><?php echo forma_elements_icon( $icon ); // phpcs:ignore ?></span>
								<span class="forma-row__text"><strong><?php echo esc_html( $title ); ?></strong><span><?php echo esc_html( $description ); ?></span></span>
								<span class="forma-row__chevron"><?php echo forma_elements_icon( 'chevron' ); // phpcs:ignore ?></span>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="forma-card">
				<h2><?php esc_html_e( 'Estado do plugin', 'forma-elements' ); ?></h2>
				<ul class="forma-list">
					<?php foreach ( $status as list( $title, $ok, $description ) ) : ?>
						<li class="forma-row forma-row--static">
							<span class="forma-row__text"><strong><?php echo esc_html( $title ); ?></strong><span><?php echo esc_html( $description ); ?></span></span>
							<span class="forma-badge <?php echo $ok ? 'is-ok' : 'is-pending'; ?>">
								<?php echo $ok ? forma_elements_icon( 'check' ) . esc_html__( 'Concluído', 'forma-elements' ) : esc_html__( 'Pendente', 'forma-elements' ); // phpcs:ignore ?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		</div>
	</div>
	<?php
}
