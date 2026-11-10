<?php
/**
 * Builds dist/coinspay-for-woocommerce-<version>.zip: the plugin as merchants
 * install it, without tests, dev files and Composer packages.
 *
 *   php bin/build.php
 *
 * @package CoinsPay\WooCommerce
 */

$root = dirname( __DIR__ );
$slug = 'coinspay-for-woocommerce';
$main = file_get_contents( $root . '/' . $slug . '.php' );
if ( ! preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $match ) ) {
	fwrite( STDERR, "No Version header in the main file\n" );
	exit( 1 );
}
$version = $match[1];
$readme  = file_get_contents( $root . '/readme.txt' );
if ( ! preg_match( '/^Stable tag:\s*(\S+)/m', $readme, $stable ) || $stable[1] !== $version ) {
	fwrite( STDERR, "Stable tag in readme.txt differs from Version {$version}\n" );
	exit( 1 );
}

$include = array( $slug . '.php', 'uninstall.php', 'readme.txt', 'src', 'assets', 'languages' );
$files   = array();
foreach ( $include as $entry ) {
	$path = $root . '/' . $entry;
	if ( is_file( $path ) ) {
		$files[] = $entry;
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$files[] = $entry . '/' . substr( str_replace( '\\', '/', $file->getPathname() ), strlen( str_replace( '\\', '/', $path ) ) + 1 );
	}
}
sort( $files );

if ( ! is_dir( $root . '/dist' ) ) {
	mkdir( $root . '/dist' );
}
$target = $root . '/dist/' . $slug . '-' . $version . '.zip';
if ( file_exists( $target ) ) {
	unlink( $target );
}
$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Cannot write {$target}\n" );
	exit( 1 );
}
foreach ( $files as $file ) {
	$zip->addFile( $root . '/' . $file, $slug . '/' . $file );
}
$zip->close();

printf( "%s: %d files, %.1f KB\n", $target, count( $files ), filesize( $target ) / 1024 );
