<?php
/**
 * Turns serialized or JSON values into a tree the UI can render.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

/**
 * Safe decoder: never instantiates objects, and caps size and depth.
 */
final class ValueTree {

	public const MAX_NODES = 500;

	public const MAX_DEPTH = 8;

	/**
	 * Counts nodes emitted during one build() call.
	 *
	 * @var int
	 */
	private int $emitted = 0;

	/**
	 * Decode a serialized string without ever creating objects.
	 *
	 * Objects come back as `__PHP_Incomplete_Class`, which is inert: no
	 * constructor, destructor or `__wakeup` runs.
	 *
	 * @param string $value Serialized data.
	 * @return array{ok: bool, value: mixed}
	 */
	public function unserialize( string $value ): array {
		if ( 'b:0;' === trim( $value ) ) {
			return array(
				'ok'    => true,
				'value' => false,
			);
		}

		// allowed_classes=false makes the result inert; malformed data is an expected input (ok=false), hence the @.
		$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

		return array(
			'ok'    => false !== $decoded,
			'value' => $decoded,
		);
	}

	/**
	 * Flatten a decoded value into rows: depth, key, display value and type.
	 *
	 * @param mixed $value Decoded value.
	 * @return list<array{depth: int, key: string, value: string, type: string}>
	 */
	public function build( mixed $value ): array {
		$this->emitted = 0;
		$rows          = array();
		$this->walk( $value, '', 0, $rows );

		return $rows;
	}

	/**
	 * Recursive part of build().
	 *
	 * @param mixed                                                             $value Current value.
	 * @param string                                                            $key   Its key in the parent.
	 * @param int                                                               $depth Current depth.
	 * @param list<array{depth: int, key: string, value: string, type: string}> $rows  Output (by reference).
	 */
	private function walk( mixed $value, string $key, int $depth, array &$rows ): void {
		if ( $this->emitted >= self::MAX_NODES ) {
			return;
		}

		$children = $this->children( $value );

		if ( null === $children ) {
			$rows[] = $this->row( $depth, $key, $this->scalar_text( $value ), $this->type_of( $value ) );
			++$this->emitted;
			return;
		}

		if ( $depth > 0 || '' !== $key ) {
			$rows[] = $this->row( $depth, $key, $this->summary( $value, count( $children ) ), $this->type_of( $value ) );
			++$this->emitted;
		}

		if ( $depth >= self::MAX_DEPTH ) {
			return;
		}

		$child_depth = ( $depth > 0 || '' !== $key ) ? $depth + 1 : $depth;

		foreach ( $children as $child_key => $child ) {
			$this->walk( $child, (string) $child_key, $child_depth, $rows );
		}
	}

	/**
	 * Child values of arrays and objects, null for scalars.
	 *
	 * @param mixed $value Value.
	 * @return array<int|string, mixed>|null
	 */
	private function children( mixed $value ): ?array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_object( $value ) ) {
			return get_object_vars( $value );
		}

		return null;
	}

	/**
	 * Display text of a scalar.
	 *
	 * @param mixed $value Scalar value.
	 */
	private function scalar_text( mixed $value ): string {
		return match ( true ) {
			null === $value  => 'null',
			true === $value  => 'true',
			false === $value => 'false',
			is_scalar( $value ) => (string) $value,
			default          => '',
		};
	}

	/**
	 * Summary text for a container: `array(3)`, `object(2)`.
	 *
	 * @param mixed $value Array or object.
	 * @param int   $count Number of children.
	 */
	private function summary( mixed $value, int $count ): string {
		return ( is_array( $value ) ? 'array' : 'object' ) . '(' . $count . ')';
	}

	/**
	 * Short type label.
	 *
	 * @param mixed $value Any value.
	 */
	private function type_of( mixed $value ): string {
		return match ( true ) {
			is_array( $value )  => 'array',
			is_object( $value ) => 'object',
			is_bool( $value )   => 'bool',
			is_int( $value )    => 'int',
			is_float( $value )  => 'float',
			null === $value     => 'null',
			default             => 'string',
		};
	}

	/**
	 * One output row.
	 *
	 * @param int    $depth Depth.
	 * @param string $key   Key.
	 * @param string $text  Display value.
	 * @param string $type  Type label.
	 * @return array{depth: int, key: string, value: string, type: string}
	 */
	private function row( int $depth, string $key, string $text, string $type ): array {
		return array(
			'depth' => $depth,
			'key'   => $key,
			'value' => $text,
			'type'  => $type,
		);
	}
}
