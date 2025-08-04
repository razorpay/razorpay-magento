<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;
use Razorpay\Magento\Model\TrackPluginInstrumentation;

/**
 * Mutation resolver for resetting cart
 */
class ResetCart implements ResolverInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

     /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    protected $objectManager;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var \Razorpay\Magento\Model\TrackPluginInstrumentation
     */
    protected $trackPluginInstrumentation;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository,
        TrackPluginInstrumentation $trackPluginInstrumentation
    )
    {
        $this->logger = $logger;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
        $this->trackPluginInstrumentation = $trackPluginInstrumentation;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $this->logger->info('graphQL: Reset Cart started');

        if (empty($args['order_id']) === true)
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "order_id" is missing');

            $properties = [
                "error_message" => "Required parameter 'order_id' is missing",
                "file_path" => "model/Resolver/ResetCart.php",
                "exception_type" => null,
                "notes" => "graphql: validation failed for order_id",
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.graphql.resetcart.validation.failed', $properties);

            throw new GraphQlInputException(__('Required parameter "order_id" is missing'));
        }
        
        $incrementId  = $args['order_id'];

        $order_id = null;
        try 
        {
            $searchCriteria = $this->searchCriteriaBuilder
                                    ->addFilter('increment_id', $incrementId)
                                    ->create();

            $orderData = $this->orderRepository->getList($searchCriteria)->getItems();

            foreach ($orderData as $order) 
            {
               $order_id = $order->getId();
            }
        } 
        catch (\Exception $exception) 
        {
            $this->logger->critical($exception->getMessage());

            $properties = [
                "error_message" => $exception->getMessage(),
                "file_path" => "model/Resolver/ResetCart.php",
                "exception_type" => get_class($exception),
                "notes" => "graphql: order not found for order_id: " . $incrementId,
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.graphql.resetcart.failed', $properties);
            
            return [
                'success'               => false,
            ];
        }

        try
        {
            $orderModel = $this->objectManager->get('Magento\Sales\Model\Order')->load($order_id);

            if ($orderModel->canCancel())
            {
                $quote_id = $orderModel->getQuoteId();
                
                $quote = $this->objectManager->get('Magento\Quote\Model\Quote')->load($quote_id);
                
                $quote->setIsActive(true)->save();
                
                //not canceling order as cancled order can't be used again for order processing.
                //$orderModel->cancel(); 
                $orderModel->setStatus('canceled');

                $orderModel->save();
                
                $this->logger->info('graphQL: Reset cart for Quote ID: ' . $quote_id . ' and ' . 'Order ID: ' . $order_id . ' completed.');

                $responseContent = [
                    'success'           => true,
                ];       
            }
            else
            {
                $this->logger->critical('graphQL: Order ID: ' . $order_id . ' cannot be canceled.');

                $properties = [
                    "error_message" => "Order ID: " . $order_id . " cannot be canceled.",
                    "file_path" => "model/Resolver/ResetCart.php",
                    "exception_type" => null,
                    "notes" => "graphql: order cannot be canceled",
                ];

                $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.graphql.resetcart.failed', $properties);

                $responseContent = [
                    'success'           => false,
                ];
            }
        }
        catch(\Exception $e)
        {
            $this->logger->critical('graphQL: Exception: ' . $e->getMessage());

            $properties = [
                "error_message" => $e->getMessage(),
                "file_path" => "model/Resolver/ResetCart.php",
                "exception_type" => get_class($e),
                "notes" => "graphql: reset cart failed",
            ];

            $this->trackPluginInstrumentation->rzpTrackDataLake('razorpay.std.graphql.resetcart.failed', $properties);

            $responseContent = [
                'success'               => false,
            ];
        }

        return $responseContent;
    }
}
