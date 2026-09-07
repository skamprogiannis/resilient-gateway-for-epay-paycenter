<?php
/** Compile PO catalogs with WordPress: wp eval-file scripts/compile-translations.php /path/to/languages. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || empty( $args[0] ) || ! is_dir( $args[0] ) ) {
	throw new RuntimeException( 'Run with WP-CLI on WordPress 6.5+ and a catalog directory.' );
}
require_once ABSPATH . WPINC . '/pomo/po.php';
require_once ABSPATH . WPINC . '/pomo/mo.php';
foreach ( glob( rtrim( $args[0], '/' ) . '/*.po' ) as $source ) {
	$po = new PO();
	if ( ! $po->import_from_file( $source ) ) {
		throw new RuntimeException( 'Cannot read catalog: ' . $source );
	}
	$mo = new MO();
	$mo->entries = $po->entries;
	$mo->headers = $po->headers;
	$base = substr( $source, 0, -3 );
	if ( ! $mo->export_to_file( $base . '.mo' ) ) {
		throw new RuntimeException( 'Cannot write compiled catalog: ' . $base );
	}
	$php = WP_Translation_File::transform( $base . '.mo', 'php' );
	if ( false === $php || false === file_put_contents( $base . '.l10n.php', $php ) ) {
		throw new RuntimeException( 'Cannot write PHP catalog: ' . $base );
	}
	WP_CLI::success( 'Compiled ' . basename( $source ) );
}
