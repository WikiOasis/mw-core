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
 *   php /srv/mediawiki/scripts/mwscript.php <script|class> --all [args...]
 *
 * Examples:
 *   php mwscript.php update.php --wiki metawiki --quick
 *   php mwscript.php rebuildall --wiki somewiki
 *   php mwscript.php maintenance/update.php --wiki metawiki --quick
 *   php mwscript.php maintenance/update.php --all --quick
 *
 * If <script> has no path separator and no .php extension it is treated as a
 * maintenance class name and automatically routed through maintenance/run.php.
 * Otherwise the path is resolved relative to the version root.
 *
 * Either --wiki or --all is required. --all iterates over every wiki listed in
 * cw_cache/databases.php and exits non-zero if any invocation fails.
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
$all    = false;
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
	} elseif ( $arg === '--all' ) {
		$all = true;
	} else {
		$rest[] = $arg;
	}
	$i++;
}

if ( $script === null ) {
	fwrite( STDERR, "Usage: mwscript.php <script> --wiki <dbname> [args...]\n" );
	fwrite( STDERR, "       mwscript.php <script> --all [args...]\n" );
	exit( 1 );
}

if ( !$all && $wiki === null ) {
	fwrite( STDERR, "Error: --wiki <dbname> or --all is required.\n" );
	exit( 1 );
}

// ── Resolve wiki list ─────────────────────────────────────────────────────────

if ( $all ) {
	$dbCacheFile = '/srv/mediawiki/cw_cache/databases.php';
	if ( !file_exists( $dbCacheFile ) ) {
		fwrite( STDERR, "Error: databases cache not found at $dbCacheFile\n" );
		exit( 1 );
	}
	$dbCache = require $dbCacheFile;
	if ( !isset( $dbCache['databases'] ) || !is_array( $dbCache['databases'] ) ) {
		fwrite( STDERR, "Error: databases cache has no 'databases' key.\n" );
		exit( 1 );
	}
	$wikis = array_keys( $dbCache['databases'] );
} else {
	$wikis = [ $wiki ];
}

// ── Resolve script path (version-independent check) ───────────────────────────

$isBareClass = !str_contains( $script, '/' ) && !str_ends_with( $script, '.php' );

// ── Execute ───────────────────────────────────────────────────────────────────

$overallExit = 0;
$versionsDir  = '/srv/mediawiki/versions';

foreach ( $wikis as $currentWiki ) {
	$version = MirahezeFunctions::getMediaWikiVersion( $currentWiki );
	$mwPath  = "$versionsDir/$version";

	if ( !is_dir( $mwPath ) ) {
		fwrite( STDERR, "Error: MediaWiki version '$version' not found at $mwPath (wiki: $currentWiki)\n" );
		$overallExit = 1;
		continue;
	}

	$resolvedScript = $script;
	$resolvedRest   = $rest;

	// A bare name with no path separator and no .php suffix is a maintenance
	// class name — route it through run.php.
	if ( $isBareClass ) {
		array_unshift( $resolvedRest, $resolvedScript );
		$resolvedScript = 'maintenance/run.php';
	}

	// Resolve relative paths against the version root.
	if ( $resolvedScript[0] !== '/' ) {
		$resolvedScript = "$mwPath/$resolvedScript";
	}

	if ( !file_exists( $resolvedScript ) ) {
		fwrite( STDERR, "Error: Script not found: $resolvedScript (wiki: $currentWiki)\n" );
		$overallExit = 1;
		continue;
	}

	// --wiki must come after $resolvedRest so that run.php's array_slice( $argv, 2 )
	// does not capture it before the class-name argument.
	$cmd = array_merge(
		[ PHP_BINARY, $resolvedScript ],
		$resolvedRest,
		[ '--wiki', $currentWiki ]
	);

	$cmdStr = implode( ' ', array_map( 'escapeshellarg', $cmd ) );
	passthru( $cmdStr, $exitCode );
	if ( $exitCode !== 0 ) {
		$overallExit = $exitCode;
	}
}

exit( $overallExit );
