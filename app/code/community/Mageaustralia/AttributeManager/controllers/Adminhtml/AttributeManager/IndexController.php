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
 * Admin controller.
 *
 * Two state-changing POST actions invoked from the "Bulk Options" tab on the
 * product-attribute edit page:
 *
 *   addAction()   — Feature A: batch-create dropdown options from a textarea
 *                   of newline-separated values (skips existing labels).
 *   mergeAction() — Feature B: reassign every product currently using one of
 *                   the selected option_ids to a single goal option_id, then
 *                   delete the now-empty source options.
 *
 * Routing is via #[Maho\Config\Route] attributes only (no <routers> XML),
 * matching the convention of Mageaustralia_OrderAttributes' AttributeController.
 * The class name yields controllerName "attributemanager_index", so
 * getUrl('adminhtml/attributemanager_index/<action>') resolves these routes.
 */
class Mageaustralia_AttributeManager_Adminhtml_AttributeManager_IndexController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'catalog/attributes/mageaustralia_attributemanager';

    #[\Override]
    public function preDispatch()
    {
        // CSRF: every action mutates data and must validate the admin form key.
        $this->_setForcedFormKeyActions(['add', 'merge', 'save']);
        parent::preDispatch();
        return $this;
    }

    #[\Override]
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')
            ->isAllowed('catalog/attributes/mageaustralia_attributemanager');
    }

    /**
     * Feature A — batch add options.
     *
     * Reads POST `options` (newline-separated), trims/dedupes, skips labels
     * that already exist on the attribute, and creates the rest via the
     * standard EAV attribute save path ($attribute->setOption(...)->save()),
     * which routes through Mage_Eav_Model_Resource_Entity_Attribute::_saveOption.
     */
    #[Maho\Config\Route('/admin/attributemanager_index/add')]
    public function addAction(): void
    {
        $attribute = $this->_loadAttribute();
        if (!$attribute) {
            return;
        }

        $raw = (string) $this->getRequest()->getPost('options', '');
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        // Collapse to unique, non-empty, trimmed values preserving order.
        $candidates = [];
        foreach ($lines as $line) {
            $value = trim($line);
            if ($value !== '' && !in_array($value, $candidates, true)) {
                $candidates[] = $value;
            }
        }

        if ($candidates === []) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('No option values were entered.'),
            );
            $this->_redirectBack($attribute);
            return;
        }

        // Existing labels (admin store) for case-insensitive skip.
        $existing = [];
        foreach ($attribute->getSource()->getAllOptions(false) as $opt) {
            $existing[mb_strtolower(trim((string) $opt['label']))] = true;
        }

        $added = [];
        $skipped = [];
        $newOptions = [];
        $i = 0;
        foreach ($candidates as $value) {
            if (isset($existing[mb_strtolower($value)])) {
                $skipped[] = $value;
                continue;
            }
            // Non-numeric option key => _saveOption() treats it as a new row.
            // [0 => label] sets the admin/default store label.
            $newOptions['option_' . $i] = [0 => $value];
            $added[] = $value;
            $i++;
        }

        if ($newOptions === []) {
            Mage::getSingleton('adminhtml/session')->addNotice(
                $this->__('All %d value(s) already exist — nothing added.', count($skipped)),
            );
            $this->_redirectBack($attribute);
            return;
        }

        try {
            // Insert the option rows directly via the EAV setup resource's
            // addAttributeOption(). We deliberately do NOT call
            // $attribute->setOption(...)->save(): saving a generically-loaded
            // eav/entity_attribute triggers _beforeSave()->getDefaultSourceModel(),
            // which fatals (TypeError: null) for option-backed attributes with no
            // explicit source model. addAttributeOption() writes
            // eav_attribute_option(+_value) rows straight through the adapter,
            // with no attribute re-save and no source-model resolution.
            /** @var Mage_Eav_Model_Entity_Setup $eavSetup */
            $eavSetup = Mage::getModel('eav/entity_setup', 'core_setup');
            $eavSetup->addAttributeOption([
                'attribute_id' => (int) $attribute->getId(),
                'value'        => $newOptions,
            ]);

            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Added %d new option(s).', count($added)),
            );
            if ($skipped !== []) {
                Mage::getSingleton('adminhtml/session')->addNotice(
                    $this->__('Skipped %d value(s) that already exist: %s', count($skipped), implode(', ', $skipped)),
                );
            }
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('Could not add options: %s', $e->getMessage()),
            );
        }

        $this->_redirectBack($attribute);
    }

    /**
     * Feature B — merge options.
     *
     * POST: merge[] (source option_ids), mergegoal (target option_id).
     * Reassigns all product values from the source options to the goal, then
     * deletes the source options. Operates on option_id integers (NOT labels)
     * — the modern EAV catalog_product_entity_int.value stores the option_id.
     */
    #[Maho\Config\Route('/admin/attributemanager_index/merge')]
    public function mergeAction(): void
    {
        $attribute = $this->_loadAttribute();
        if (!$attribute) {
            return;
        }

        $merge = $this->getRequest()->getPost('merge', []);
        $goal = (int) $this->getRequest()->getPost('mergegoal', 0);

        $sourceIds = [];
        if (is_array($merge)) {
            foreach ($merge as $id) {
                $id = (int) $id;
                if ($id > 0 && $id !== $goal) {
                    $sourceIds[$id] = $id;
                }
            }
        }
        $sourceIds = array_values($sourceIds);

        if ($goal <= 0) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('Select a merge target (the option to keep).'),
            );
            $this->_redirectBack($attribute);
            return;
        }
        if (count($sourceIds) < 1) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('Select at least one option to merge into the target.'),
            );
            $this->_redirectBack($attribute);
            return;
        }

        // Validate every id (goal + sources) really belongs to this attribute.
        $validIds = array_map('intval', array_keys($this->_getOptionLabels($attribute)));
        if (!in_array($goal, $validIds, true)) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('The merge target is not a valid option of this attribute.'),
            );
            $this->_redirectBack($attribute);
            return;
        }
        foreach ($sourceIds as $sid) {
            if (!in_array($sid, $validIds, true)) {
                Mage::getSingleton('adminhtml/session')->addError(
                    $this->__('One of the selected options is not valid for this attribute.'),
                );
                $this->_redirectBack($attribute);
                return;
            }
        }

        try {
            $result = Mage::helper('mageaustralia_attributemanager/merge')
                ->mergeOptions($attribute, $sourceIds, $goal);

            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__(
                    'Merged %d option(s) into "%s". Reassigned %d product value(s); %d obsolete option(s) deleted.',
                    count($sourceIds),
                    $this->_getOptionLabels($attribute)[$goal] ?? (string) $goal,
                    $result['reassigned'],
                    $result['deleted'],
                ),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('Merge failed and was rolled back: %s', $e->getMessage()),
            );
        }

        $this->_redirectBack($attribute);
    }

    /**
     * Save the "one by one" options grid (Section 2).
     *
     * The grid is its OWN form (it does NOT reuse the native option[...] /
     * default[] field names inside #edit_form), so on save we receive a
     * self-contained payload:
     *
     *   option[value][{id}][{store_id}]  per-store label (admin = store 0)
     *   option[order][{id}]              position
     *   option[delete][{id}]             "1" to delete
     *   default[]                        option_id(s) marked Is Default
     *
     * {id} is the existing option_id, or "option_N" for client-added rows
     * (non-numeric => _saveOption() inserts a new row). This is exactly the
     * subset of POST that the core
     * Mage_Adminhtml_Catalog_Product_AttributeController::saveAction() forwards
     * into the attribute, so we replicate it here: load the catalog EAV
     * attribute model, addData() the option/default arrays and save(). The
     * resource model's _saveOption() does the insert/update/delete and rewrites
     * default_value. We use catalog/resource_eav_attribute (NOT a generic
     * eav/entity_attribute) because the latter fatals in _beforeSave() resolving
     * a null default source model for option-backed attributes.
     */
    #[Maho\Config\Route('/admin/attributemanager_index/save')]
    public function saveAction(): void
    {
        $attribute = $this->_loadAttribute();
        if (!$attribute) {
            return;
        }

        $option  = $this->getRequest()->getPost('option', []);
        $default = $this->getRequest()->getPost('default', []);

        if (!is_array($option) || !isset($option['value']) || !is_array($option['value'])) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('No option data was submitted.'),
            );
            $this->_redirectBack($attribute);
            return;
        }
        if (!is_array($default)) {
            $default = [];
        }

        try {
            // Reload as the catalog EAV attribute so save()/_saveOption() runs
            // with a valid source model (mirrors the core controller).
            /** @var Mage_Catalog_Model_Resource_Eav_Attribute $model */
            $model = Mage::getModel('catalog/resource_eav_attribute')->load((int) $attribute->getId());
            if (!$model->getId()) {
                Mage::getSingleton('adminhtml/session')->addError($this->__('This attribute no longer exists.'));
                $this->_redirect('adminhtml/catalog_product_attribute/index');
                return;
            }

            $model->addData(['option' => $option, 'default' => $default]);
            $model->setOption($option);
            $model->setDefault($default);
            $model->save();

            // Attribute labels live in the translation cache (same as core).
            Mage::app()->cleanCache([Mage_Core_Model_Translate::CACHE_TAG]);

            Mage::getSingleton('adminhtml/session')->addSuccess(
                $this->__('Attribute options have been saved.'),
            );
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('Could not save options: %s', $e->getMessage()),
            );
        }

        $this->_redirectBack($attribute);
    }

    /**
     * Load and validate the target attribute from POST attribute_id.
     * Returns null (and redirects) on any problem.
     */
    private function _loadAttribute(): ?Mage_Eav_Model_Entity_Attribute
    {
        $id = (int) $this->getRequest()->getPost('attribute_id', 0);
        if ($id <= 0) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('Missing attribute reference.'));
            $this->_redirect('adminhtml/catalog_product_attribute/index');
            return null;
        }

        /** @var Mage_Eav_Model_Entity_Attribute $attribute */
        $attribute = Mage::getModel('eav/entity_attribute')->load($id);
        if (!$attribute->getId()) {
            Mage::getSingleton('adminhtml/session')->addError($this->__('This attribute no longer exists.'));
            $this->_redirect('adminhtml/catalog_product_attribute/index');
            return null;
        }

        if (!Mage::helper('mageaustralia_attributemanager')->isManageable($attribute)) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->__('This tool only supports dropdown / multiple-select attributes backed by the option table.'),
            );
            $this->_redirect('adminhtml/catalog_product_attribute/edit', ['attribute_id' => $id]);
            return null;
        }

        return $attribute;
    }

    /**
     * @return array<int, string> option_id => admin-store label
     */
    private function _getOptionLabels(Mage_Eav_Model_Entity_Attribute $attribute): array
    {
        $labels = [];
        foreach ($attribute->getSource()->getAllOptions(false) as $opt) {
            $value = $opt['value'] ?? null;
            if ($value !== null && $value !== '') {
                $labels[(int) $value] = (string) $opt['label'];
            }
        }
        return $labels;
    }

    private function _redirectBack(Mage_Eav_Model_Entity_Attribute $attribute): void
    {
        $this->_redirect('adminhtml/catalog_product_attribute/edit', [
            'attribute_id' => (int) $attribute->getId(),
            'tab'          => 'product_attribute_tabs_mageaustralia_attributemanager_bulk',
        ]);
    }
}
