<?php
/**
 * Picks the column that best names a row.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

/**
 * Pure heuristic: known "title" column names, in order of preference.
 */
final class TitleGuesser {

	private const CANDIDATES = array(
		'post_title',
		'display_name',
		'name',
		'nome',
		'title',
		'titulo',
		'user_login',
		'option_name',
		'comment_author',
		'slug',
		'label',
		'meta_key',
	);

	/**
	 * Title for a row, or null when no column looks like one.
	 *
	 * @param array<string, string|null> $row Column => value.
	 */
	public function guess( array $row ): ?string {
		foreach ( self::CANDIDATES as $column ) {
			$value = $row[ $column ] ?? null;

			if ( null !== $value && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return null;
	}
}
