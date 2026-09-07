<?php
/**
 * Fail when plugin classes form a circular dependency.
 *
 * Usage: php scripts/check-dependencies.php [--self-test|includes-directory]
 * Includes direct class references and literal callback/class_exists names.
 */

/**
 * Read one class per source file, matching the plugin's includes convention.
 *
 * @param string $source PHP source; never executed.
 * @return array{name:string,references:array<string,true>}|null
 */
function epay_dependency_class( string $source ): ?array {
	$declared_class = null;
	$references    = array();
	$expect_class  = false;
	$previous      = null;
	$reference_tokens = array( T_STRING, T_CONSTANT_ENCAPSED_STRING );
	if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
		$reference_tokens[] = constant( 'T_NAME_FULLY_QUALIFIED' );
	}

	foreach ( token_get_all( $source ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$kind = is_array( $token ) ? $token[0] : $token;
		if ( T_CLASS === $kind ) {
			// Foo::class is a class-name expression; new class is anonymous.
			$expect_class = T_DOUBLE_COLON !== $previous && T_NEW !== $previous;
		} elseif ( $expect_class ) {
			if ( T_STRING === $kind && 1 === preg_match( '/^Epay_Paycenter_[a-z0-9_]+$/iD', $token[1] ) ) {
				if ( null !== $declared_class ) {
					throw new RuntimeException( 'Each scanned file must declare at most one named plugin class.' );
				}
				$declared_class = $token[1];
			}
			$expect_class = false;
		} elseif ( in_array( $kind, $reference_tokens, true ) ) {
			$name = $token[1];
			if ( T_CONSTANT_ENCAPSED_STRING === $kind ) {
				$name = "'" === $name[0]
					? str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), substr( $name, 1, -1 ) )
					: stripcslashes( substr( $name, 1, -1 ) );
			}
			if ( 1 === preg_match( '/^\\\\?Epay_Paycenter_[a-z0-9_]+$/iD', $name ) ) {
				$references[ strtolower( ltrim( $name, '\\' ) ) ] = true;
			}
		}
		$previous = $kind;
	}

	if ( null === $declared_class ) {
		return null;
	}
	unset( $references[ strtolower( $declared_class ) ] );
	return array( 'name' => $declared_class, 'references' => $references );
}

/**
 * Find strongly connected components, ignoring references outside the plugin.
 *
 * @param array<string,array<string,true>> $graph Class names normalized to lowercase.
 * @return list<list<string>>
 */
function epay_dependency_cycles( array $graph ): array {
	$index      = 0;
	$indexes    = array();
	$low_links  = array();
	$on_stack   = array();
	$stack      = array();
	$cycles     = array();

	$visit = function ( string $class ) use ( &$visit, $graph, &$index, &$indexes, &$low_links, &$on_stack, &$stack, &$cycles ): void {
		$indexes[ $class ]   = $index;
		$low_links[ $class ] = $index;
		++$index;
		$stack[]           = $class;
		$on_stack[ $class ] = true;

		foreach ( array_keys( $graph[ $class ] ) as $dependency ) {
			if ( ! isset( $graph[ $dependency ] ) ) {
				continue;
			}
			if ( ! array_key_exists( $dependency, $indexes ) ) {
				$visit( $dependency );
				$low_links[ $class ] = min( $low_links[ $class ], $low_links[ $dependency ] );
			} elseif ( ! empty( $on_stack[ $dependency ] ) ) {
				$low_links[ $class ] = min( $low_links[ $class ], $indexes[ $dependency ] );
			}
		}
		if ( $low_links[ $class ] !== $indexes[ $class ] ) {
			return;
		}
		$component = array();
		do {
			$member             = array_pop( $stack );
			$on_stack[ $member ] = false;
			$component[]        = $member;
		} while ( $member !== $class );
		if ( count( $component ) > 1 ) {
			sort( $component );
			$cycles[] = $component;
		}
	};

	foreach ( array_keys( $graph ) as $class ) {
		if ( ! array_key_exists( $class, $indexes ) ) {
			$visit( $class );
		}
	}
	return $cycles;
}

/** Exercise the scanner without reading, writing, or executing plugin files. */
function epay_dependency_self_test(): void {
	$sources = array(
		'<?php class Epay_Paycenter_A { function boot() { register_hook(array("Epay_Paycenter_B", "run")); Epay_Paycenter_C:: /* expression */ class; Epay_Paycenter_D::run(); new class extends Epay_Paycenter_E {}; } }',
		'<?php class Epay_Paycenter_B { function boot() { Epay_Paycenter_A::run(); } }',
		'<?php class Epay_Paycenter_C {}',
		'<?php class Epay_Paycenter_D { function boot() { $description = "Epay_Paycenter_A is an example"; /* Epay_Paycenter_A */ } }',
		'<?php class Epay_Paycenter_E {}',
	);
	$graph = array();
	foreach ( $sources as $source ) {
		$class = epay_dependency_class( $source );
		if ( null === $class ) {
			throw new RuntimeException( 'Self-test failed: a named class was lost.' );
		}
		$graph[ strtolower( $class['name'] ) ] = $class['references'];
	}
	if ( array_keys( $graph['epay_paycenter_a'] ) !== array( 'epay_paycenter_b', 'epay_paycenter_c', 'epay_paycenter_d', 'epay_paycenter_e' )
		|| array() !== $graph['epay_paycenter_d']
		|| array( array( 'epay_paycenter_a', 'epay_paycenter_b' ) ) !== epay_dependency_cycles( $graph ) ) {
		throw new RuntimeException( 'Self-test failed: literal callbacks, class expressions, or cycle detection are incorrect.' );
	}
	$graph['epay_paycenter_b'] = array();
	if ( array() !== epay_dependency_cycles( $graph ) ) {
		throw new RuntimeException( 'Self-test failed: an acyclic fixture was rejected.' );
	}
	$quoted = epay_dependency_class( "<?php class Epay_Paycenter_Quoted { function boot() { register_hook(array('epay_paycenter_a', 'run')); } }" );
	if ( null === $quoted || array( 'epay_paycenter_a' => true ) !== $quoted['references'] ) {
		throw new RuntimeException( 'Self-test failed: single-quoted or case-insensitive class references were missed.' );
	}
	$qualified = epay_dependency_class( '<?php class Epay_Paycenter_Qualified { function boot() { \\Epay_Paycenter_A::run(); } }' );
	if ( null === $qualified || array( 'epay_paycenter_a' => true ) !== $qualified['references'] ) {
		throw new RuntimeException( 'Self-test failed: a fully qualified class reference was missed.' );
	}
	echo "Dependency checker self-test passed.\n";
}

try {
	$target = $argv[1] ?? dirname( __DIR__ ) . '/includes';
	if ( '--self-test' === $target ) {
		epay_dependency_self_test();
		exit( 0 );
	}
	$files = is_dir( $target ) ? glob( rtrim( $target, '/' ) . '/*.php' ) : false;
	if ( false === $files || array() === $files ) {
		throw new RuntimeException( 'Unable to enumerate plugin class files.' );
	}
	$graph = array();
	$names = array();
	foreach ( $files as $file ) {
		$source = file_get_contents( $file );
		if ( false === $source ) {
			throw new RuntimeException( 'Unable to read ' . $file . '.' );
		}
		$class = epay_dependency_class( $source );
		if ( null !== $class ) {
			$key = strtolower( $class['name'] );
			if ( isset( $graph[ $key ] ) ) {
				throw new RuntimeException( 'Duplicate plugin class declaration: ' . $class['name'] );
			}
			$graph[ $key ] = $class['references'];
			$names[ $key ] = $class['name'];
		}
	}
	if ( array() === $graph ) {
		throw new RuntimeException( 'No named plugin classes were found.' );
	}
	$cycles = epay_dependency_cycles( $graph );
	if ( array() !== $cycles ) {
		fwrite( STDERR, "Circular plugin class dependency groups detected:\n" );
		foreach ( $cycles as $cycle ) {
			$labels = array_map( static function ( string $class ) use ( $names ): string { return $names[ $class ]; }, $cycle );
			fwrite( STDERR, ' - ' . implode( ', ', $labels ) . "\n" );
		}
		exit( 1 );
	}
	echo "Plugin class dependency graph is acyclic.\n";
} catch ( RuntimeException $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 2 );
}
