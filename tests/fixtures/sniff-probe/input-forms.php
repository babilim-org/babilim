<?php
/**
 * Probe: candidate input-read forms, run through the official Plugin Check sniff.
 *
 * @package babilim
 */

/** Helper form. */
function babilim_probe_helper() {
	check_admin_referer( 'probe' );
	return babilim_probe_sanitize_list( wp_unslash( $_POST['k'] ?? array() ) );
}

/** Helper form: the sanitizer. */
function babilim_probe_sanitize_list( $raw ) {
	$out = array();
	foreach ( (array) $raw as $v ) {
		if ( is_string( $v ) ) {
			$out[] = sanitize_text_field( $v );
		}
	}
	return $out;
}

/** Map-after-filter form. */
function babilim_probe_map() {
	check_admin_referer( 'probe' );
	return is_array( $_POST['k'] ?? null ) ? array_map( 'sanitize_text_field', array_filter( wp_unslash( $_POST['k'] ), 'is_string' ) ) : array();
}

/** Loop form. */
function babilim_probe_loop() {
	check_admin_referer( 'probe' );
	$out = array();
	foreach ( (array) wp_unslash( $_POST['k'] ?? array() ) as $v ) {
		if ( ! is_string( $v ) ) {
			continue;
		}
		$out[] = sanitize_text_field( $v );
	}
	return $out;
}

/** DOCUMENT_ROOT, plain comparison. */
function babilim_probe_root_plain() {
	$clean = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
	if ( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) !== $clean ) {
		return null;
	}
	return $clean;
}

/** DOCUMENT_ROOT, raw filter_var comparison. */
function babilim_probe_root_raw() {
	$clean = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
	if ( (string) filter_var( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ), FILTER_UNSAFE_RAW ) !== $clean ) {
		return null;
	}
	return $clean;
}

/** Plain comparison with an isset guard. */
function babilim_probe_root_plain_guarded() {
	if ( ! isset( $_SERVER['DOCUMENT_ROOT'] ) || ! is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
		return null;
	}
	$clean = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
	if ( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) !== $clean ) {
		return null;
	}
	return $clean;
}

/** Raw comparison with an isset guard. */
function babilim_probe_root_raw_guarded() {
	if ( ! isset( $_SERVER['DOCUMENT_ROOT'] ) || ! is_string( $_SERVER['DOCUMENT_ROOT'] ) ) {
		return null;
	}
	$clean = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
	if ( (string) filter_var( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ), FILTER_UNSAFE_RAW ) !== $clean ) {
		return null;
	}
	return $clean;
}

/** Extra: array_map directly on the unslashed array. */
function babilim_probe_extra_direct() {
	check_admin_referer( 'probe' );
	return isset( $_POST['k'] ) && is_array( $_POST['k'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['k'] ) ) : array();
}

/** Extra: filter first, then array_map, with isset. */
function babilim_probe_extra_filtered() {
	check_admin_referer( 'probe' );
	return isset( $_POST['k'] ) && is_array( $_POST['k'] ) ? array_map( 'sanitize_text_field', array_filter( wp_unslash( $_POST['k'] ), 'is_string' ) ) : array();
}

/** Extra: array_map directly, then drop empties. */
function babilim_probe_extra_strlen() {
	check_admin_referer( 'probe' );
	$clean = isset( $_POST['k'] ) && is_array( $_POST['k'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['k'] ) ) : array();
	return array_values( array_filter( $clean, 'strlen' ) );
}

/** Extra: closure that skips non-strings. */
function babilim_probe_extra_closure() {
	check_admin_referer( 'probe' );
	return isset( $_POST['k'] ) && is_array( $_POST['k'] ) ? array_map(
		static function ( $v ) {
			return is_string( $v ) ? sanitize_text_field( $v ) : null;
		},
		wp_unslash( $_POST['k'] )
	) : array();
}

/** Extra: reject when any element is not a string, before reading. */
function babilim_probe_extra_early() {
	check_admin_referer( 'probe' );
	if ( ! isset( $_POST['k'] ) || ! is_array( $_POST['k'] ) ) {
		return array();
	}
	return array_map( 'sanitize_text_field', wp_unslash( $_POST['k'] ) );
}

/** Candidate: sanitize every element, then drop the empty results, one expression. */
function babilim_probe_map_then_filter() {
	check_admin_referer( 'probe' );
	return isset( $_POST['k'] ) && is_array( $_POST['k'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['k'] ) ), 'strlen' ) ) : array();
}
