<?php
declare(strict_types=1);

namespace PerfectCode\ImportCreateOptionsOnFly\Model\Import\Product;

use Magento\Catalog\Model\Product\Attribute\OptionManagement;
use Magento\CatalogImportExport\Model\Import\Product as Product;
use Magento\CatalogImportExport\Model\Import\Product\RowValidatorInterface as RowValidatorInterface;
use Magento\CatalogImportExport\Model\Import\Product\Validator as ParentValidator;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Stdlib\StringUtils;

class Validator extends ParentValidator
{
    /**
     * @var array
     */
    private array $dynamicallyOptionAdded = [];

    /**
     * @var OptionManagement
     */
    private OptionManagement $optionManagement;

    /**
     * @var AttributeOptionInterfaceFactory
     */
    private AttributeOptionInterfaceFactory $optionDataFactory;

    /**
     * @var DataObjectHelper
     */
    private DataObjectHelper $dataObjectHelper;

    /**
     * Validator constructor.
     * @param StringUtils $string
     * @param array $validators
     * @param OptionManagement $optionManagement
     * @param AttributeOptionInterfaceFactory $optionDataFactory
     * @param DataObjectHelper $dataObjectHelper
     */
    public function __construct(
        StringUtils $string,
        OptionManagement $optionManagement,
        AttributeOptionInterfaceFactory $optionDataFactory,
        DataObjectHelper $dataObjectHelper,
        array $validators = []
    ) {
        parent::__construct($string, $validators);
        $this->optionManagement = $optionManagement;
        $this->optionDataFactory = $optionDataFactory;
        $this->dataObjectHelper = $dataObjectHelper;
    }

    /**
     * @param string $attrCode
     * @param array $attrParams
     * @param array $rowData
     * @return bool
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function isAttributeValid($attrCode, array $attrParams, array $rowData)
    {
        $this->_rowData = $rowData;
        if (isset($rowData['product_type']) && !empty($attrParams['apply_to'])
            && !in_array($rowData['product_type'], $attrParams['apply_to'])
        ) {
            return true;
        }

        if (!$this->isRequiredAttributeValid($attrCode, $attrParams, $rowData)) {
            $valid = false;
            $this->_addMessages(
                [
                    sprintf(
                        $this->context->retrieveMessageTemplate(
                            RowValidatorInterface::ERROR_VALUE_IS_REQUIRED
                        ),
                        $attrCode
                    )
                ]
            );
            return $valid;
        }

        if ($rowData[$attrCode] === null || trim($rowData[$attrCode]) === '') {
            return true;
        }

        if ($rowData[$attrCode] === $this->context->getEmptyAttributeValueConstant() && !$attrParams['is_required']) {
            return true;
        }
        switch ($attrParams['type']) {
            case 'varchar':
            case 'text':
                $valid = $this->textValidation($attrCode, $attrParams['type']);
                break;
            case 'decimal':
            case 'int':
                $valid = $this->numericValidation($attrCode, $attrParams['type']);
                break;
            case 'boolean':
                $valid = $this->validateOption($attrCode, $attrParams['options'], $rowData[$attrCode]);
                break;
            case 'select':
            case 'multiselect':
                $values = explode(Product::PSEUDO_MULTI_LINE_SEPARATOR, $rowData[$attrCode]);
                $valid = true;

                // Start custom
                foreach ($values as $value) {
                    // If option does not exist and wasn't already dynamically added
                    if (!empty($value) && !isset($attrParams['options'][strtolower($value)])
                        && !isset($this->dynamicallyOptionAdded[$attrCode][strtolower($value)])
                    ) {
                        // Create option value
                        $optionDataObject = $this->optionDataFactory->create();
                        $this->dataObjectHelper->populateWithArray(
                            $optionDataObject,
                            [
                                'label' => $value,
                                'sort_order' => 100,
                                'is_default' => true
                            ],
                            AttributeOptionInterface::class
                        );

                        // Add option dynamically
                        if ($this->optionManagement->add($attrCode, $optionDataObject)) {
                            // Add new option value dynamically created to the different entityTypeModel cache
                            /** @var \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType $entityTypeModel */
                            $entityTypeModel = $this->context->retrieveProductTypeByName($rowData['product_type']);

                            // Refresh attributes cache for entityTypeModel cache
                            if ($entityTypeModel) {
                                $entityTypeModel->__destruct();
                            }

                            $this->dynamicallyOptionAdded[$attrCode][strtolower($value)] = true;
                            $attrParams['options'][strtolower($value)] = true;
                        }
                    }
                }

                if (isset($this->dynamicallyOptionAdded[$attrCode])) {
                    foreach ($this->dynamicallyOptionAdded[$attrCode] as $key => $value) {
                        $attrParams['options'][$key] = $value;
                    }
                }
                // end custom

                foreach ($values as $value) {
                    $valid = $valid && isset($attrParams['options'][strtolower($value)]);
                }
                if (!$valid) {
                    $this->_addMessages(
                        [
                            sprintf(
                                $this->context->retrieveMessageTemplate(
                                    RowValidatorInterface::ERROR_INVALID_ATTRIBUTE_OPTION
                                ),
                                $attrCode
                            )
                        ]
                    );
                }

                $uniqueValues = array_unique($values);
                if (count($uniqueValues) != count($values)) {
                    $valid = false;
                    $this->_addMessages([RowValidatorInterface::ERROR_DUPLICATE_MULTISELECT_VALUES]);
                }

                break;
            case 'datetime':
                $val = trim($rowData[$attrCode]);
                $valid = strtotime($val) !== false;
                if (!$valid) {
                    $this->_addMessages([RowValidatorInterface::ERROR_INVALID_ATTRIBUTE_TYPE]);
                }
                break;
            default:
                $valid = false;
                $this->_addMessages([RowValidatorInterface::ERROR_INVALID_TYPE]);
                break;
        }

        if ($valid && !empty($attrParams['is_unique'])) {
            if (isset($this->_uniqueAttributes[$attrCode][$rowData[$attrCode]])
                && ($this->_uniqueAttributes[$attrCode][$rowData[$attrCode]] != $rowData[Product::COL_SKU])) {
                $this->_addMessages([RowValidatorInterface::ERROR_DUPLICATE_UNIQUE_ATTRIBUTE]);
                return false;
            }
            $this->_uniqueAttributes[$attrCode][$rowData[$attrCode]] = $rowData[Product::COL_SKU];
        }

        if (!$valid) {
            $this->setInvalidAttribute($attrCode);
        }

        return (bool)$valid;
    }

    /**
     * Check if value is valid attribute option
     *
     * @param string $attrCode
     * @param array $possibleOptions
     * @param string $value
     * @return bool
     */
    private function validateOption($attrCode, $possibleOptions, $value)
    {
        if (!isset($possibleOptions[strtolower($value)])) {
            $this->_addMessages(
                [
                    sprintf(
                        $this->context->retrieveMessageTemplate(
                            RowValidatorInterface::ERROR_INVALID_ATTRIBUTE_OPTION
                        ),
                        $attrCode
                    )
                ]
            );
            return false;
        }
        return true;
    }
}
