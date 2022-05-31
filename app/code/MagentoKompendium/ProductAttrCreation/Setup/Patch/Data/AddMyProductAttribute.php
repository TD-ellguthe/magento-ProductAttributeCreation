<?php
/**
 * Copyright (c) 2022 TechDivision GmbH <info@techdivision.com> - TechDivision GmbH
 * All rights reserved
 *
 * This product includes proprietary software developed at TechDivision GmbH, Germany
 * For more information see http://www.techdivision.com/
 *
 * To obtain a valid license for using this software please contact us at
 * license@techdivision.com
 */
declare(strict_types=1);

namespace TdEllguthe\ProductAttrCreation\Setup\Patch\Data;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Config;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeFrontendLabelInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterface;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Framework\Validator\Exception;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Zend_Validate_Exception;

/**
 * @copyright  Copyright (c) 2022 TechDivision GmbH <info@techdivision.com> - TechDivision GmbH
 *
 * @link       https://www.techdivision.com/
 * @author     Team Zero <zero@techdivision.com>
 */
class AddMyProductAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public const ATTR_CODE = 'my_attribute';

    private ModuleDataSetupInterface $moduleDataSetup;
    private EavSetup $eavSetup;
    private int $productEntityTypeId;
    private Config $catalogConfig;
    private ProductAttributeRepositoryInterface $attributeRepository;
    private AttributeFrontendLabelInterfaceFactory $labelFactory;
    private AttributeOptionLabelInterfaceFactory $optionLabelFactory;
    private AttributeOptionInterfaceFactory $optionFactory;
    private AttributeOptionManagementInterface $optionManagement;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     * @param Config $catalogConfig
     * @param ProductAttributeRepositoryInterface $attributeRepository
     * @param AttributeFrontendLabelInterfaceFactory $labelFactory
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeOptionLabelInterfaceFactory $optionLabelFactory
     * @param AttributeOptionManagementInterface $optionManagement
     * @throws LocalizedException
     */
    public function __construct(
        ModuleDataSetupInterface               $moduleDataSetup,
        EavSetupFactory                        $eavSetupFactory,
        Config                                 $catalogConfig,
        ProductAttributeRepositoryInterface    $attributeRepository,
        AttributeFrontendLabelInterfaceFactory $labelFactory,
        AttributeOptionInterfaceFactory        $optionFactory,
        AttributeOptionLabelInterfaceFactory   $optionLabelFactory,
        AttributeOptionManagementInterface     $optionManagement
    ) {
        $this->moduleDataSetup     = $moduleDataSetup;
        $this->eavSetup            = $eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        $this->productEntityTypeId = (int) $this->eavSetup->getEntityTypeId(Product::ENTITY);
        $this->catalogConfig       = $catalogConfig;
        $this->attributeRepository = $attributeRepository;
        $this->labelFactory        = $labelFactory;
        $this->optionFactory       = $optionFactory;
        $this->optionLabelFactory  = $optionLabelFactory;
        $this->optionManagement    = $optionManagement;
    }

    /**
     * @throws Zend_Validate_Exception
     * @throws LocalizedException
     */
    public function apply()
    {
        // disable foreign key checks & prevent auto-increment values when inserting 0
        $this->moduleDataSetup->getConnection()->startSetup();

        /**
         * @var string $backendType
         * possible types: static, varchar, int, decimal, datetime
         * static: data is stored in the main table (catalog_product_entity), not in the type-specific table
         * @see $backendTable
         */
        $backendType = 'varchar';

        /**
         * @var string|null
         * define a value table (i.g. "catalog_product_entity_my_varchar"), independently from $backendType
         * @see $backendType
         */
        $backendTable = null;

        /**
         * @var string | null $inputRendererClassName
         * class name of a render class for input html generation in admin area
         * @see \Magento\Framework\Data\Form\Element\AbstractElement::getElementHtml()
         */
        $inputRendererClassName = null;

        /**
         * @var string | null $attributeModelClassName
         * class name of a custom attribute model (rarely used)
         * @see \Magento\Eav\Model\Entity\Attribute
         */
        $attributeModelClassName = null;

        /**
         * class name for options (select, multiselect, dropdown, radio)
         * @var string | null $sourceModelClassName
         * @see \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
         */
        $sourceModelClassName = null;

        /**
         * Model for attribute data modification after retrieval from DB
         * @var string | null $frontendModelClassName
         * @see \Magento\Eav\Model\Entity\Attribute\Frontend\AbstractFrontend
         */
        $frontendModelClassName = null;

        /**
         * Model for attribute data modification before saving
         * including validate, afterLoad, beforeSave, afterSave, beforeDelete, afterDelete
         * @var string | null $backendModelClassName
         * @see \Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend
         */
        $backendModelClassName = null;

        /**
         * @var bool $showOnPdp
         * show in "Additional Information" tab of the product detail page (frontend)
         */
        $showOnPdp = true;

        /**
         * @var string[] $applicableProductTypes
         * the attribute will only be available for the following product types
         */
        $applicableProductTypes = [
            Type::TYPE_SIMPLE,
            Type::TYPE_BUNDLE,
            Type::TYPE_VIRTUAL,
            Grouped::TYPE_CODE,
            Configurable::TYPE_CODE,
        ];

        /**
         * @var string $groupName
         * name of the accordion element of product page in admin area
         * @see table "eav_attribute_group"
         */
        $groupName = 'General';

        /**
         * @var string $groupCode
         * code of the accordion element of product page in admin area
         * usually the group name can be used
         * @see \Magento\Eav\Setup\EavSetup::convertToAttributeGroupCode()
         * @see table `eav_attribute_group`.`attribute_group_code`
         */
        $groupCode = $groupName;


        /**
         * @var string
         * name of the attribute (visible in admin & frontend)
         */
        $defaultLabel        = 'My Attribute';
        $storeSpecificLabels = [
            1 => 'My label for Store View 1',
            2 => 'My label for Store View 2',
            3 => 'My label for Store View 3',
        ];

        /**
         * @var $inputType
         * possible values: text, int, select, multiselect, date, hidden, boolean, multiline
         */
        $inputType = 'text';

        /**
         * options are saved in DB tables eav_attribute_option and eav_attribute_option_value
         * - no store specific values are possible: use $options instead!
         * - only compatible with the following input types: select, multiselect
         * - incompatible with a source model
         * @see $inputType
         * @see $sourceModel
         * @see $options
         */
        $simpleOptions = [
//            'Value A',
//            'Value B',
//            'Value C',
//            'Value D',
        ];

        /**
         * options are saved in DB tables eav_attribute_option and eav_attribute_option_value
         * - only compatible with the following input types: select, multiselect
         * - incompatible with source model
         * @see $inputType
         * @see $sourceModelClassName
         * @see $simpleOptions
         */
        $options = [
//            [
//                0 => 'Default Label for Option 1',
//                1 => 'Label for Option 1 (Store View 1)',
//                2 => 'Label for Option 1 (Store View 2)',
//                3 => 'Label for Option 1 (Store View 3)',
//            ],
//            [
//                0 => 'Default Label for Option 2',
//                1 => 'Label for Option 2 (Store View 1)',
//                2 => 'Label for Option 2 (Store View 2)',
//                3 => 'Label for Option 2 (Store View 3)',
//            ],
        ];


        // validate
        if ((!empty($options)) && (!empty($storeSpecificLabels))) {
            /** @see self::createOptions()*/
            /** @see self::setAttributeLabels()*/
            $errorMsg  = 'store specific labels and option creation are bugged in Magento 2.4!';
            $errorMsg .= ' Please apply one of them manually in the admin area.';

            throw new Exception(__($errorMsg));
        }

        /**
         * define & add attribute
         * some keys will be mapped to different DB columns
         * @see \Magento\Eav\Model\Entity\Setup\PropertyMapper::map()
         */
        $this->eavSetup->addAttribute(
            Product::ENTITY,
            self::ATTR_CODE,
            [
                'label'                      => $defaultLabel,  // see variable declaration above
                'type'                       => $backendType,   // see variable declaration above
                'input'                      => $inputType,     // see variable declaration above
                'input_renderer'             => $inputRendererClassName, // see variable declaration above
                'wysiwyg_enabled'            => false,          // show wysiwyg-editor instead of textarea in admin area
                'required'                   => true,           // true: needs to have a value set
                'attribute_model'            => $attributeModelClassName, // see variable declaration above
                'source'                     => $sourceModelClassName,    // alternative: "option" (see below)
                'frontend'                   => $frontendModelClassName,  // see variable declaration above
                'backend'                    => $backendModelClassName,   // see variable declaration above
                'sort_order'                 => 30,             // position among other attributes (admin area & frontend)
                'global'                     => ScopedAttributeInterface::SCOPE_STORE, // defines scope
                'default'                    => null,           // default value
                'visible'                    => true,           // admin area & frontend
                'user_defined'               => false,          // true: admin can edit attribute properties in admin area (Stores -> Attributes)
                'searchable'                 => false,          // consider attribute value in search / on search result page
                'filterable'                 => false,          // show in layered navigation on category pages
                'filterable_in_search'       => false,          // show in layered navigation on search result page
                'visible_in_advanced_search' => false,          // show attribute on advanced search result page
                'comparable'                 => false,          // is visible on compare page
                'visible_on_front'           => $showOnPdp,     // see variable declaration above
                'is_html_allowed_on_front'   => false,          // true: value will be rendered as HTML
                'unique'                     => false,          // globally unique
                'apply_to'                   => implode(',', $applicableProductTypes), // see variable declaration above
                'attribute_set'              => 'Default',      // optional, name of the attribute set (consistent with 'groupName')
                'used_in_product_listing'    => false,          // load attribute in collections in category listing
                'is_used_in_grid'            => false,          // load for product list page (admin area)
                'is_visible_in_grid'         => false,          // show on product list page (admin area)
                'is_filterable_in_grid'      => false,          // show filter on product list page (admin area)
                'used_for_sort_by'           => false,          // show in "sort by" dropdowns (search / category)
                'is_used_for_promo_rules'    => false,          // available for in cart rules / catalog rules
                'table'                      => $backendTable,  // see variable declaration above
                'note'                       => null,           // string, shown as hint beneath the input in admin area
                'option' => ['values' => $simpleOptions],       // see variable declaration above
            ]
        );

        // load newly created attribute
        $attribute = $this->attributeRepository->get(self::ATTR_CODE);

        // add attribute to group (create it, if necessary)
        $this->addAttributeToGroup((int) $attribute->getAttributeId(), $groupName, $groupCode, 5, 10);

        // set store view specific labels
        foreach ($storeSpecificLabels as $storeId => $label) {
            $this->setAttributeLabels($attribute, $storeId, $label);
        }

        // create dropdown options
        if (!empty($options)) {
            $this->createOptions($attribute, $options);
        }

        // enable foreign key checks & restore original SQL_MODE
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @param int $attributeId
     * @param string $groupName
     * @param string $groupCode
     * @param int $groupPosInEntity
     * @param int $attrPosInGroup
     */
    private function addAttributeToGroup(
        int $attributeId,
        string $groupName,
        string $groupCode,
        int $groupPosInEntity,
        int $attrPosInGroup
    ): void {
        $attributeSetId   = $this->eavSetup->getDefaultAttributeSetId(Product::ENTITY);
        $attributeGroupId = $this->catalogConfig->getAttributeGroupId($attributeSetId, $groupName);

        if (empty($attributeGroupId)) {
            // group does not exist yet => create it
            $this->eavSetup->addAttributeGroup(
                $this->productEntityTypeId,
                $attributeSetId,
                $groupName,
                $groupPosInEntity
            );
        }

        // add attribute to group
        $this->eavSetup->addAttributeToGroup(
            $this->productEntityTypeId,
            $attributeSetId,
            $groupCode,
            $attributeId,
            $attrPosInGroup
        );
    }

    /**
     * @param ProductAttributeInterface $attribute
     * @param int $storeId
     * @param string $label
     * @return void
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws StateException
     */
    private function setAttributeLabels(
        ProductAttributeInterface $attribute,
        int $storeId,
        string $label
    ): void {
        $frontendLabels   = $attribute->getFrontendLabels();
        $frontendLabels[] = $this->labelFactory->create()->setStoreId($storeId)->setLabel($label);

        $attribute->setFrontendLabels($frontendLabels);
        $this->attributeRepository->save($attribute);
    }

    /**
     * @param ProductAttributeInterface $attribute
     * @param array $options
     * @return void
     * @throws InputException
     * @throws StateException
     */
    private function createOptions(ProductAttributeInterface $attribute, array $options): void
    {
        $sortOrder = 0;

        if ($attribute->getSourceModel() !== null) {
            throw new Exception(__('cannot create options, source model is already set!'));
        }

        if (!in_array($attribute->getFrontendInput(), ['select', 'multiselect'])) {
            throw new Exception(__('input "%1" is not compatible with options!', $attribute->getFrontendInput()));
        }

        foreach ($options as $optionLabels) {
            /** @var AttributeOptionLabelInterface[] $optionLabelObjs */
            $optionLabelObjs = [];

            foreach ($optionLabels as $storeId => $optionLabel) {
                $optionLabelObj = $this->optionLabelFactory->create();
                $optionLabelObj->setStoreId($storeId);
                $optionLabelObj->setLabel($optionLabel);

                $optionLabelObjs[$storeId] = $optionLabelObj;
            }

            if (isset($optionLabelObjs[0])) {
                // admin label was provided
                $defaultLabelString = $optionLabelObjs[0]->getLabel();
            } else {
                // use first found label
                $defaultLabelString = reset($optionLabelObjs)->getLabel();
            }

            /** @var AttributeOptionInterface $option */
            $option = $this->optionFactory->create();
            $option->setLabel($defaultLabelString);
            $option->setStoreLabels($optionLabelObjs);
            $option->setSortOrder($sortOrder++);
            $option->setIsDefault(false);

            // remark: this removes store specific attribute labels from the attribute in Magento 2.4
            $this->optionManagement->add(Product::ENTITY, $attribute->getAttributeId(), $option);
        }
    }

    /**
     * calling this function should uninstall this patch, if already applied
     */
    public function revert(): void
    {
        // disable foreign key checks & prevent auto-increment values when inserting 0
        $this->moduleDataSetup->getConnection()->startSetup();

        // remove the attribute
        $this->eavSetup->removeAttribute(Product::ENTITY, self::ATTR_CODE);

        // enable foreign key checks & restore original SQL_MODE
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @return string[]
     * some patches with could change their names during development
     * to keep track of already installed patches and dependencies, all previously used (and other) names can be entered here
     */
    public function getAliases(): array
    {
        return [
            // \SomeVendor\SomeModule\Setup\Patch\Data\SomePatch::class
        ];
    }

    /**
     * @return string[]
     * if some data patches must be applied before this one: list them here
     */
    public static function getDependencies(): array
    {
        return [
            // \SomeVendor\SomeModule\Setup\Patch\Data\SomePatch::class
        ];
    }
}
