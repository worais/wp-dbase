<?php
/**
 * Groups shown in the table sidebar.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\Database;

/**
 * Where a table comes from — decides colour and warnings in the UI.
 */
enum TableGroup: string {

	/** Created by WordPress core: editing it can break the site. */
	case Core = 'core';

	/** Belongs to a well-known plugin. */
	case Plugin = 'plugin';

	/** Uses the site prefix but is not recognised. */
	case Custom = 'custom';

	/** Does not use the site prefix (another app, or a leftover). */
	case Foreign = 'foreign';
}
