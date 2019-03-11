<?php
namespace Razorpay\Magento\Ui\Component\Listing\Columns;
 
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
 
class Sendmail extends Column
{
    
    protected $urlBuilder;
 
    
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }
 
    
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            $fieldName = $this->getData('name');
            foreach ($dataSource['data']['items'] as & $item) { 
                $item[$fieldName . '_html'] = "<button class='button'><span>Verify Transaction</span></button>";
                $item[$fieldName . '_title'] = __('Please enter a message that you want to send to customer');
                $item[$fieldName . '_submitlabel'] = __('Send');
                $item[$fieldName . '_cancellabel'] = __('Reset');
                $item[$fieldName . '_customerid'] = $item['entity_id'];
 
                $item[$fieldName . '_formaction'] = $this->urlBuilder->getUrl('grid/customer/sendmail');
            }
        }
 
        return $dataSource;
    }
}
