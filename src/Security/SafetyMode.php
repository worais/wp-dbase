<?php
/**
 * The three safety modes a user can work in.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Security;

use WPDBase\Sql\StatementKind;

/**
 * Safety modes, ordered from the safest to the most permissive.
 *
 * - Read:     nothing can change, not even from the SQL console.
 * - Assisted: guided writes (inline row edits) are allowed; free SQL writes and DDL are not.
 * - Expert:   free SQL and DDL are allowed, always with a typed confirmation.
 */
enum SafetyMode: string {

	case Read     = 'read';
	case Assisted = 'assisted';
	case Expert   = 'expert';

	/**
	 * Parse a stored or user-supplied value, falling back to the safest mode.
	 *
	 * @param mixed $value Raw value (option, user meta, request param, constant).
	 */
	public static function parse( mixed $value ): self {
		if ( ! is_string( $value ) ) {
			return self::Read;
		}

		return self::tryFrom( strtolower( trim( $value ) ) ) ?? self::Read;
	}

	/**
	 * Position in the safe → permissive order (0, 1, 2).
	 */
	public function rank(): int {
		return match ( $this ) {
			self::Read     => 0,
			self::Assisted => 1,
			self::Expert   => 2,
		};
	}

	/**
	 * This mode, or the ceiling if this one is more permissive than allowed.
	 *
	 * @param SafetyMode $ceiling Most permissive mode the site allows.
	 */
	public function cap_to( self $ceiling ): self {
		return self::cases()[ min( $this->rank(), $ceiling->rank() ) ];
	}

	/**
	 * Whether a free-form SQL statement of the given kind may run in this mode.
	 *
	 * @param StatementKind $kind Classification of the statement.
	 */
	public function allows_statement( StatementKind $kind ): bool {
		return StatementKind::Read === $kind || self::Expert === $this;
	}

	/**
	 * Whether guided row edits (update/delete one row by primary key) are allowed.
	 */
	public function allows_row_edits(): bool {
		return self::Read !== $this;
	}
}
