<?php

namespace Razorpay\Magento\Model;

use Razorpay\Api\Api;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Magento\Sales\Model\Order\Payment\Transaction as PaymentTransaction;
use Magento\Payment\Model\InfoInterface;
use Razorpay\Magento\Model\Config;
use Magento\Catalog\Model\Session;

/**
 * Class PaymentMethod
 * @package Razorpay\Magento\Model
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    const CHANNEL_NAME                  = 'Magento';
    const METHOD_CODE                   = 'razorpay';
    const CONFIG_MASKED_FIELDS          = 'masked_fields';
    const CURRENCY                      = 'INR';

    /**
     * @var string
     */
    protected $_code                    = self::METHOD_CODE;

    /**
     * @var bool
     */
    protected $_canAuthorize            = true;

    /**
     * @var bool
     */
    protected $_canCapture              = true; // sessions , set this to false

    /**
     * @var bool
     */
    protected $_canRefund               = true;

    /**
     * @var bool
     */
    protected $_canUseInternal          = true;

    /**
     * @var bool
     */
    protected $_canUseCheckout          = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var array|null
     */
    protected $requestMaskedFields      = null;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var TransactionCollectionFactory
     */
    protected $salesTransactionCollectionFactory;

    /**
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    protected $productMetaData;

    /**
     * @var \Magento\Directory\Model\RegionFactory
     */
    protected $regionFactory;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Framework\App\RequestInterface $request
     * @param TransactionCollectionFactory $salesTransactionCollectionFactory
     * @param \Magento\Framework\App\ProductMetadataInterface $productMetaData
     * @param \Magento\Directory\Model\RegionFactory $regionFactory
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Framework\App\RequestInterface $request,
        TransactionCollectionFactory $salesTransactionCollectionFactory,
        \Magento\Framework\App\ProductMetadataInterface $productMetaData,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Razorpay\Magento\Controller\Payment\Order $order,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->config = $config;
        $this->request = $request;
        $this->salesTransactionCollectionFactory = $salesTransactionCollectionFactory;
        $this->productMetaData = $productMetaData;
        $this->regionFactory = $regionFactory;
        $this->orderRepository = $orderRepository;

        $this->key_id = $this->config->getConfigData(Config::KEY_PUBLIC_KEY);
        $this->key_secret = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        $this->rzp = new Api($this->key_id, $this->key_secret);

        $this->order = $order;

        $this->rzp->setHeader('User-Agent', 'Razorpay/'. $this->getChannel());
    }

    /**
     * Validate data
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function validate()
    {
        $info = $this->getInfoInstance();
        if ($info instanceof \Magento\Sales\Model\Order\Payment) {
            $billingCountry = $info->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $info->getQuote()->getBillingAddress()->getCountryId();
        }

        if (!$this->config->canUseForCountry($billingCountry)) {
            throw new LocalizedException(__('Selected payment type is not allowed for billing country.'));
        }

        return $this;
    }

    /**
     * Authorizes specified amount
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * Captures specified amount
     *
     * @param InfoInterface $payment
     * @param string $amount
     * @return $this
     * @throws LocalizedException
     */
    public function capture(InfoInterface $payment, $amount)
    {
        try {
            /** @var \Magento\Sales\Model\Order\Payment $payment */
            $order = $payment->getOrder();
            $orderId = $order->getIncrementId();

            $request = $this->getPostData();

            $payment_id = $request['paymentMethod']['additional_data']['rzp_payment_id'];
            
            $success = $this->validateSignature($request);

            // if success of validate signature is true
            if ($success === true) {
                $payment->setStatus(self::STATUS_APPROVED)
                    ->setAmountPaid($amount)
                    ->setLastTransId($payment_id)
                    ->setTransactionId($payment_id)
                    ->setIsTransactionClosed(true)
                    ->setShouldCloseParentTransaction(true);
            } else {
                throw new LocalizedException($result['error']['description']);
            }
        } catch (\Exception $e) {
            $this->_logger->critical($e);
            throw new LocalizedException(__('There was an error capturing the transaction: %1.', $e->getMessage()));
        } catch(\Razorpay\Api\Errors\Error $e) {
            $this->_logger->critical($e);
            throw new LocalizedException(__('There was an error capturing the transaction: %1.', $e->getMessage()));
        }

        return $this;
    }

    protected function validateSignature($request)
    {
        $paymentId = $request['paymentMethod']['additional_data']['rzp_payment_id'];
        $rzpSignature = $request['paymentMethod']['additional_data']['rzp_signature'];

        $stringToHash = $this->order->getCatalogSession() . "|" . $paymentId;

        $keySecret = $this->config->getConfigData(Config::KEY_PRIVATE_KEY);

        $signature = hash_hmac('sha256', $stringToHash, $keySecret);
        
        $success = false;

        if ($this->hash_equals($signature , $rzpSignature))
        {
            $success = true;
        }
        
        return $success;
    }

    /*
     * Taken from https://stackoverflow.com/questions/10576827/secure-string-compare-function
     * under the MIT license
     */
    protected function hash_equals($generatedString, $actualString)
    {
        if (function_exists('hash_equals'))
        {
            return hash_equals($generatedString, $actualString);
        }

        if (strlen($generatedString) !== strlen($actualString))
        {
            return false;
        }

        $result = 0;

        for ($i=0; $i<strlen($generatedString); $i++)
        {
            $result |= ord($generatedString[$i]) ^ ord($actualString[$i]);
        }

        return ($result === 0);
    }

    protected function getPostData()
    {
        $request = file_get_contents('php://input');

        return json_decode($request, true);
    }

    /**
     * Refunds specified amount
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * Format param "channel" for transaction
     *
     * @return string
     */
    protected function getChannel()
    {
        $edition = $this->productMetaData->getEdition();
        $version = $this->productMetaData->getVersion();
        return self::CHANNEL_NAME . ' ' . $edition . ' ' . $version;
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|\Magento\Store\Model\Store $storeId
     *
     * @return mixed
     */
    public function getConfigData($field, $storeId = null)
    {
        if ('order_place_redirect_url' === $field) {
            return $this->getOrderPlaceRedirectUrl();
        }
        return $this->config->getConfigData($field, $storeId);
    }
}
