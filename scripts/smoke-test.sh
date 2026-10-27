#!/usr/bin/env bash
# Instala um WordPress descartável em português, ativa dist/forma-elements.zip junto com addons
# populares e confere se tudo funciona. Se qualquer passo falhar, o robô NÃO publica a versão.
# Requer: php, um MySQL em 127.0.0.1 (usuário root, senha root, banco wp), curl, unzip e openssl.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WP_DIR="${WP_DIR:-/tmp/wp}"
PORT=8080
URL="http://127.0.0.1:$PORT"
ADMIN_PASSWORD="$(openssl rand -hex 16)"
ADDONS="elementskit-lite royal-elementor-addons essential-addons-for-elementor-lite happy-elementor-addons"
FAILED=0

curl -sSL -o /tmp/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
wp() { php -d memory_limit=512M /tmp/wp-cli.phar --path="$WP_DIR" "$@"; }
check() { if [ "$1" = "ok" ]; then echo "OK    $2"; else echo "FALHA $2"; FAILED=1; fi; }

echo "::group::Instalando WordPress em português"
rm -rf "$WP_DIR"
wp core download
wp config create --dbname=wp --dbuser=root --dbpass=root --dbhost=127.0.0.1 --skip-check
wp config set WP_DEBUG true --raw
wp config set WP_DEBUG_LOG true --raw
wp config set WP_DEBUG_DISPLAY false --raw
wp config set WP_MEMORY_LIMIT 512M
wp config set WP_MAX_MEMORY_LIMIT 512M
wp core install --url="$URL" --title=Smoke --admin_user=smoke --admin_password="$ADMIN_PASSWORD" \
  --admin_email=smoke@example.com --skip-email
wp language core install pt_BR --activate
wp core version
echo "::endgroup::"

echo "::group::Ativando Forma Elements"
unzip -q "$ROOT/dist/forma-elements.zip" -d "$WP_DIR/wp-content/plugins"
wp plugin activate forma-elements
wp eval '
  $bundled = WP_PLUGIN_DIR . "/forma-elements/bundled/";
  $checks = [
    "Elementor carregado"            => defined( "ELEMENTOR_VERSION" ),
    "PRO Elements carregado"         => class_exists( "ElementorPro\\Plugin" ),
    "Atualizador carregado"          => class_exists( "YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory" ),
    "Atualizador do PRO desligado"   => false !== strpos( file_get_contents( $bundled . "pro-elements/updater/updater.php" ), "Forma Elements" ),
    "Lista de textos da marca"       => is_readable( $bundled . "brand-strings.json" ),
    "Compatibilidade addons"         => in_array( "elementor/elementor.php", get_option( "active_plugins" ), true ),
    "Forma Elements primeiro"        => get_option( "active_plugins" )[0] === "forma-elements/forma-elements.php",
    "Tradução baixada na ativação"   => (bool) glob( WP_LANG_DIR . "/plugins/elementor-pt_BR*" ),
  ];
  foreach ( $checks as $label => $ok ) {
    echo ( $ok ? "OK    " : "FALHA " ) . "$label\n";
  }
  echo "Elementor " . ELEMENTOR_VERSION . " + PRO Elements " . ELEMENTOR_PRO_VERSION . "\n";
  exit( in_array( false, $checks, true ) ? 1 : 0 );
' || FAILED=1
wp eval '
  $checks = [
    "Elementor em português"       => "Configurações" === __( "Settings", "elementor" ),
    "PRO usa tradução do Elementor" => "Título" === __( "Title", "elementor-pro" ),
    "Marca trocada nos textos"     => false === strpos( __( "Edit with Elementor", "elementor" ), "Elementor" ),
  ];
  foreach ( $checks as $label => $ok ) {
    echo ( $ok ? "OK    " : "FALHA " ) . "$label\n";
  }
  exit( in_array( false, $checks, true ) ? 1 : 0 );
' || FAILED=1
echo "::endgroup::"

echo "::group::Addons de Elementor"
# A ativação pelo WP-CLI respeita o "Requires Plugins: elementor" dos addons (WordPress 6.5+).
for addon in $ADDONS; do
  if wp plugin install "$addon" --activate; then check ok "ativou $addon"; else check falha "ativou $addon"; fi
done
ADDONS="$ADDONS" wp eval '
  $per_plugin = [];
  foreach ( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $widget ) {
    $file = wp_normalize_path( ( new ReflectionClass( $widget ) )->getFileName() );
    if ( preg_match( "#/plugins/([^/]+)/#", $file, $m ) ) {
      $per_plugin[ $m[1] ] = ( $per_plugin[ $m[1] ] ?? 0 ) + 1;
    }
  }
  $missing = 0;
  foreach ( explode( " ", getenv( "ADDONS" ) ) as $addon ) {
    $count = $per_plugin[ $addon ] ?? 0;
    echo ( $count ? "OK    " : "FALHA " ) . "$addon registrou $count widgets\n";
    $missing += $count ? 0 : 1;
  }
  exit( $missing ? 1 : 0 );
' || FAILED=1
echo "::endgroup::"

echo "::group::Telas e página"
DATA='[{"id":"a1b2c3d","elType":"container","isInner":false,"settings":{},"elements":[
  {"id":"b1c2d3e","elType":"widget","widgetType":"heading","settings":{"title":"Forma smoke heading"},"elements":[]},
  {"id":"c1d2e3f","elType":"widget","widgetType":"animated-headline","settings":{"before_text":"Forma","highlighted_text":"Elements","after_text":"ok"},"elements":[]},
  {"id":"d1e2f3a","elType":"widget","widgetType":"elementskit-heading","settings":{},"elements":[]},
  {"id":"e1f2a3b","elType":"widget","widgetType":"ha-card","settings":{},"elements":[]}
]}]'
PAGE_ID=$(wp post create --post_type=page --post_status=publish --post_title=Smoke --porcelain)
wp post meta update "$PAGE_ID" _elementor_edit_mode builder
wp post meta update "$PAGE_ID" _elementor_version "$(wp eval 'echo ELEMENTOR_VERSION;')"
wp post meta update "$PAGE_ID" _elementor_data "$DATA"

php -d memory_limit=512M -S "127.0.0.1:$PORT" -t "$WP_DIR" >/tmp/php-server.log 2>&1 &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null || true' EXIT
sleep 2

HTML=$(curl -sS --fail "$URL/?page_id=$PAGE_ID")
for needle in "Forma smoke heading" "elementor-widget-heading" "elementor-widget-animated-headline" \
  "elementor-widget-elementskit-heading" "elementor-widget-ha-card"; do
  if grep -q "$needle" <<<"$HTML"; then check ok "página contém $needle"; else check falha "página contém $needle"; fi
done

# Painel logado: a tela Plugins não pode desativar os caminhos "fingidos" do Elementor.
COOKIES=/tmp/smoke-cookies.txt
curl -sS -c "$COOKIES" -b "wordpress_test_cookie=WP%20Cookie%20check" -o /dev/null \
  --data-urlencode "log=smoke" --data-urlencode "pwd=$ADMIN_PASSWORD" --data-urlencode "testcookie=1" "$URL/wp-login.php"
PLUGINS_HTML=$(curl -sS -b "$COOKIES" "$URL/wp-admin/plugins.php")
if grep -q 'data-plugin="forma-elements/forma-elements.php"' <<<"$PLUGINS_HTML"; then
  check ok "login no painel"
else
  check falha "login no painel"
fi
if grep -qiE "foi desativado devido a um erro|has been deactivated due to an error" <<<"$PLUGINS_HTML"; then
  check falha "tela Plugins sem erro de plugin desativado"
else
  check ok "tela Plugins sem erro de plugin desativado"
fi
if wp option get active_plugins --format=json | grep -q '"elementor/elementor.php"'; then
  check falha "caminhos fingidos não gravados no banco"
else
  check ok "caminhos fingidos não gravados no banco"
fi
EDITOR_HTML=$(curl -sS -b "$COOKIES" "$URL/wp-admin/post.php?post=$PAGE_ID&action=elementor")
if grep -q "forma-elements-editor-css" <<<"$EDITOR_HTML" && grep -q "elementor-editor-wrapper-v2\|elementor-panel" <<<"$EDITOR_HTML"; then
  check ok "editor abre com o tema Forma"
else
  check falha "editor abre com o tema Forma"
fi
echo "::endgroup::"

LOG="$WP_DIR/wp-content/debug.log"
if [ -f "$LOG" ] && grep -q "PHP Fatal" "$LOG"; then
  check falha "debug.log sem erro fatal"
  grep "PHP Fatal" "$LOG"
else
  check ok "debug.log sem erro fatal"
fi

exit $FAILED
