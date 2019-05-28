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
        ParamOverriderCartId $overriderCartId
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
    }

    /**
     * @param array $args
     * @return mixed
     * @throws NoSuchEntityException
     */
    private function getCartItem(array $args): CartItemInterface
    {
        $quote = $this->quoteRepository->getActive($args['quote_id']);
        $cartItem = $quote->getItemById($args['item_id']);
        $cartItem->setQty($args['qty']);
        return $cartItem;
    }

    /**
     * @param CartItemInterface $cartItem
     * @param array $productOptions
     * @return CartItemInterface
     */
    private function createConfigurable(CartItemInterface $cartItem, array $productOptions): CartItemInterface
    {
        $extensionAttributes = $productOptions['extension_attributes'];

        $attributes = [];
        foreach ($extensionAttributes['configurable_item_options'] as $attribute) {
            $attributes[] = $this->configurableItemOptionValueFactory->create(['data' => $attribute]);
        }

        $ext = $this->productOptionExtensionFactory->create(
            ['data' => ['configurable_item_options' => $attributes]]);

        $options = $this->productOptionFactory->create(['data' => ['extension_attributes' => $ext]]);

        return $cartItem->setProductOption($options);
    }

    /**
     * @param array $args
     * @return CartItemInterface
     */
    private function createCartItem(array $args): CartItemInterface
    {
        $cartItem = $this->cartItemFactory->create([
            'data' => [
                CartItemInterface::KEY_SKU => $args[CartItemInterface::KEY_SKU],
                CartItemInterface::KEY_QTY => $args[CartItemInterface::KEY_QTY],
                CartItemInterface::KEY_QUOTE_ID => $args[CartItemInterface::KEY_QUOTE_ID],
            ]
        ]);

        if (array_key_exists(CartItemInterface::KEY_PRODUCT_TYPE, $args)) {
            $cartItem->setProductType($args[CartItemInterface::KEY_PRODUCT_TYPE]);
        }

        if (
            $args[CartItemInterface::KEY_PRODUCT_TYPE] === 'configurable'
            && array_key_exists(CartItemInterface::KEY_PRODUCT_OPTION, $args)
        ) {
            $cartItem = $this->createConfigurable(
                $cartItem,
                $args[CartItemInterface::KEY_PRODUCT_OPTION]
            );
        }

        return $cartItem;
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
            $cartItem = $this->getCartItem($requestCartItem);
            $this->cartItemRepository->save($cartItem);
        } else {
            $cartItem = $this->createCartItem($requestCartItem);
            $newCartItem = $this->cartItemRepository->save($cartItem);
            $this->cartItemRepository->save($newCartItem);
        }

        return [];
    }
}
