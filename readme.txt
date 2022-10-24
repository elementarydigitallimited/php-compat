=== PHP Compatibility Checker ===
Requires at least: 4.8

Make sure your plugins and themes are compatible with newer PHP versions.

== Description ==

PHP Compatibility Checker provides static code analysis for themes and plugins. It detects themes and plugins which may not be compatiblity with PHP 8 and above. 

**This plugin does not execute your theme and plugin code, as such this plugin cannot detect runtime compatibility issues.**

== Installation ==

To manually install:
1. Upload `phpcompat` to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress

You will find the plugin options in the WP Admin `Tools => PHP Compatibility` menu. Once you click `run` it will take a few minutes to conduct the test. Feel free to navigate away from the page and check back later.

== Other Notes ==

PHP Compatibility Checker includes WP-CLI command support:

`wp phpcompat <version> [--scan=<scan>]`

`
<version>
    PHP version to test.

[--scan=<scan>]
  Whether to scan only active plugins and themes or all of them.
  default: active
  options:
    - active
    - all
`
Example: `wp phpcompat 8.0 --scan=active`