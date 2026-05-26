#!/usr/bin/env php
<?php
/**
 * mwscript.php — MediaWiki farm maintenance runner.
 *
 * Automatically locates the correct MediaWiki version for a wiki and
 * executes a maintenance script within that version.
 *
 * Usage:
 *   php /srv/mediawiki/scripts/mwscript.php <script|class> --wiki <dbname> [args...]
 *
 * Examples:
 *   php mwscript.php update.php --wiki metawiki --quick
 *   php mwscript.php rebuildall --wiki somewiki
 *   php mwscript.php maintenance/update.php --wiki metawiki --quick
 *
 * If <script> has no path separator and no .php extension it is treated as a
 * maintenance class name and automatically routed through maintenance/run.php.
 * Otherwise the path is resolved relative to the version root.
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

$i = 0;
while ( $i < count( $args ) ) {
	$arg = $args[$i];
	if ( $script === null && $arg[0] !== '-' ) {
		$script = $arg;
	} elseif ( $arg === '--wiki' ) {
		$wiki = $args[++$i] ?? null;
	} elseif ( str_starts_with( $arg, '--wiki=' ) ) {
		$wiki = substr( $arg, 7 );
	} else {
		$rest[] = $arg;
	}
	$i++;
}

if ( $script === null ) {
	fwrite( STDERR, "Usage: mwscript.php <script> --wiki <dbname> [args...]\n" );
	exit( 1 );
}

if ( $wiki === null ) {
	fwrite( STDERR, "Error: --wiki <dbname> is required.\n" );
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

// A bare name with no path separator and no .php suffix is a maintenance class
// name — route it through run.php rather than treating it as a file path.
if ( !str_contains( $script, '/' ) && !str_ends_with( $script, '.php' ) ) {
	array_unshift( $rest, $script );
	$script = 'maintenance/run.php';
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

// --wiki must come after $rest so that run.php's array_slice( $argv, 2 ) does
// not capture it before the class-name argument, which would inject an extra
// positional arg into the maintenance script's argv.
$cmd = array_merge(
	[ PHP_BINARY, $script ],
	$rest,
	[ '--wiki', $wiki ]
);

$cmdStr = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
passthru( $cmdStr, $exitCode );
exit( $exitCode );
