#!/usr/bin/env php
<?php
/**
 * mwscript.php — MediaWiki farm maintenance runner.
 *
 * Automatically locates the correct MediaWiki version for a wiki and
 * executes a maintenance script within that version.  It is a drop-in
 * replacement for calling maintenance/run.php directly.
 *
 * Usage:
 *   php /srv/mediawiki/scripts/mwscript.php <script> --wiki=<dbname> [args...]
 *
 * Examples:
 *   php mwscript.php maintenance/update.php --wiki=metawiki --quick
 *   php mwscript.php maintenance/run.php --wiki=somewiki rebuildall
 *
 * The --wiki argument is required so we can determine which MediaWiki
 * version that wiki runs on before booting any MW code.
 */

if ( PHP_SAPI !== 'cli' ) {
	die( "This script must be run from the command line.\n" );
}

define( 'MW_ENTRY_POINT', 'cli' );

require_once '/srv/mediawiki/config/MirahezeFunctions.php';

// ── Parse arguments ──────────────────────────────────────────────────────────

$args   = array_slice( $argv, 1 );
$script = null;
$wiki   = null;
$rest   = [];

foreach ( $args as $i => $arg ) {
	if ( $script === null && $arg[0] !== '-' ) {
		$script = $arg;
		continue;
	}
	if ( str_starts_with( $arg, '--wiki=' ) ) {
		$wiki = substr( $arg, 7 );
		continue;
	}
	$rest[] = $arg;
}

if ( $script === null ) {
	fwrite( STDERR, "Usage: mwscript.php <script> --wiki=<dbname> [args...]\n" );
	exit( 1 );
}

if ( $wiki === null ) {
	fwrite( STDERR, "Error: --wiki=<dbname> is required.\n" );
	exit( 1 );
}

// ── Determine version ─────────────────────────────────────────────────────────

define( 'MW_DB', $wiki );
$version    = MirahezeFunctions::getMediaWikiVersion( $wiki );
$versionsDir = '/srv/mediawiki/versions';
$mwPath     = "$versionsDir/$version";

if ( !is_dir( $mwPath ) ) {
	fwrite( STDERR, "Error: MediaWiki version '$version' not found at $mwPath\n" );
	exit( 1 );
}

// Resolve the script path relative to the version root if not absolute.
if ( $script[0] !== '/' ) {
	$script = "$mwPath/$script";
}

if ( !file_exists( $script ) ) {
	fwrite( STDERR, "Error: Script not found: $script\n" );
	exit( 1 );
}

// ── Execute ───────────────────────────────────────────────────────────────────

$cmd = array_merge(
	[ PHP_BINARY, $script, "--wiki=$wiki" ],
	$rest
);

$cmdStr = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
passthru( $cmdStr, $exitCode );
exit( $exitCode );
