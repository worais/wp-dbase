<?php
/**
 * Spots duplicate and redundant indexes.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

/**
 * Pure analysis over the index list of one table.
 */
final class IndexAdvisor {

	/**
	 * Annotate each index with an assessment.
	 *
	 * - `duplicate`: same columns, in the same order, as an earlier index.
	 * - `redundant`: its columns are a leftmost prefix of another index, which
	 *   can serve the same lookups (unique and primary indexes are never redundant,
	 *   since they enforce a constraint).
	 * - `ok`: anything else.
	 *
	 * @param list<array{name: string, columns: list<string>, unique: bool}> $indexes Indexes in table order.
	 * @return list<array{name: string, columns: list<string>, unique: bool, assessment: string, covered_by: string|null}>
	 */
	public function assess( array $indexes ): array {
		$result = array();

		foreach ( $indexes as $position => $index ) {
			$assessment = 'ok';
			$covered_by = null;

			foreach ( $indexes as $other_position => $other ) {
				// Identical columns (the index itself included): only an earlier one makes this a duplicate.
				if ( $other['columns'] === $index['columns'] ) {
					if ( $other_position < $position && ! $index['unique'] ) {
						$assessment = 'duplicate';
						$covered_by = $other['name'];
						break;
					}
					continue;
				}

				if ( ! $index['unique'] && $this->is_left_prefix( $index['columns'], $other['columns'] ) ) {
					$assessment = 'redundant';
					$covered_by = $other['name'];
				}
			}

			$result[] = $index + array(
				'assessment' => $assessment,
				'covered_by' => $covered_by,
			);
		}//end foreach

		return $result;
	}

	/**
	 * Whether `$short` is a strict leftmost prefix of `$long`.
	 *
	 * Identical lists never get here (assess() handles them first), so equal
	 * lengths need no special case.
	 *
	 * @param list<string> $short Columns of the candidate index.
	 * @param list<string> $long  Columns of the other index.
	 */
	private function is_left_prefix( array $short, array $long ): bool {
		return array_slice( $long, 0, count( $short ) ) === $short;
	}
}
