<?php
namespace Razorpay\Magento\Model\Total;

use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Sales\Model\Order;

class CustomCodFee extends AbstractTotal
{
    /**
     * Collect totals information about custom shipping & handling fee
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment
     * @param \Magento\Quote\Model\Quote\Address\Total $total
     * @return $this
     */
    public function collect(
        \Magento\Quote\Model\Quote $quote,
        \Magento\Quote\Api\Data\ShippingAssignmentInterface $shippingAssignment,
        \Magento\Quote\Model\Quote\Address\Total $total
    ) {
        parent::collect($quote, $shippingAssignment, $total);

        // Check if a custom fee exists and add it to the totals
        $customFee = $quote->getData('razorpay_cod_fee');
        if ($customFee) {
            $total->setTotalAmount('razorpay_cod_fee', $customFee);
            $total->setBaseTotalAmount('razorpay_cod_fee', $customFee);

            $total->setGrandTotal($total->getGrandTotal() + $customFee);
            $total->setBaseGrandTotal($total->getBaseGrandTotal() + $customFee);
        }

        return $this;
    }

    /**
     * Fetch the custom total data
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param \Magento\Quote\Model\Quote\Address\Total $total
     * @return array
     */
    public function fetch(\Magento\Quote\Model\Quote $quote, \Magento\Quote\Model\Quote\Address\Total $total)
    {
        $customFee = $quote->getData('razorpay_cod_fee');
        return [
            'code' => 'razorpay_cod_fee',
            'title' => __('Shipping & Handling Fee'),
            'value' => $customFee
        ];
    }
}
