<?php
/**
 * The WP-CLI methods Windrose Livery calls (class-rename-colors.php), for PHPStan.
 *
 * @package Windrose\Livery
 */

class WP_CLI {
	/**
	 * @param string          $name     Command name.
	 * @param callable|string $callable Command class.
	 * @param array<string, mixed> $args Options.
	 */
	public static function add_command( $name, $callable, $args = array() ): bool {}
	public static function success( string $message ): void {}
	public static function line( string $message = '' ): void {}
}
