<?php

// app/code/Vendor/Module/Model/Resolver/CouponList.php

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\RuleFactory;
use Magento\SalesRule\Model\Rule;

class CouponList implements ResolverInterface
{
    /**
     * @var RuleCollectionFactory
     */
    private $ruleCollectionFactory;

    private $ruleFactory;

    /**
     * @var Coupon
     */
    private $couponModel;

    public function __construct(
        RuleCollectionFactory $ruleCollectionFactory,
        Coupon $couponModel,
        RuleFactory $ruleFactory
    ) {
        $this->ruleCollectionFactory = $ruleCollectionFactory;
        $this->couponModel = $couponModel;
        $this->ruleFactory = $ruleFactory;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        $appliedCoupons = [];

        $ruleCollection = $this->ruleCollectionFactory->create();

        // Add filters to include only active and non-expired rules
        $ruleCollection->addFieldToFilter('is_active', 1);

        $ruleCollection->addFieldToFilter('to_date', [['gteq' => $currentDate], ['null' => true]]);

        $ruleCollection->addFieldToFilter('conditions_serialized', ['nlike' => '%"shipping_method"%']);

        $ruleCollection->addFieldToFilter('conditions_serialized', ['nlike' => '%"payment_method"%']);

        foreach ($ruleCollection as $rule) {
            $couponCollection = $this->couponModel->getCollection()
                ->addFieldToFilter('rule_id', $rule->getId());

            foreach ($couponCollection as $coupon) {
                $appliedCoupons[] = [
                    'title' => $this->getCouponCodeByRuleId($rule->getId()),
                    'discountAmount' => $this->calculateDiscountAmount($rule),
                    'description' => $rule->getDescription() ?: '',
                ];
            }
        }

        return $appliedCoupons;
    }

    /**
     * Calculate discount amount for a rule
     *
     * @param \Magento\SalesRule\Model\Rule $rule
     * @return float|null
     */
    private function calculateDiscountAmount($rule)
    {
        // You may need to adjust this calculation based on your specific rule configuration
        $discountAmount = $rule->getDiscountAmount();

        // Apply additional logic if needed
        // ...

        return $discountAmount;
    }

    /**
     * Fetch the coupon code by rule ID
     *
     * @param int $ruleId
     * @return string|null
     */
    public function getCouponCodeByRuleId($ruleId)
    {
        $couponCollection = $this->couponModel->getCollection()
            ->addFieldToFilter('rule_id', $ruleId);

        /** @var Coupon $coupon */
        $coupon = $couponCollection->getFirstItem();

        if ($coupon->getId()) {
            return $coupon->getCode();
        }

        return null;
    }
}