<?php

declare(strict_types=1);

/**
 * Maho
 *
 * @package    Mageaustralia_AttributeManager
 * @copyright  Copyright (c) 2026 Maho Australia
 * @license    https://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Data helper.
 */
class Mageaustralia_AttributeManager_Helper_Data extends Mage_Core_Helper_Abstract
{
    protected $_moduleName = 'Mageaustralia_AttributeManager';

    /**
     * Frontend inputs whose values are stored as option_id references and
     * therefore support batch-add / merge.
     *
     * @var string[]
     */
    public const SUPPORTED_INPUTS = ['select', 'multiselect'];

    /**
     * Whether the given attribute is an option-backed (select/multiselect)
     * user-editable attribute we can manage.
     */
    public function isManageable(?Mage_Eav_Model_Entity_Attribute_Abstract $attribute): bool
    {
        if (!$attribute || !$attribute->getId()) {
            return false;
        }
        if (!in_array($attribute->getFrontendInput(), self::SUPPORTED_INPUTS, true)) {
            return false;
        }
        // Only source-model-less (option table backed) attributes can have
        // options added/merged here. Attributes with a custom source model
        // (e.g. status, visibility) don't use eav_attribute_option.
        $source = $attribute->getSourceModel();
        return $source === null || $source === '' || $source === 'eav/entity_attribute_source_table';
    }
}
