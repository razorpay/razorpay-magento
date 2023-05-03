<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;

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
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $this->logger->info('graphQL: Reset Cart started');

        if (empty($args['quote_id']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "quote_id" is missing');

            throw new GraphQlInputException(__('Required parameter "quote_id" is missing'));
        }

        if (empty($args['order_id']))
        {
            $this->logger->critical('graphQL: Input Exception: Required parameter "order_id" is missing');

            throw new GraphQlInputException(__('Required parameter "order_id" is missing'));
        }
        
        $quote_id   = $args['quote_id'];
        $order_id  = $args['order_id'];

        try{
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

            $orderModel = $objectManager->get('Magento\Sales\Model\Order')->load($order_id);

            if ($orderModel->canCancel())
            {
                $quote = $objectManager->get('Magento\Quote\Model\Quote')->load($quote_id);
                
                //check if quote_id provided is valid
                if($quote->getId())
                {
                    $quote->setIsActive(true)->save();
                    
                    //not canceling order as cancled order can't be used again for order processing.
                    //$orderModel->cancel(); 
                    $orderModel->setStatus('canceled');
    
                    $orderModel->save();
                    
                    $this->logger->info('graphQL: Reset cart for Quote ID: ' . $quote_id . ' and ' . 'Order ID: ' . $order_id . 'completed.');

                    $responseContent = [
                        'success'           => true,
                    ];       
                }
                else
                {
                    $this->logger->critical('graphQL: Quote ID: ' . $quote_id . ' does not exist.');

                    $responseContent = [
                        'success'           => false,
                    ];
                }
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
