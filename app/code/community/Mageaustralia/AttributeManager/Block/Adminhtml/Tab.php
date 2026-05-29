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
 * "Bulk Options" tab content block.
 *
 * Renders the batch-add textarea (Feature A) and the merge controls
 * (Feature B) for the current dropdown attribute. Read-only helpers for the
 * template; all state-changing work happens in the controller actions.
 */
class Mageaustralia_AttributeManager_Block_Adminhtml_Tab extends Mage_Adminhtml_Block_Template
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('mageaustralia/attributemanager/tab.phtml');
    }

    public function getAttribute(): ?Mage_Eav_Model_Entity_Attribute
    {
        $attribute = Mage::registry('entity_attribute');
        return $attribute instanceof Mage_Eav_Model_Entity_Attribute ? $attribute : null;
    }

    public function getAttributeId(): int
    {
        return (int) ($this->getAttribute()?->getId() ?? 0);
    }

    public function getAttributeCode(): string
    {
        return (string) ($this->getAttribute()?->getAttributeCode() ?? '');
    }

    /**
     * Existing options as [option_id => label] for the admin (default) store,
     * sorted by sort_order. Used for the merge control list.
     *
     * @return array<int, string>
     */
    public function getOptions(): array
    {
        $attribute = $this->getAttribute();
        if (!$attribute || !$attribute->getId()) {
            return [];
        }

        $result = [];
        // getSource()->getAllOptions() returns admin-store labels with
        // option_id values, excluding the empty "please select" row.
        foreach ($attribute->getSource()->getAllOptions() as $option) {
            $value = $option['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $result[(int) $value] = (string) $option['label'];
        }
        return $result;
    }

    /**
     * Explicit admin route to our controller. We must NOT use '*\/*\/...' here:
     * this block renders inside the core catalog attribute-edit controller
     * context, so the wildcards would resolve to that controller, not ours.
     * 'attributemanager_index' is the controllerName derived from our
     * controller class (Adminhtml/AttributeManager/IndexController).
     */
    public function getAddUrl(): string
    {
        return $this->getUrl('adminhtml/attributemanager_index/add');
    }

    public function getMergeUrl(): string
    {
        return $this->getUrl('adminhtml/attributemanager_index/merge');
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('adminhtml/attributemanager_index/save');
    }

    /**
     * Whether this attribute is multiselect (default selection becomes a
     * checkbox set rather than a single radio). Mirrors the native options
     * template's choice of `intype`.
     */
    public function isMultiselect(): bool
    {
        return $this->getAttribute()?->getFrontendInput() === 'multiselect';
    }

    /**
     * The "Is Default" input type for the grid - radio for select, checkbox
     * for multiselect. Matches Mage_Eav_Block_Adminhtml_Attribute_Edit_Options_Abstract.
     */
    public function getDefaultInputType(): string
    {
        return $this->isMultiselect() ? 'checkbox' : 'radio';
    }

    /**
     * Stores collection including the admin (store_id 0) "default" entry,
     * exactly as the native options tab builds it. The first row is the admin
     * store; subsequent rows are each store view.
     *
     * @return Mage_Core_Model_Resource_Store_Collection
     */
    public function getStores()
    {
        $stores = $this->getData('stores');
        if ($stores === null) {
            $stores = Mage::getModel('core/store')
                ->getResourceCollection()
                ->setLoadDefault(true)
                ->load();
            $this->setData('stores', $stores);
        }
        return $stores;
    }

    /**
     * Per-store option-label map for a given store id: [option_id => value].
     * Mirrors Mage_Eav_Block_Adminhtml_Attribute_Edit_Options_Abstract::getStoreOptionValues().
     *
     * @return array<int, string>
     */
    public function getStoreOptionValues(int $storeId): array
    {
        $key = 'store_option_values_' . $storeId;
        $values = $this->getData($key);
        if ($values === null) {
            $values = [];
            $attribute = $this->getAttribute();
            if ($attribute && $attribute->getId()) {
                $collection = Mage::getResourceModel('eav/entity_attribute_option_collection')
                    ->setAttributeFilter($attribute->getId())
                    ->setStoreFilter($storeId, false)
                    ->load();
                foreach ($collection as $item) {
                    $values[(int) $item->getId()] = (string) $item->getValue();
                }
            }
            $this->setData($key, $values);
        }
        return $values;
    }

    /**
     * Fully-built option rows for the "one by one" grid, in ascending position
     * order. Each row mirrors what the native options template needs PLUS the
     * per-store label map.
     *
     * @return array<int, array{id:int, sort_order:int, is_default:bool, stores:array<int,string>}>
     */
    public function getOptionRows(): array
    {
        $attribute = $this->getAttribute();
        if (!$attribute || !$attribute->getId()) {
            return [];
        }

        // Default selection(s): default_value is a CSV of option_ids for
        // select/multiselect (same parsing the native block does).
        $defaultValues = [];
        foreach (explode(',', (string) $attribute->getDefaultValue()) as $v) {
            $v = (int) trim($v);
            if ($v > 0) {
                $defaultValues[$v] = true;
            }
        }

        $collection = Mage::getResourceModel('eav/entity_attribute_option_collection')
            ->setAttributeFilter($attribute->getId())
            ->setPositionOrder('asc', true)
            ->load();

        $storeIds = [];
        foreach ($this->getStores() as $store) {
            $storeIds[] = (int) $store->getId();
        }

        $rows = [];
        foreach ($collection as $option) {
            $optionId = (int) $option->getId();
            $stores = [];
            foreach ($storeIds as $storeId) {
                $storeValues = $this->getStoreOptionValues($storeId);
                $stores[$storeId] = $storeValues[$optionId] ?? '';
            }
            $rows[] = [
                'id'         => $optionId,
                'sort_order' => (int) $option->getSortOrder(),
                'is_default' => isset($defaultValues[$optionId]),
                'stores'     => $stores,
            ];
        }
        return $rows;
    }
}
