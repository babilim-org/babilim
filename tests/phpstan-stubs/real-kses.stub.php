<?php
/**
 * Static declaration of the two functions tests/support/real-kses.php
 * creates at run time (by renaming `wp_kses`/`wp_kses_post` in the
 * pinned real wp-includes/kses.php before requiring it), so PHPStan can
 * resolve them. Scanned for symbols only; never loaded.
 */

/**
 * @param string|mixed                            $content           Content to filter.
 * @param array<string, array<string, mixed>>|string $allowed_html   Allowed HTML.
 * @param string[]                                $allowed_protocols Allowed protocols.
 * @return string
 */
function babilim_real_wp_kses( $content, $allowed_html, $allowed_protocols = array() ) {}

/**
 * @param string $content Content to filter.
 * @return string
 */
function babilim_real_wp_kses_post( $content ) {}
