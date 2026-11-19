<?php
// Minimal stand-ins for the Razorpay SDK error classes so the standalone test runs without composer.
namespace Razorpay\Api\Errors;

class Error extends \Exception
{
    protected $httpStatusCode;

    public function __construct($message, $code, $httpStatusCode)
    {
        $this->message = $message;
        $this->code = $code;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getHttpStatusCode()
    {
        return $this->httpStatusCode;
    }
}

class BadRequestError extends Error
{
}
