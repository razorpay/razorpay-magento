<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\OrderRepositoryInterface;

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

    /**
     * @param \Psr\Log\LoggerInterface $logger
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        OrderRepositoryInterface $orderRepository
    )
    {
        $this->logger = $logger;
        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->orderRepository = $orderRepository;
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
        catch (Exception $exception) 
        {
            $this->logger->error($exception->getMessage());
            
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

                $responseContent = [
                    'success'           => false,
                ];
            }
        }
        catch(\Exception $e)
        {
            $this->logger->critical('graphQL: Exception: ' . $e->getMessage());

            $responseContent = [
                'success'               => false,
            ];
        }

        return $responseContent;
    }
}
