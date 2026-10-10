# CORE - CiviCRM Footer Afforms

A WordPress must-use plugin that makes CiviCRM Form Builder (Afform) forms work
when they are placed **outside the page content**: footers, headers, popups and
widgets.

## The problem

CiviCRM only loads its Angular/Afform resources when it finds a CiviCRM
shortcode in the page body. A form placed in a footer is rendered after
`wp_head` has already run, so its scripts are never queued and the form does
not display, unless the same page also happens to have a form in the body.

There is a second problem for logged-in users. CiviCRM builds its front-end
AJAX URLs from the permalink of the global `$post`. While Elementor renders a
footer template, `$post` is the template itself (`/?elementor_library=...`),
and CiviCRM appends `?civiwp=CiviCRM&q=...`, producing a URL with two `?`.
`Afform.prefill` then returns a 404 and the form hangs on its loading spinner.

## What it does

1. **Finds forms used outside the content.** It scans for
   `[civicrm component="afform" name="..."]` shortcodes in:
   - Elementor Pro Theme Builder header, footer and popup templates
   - Elementor Header & Footer Builder templates (`elementor-hf`)
   - Astra Custom Layouts / Site Builder (`astra-advanced-hook`)
   - Block theme template parts (`wp_template_part`)
   - Classic Text, Custom HTML and Block widgets

   The list of form names is cached in a transient and cleared whenever one of
   those sources changes.
2. **Queues them before `wp_head`.** On the `wp` hook it initialises CiviCRM,
   calls `front_end_page_load()` and adds the forms' Angular modules. Sites
   with no such forms never boot CiviCRM.
3. **Fixes CiviCRM's front-end URL base.** Through the `civicrm/core/url/base`
   filter, URLs built while a layout template is the current post use the page
   actually being viewed, and a base that already contains `?` falls back to
   the home page.

## Installation

Copy `core-civicrm-footer-afforms.php` into your mu-plugins directory
(normally `wp-content/mu-plugins/`; create it if it does not exist). Must-use
plugins load automatically, with no activation step.

After installing, flush CiviCRM caches once (`cv flush`, or Administer >
System Settings > Cleanup Caches and Update Paths) so cached JavaScript picks
up the corrected URLs.

## Forms that are not detected

Add form names yourself with the `core_footer_afform_names` filter:

```php
add_filter( 'core_footer_afform_names', function ( $names ) {
    $names[] = 'afformMyForm';
    return $names;
} );
```

## Requirements

- WordPress with CiviCRM and Form Builder (Afform)
- Forms placed with the `[civicrm component="afform" name="..."]` shortcode

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
