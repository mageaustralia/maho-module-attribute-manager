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
 * Option merge helper.
 *
 * Reassigns product assignments from a set of source option_ids to a single
 * goal option_id, then deletes the source options. ALL work is done on
 * option_id integers (not labels): in modern Maho EAV the product value tables
 * store the option_id as a foreign key into eav_attribute_option, NOT the
 * label string. The original Elgentos extension updated `value` as if it held
 * the label - that is wrong on this schema and is deliberately NOT replicated.
 *
 * Tables touched (verified against the live dev schema):
 *   - catalog_product_entity_int.value      => option_id for select/int attrs
 *   - catalog_product_entity_varchar.value  => CSV of option_ids for multiselect
 *   - catalog_product_super_attribute_pricing.value_index
 *                                           => option_id (varchar) for
 *                                              configurable super-attribute pricing
 *   - eav_attribute_option                  => the option rows (deleted last;
 *                                              eav_attribute_option_value rows
 *                                              cascade-delete via FK)
 *
 * Portability: every statement uses the adapter query builder
 * (select/update/delete with quoted identifiers and bound conditions) so it
 * runs on MySQL, PostgreSQL and SQLite. No raw SQL, no backticks, no
 * MySQL-only multi-table DELETE. The whole operation runs in one transaction.
 */
class Mageaustralia_AttributeManager_Helper_Merge extends Mage_Core_Helper_Abstract
{
    protected $_moduleName = 'Mageaustralia_AttributeManager';

    /**
     * @param int[] $sourceIds option_ids to merge away (already validated, != goal)
     * @param int   $goalId    option_id to keep
     * @return array{reassigned: int, deleted: int}
     * @throws Throwable on failure (transaction is rolled back before re-throw)
     */
    public function mergeOptions(
        Mage_Eav_Model_Entity_Attribute $attribute,
        array $sourceIds,
        int $goalId,
    ): array {
        $sourceIds = array_values(array_unique(array_map('intval', $sourceIds)));
        $sourceIds = array_filter($sourceIds, static fn(int $id): bool => $id > 0 && $id !== $goalId);
        if ($sourceIds === [] || $goalId <= 0) {
            return ['reassigned' => 0, 'deleted' => 0];
        }

        /** @var Mage_Core_Model_Resource $resources */
        $resources = Mage::getSingleton('core/resource');
        $write = $resources->getConnection('core_write');

        $attributeId = (int) $attribute->getId();
        $isMultiselect = $attribute->getFrontendInput() === 'multiselect';

        // Canonical EAV API: the backend knows its exact value table
        // (e.g. catalog_product_entity_int / _varchar), schema-prefix aware.
        $valueTable = $attribute->getBackend()->getTable();

        $reassigned = 0;

        $write->beginTransaction();
        try {
            if ($isMultiselect) {
                $reassigned += $this->_remapMultiselect(Maho\Db\Adapter\AdapterInterface $write, $valueTable, $attributeId, $sourceIds, $goalId);
            } else {
                $reassigned += $this->_remapInt(Maho\Db\Adapter\AdapterInterface $write, $valueTable, $attributeId, $sourceIds, $goalId);
            }

            // Configurable super-attribute pricing references option_id as a
            // string in value_index. Remap any pricing rows that point at a
            // source option so per-option price modifiers follow the merge.
            $reassigned += $this->_remapSuperAttributePricing(Maho\Db\Adapter\AdapterInterface $write, $attributeId, $sourceIds, $goalId);

            // Finally delete the now-orphaned source options. The matching
            // eav_attribute_option_value rows cascade-delete via FK.
            $optionTable = $resources->getTableName('eav/attribute_option');
            $deleted = (int) $write->delete(
                $optionTable,
                ['option_id IN (?)' => $sourceIds, 'attribute_id = ?' => $attributeId],
            );

            $write->commit();
            return ['reassigned' => $reassigned, 'deleted' => $deleted];
        } catch (\Throwable $e) {
            $write->rollBack();
            throw $e;
        }
    }

    /**
     * Single-value select attributes: catalog_product_entity_int.value holds
     * the option_id. Re-point source option_ids to the goal.
     *
     * Done per source id so we don't clobber rows that already hold the goal
     * value, and to keep the WHERE strictly scoped to this attribute.
     */
    private function _remapInt(Maho\Db\Adapter\AdapterInterface $write, string $table, int $attributeId, array $sourceIds, int $goalId): int
    {
        return (int) $write->update(
            $table,
            ['value' => $goalId],
            [
                'attribute_id = ?' => $attributeId,
                'value IN (?)'     => $sourceIds,
            ],
        );
    }

    /**
     * Multiselect attributes: catalog_product_entity_varchar.value holds a
     * comma-separated list of option_ids. Rewrite each affected row's CSV,
     * replacing source ids with the goal id and de-duplicating.
     *
     * Read-modify-write in PHP keeps this portable (no DB string functions)
     * and correct for the CSV semantics.
     */
    private function _remapMultiselect(Maho\Db\Adapter\AdapterInterface $write, string $table, int $attributeId, array $sourceIds, int $goalId): int
    {
        $sourceSet = array_fill_keys($sourceIds, true);

        $select = $write->select()
            ->from($table, ['value_id', 'value'])
            ->where('attribute_id = ?', $attributeId);

        $rows = $write->fetchAll($select);
        $count = 0;
        foreach ($rows as $row) {
            $value = (string) $row['value'];
            if ($value === '') {
                continue;
            }
            $ids = array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== '');
            $touched = false;
            $out = [];
            foreach ($ids as $idStr) {
                $id = (int) $idStr;
                if (isset($sourceSet[$id])) {
                    $id = $goalId;
                    $touched = true;
                }
                // De-dupe (a product may have held both a source and the goal).
                if (!in_array($id, $out, true)) {
                    $out[] = $id;
                }
            }
            if ($touched) {
                $write->update(
                    $table,
                    ['value' => implode(',', $out)],
                    ['value_id = ?' => (int) $row['value_id']],
                );
                $count++;
            }
        }
        return $count;
    }

    /**
     * catalog_product_super_attribute_pricing.value_index stores the option_id
     * as a string for per-option price modifiers on configurable products.
     * Re-point rows that reference a source option to the goal, only for
     * super-attributes that are THIS attribute.
     */
    private function _remapSuperAttributePricing(Maho\Db\Adapter\AdapterInterface $write, int $attributeId, array $sourceIds, int $goalId): int
    {
        $resource     = Mage::getSingleton('core/resource');
        $pricingTable = $resource->getTableName('catalog/product_super_attribute_pricing');
        $superTable   = $resource->getTableName('catalog/product_super_attribute');

        // Which super-attribute rows belong to this attribute?
        $superIds = $write->fetchCol(
            $write->select()
                ->from($superTable, ['product_super_attribute_id'])
                ->where('attribute_id = ?', $attributeId),
        );
        if (!$superIds) {
            return 0;
        }
        $superIds = array_map('intval', $superIds);

        // value_index is varchar; compare against string source ids.
        $sourceStr = array_map('strval', $sourceIds);
        $count = (int) $write->update(
            $pricingTable,
            ['value_index' => (string) $goalId],
            [
                'product_super_attribute_id IN (?)' => $superIds,
                'value_index IN (?)'                => $sourceStr,
            ],
        );
        return $count;
    }
}
