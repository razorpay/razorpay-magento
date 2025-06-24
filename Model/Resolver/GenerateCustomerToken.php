<?php

namespace Razorpay\Magento\Model\Resolver;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\HTTP\PhpEnvironment\Request;
use Magento\Integration\Model\Oauth\TokenFactory;
use Razorpay\Magento\Model\Config as RazorpayConfig;

class GenerateCustomerToken implements ResolverInterface
{
    protected $customerRepository;
    protected $request;
    protected $tokenFactory;
    protected $razorpayConfig;

    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        Request $request,
        TokenFactory $tokenFactory,
        RazorpayConfig $razorpayConfig
    ) {
        $this->customerRepository = $customerRepository;
        $this->request = $request;
        $this->tokenFactory = $tokenFactory;
        $this->razorpayConfig = $razorpayConfig;
    }

    public function resolve(
        $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        // Step 1: Read access token from custom header
        $accessToken = $this->request->getHeader('X-Integration-Token');
        if (!$accessToken) {
            throw new GraphQlAuthorizationException(__('Missing integration token.'));
        }


        // Step 2: Load and validate token
        $token = $this->tokenFactory->create()->loadByToken(trim($accessToken));
        
        if (!$token || !$token->getId() || $token->getType() !== 'access' || $token->getData()['revoked'] == 1) {
            throw new GraphQlAuthorizationException(__('Invalid or unauthorized token.'));
        }

        // Step 4: IP Whitelist
        $requestIps = explode(',', $this->request->getClientIp());
        $allowedIps = $this->razorpayConfig->getAllowedIps();
        $isIpAllowed = false;

        foreach ($requestIps as $ip) {
            $ip = trim($ip);
            if (in_array($ip, $allowedIps)) {
                $isIpAllowed = true;
                break;
            }
        }

        if (!$isIpAllowed) {
            throw new GraphQlAuthorizationException(__('IP address not allowed.'));
        }

        // Step 5: Check Shared Secret
        $sharedSecret = $this->request->getHeader('X-Shared-Secret');

        if ($sharedSecret !== $token->getData()['secret']) { // 🔁 Replace with your secret
            throw new GraphQlAuthorizationException(__('Invalid shared secret.'));
        }

        // Step 6: Validate email input
        if (empty($args['email'])) {
            throw new GraphQlInputException(__('Email is required.'));
        }

        try {
            $customer = $this->customerRepository->get($args['email']);
            $customerId = $customer->getId();

            // Step 5: Generate token using TokenFactory (no password required)
            $token = $this->tokenFactory->create()->createCustomerToken($customerId)->getToken();

            return $token;
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            throw new GraphQlInputException(__('Customer not found.'));
        } catch (\Exception $e) {
            throw new GraphQlInputException(__($e->getMessage()));
        }
    }
}
