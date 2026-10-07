<?php
/**
 * Translation tooling without WP-CLI.
 *
 *   php bin/i18n.php list    print every translatable string in the plugin
 *   php bin/i18n.php check   fail when a string has no translation in languages/*.l10n.php,
 *                            or a translation exists for a string no longer in the code
 */

const FUNCTIONS = array( '__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e' );
const DOMAIN    = 'let-agents';

$root = dirname( __DIR__ );

/**
 * @return array<string, list<string>> string => locations
 */
function extract_strings( string $root ): array {
	$files = array( $root . '/let-agents.php' );
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );

	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files );
	$strings = array();

	foreach ( $files as $file ) {
		$tokens = token_get_all( (string) file_get_contents( $file ) );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_STRING !== $tokens[ $i ][0] || ! in_array( $tokens[ $i ][1], FUNCTIONS, true ) ) {
				continue;
			}

			$line = $tokens[ $i ][2];
			$args = array();
			$j    = $i + 1;

			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}

			if ( '(' !== ( $tokens[ $j ] ?? null ) ) {
				continue;
			}

			// Collect the first two arguments when they are plain string literals.
			for ( ++$j; $j < $count && count( $args ) < 2; ++$j ) {
				$token = $tokens[ $j ];

				if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$args[] = stripcslashes( substr( $token[1], 1, -1 ) );
				} elseif ( is_array( $token ) && T_WHITESPACE === $token[0] ) {
					continue;
				} elseif ( ',' === $token ) {
					continue;
				} else {
					break;
				}
			}

			if ( 2 === count( $args ) && DOMAIN === $args[1] ) {
				$relative              = substr( str_replace( '\\', '/', $file ), strlen( str_replace( '\\', '/', $root ) ) + 1 );
				$strings[ $args[0] ][] = $relative . ':' . $line;
			} elseif ( count( $args ) < 2 ) {
				fwrite( STDERR, "Not a literal string with the let-agents domain: {$file}:{$line}\n" );
				exit( 1 );
			}
		}
	}

	ksort( $strings );

	return $strings;
}

$command = $argv[1] ?? 'check';
$strings = extract_strings( $root );

if ( 'list' === $command ) {
	foreach ( $strings as $string => $locations ) {
		echo json_encode( $string, JSON_UNESCAPED_UNICODE ), "\t", implode( ', ', $locations ), "\n";
	}
	exit( 0 );
}

$problems = array();

foreach ( glob( $root . '/languages/let-agents-*.l10n.php' ) as $file ) {
	$data     = require $file;
	$messages = $data['messages'] ?? array();
	$locale   = $data['language'] ?? basename( $file );

	foreach ( $strings as $string => $locations ) {
		if ( ! isset( $messages[ $string ] ) || '' === $messages[ $string ] ) {
			$problems[] = "[{$locale}] missing: " . json_encode( $string, JSON_UNESCAPED_UNICODE ) . ' (' . $locations[0] . ')';
		}
	}

	foreach ( array_keys( $messages ) as $string ) {
		if ( ! isset( $strings[ $string ] ) ) {
			$problems[] = "[{$locale}] unused: " . json_encode( $string, JSON_UNESCAPED_UNICODE );
		}
	}
}

if ( array() === glob( $root . '/languages/let-agents-*.l10n.php' ) ) {
	$problems[] = 'No translation files in languages/.';
}

if ( array() !== $problems ) {
	fwrite( STDERR, implode( "\n", $problems ) . "\n" . count( $problems ) . " translation problem(s).\n" );
	exit( 1 );
}

echo count( $strings ) . " strings, all translated.\n";
