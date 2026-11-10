<?php
/**
 * Types a stored value can be recognised as.
 *
 * @package WPDBase
 */

declare(strict_types=1);

namespace WPDBase\ObjectView;

/**
 * Detected type of a column or meta value — picks the editor/renderer in the UI.
 */
enum ValueType: string {

	case Null       = 'null';
	case Empty      = 'empty';
	case Serialized = 'serialized';
	case Json       = 'json';
	case Boolean    = 'bool';
	case Integer    = 'int';
	case Decimal    = 'decimal';
	case DateTime   = 'datetime';
	case Date       = 'date';
	case Url        = 'url';
	case Email      = 'email';
	case Html       = 'html';
	case Text       = 'text';
}
