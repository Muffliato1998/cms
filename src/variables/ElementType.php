<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\variables;

use craft\app\models\ElementInterface;

/**
 * Element type template variable.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class ElementType extends BaseComponentTypeVariable
{
	// Public Methods
	// =========================================================================

	/**
	 * Returns whether this element type stores data on a per-locale basis.
	 *
	 * @return bool
	 */
	public function isLocalized()
	{
		return $this->component->isLocalized();
	}

	/**
	 * Returns whether this element type can have statuses.
	 *
	 * @return bool
	 */
	public function hasStatuses()
	{
		return $this->component->hasStatuses();
	}

	/**
	 * Returns all of the possible statuses that elements of this type may have.
	 *
	 * @return array|null
	 */
	public function getStatuses()
	{
		return $this->component->getStatuses();
	}

	/**
	 * Return a key/label list of the element type's sources.
	 *
	 * @param string|null $context
	 *
	 * @return array|false
	 */
	public function getSources($context = null)
	{
		return $this->component->getSources($context);
	}

	/**
	 * Returns whether this element type can have titles.
	 *
	 * @return bool
	 */
	public function hasTitles()
	{
		return $this->component->hasTitles();
	}

	/**
	 * Returns the attributes that elements can be sorted by.
	 *
	 * @retrun array
	 */
	public function defineSortableAttributes()
	{
		return $this->component->defineSortableAttributes();
	}

	/**
	 * Returns the table view HTML for a given attribute.
	 *
	 * @param ElementInterface $element
	 * @param string           $attribute
	 *
	 * @return string
	 */
	public function getTableAttributeHtml(ElementInterface $element, $attribute)
	{
		return $this->component->getTableAttributeHtml($element, $attribute);
	}
}
