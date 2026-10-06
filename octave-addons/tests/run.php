<?php

/*
TEST RUNNER
-- Dependency-free: runs every test_* function in tests/test-*.php, each
-- after a state reset, and exits non-zero if any fails
-- Usage: php tests/run.php [filter]
---------------------------------------------------------- */

require __DIR__ . '/bootstrap.php';

class OA_Test_Failure extends Exception {}

function oa_assert( bool $condition, string $message = 'Assertion failed' ): void {

	$GLOBALS['oa_assertions'] = ( $GLOBALS['oa_assertions'] ?? 0 ) + 1;

	if ( ! $condition ) {

		throw new OA_Test_Failure( $message );

	}

}

function oa_assert_same( $expected, $actual, string $message = '' ): void {

	oa_assert( $expected === $actual, ( $message ? $message . ': ' : '' ) . 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );

}

function oa_assert_contains( string $needle, string $haystack, string $message = '' ): void {

	oa_assert( false !== strpos( $haystack, $needle ), ( $message ? $message . ': ' : '' ) . 'missing ' . $needle );

}

function oa_assert_not_contains( string $needle, string $haystack, string $message = '' ): void {

	oa_assert( false === strpos( $haystack, $needle ), ( $message ? $message . ': ' : '' ) . 'unexpected ' . $needle );

}

/*
JSON CALL
-- Runs an AJAX handler and returns the response it sent
---------------------------------------------------------- */

function oa_json_call( callable $handler ): OA_Test_Json_Response {

	try {

		$handler();

	} catch ( OA_Test_Json_Response $response ) {

		return $response;

	}

	throw new OA_Test_Failure( 'Handler sent no JSON response' );

}

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {

	require_once $file;

}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = [];

foreach ( get_defined_functions()['user'] as $function ) {

	if ( 0 !== strpos( $function, 'test_' ) || ( '' !== $filter && false === strpos( $function, $filter ) ) ) {

		continue;

	}

	oa_test_reset();

	try {

		$function();
		$passed++;
		echo '.';

	} catch ( Throwable $error ) {

		$failed[] = $function . ': ' . $error->getMessage() . ' (' . basename( $error->getFile() ) . ':' . $error->getLine() . ')';
		echo 'F';

	}

}

echo "\n\n";

foreach ( $failed as $failure ) {

	echo 'FAIL ' . $failure . "\n";

}

printf( "%d passed, %d failed, %d assertions\n", $passed, count( $failed ), $GLOBALS['oa_assertions'] ?? 0 );

exit( empty( $failed ) ? 0 : 1 );
