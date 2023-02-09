<?php
namespace Razorpay\Magento\Test\Mockfactory;

class ControllerMockApi
{
	protected static $response_type = null;

	public function call($method, $request, $data = [])
	{
        $response_type = self::$response_type;
        $response = $this->loadData();
        return $response[$method][$request][$response_type];
	}

	function setResonseType($type)
	{
        self::$response_type = $type;
	}

	private function loadData()
	{
		return [
			'POST' => [
				'razorpay/payment/order' => [
					'execute_success' => 'Success Response',
					'execute_failed' => 'Failed Response',
				]
			]
		];
	}
}
