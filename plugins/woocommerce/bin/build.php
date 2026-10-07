<?php
/**
 * Builds the installable zip: dist/let-agents-{version}.zip with a top-level "let-agents/" folder, the
 * shape WordPress expects in Plugins > Add New > Upload.
 *
 *   php bin/build.php
 */

$root    = dirname( __DIR__ );
$header  = (string) file_get_contents( $root . '/let-agents.php' );
$version = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $header, $m ) ? $m[1] : null;

if ( null === $version ) {
	fwrite( STDERR, "No Version header in let-agents.php\n" );
	exit( 1 );
}

if ( ! preg_match( "/define\(\s*'LET_AGENTS_VERSION',\s*'" . preg_quote( $version, '/' ) . "'\s*\)/", $header ) ) {
	fwrite( STDERR, "LET_AGENTS_VERSION does not match the Version header ({$version}).\n" );
	exit( 1 );
}

// Only what the plugin needs at runtime.
$include = array( 'let-agents.php', 'uninstall.php', 'readme.txt', 'src', 'languages' );

@mkdir( $root . '/dist' );
$target = $root . "/dist/let-agents-{$version}.zip";
@unlink( $target );

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot create {$target}\n" );
	exit( 1 );
}

$added = 0;

foreach ( $include as $entry ) {
	$path = $root . '/' . $entry;

	if ( is_file( $path ) ) {
		$zip->addFile( $path, 'let-agents/' . $entry );
		++$added;
		continue;
	}

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $files as $file ) {
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		$zip->addFile( $file->getPathname(), 'let-agents/' . $relative );
		++$added;
	}
}

$zip->close();

echo "Built {$target} ({$added} files, " . round( filesize( $target ) / 1024, 1 ) . " KB)\n";
