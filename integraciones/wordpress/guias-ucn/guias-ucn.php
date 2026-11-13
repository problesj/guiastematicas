<?php
/**
 * Plugin Name:       Guías temáticas UCN
 * Description:       Muestra en WordPress las guías temáticas, bloques de guías y recursos de la plataforma de Guías temáticas de Bibliotecas UCN, con shortcodes.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Bibliotecas UCN
 * License:           GPL-2.0-or-later
 * Text Domain:       guias-ucn
 */

if (!defined('ABSPATH')) {
    exit;
}

define('GUIAS_UCN_VERSION', '1.0.0');
define('GUIAS_UCN_OPCION', 'guias_ucn_opciones');

/** Ajustes guardados, con la dirección del servidor que trae el paquete descargado desde el panel (servidor.php). */
function guias_ucn_opciones(): array
{
    $opciones = get_option(GUIAS_UCN_OPCION, []);
    $opciones = is_array($opciones) ? $opciones : [];
    if (empty($opciones['servidor']) && is_readable(__DIR__ . '/servidor.php')) {
        $predeterminado = include __DIR__ . '/servidor.php';
        $opciones['servidor'] = is_string($predeterminado) ? $predeterminado : '';
    }

    return wp_parse_args($opciones, ['servidor' => '', 'cache' => 15]);
}

function guias_ucn_servidor(): string
{
    return untrailingslashit((string) guias_ucn_opciones()['servidor']);
}

/**
 * Consulta la API pública (/api/v1) y guarda la respuesta en caché unos minutos.
 *
 * @return array|WP_Error
 */
function guias_ucn_api(string $ruta, array $parametros = [])
{
    $servidor = guias_ucn_servidor();
    if ('' === $servidor) {
        return new WP_Error('guias_ucn_sin_servidor', __('Falta la dirección del servidor de Guías temáticas (Ajustes → Guías temáticas UCN).', 'guias-ucn'));
    }
    $parametros = array_filter($parametros, static fn ($v) => '' !== $v && null !== $v);
    $url = add_query_arg(array_map('rawurlencode', $parametros), $servidor . '/api/v1' . $ruta);
    $clave = 'guias_ucn_' . md5($url);
    $guardado = get_transient($clave);
    if (is_array($guardado)) {
        return $guardado;
    }

    $respuesta = wp_remote_get($url, ['timeout' => 8, 'headers' => ['Accept' => 'application/json']]);
    if (is_wp_error($respuesta)) {
        return $respuesta;
    }
    $codigo = (int) wp_remote_retrieve_response_code($respuesta);
    $datos = json_decode((string) wp_remote_retrieve_body($respuesta), true);
    if (200 !== $codigo || !is_array($datos)) {
        return new WP_Error('guias_ucn_respuesta', sprintf(__('El servidor de Guías temáticas respondió %d.', 'guias-ucn'), $codigo));
    }
    $minutos = max(1, (int) guias_ucn_opciones()['cache']);
    set_transient($clave, $datos, $minutos * MINUTE_IN_SECONDS);

    return $datos;
}

/** Un error solo lo ven quienes pueden editar la página; a los visitantes no se les muestra nada. */
function guias_ucn_error(WP_Error $error): string
{
    if (!current_user_can('edit_posts')) {
        return '';
    }

    return '<p class="guias-ucn-error">' . esc_html__('Guías temáticas UCN:', 'guias-ucn') . ' ' . esc_html($error->get_error_message()) . '</p>';
}

function guias_ucn_estilos(): void
{
    wp_enqueue_style('guias-ucn', plugins_url('assets/guias-ucn.css', __FILE__), [], GUIAS_UCN_VERSION);
}

function guias_ucn_si(string $valor): bool
{
    return in_array(strtolower(trim($valor)), ['si', 'sí', 'yes', '1', 'true'], true);
}

/** Tarjeta de una guía: icono (o inicial), título enlazado, descripción y materias. */
function guias_ucn_tarjeta(array $guia, bool $descripcion = true): string
{
    $titulo = (string) ($guia['titulo'] ?? '');
    $url = (string) ($guia['url'] ?? '');
    $inicial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($titulo, 0, 1)) : strtoupper(substr($titulo, 0, 1));
    $icono = !empty($guia['icono'])
        ? '<img class="guias-ucn__icono" src="' . esc_url($guia['icono']) . '" alt="" width="64" height="64" loading="lazy">'
        : '<span class="guias-ucn__icono guias-ucn__icono--vacio" aria-hidden="true">' . esc_html($inicial) . '</span>';
    $html = '<li class="guias-ucn__guia" lang="' . esc_attr((string) ($guia['idioma'] ?? 'es')) . '">' . $icono . '<div class="guias-ucn__texto">';
    $html .= '<h3 class="guias-ucn__titulo"><a href="' . esc_url($url) . '">' . esc_html($titulo) . '</a></h3>';
    if ($descripcion && !empty($guia['descripcion'])) {
        $html .= '<p class="guias-ucn__descripcion">' . esc_html($guia['descripcion']) . '</p>';
    }
    if (!empty($guia['principal']['titulo'])) {
        $html .= '<p class="guias-ucn__parte">' . esc_html__('Parte de:', 'guias-ucn') . ' ' . esc_html($guia['principal']['titulo']) . '</p>';
    }
    if (!empty($guia['materias']) && is_array($guia['materias'])) {
        $html .= '<p class="guias-ucn__materias">' . esc_html(implode(' · ', array_filter(array_map(static fn ($m) => is_array($m) ? (string) ($m['nombre'] ?? '') : '', $guia['materias'])))) . '</p>';
    }

    return $html . '</div></li>';
}

/**
 * [guias_ucn materia="medicina" q="" idioma="es" vista="lista|cuadricula" limite="0" descripcion="si"]
 * Lista de guías publicadas (las restringidas no aparecen).
 */
function guias_ucn_shortcode_lista($atributos): string
{
    $a = shortcode_atts(['materia' => '', 'q' => '', 'idioma' => '', 'vista' => 'lista', 'limite' => '0', 'descripcion' => 'si'], $atributos, 'guias_ucn');
    $datos = guias_ucn_api('/guias', ['materia' => sanitize_title($a['materia']), 'q' => sanitize_text_field($a['q']), 'idioma' => in_array($a['idioma'], ['es', 'en'], true) ? $a['idioma'] : '']);
    if (is_wp_error($datos)) {
        return guias_ucn_error($datos);
    }
    guias_ucn_estilos();
    $guias = is_array($datos['guias'] ?? null) ? $datos['guias'] : [];
    if ((int) $a['limite'] > 0) {
        $guias = array_slice($guias, 0, (int) $a['limite']);
    }
    if ([] === $guias) {
        return '<p class="guias-ucn-vacio">' . esc_html__('No hay guías publicadas para mostrar.', 'guias-ucn') . '</p>';
    }
    $vista = 'cuadricula' === $a['vista'] ? 'cuadricula' : 'lista';
    $html = '<ul class="guias-ucn guias-ucn--' . $vista . '">';
    foreach ($guias as $guia) {
        if (is_array($guia)) {
            $html .= guias_ucn_tarjeta($guia, guias_ucn_si($a['descripcion']));
        }
    }

    return $html . '</ul>';
}

/** [guia_ucn slug="ingenieria" pestanas="si"]: una guía con enlaces a sus pestañas. */
function guias_ucn_shortcode_guia($atributos): string
{
    $a = shortcode_atts(['slug' => '', 'pestanas' => 'si'], $atributos, 'guia_ucn');
    $slug = sanitize_title($a['slug']);
    if ('' === $slug) {
        return guias_ucn_error(new WP_Error('guias_ucn_slug', __('Indica la guía con slug="...".', 'guias-ucn')));
    }
    $guia = guias_ucn_api('/guias/' . $slug);
    if (is_wp_error($guia)) {
        return guias_ucn_error($guia);
    }
    guias_ucn_estilos();
    $html = '<ul class="guias-ucn guias-ucn--lista guias-ucn--una">' . guias_ucn_tarjeta($guia) . '</ul>';
    $pestanas = is_array($guia['pestanas'] ?? null) ? array_filter($guia['pestanas'], static fn ($p) => is_array($p) && empty($p['padre'])) : [];
    if (guias_ucn_si($a['pestanas']) && count($pestanas) > 1) {
        $html .= '<nav class="guias-ucn__pestanas" aria-label="' . esc_attr(sprintf(__('Páginas de la guía %s', 'guias-ucn'), (string) $guia['titulo'])) . '"><ul>';
        foreach ($pestanas as $p) {
            $html .= '<li><a href="' . esc_url((string) ($p['url'] ?? '')) . '">' . esc_html((string) ($p['titulo'] ?? '')) . '</a></li>';
        }
        $html .= '</ul></nav>';
    }

    return $html;
}

/** [guia_ucn_bloque id="12" idioma="es" titulo="..."]: un bloque de una guía, con incrustar.js del servidor. */
function guias_ucn_shortcode_bloque($atributos): string
{
    $a = shortcode_atts(['id' => '', 'idioma' => 'es', 'titulo' => ''], $atributos, 'guia_ucn_bloque');
    $id = absint($a['id']);
    $servidor = guias_ucn_servidor();
    if (0 === $id || '' === $servidor) {
        return guias_ucn_error(new WP_Error('guias_ucn_bloque', '' === $servidor ? __('Falta la dirección del servidor de Guías temáticas (Ajustes → Guías temáticas UCN).', 'guias-ucn') : __('Indica el bloque con id="..." (está en «Incrustar» del editor de la guía).', 'guias-ucn')));
    }
    wp_enqueue_script('guias-ucn-incrustar', $servidor . '/incrustar.js', [], null, ['in_footer' => true, 'strategy' => 'async']);

    return '<div class="guias-ucn-bloque" data-guias-bloque="' . esc_attr((string) $id) . '" data-idioma="' . ('en' === $a['idioma'] ? 'en' : 'es') . '"'
        . ('' !== $a['titulo'] ? ' data-titulo="' . esc_attr($a['titulo']) . '"' : '') . '></div>';
}

/** [recursos_ucn materia="medicina" tipo="base_datos" q="" limite="10"]: recursos de la lista A-Z. */
function guias_ucn_shortcode_recursos($atributos): string
{
    $a = shortcode_atts(['materia' => '', 'tipo' => '', 'q' => '', 'limite' => '10', 'descripcion' => 'no'], $atributos, 'recursos_ucn');
    $limite = (int) $a['limite'];
    $porPagina = $limite > 50 ? 100 : ($limite > 25 ? 50 : ($limite > 10 ? 25 : 10));
    $datos = guias_ucn_api('/recursos', ['materia' => sanitize_title($a['materia']), 'tipo' => sanitize_key($a['tipo']), 'q' => sanitize_text_field($a['q']), 'por_pagina' => $porPagina]);
    if (is_wp_error($datos)) {
        return guias_ucn_error($datos);
    }
    guias_ucn_estilos();
    $recursos = array_slice(is_array($datos['recursos'] ?? null) ? $datos['recursos'] : [], 0, max(1, $limite));
    if ([] === $recursos) {
        return '<p class="guias-ucn-vacio">' . esc_html__('No hay recursos para mostrar.', 'guias-ucn') . '</p>';
    }
    $html = '<ul class="guias-ucn-recursos">';
    foreach ($recursos as $r) {
        if (!is_array($r)) {
            continue;
        }
        $html .= '<li><a href="' . esc_url((string) ($r['url'] ?? '')) . '">' . esc_html((string) ($r['titulo'] ?? '')) . '</a>';
        if (guias_ucn_si($a['descripcion']) && !empty($r['descripcion'])) {
            $html .= '<br><span class="guias-ucn-recursos__descripcion">' . esc_html(wp_strip_all_tags((string) $r['descripcion'])) . '</span>';
        }
        $html .= '</li>';
    }

    return $html . '</ul>';
}

add_action('init', static function (): void {
    load_plugin_textdomain('guias-ucn', false, dirname(plugin_basename(__FILE__)) . '/languages');
    add_shortcode('guias_ucn', 'guias_ucn_shortcode_lista');
    add_shortcode('guia_ucn', 'guias_ucn_shortcode_guia');
    add_shortcode('guia_ucn_bloque', 'guias_ucn_shortcode_bloque');
    add_shortcode('recursos_ucn', 'guias_ucn_shortcode_recursos');
});

/* Ajustes → Guías temáticas UCN */
add_action('admin_init', static function (): void {
    register_setting('guias_ucn', GUIAS_UCN_OPCION, [
        'type' => 'array',
        'sanitize_callback' => static function ($valor): array {
            $valor = is_array($valor) ? $valor : [];
            $servidor = esc_url_raw(trim((string) ($valor['servidor'] ?? '')), ['https', 'http']);

            return ['servidor' => untrailingslashit($servidor), 'cache' => min(1440, max(1, (int) ($valor['cache'] ?? 15)))];
        },
    ]);
});

add_action('admin_menu', static function (): void {
    add_options_page(__('Guías temáticas UCN', 'guias-ucn'), __('Guías temáticas UCN', 'guias-ucn'), 'manage_options', 'guias-ucn', 'guias_ucn_pagina_ajustes');
});

function guias_ucn_pagina_ajustes(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $o = guias_ucn_opciones();
    $prueba = '' !== guias_ucn_servidor() ? guias_ucn_api('/guias') : null; ?>
    <div class="wrap">
        <h1><?php esc_html_e('Guías temáticas UCN', 'guias-ucn'); ?></h1>
        <?php if (is_wp_error($prueba)) { ?>
            <div class="notice notice-error"><p><?php echo esc_html($prueba->get_error_message()); ?></p></div>
        <?php } elseif (is_array($prueba)) { ?>
            <div class="notice notice-success"><p><?php echo esc_html(sprintf(_n('Conectado: %d guía publicada.', 'Conectado: %d guías publicadas.', count($prueba['guias'] ?? []), 'guias-ucn'), count($prueba['guias'] ?? []))); ?></p></div>
        <?php } ?>
        <form method="post" action="options.php">
            <?php settings_fields('guias_ucn'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="guias-ucn-servidor"><?php esc_html_e('Dirección del servidor', 'guias-ucn'); ?></label></th>
                    <td><input type="url" class="regular-text" id="guias-ucn-servidor" name="<?php echo esc_attr(GUIAS_UCN_OPCION); ?>[servidor]" value="<?php echo esc_attr($o['servidor']); ?>" placeholder="https://guias.bibliotecas.ucn.cl">
                        <p class="description"><?php esc_html_e('Dirección de la plataforma de Guías temáticas, sin barra final.', 'guias-ucn'); ?></p></td>
                </tr>
                <tr>
                    <th scope="row"><label for="guias-ucn-cache"><?php esc_html_e('Minutos en caché', 'guias-ucn'); ?></label></th>
                    <td><input type="number" min="1" max="1440" class="small-text" id="guias-ucn-cache" name="<?php echo esc_attr(GUIAS_UCN_OPCION); ?>[cache]" value="<?php echo esc_attr((string) $o['cache']); ?>">
                        <p class="description"><?php esc_html_e('Cada cuánto se vuelven a pedir los datos al servidor.', 'guias-ucn'); ?></p></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <h2><?php esc_html_e('Cómo usarlo', 'guias-ucn'); ?></h2>
        <p><?php esc_html_e('Agrega un bloque «Código corto» (Shortcode) en cualquier página o entrada con uno de estos códigos:', 'guias-ucn'); ?></p>
        <ul class="ul-disc">
            <li><code>[guias_ucn]</code> <?php esc_html_e('todas las guías publicadas;', 'guias-ucn'); ?> <code>[guias_ucn materia="medicina" vista="cuadricula" limite="6"]</code></li>
            <li><code>[guia_ucn slug="ingenieria"]</code> <?php esc_html_e('una guía con sus páginas;', 'guias-ucn'); ?></li>
            <li><code>[guia_ucn_bloque id="12"]</code> <?php esc_html_e('un bloque de una guía (el número está en «Incrustar» del editor de la guía);', 'guias-ucn'); ?></li>
            <li><code>[recursos_ucn materia="medicina" limite="10"]</code> <?php esc_html_e('recursos de la lista A-Z.', 'guias-ucn'); ?></li>
        </ul>
    </div>
    <?php
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function (array $enlaces): array {
    array_unshift($enlaces, '<a href="' . esc_url(admin_url('options-general.php?page=guias-ucn')) . '">' . esc_html__('Ajustes', 'guias-ucn') . '</a>');

    return $enlaces;
});
