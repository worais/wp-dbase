<?php
/**
 * Recognises what a stored string actually holds.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

/**
 * Pure type sniffing for database values (no WordPress calls).
 */
final class ValueTypeDetector {

	/**
	 * Detect the type of a value as returned by the database driver.
	 *
	 * @param string|null $value Raw value.
	 */
	public function detect( ?string $value ): ValueType {
		if ( null === $value ) {
			return ValueType::Null;
		}

		if ( '' === $value ) {
			return ValueType::Empty;
		}

		$trimmed = trim( $value );

		return match ( true ) {
			$this->is_serialized( $trimmed )                          => ValueType::Serialized,
			$this->is_json( $trimmed )                                => ValueType::Json,
			in_array( strtolower( $trimmed ), array( 'true', 'false', 'yes', 'no', 'on', 'off' ), true ) => ValueType::Boolean,
			1 === preg_match( '/^-?(?:0|[1-9]\d*)$/', $trimmed )      => ValueType::Integer,
			1 === preg_match( '/^-?\d+\.\d+$/', $trimmed )            => ValueType::Decimal,
			1 === preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/', $trimmed ) => ValueType::DateTime,
			1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $trimmed )     => ValueType::Date,
			1 === preg_match( '#^https?://\S+$#i', $trimmed )         => ValueType::Url,
			false !== filter_var( $trimmed, FILTER_VALIDATE_EMAIL )   => ValueType::Email,
			1 === preg_match( '/<\/?[a-z][a-z0-9-]*(?:\s[^>]*)?>/i', $value ) => ValueType::Html,
			default                                                   => ValueType::Text,
		};
	}

	/**
	 * Whether the string is PHP-serialized data (same rules as core's is_serialized()).
	 *
	 * @param string $value Trimmed value.
	 */
	public function is_serialized( string $value ): bool {
		if ( 'N;' === $value ) {
			return true;
		}

		if ( 1 === preg_match( '/^b:[01];$/', $value ) ) {
			return true;
		}

		if ( 1 === preg_match( '/^[id]:-?[0-9.E+-]+;$/', $value ) ) {
			return true;
		}

		if ( 1 === preg_match( '/^s:\d+:".*";$/s', $value ) ) {
			return true;
		}

		return 1 === preg_match( '/^[aO]:\d+:(?:"[^"]+":\d+:)?\{.*\}$/s', $value );
	}

	/**
	 * Whether the string is a JSON object or array.
	 *
	 * Scalars (`"1"`, `true`) are valid JSON too, but showing them as JSON would be noise.
	 *
	 * @param string $value Trimmed value.
	 */
	private function is_json( string $value ): bool {
		$first = $value[0] ?? '';

		if ( '{' !== $first && '[' !== $first ) {
			return false;
		}

		json_decode( $value );

		return JSON_ERROR_NONE === json_last_error();
	}
}
