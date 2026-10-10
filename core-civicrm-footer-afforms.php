<?php
/**
 * Plugin Name: CORE - CiviCRM Footer Afforms
 * Description: Loads CiviCRM Form Builder (Afform) forms placed outside the page content (footers, headers, popups, widgets), so they render on every page, not only on pages that also have a form in the body.
 * Author:      CORE Australia
 * Author URI:  https://www.coreaustralia.org.au
 * Version:     1.2.0
 *
 * How it works:
 * Scans the places where site-wide layout lives for [civicrm component="afform" name="..."]
 * shortcodes, caches the form names in a transient, and on each front-end request queues
 * those forms' Angular modules and CiviCRM's front-end resources before wp_head.
 * Sites without such forms never bootstrap CiviCRM.
 *
 * Sources scanned:
 *  - Elementor Pro Theme Builder: header, footer and popup templates
 *  - Elementor Header & Footer Builder (free plugin): all templates
 *  - Astra Custom Layouts / Site Builder: all layouts (block editor or Elementor)
 *  - Block themes: customised template parts (header, footer...)
 *  - Classic widgets: Text, Custom HTML and Block widgets
 *
 * Anything not detected can be added per site with the 'core_footer_afform_names' filter:
 *   add_filter( 'core_footer_afform_names', fn( $names ) => array_merge( $names, [ 'afformMyForm' ] ) );
 */

defined('ABSPATH') || exit();

/** Post types whose content is scanned (Elementor Pro templates are handled separately). */
const CORE_FOOTER_AFFORM_POST_TYPES = ['elementor-hf', 'astra-advanced-hook', 'wp_template_part'];

/** Widget options whose content is scanned. */
const CORE_FOOTER_AFFORM_OPTIONS = ['widget_text', 'widget_custom_html', 'widget_block'];

if (!function_exists('core_footer_afform_names_in')) {
  /**
   * Extracts Afform names from [civicrm component="afform" name="..."] shortcodes in a string.
   *
   * @param string $text Raw content (post content, Elementor JSON, serialized widget option).
   * @return string[]
   */
  function core_footer_afform_names_in($text)
  {
    $names = [];
    if (!is_string($text) || false === stripos($text, '[civicrm')) {
      return $names;
    }
    if (!preg_match_all('/\[civicrm\b[^\]]*\]/i', $text, $m)) {
      return $names;
    }
    foreach ($m[0] as $shortcode) {
      // Quotes are JSON-escaped (\") inside _elementor_data; harmless elsewhere.
      $atts = shortcode_parse_atts(stripslashes(substr($shortcode, 1, -1)));
      if (is_array($atts) && isset($atts['component'], $atts['name']) && 'afform' === $atts['component']) {
        $names[] = $atts['name'];
      }
    }
    return $names;
  }
}

if (!function_exists('core_footer_afform_names')) {
  /**
   * Names of Afforms used outside the page content. Cached until a source changes.
   *
   * @return string[]
   */
  function core_footer_afform_names()
  {
    $names = get_transient('core_footer_afforms');

    if (false === $names) {
      $names = [];

      $common = [
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields' => 'ids',
        'suppress_filters' => true,
        'no_found_rows' => true,
      ];

      // Elementor Pro Theme Builder: header, footer and popup templates.
      $ids = get_posts(
        $common + [
          'post_type' => 'elementor_library',
          'meta_query' => [
            [
              'key' => '_elementor_template_type',
              'value' => ['header', 'footer', 'popup'],
              'compare' => 'IN',
            ],
          ],
        ],
      );

      // Header/footer builders and block template parts.
      $ids = array_merge(
        $ids,
        get_posts(
          $common + [
            'post_type' => CORE_FOOTER_AFFORM_POST_TYPES,
          ],
        ),
      );

      foreach (array_unique($ids) as $id) {
        $names = array_merge(
          $names,
          core_footer_afform_names_in((string) get_post_field('post_content', $id)),
          core_footer_afform_names_in((string) get_post_meta($id, '_elementor_data', true)),
        );
      }

      // Classic widgets.
      foreach (CORE_FOOTER_AFFORM_OPTIONS as $option) {
        $names = array_merge($names, core_footer_afform_names_in(maybe_serialize(get_option($option))));
      }

      $names = array_values(array_unique($names));
      set_transient('core_footer_afforms', $names, DAY_IN_SECONDS);
    }

    /**
     * Filters the Afform names loaded on every front-end page.
     *
     * @param string[] $names Detected Afform names.
     */
    return array_values(array_unique((array) apply_filters('core_footer_afform_names', $names)));
  }
}

/*
 * Cache invalidation.
 */
function core_footer_afforms_flush()
{
  delete_transient('core_footer_afforms');
}

/**
 * Flush when a scanned widget option or the sidebar map changes.
 *
 * @param string $option Option name.
 */
function core_footer_afforms_on_option($option)
{
  if ('sidebars_widgets' === $option || in_array($option, CORE_FOOTER_AFFORM_OPTIONS, true)) {
    core_footer_afforms_flush();
  }
}

add_action(
  'save_post',
  function ($post_id, $post) {
    if ('elementor_library' === $post->post_type || in_array($post->post_type, CORE_FOOTER_AFFORM_POST_TYPES, true)) {
      core_footer_afforms_flush();
    }
  },
  10,
  2,
);
add_action('deleted_post', 'core_footer_afforms_flush');
add_action('elementor/document/after_save', 'core_footer_afforms_flush');
// added_option covers the first widget of a type; deleted_option covers removing the last.
add_action('added_option', 'core_footer_afforms_on_option');
add_action('updated_option', 'core_footer_afforms_on_option');
add_action('deleted_option', 'core_footer_afforms_on_option');
add_action('switch_theme', 'core_footer_afforms_flush');

/*
 * Fix CiviCRM front-end URLs built while a layout template is rendering.
 *
 * CiviCRM builds front-end URLs (including the AJAX URLs Afform uses) from the
 * permalink of the global $post. While Elementor renders a footer/header/popup,
 * $post is the template itself, whose permalink is "/?elementor_library=...".
 * CiviCRM then appends "?civiwp=CiviCRM&q=..." giving a URL with two "?" that
 * 404s, so the form hangs on its loading spinner (logged-in users, who trigger
 * Afform.prefill). Point those URLs at the page actually being viewed instead.
 */
add_filter(
  'civicrm/core/url/base',
  function ($base, $admin_request) {
    if ($admin_request || is_admin()) {
      return $base;
    }

    global $post;
    $template_types = array_merge(['elementor_library'], CORE_FOOTER_AFFORM_POST_TYPES);

    if ($post instanceof WP_Post && in_array($post->post_type, $template_types, true)) {
      $queried_id = get_queried_object_id();
      $url = is_singular() && $queried_id ? get_permalink($queried_id) : '';
      $base = $url ? $url : home_url('/');
    }

    // With pretty permalinks CiviCRM always appends "?...", so the base must not
    // already contain a query string (e.g. previewing a template directly).
    if ('' !== get_option('permalink_structure') && false !== strpos($base, '?')) {
      $base = home_url('/');
    }

    return $base;
  },
  10,
  2,
);

/*
 * Front end: queue the forms before wp_head.
 */
add_action(
  'wp',
  function () {
    if (is_admin() || is_feed() || is_robots() || is_embed() || is_customize_preview() || wp_doing_ajax() || !function_exists('civi_wp')) {
      return;
    }

    // Cheap check first: no forms outside content means CiviCRM never starts.
    $names = core_footer_afform_names();
    if (!$names) {
      return;
    }

    $civi = civi_wp();
    if (!$civi->initialize()) {
      return;
    }

    try {
      $modules = civicrm_api4('Afform', 'get', [
        'select' => ['module_name'],
        'where' => [['name', 'IN', $names]],
      ])->column('module_name');
    } catch (\Throwable $e) {
      return;
    }

    if (!$modules) {
      return;
    }

    // Same call CiviCRM makes when it finds a shortcode in the body.
    $civi->front_end_page_load();
    \Civi::service('angularjs.loader')->addModules($modules);
  },
  30,
);
