<?php

class Razorpay_Payments_Model_Paymentmethod extends Mage_Payment_Model_Method_Abstract
{
    const CHANNEL_NAME                  = 'Razorpay/Magento%s_%s/%s';
    const METHOD_CODE                   = 'razorpay';
    const CURRENCY                      = 'INR';
    const VERSION                       = '1.1.25';
    const KEY_ID                        = 'key_id';
    const KEY_SECRET                    = 'key_secret';

    protected $_code                    = self::METHOD_CODE;
    protected $_canOrder                = false;
    protected $_isInitializeNeeded      = false;
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = false;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = false;
    protected $_canVoid                 = false;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canRefundInvoicePartial = false;
    protected $_canUseForMultishipping  = false;

    protected $api;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();

        $this->requireAllRazorpayFiles();

        $keyId     = $this->getConfigData(self::KEY_ID);
        $keySecret = $this->getConfigData(self::KEY_SECRET);

        $this->api = new Razorpay\Api\Api($keyId, $keySecret);
    }

    /**
     * Check method for processing with base currency
     *
     * @param string $currencyCode
     * @return boolean
     */
    public function canUseForCurrency($currencyCode)
    {
        if ($currencyCode === 'INR')
        {
            return true;
        }

        return false;
    }

    /**
     * Authorizes specified amount
     *
     * @param Varien_Object $payment
     * @param decimal $amount
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        return $this;
    }

    public function getOrderPlaceRedirectUrl()
    {
        $url = Mage::getUrl('razorpay/checkout/index');

        return $url;
    }

    public function validateSignature($response)
    {
        $requestFields = Mage::app()->getRequest()->getPost();

        $paymentId = $requestFields['razorpay_payment_id'];        

        $session = Mage::getSingleton('checkout/session');
        $order = Mage::getModel('sales/order');
        $orderId = $session->getLastRealOrderId();
        $order->loadByIncrementId($orderId);

        if ((empty($orderId) === false) and 
            (isset($requestFields['razorpay_payment_id']) === true))
        {
            $attributes = array(
                'razorpay_payment_id' => $requestFields['razorpay_payment_id'],
                'razorpay_order_id'   => Mage::getSingleton('core/session')->getRazorpayOrderID(),
                'razorpay_signature'  => $requestFields['razorpay_signature']
            );

            $success = true;

            $errorMessage = 'Payment failed. Most probably user closed the popup.';

            try
            {
                $this->api->utility->verifyPaymentSignature($attributes);
            }
            catch (Razorpay\Api\Errors\SignatureVerificationError $e)
            {
                $success = false;

                $errorMessage = 'Payment to Razorpay Failed. ' .  $e->getMessage();
            }

            if ($success === true)
            {
                $order->sendNewOrderEmail();
                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true);
                $order->addStatusHistoryComment('Payment Successful. Razorpay Payment Id:'.$paymentId);
                $order->save();
            }
            else
            {
                $this->updateOrderFailed($order, $errorMessage);
            }
        }
        else
        {
            $success = false;

            $this->handleErrorCase($order, $orderId, $requestFields);
        }

        return $success;
    }

    protected function handleErrorCase($order, $orderId, $requestFields)
    {
        if (empty($orderId) === true)
        {
            $errorMessage = 'An error occurred while processing the order';
        }
        else if (isset($requestFields['error']) === true)
        {
            $error = $requestFields['error'];

            $errorMessage = 'An error occurred. Description : ' 
            . $error['description'] 
            . '. Code : ' . $error['code'];

            if (isset($error['field']) === true)
            {
                $errorMessage .= '. Field : ' . $error['field'];
            }
        }

        $this->updateOrderFailed($order, $errorMessage);
    }

    protected function updateOrderFailed($order, $errorMessage)
    {
        $order->setState(Mage_Sales_Model_Order::STATE_CANCELED, true);
        $order->addStatusHistoryComment($errorMessage);
        $order->save();
        $this->updateInventory($order);   
    }

    protected function updateInventory($order)
    {
        $stockItem = Mage::getModel('cataloginventory/stock_item');

        $items = $order->getAllItems();

        foreach ($items as $item)
        {
            $item->cancel();
        }
    }

    public function getFields($order)
    {
        $helper = Mage::helper('razorpay_payments');

        $responseArray = $helper->createOrder($order);

        $responseArray['key_id'] = $this->getConfigData('key_id');
        $responseArray['merchant_name'] = $this->getConfigData('merchant_name');
        $responseArray['failure_url'] = Mage::getUrl('razorpay/checkout/failure');

        return $responseArray;
    }

    /**
     * Format param "channel" for transaction
     *
     * @return string
     */
    public function _getChannel()
    {
        $edition = 'CE';
        if (Mage::getEdition() == Mage::EDITION_ENTERPRISE)
        {
            $edition = 'EE';
        }
        return sprintf(self::CHANNEL_NAME, $edition, Mage::getVersion(), self::VERSION);
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|Mage_Core_Model_Store $storeId
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if (null === $storeId)
        {
            if (Mage::app()->getStore()->isAdmin())
            {
                $storeId = Mage::getSingleton('adminhtml/session_quote')->getStoreId();
            }
            else
            {
                $storeId = $this->getStore();
            }
        }
        $path = 'payment/'.$this->getCode().'/'.$field;

        return Mage::getStoreConfig($path, $storeId);
    }

    /**
     * We need to use this method to load all the razorpay-php files
     */
    public function requireAllRazorpayFiles()
    {
        $baseDir = Mage::getBaseDir('lib') . DS . 'razorpay-php';

        $apiClassesBase = $baseDir . DS . 'src';
        $errorClassesBase = $baseDir . DS . 'src/Errors';
        $requestClassesBase = $baseDir . DS . 'libs/Requests-1.6.1/library/Requests';

        // Require requests class
        require_once $requestClassesBase . '.php';

        // Require all requests files first
        $this->recursiveReadDirectory($requestClassesBase);

        // Require Entity, Resource and ArrayableInterface first
        require_once $apiClassesBase . DS . 'Resource.php';
        require_once $apiClassesBase . DS . 'ArrayableInterface.php';
        require_once $apiClassesBase . DS . 'Entity.php';

        // Requiring base error class first
        require_once $errorClassesBase . DS . 'Error.php';

        // Require all src files first
        foreach (scandir($apiClassesBase) as $file)
        {
            if (strpos((string) $file, 'php') !== false)
            {
                require_once $apiClassesBase . DS . $file;
            }
        }

        // Requiring all Error files
        foreach (scandir($errorClassesBase) as $file)
        {
            if (strpos($file, 'php') !== false)
            {
                require_once $errorClassesBase . DS . $file;
            }
        }
    }

    protected function recursiveReadDirectory($path)
    {
        if ($handle = opendir($path))
        {
            $directories = array();

            while (false !== ($entry = readdir($handle)))
            {
                // Requiring all the root files
                if (strpos($entry, 'php') !== false)
                {
                    require_once $path . DS . $entry;
                }
                else if ((is_dir($path . DS . $entry)) and
                    (in_array($entry, ['.', '..']) === false))
                {
                    // Requiring directories after all the files
                    $newPath = $path . DS . $entry;
                    array_push($directories, $newPath);
                }
            }

            $directories = array_reverse($directories);

            foreach ($directories as $directory)
            {
                $this->recursiveReadDirectory($directory);
            }

            closedir($handle);
        }
    }
}
