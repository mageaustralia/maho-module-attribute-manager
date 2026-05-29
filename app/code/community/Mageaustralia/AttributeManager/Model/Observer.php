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
 * Admin observer.
 *
 * Appends a "Bulk Options" tab to the product-attribute edit page. We hook
 * `adminhtml_block_widget_tabs_html_before`, which fires for every admin
 * tabs widget AFTER its own `_beforeToHtml()` has already registered the
 * native tabs — so addTab() here safely appends below them, leaving the
 * native "Manage Label / Options" grid fully intact and upgrade-safe (no
 * block rewrite, no core layout edits).
 */
class Mageaustralia_AttributeManager_Model_Observer
{
    public function addBulkOptionsTab(Varien_Event_Observer $observer): void
    {
        $block = $observer->getEvent()->getBlock();

        // Only the catalog product-attribute edit tabs block.
        if (!($block instanceof Mage_Adminhtml_Block_Catalog_Product_Attribute_Edit_Tabs)) {
            return;
        }

        // Respect ACL: don't surface the tab to roles that lack the resource.
        if (!Mage::getSingleton('admin/session')->isAllowed('catalog/attributes/mageaustralia_attributemanager')) {
            return;
        }

        /** @var Mage_Eav_Model_Entity_Attribute|null $attribute */
        $attribute = Mage::registry('entity_attribute');
        if (!Mage::helper('mageaustralia_attributemanager')->isManageable($attribute)) {
            return;
        }

        /** @var Mageaustralia_AttributeManager_Block_Adminhtml_Tab $tabBlock */
        $tabBlock = $block->getLayout()
            ->createBlock('mageaustralia_attributemanager/adminhtml_tab');

        $block->addTab('mageaustralia_attributemanager_bulk', [
            'label'   => Mage::helper('mageaustralia_attributemanager')->__('Bulk Options'),
            'title'   => Mage::helper('mageaustralia_attributemanager')->__('Bulk Options'),
            'content' => $tabBlock->toHtml(),
        ]);
    }
}
