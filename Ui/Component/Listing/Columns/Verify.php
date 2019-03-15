<?php
namespace Razorpay\Magento\Ui\Component\Listing\Columns;

use Razorpay\Api\Api;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Sales\Model\Order\Payment\Transaction;
 
class Verify extends Column
{
    /**
     * @var UrlInterface
     */
    protected $urlBuilder;
 
    /**
     * Constructor
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        Transaction $transaction,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data, $transaction);
        $this->transaction = $transaction;
    }
 
    
    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            $fieldName = $this->getData('name');
            foreach ($dataSource['data']['items'] as & $item) { 
                $item[$fieldName . '_html'] = "<button class='button'><span>Verify</span></button>";
                $item[$fieldName . '_title'] = __('Please enter a message that you want to send to customer');
                $item[$fieldName . '_submitlabel'] = __('Send');
                $item[$fieldName . '_cancellabel'] = __('Reset');
                $item[$fieldName . '_customerid'] = $this->transaction->getTxnId();
 
                $item[$fieldName . '_formaction'] = $this->urlBuilder->getUrl('grid/sales/verify');
            }
        }
 
        return $dataSource;
    }
}
