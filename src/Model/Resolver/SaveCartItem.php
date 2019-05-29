<?php
/**
 * ScandiPWA - Progressive Web App for Magento
 *
 * Copyright © Scandiweb, Inc. All rights reserved.
 * See LICENSE for license details.
 *
 * @license OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * @package scandipwa/quote-graphql
 * @link    https://github.com/scandipwa/quote-graphql
 */

declare(strict_types=1);


namespace ScandiPWA\QuoteGraphQl\Model\Resolver;


use Exception;
use Magento\ConfigurableProduct\Model\Quote\Item\ConfigurableItemOptionValueFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\ProductOptionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartItemRepositoryInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartItemInterface;
use \Magento\Quote\Api\Data\CartItemInterfaceFactory;
use Magento\Quote\Api\Data\ProductOptionExtensionFactory;
use Magento\Quote\Api\GuestCartItemRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Quote\Model\Webapi\ParamOverriderCartId;
use Magento\Catalog\Model\ProductRepository;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Bundle\Model\Product\Type;
use Magento\Framework\DataObject;
use Magento\Catalog\Model\Product\Attribute\Repository;

/**
 * Class SaveCartItem
 * @package ScandiPWA\QuoteGraphQl\Model\Resolver
 */
class SaveCartItem implements ResolverInterface
{
    /**
     * @var CartItemRepositoryInterface
     */
    private $cartItemRepository;
    
    /**
     * @var CartItemInterfaceFactory
     */
    private $cartItemFactory;
    
    /**
     * @var GuestCartItemRepositoryInterface
     */
    private $guestCartItemRepository;
    
    /**
     * @var QuoteIdMaskFactory
     */
    private $quoteIdMaskFactory;
    
    /**
     * @var CartRepositoryInterface
     */
    private $quoteRepository;
    
    /**
     * @var ProductOptionFactory
     */
    private $productOptionFactory;
    
    /**
     * @var ProductOptionExtensionFactory
     */
    private $productOptionExtensionFactory;
    
    /**
     * @var ConfigurableItemOptionValueFactory
     */
    private $configurableItemOptionValueFactory;

    /**
     * @var ParamOverriderCartId
     */
    protected $overriderCartId;

    /**
     * @var ProductRepository
     */
    protected $productRepository;

    /**
     * @var Repository
     */
    protected $attributeRepository;

    /**
     * SaveCartItem constructor.
     * @param CartItemRepositoryInterface $cartItemRepository
     * @param CartItemInterfaceFactory $cartItemFactory
     * @param GuestCartItemRepositoryInterface $guestCartItemRepository
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param CartRepositoryInterface $quoteRepository
     * @param ProductOptionFactory $productOptionFactory
     * @param ProductOptionExtensionFactory $productOptionExtensionFactory
     * @param ConfigurableItemOptionValueFactory $configurableItemOptionValueFactory
     * @param ParamOverriderCartId $overriderCartId
     * @param ProductRepository $productRepository
     * @param Repository $attributeRepository
     */
    public function __construct(
        CartItemRepositoryInterface $cartItemRepository,
        CartItemInterfaceFactory $cartItemFactory,
        GuestCartItemRepositoryInterface $guestCartItemRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $quoteRepository,
        ProductOptionFactory $productOptionFactory,
        ProductOptionExtensionFactory $productOptionExtensionFactory,
        ConfigurableItemOptionValueFactory $configurableItemOptionValueFactory,
        ParamOverriderCartId $overriderCartId,
        ProductRepository $productRepository,
        Repository $attributeRepository
    )
    {
        $this->cartItemRepository = $cartItemRepository;
        $this->cartItemFactory = $cartItemFactory;
        $this->guestCartItemRepository = $guestCartItemRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->quoteRepository = $quoteRepository;
        $this->productOptionFactory = $productOptionFactory;
        $this->productOptionExtensionFactory = $productOptionExtensionFactory;
        $this->configurableItemOptionValueFactory = $configurableItemOptionValueFactory;
        $this->overriderCartId = $overriderCartId;
        $this->productRepository = $productRepository;
        $this->attributeRepository = $attributeRepository;
    }

    private function makeAddRequest(Product $product, $sku = null, $qty = 1)
    {
        $data = [
            'product' => $product->getEntityId(),
            'qty' => $qty
        ];

        switch ($product->getTypeId()) {
            case Configurable::TYPE_CODE:
                $data = $this->setConfigurableRequestOptions($product, $sku, $data);
                break;
            case Type::TYPE_CODE:
                $data = $this->setBundleRequestOptions($product, $data);
                break;
        }

        $request = new DataObject();
        $request->setData($data);

        return $request;
    }

    private function setConfigurableRequestOptions(Product $product, $sku, array $data)
    {
        /** @var Type | Configurable $typedProduct */
        $typedProduct = $product->getTypeInstance();

        $childProduct = $this->productRepository->get($sku);
        $productAttributeOptions = $typedProduct->getConfigurableAttributesAsArray($product);

        $superAttributes = [];
        foreach ($productAttributeOptions as $option) {
            $superAttributes[$option['attribute_id']] = $childProduct->getData($option['attribute_code']);
        }

        $data['super_attribute'] = $superAttributes;
        return $data;
    }

    private function setBundleRequestOptions(Product $product, array $data)
    {
        /** @var Type $typedProduct */
        $typedProduct = $product->getTypeInstance();

        $selectionCollection = $typedProduct->getSelectionsCollection($typedProduct->getOptionsIds($product), $product);

        $options = [];
        foreach ($selectionCollection as $proSelection) {
            $options[$proSelection->getOptionId()] = $proSelection->getSelectionId();
        }

        $data['bundle_option'] = $options;
        return $data;
    }

    /**
     * Fetches the data from persistence models and format it according to the GraphQL schema.
     *
     * @param Field $field
     * @param ContextInterface                                $context
     * @param ResolveInfo                                     $info
     * @param array|null                                      $value
     * @param array|null                                      $args
     * @return mixed|Value
     * @throws Exception
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    )
    {
        $requestCartItem = $args['cartItem'];

        $isGuestCartItemRequest = isset($args['guestCartId']);

        $requestCartItem['quote_id'] = $isGuestCartItemRequest
            ? $this->quoteIdMaskFactory->create()->load($args['guestCartId'], 'masked_id')->getQuoteId()
            : $this->overriderCartId->getOverriddenValue();

        if (array_key_exists('item_id', $requestCartItem)) {
            $quote = $this->quoteRepository->getActive($requestCartItem['quote_id']);
            $cartItem = $quote->getItemById($requestCartItem['item_id']);
            $cartItem->setQty($requestCartItem['qty']);
            $this->cartItemRepository->save($cartItem);
        } else {
            $quote = $this->quoteRepository->getActive($requestCartItem['quote_id']);
            $product = $this->productRepository->get($requestCartItem['sku']);
            $quote->addProduct($product, $this->makeAddRequest(
                $product,
                $requestCartItem['sku'],
                $requestCartItem['qty']
            ));
            $this->quoteRepository->save($quote);

            // Related to bug: https://github.com/magento/magengto2/issues/2991
            $quote = $this->quoteRepository->getActive($requestCartItem['quote_id']);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->quoteRepository->save($quote);
        }

        return [];
    }
}
