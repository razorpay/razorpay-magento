<?php
namespace Razorpay\Magento\Test\Mockfactory;

use Requests;
use Exception;

/**
 * Request class to communicate to the request libarary
 */

class Request
{
    public function request($method, $url, $data = array())
    {
        $key_id = MockApi::getKey();
        $response = $this->loadData();
        return $response[$key_id][$method][$url];
    }

    public function loadData()
    {
        return [
            'key_id' => [
                'GET' => [
                    'webhooks' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'LDATzQq2wsAAAA',
                                'url' => 'https://www.example-one.com/razorpay/payment/webhook',
                                'entity' => 'webhook',
                                'active' => true,
                                'events' => [
                                    'payment.authorized' => true,
                                    'order.paid' => true,
                                ]
                            ],
                        ]
                    ],
                    'webhooks?count=10&skip=0' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'LDATzQq2wsBBBB',
                                'url' => 'https://www.example-two.com/razorpay/payment/webhook',
                                'entity' => 'webhook',
                                'active' => true,
                                'events' => [
                                    'payment.authorized' => true,
                                    'order.paid' => true,
                                ]
                            ],
                        ]
                    ],
                    'preferences' => ['options'],
                    'orders' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'order_test',
                                'entity' => 'order',
                                'amount' => 0,
                                'amount_paid' => 0,
                                'amount_due' => 0,
                                'currency' => 'INR',
                                'receipt' => '11',
                                'offer_id' => null,
                                'status' => 'created',
                                'attempts' => 0,
                                'notes' => [
                                    'woocommerce_order_number' => '11'
                                ],
                                'created_at' => 1666097548
                            ]
                        ]
                    ]
                ],
                'POST' => [
                    'plugins/segment' => [
                        'status' => 'success'
                    ],
                    'orders' => [
                        'id' => 'razorpay_test_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'INR',
                    ],
                    'orders/id' => [
                        'id' => 'razorpay_test_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'INR',
                    ],
                    'webhooks/' => [
                        'id' => 'create',
                        'url' => 'https://www.example-one.com/razorpay/payment/webhook',
                        'entity' => 'webhook',
                        'active' => true,
                        'events' => [
                            'payment.authorized' => true,
                            'order.paid' => true
                        ]
                    ]
                ]
            ],
            'key_id_1' => [
                'GET' => [
                    'preferences' => [
                        'options' => [
                            'redirect' => true,
                            'image' => 'image.png'
                        ]
                    ],
                    'orders' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'order_test',
                                'entity' => 'order',
                                'amount' => 0,
                                'amount_paid' => 0,
                                'amount_due' => 0,
                                'currency' => 'INR',
                                'receipt' => '11',
                                'offer_id' => null,
                                'status' => 'created',
                                'attempts' => 0,
                                'notes' => [
                                    'woocommerce_order_number' => '11'
                                ],
                                'created_at' => 1666097548
                            ]
                        ]
                    ],
                    'webhooks?count=10&skip=0' => [
                        'entity' => 'collection',
                        'count' => 1,
                        'items' => [
                            [
                                'id' => 'update',
                                'url' => 'https://www.example-one.com/razorpay/payment/webhook',
                                'entity' => 'webhook',
                                'active' => true,
                                'events' => [
                                    'payment.authorized' => true,
                                    'order.paid' => true,
                                ]
                            ],
                        ]
                    ],
                ],
                'PUT' => [
                    'webhooks/update' => [
                        'id' => 'update',
                        'url' => 'https://www.example-two.com/razorpay/payment/webhook',
                        'entity' => 'webhook',
                        'active' => true,
                        'events' => [
                            'payment.authorized' => true,
                            'order.paid' => true
                        ],
                    ]
                ]
            ],
            'key_id_2' => [
                'GET' => [
                    'webhooks' =>
                        [
                            "entity" => "collection",
                            "count" => 1,
                            "items" => [
                                [
                                    "id" => "abcd",
                                    "url" => "https://www.example-two.com/razorpay/payment/webhook",
                                    "entity" => "webhook",
                                    "active" => true,
                                    "events" => [
                                        "payment.authorized" => false,
                                        "order.paid" => false,
                                    ]
                                ],
                            ]
                        ],
                    'preferences' => ['options'],
                    'orders' => [
                        "entity" => "collection",
                        "count" => 1,
                        "items" => [
                            [
                                "id" => "order_test",
                                "entity" => "order",
                                "amount" => 0,
                                "amount_paid" => 0,
                                "amount_due" => 0,
                                "currency" => "USD",
                                "receipt" => "11",
                                "offer_id" => null,
                                "status" => "created",
                                "attempts" => 0,
                                "notes" => [
                                    "woocommerce_order_number" => "11"
                                ],
                                "created_at" => 1666097548
                            ]
                        ]
                    ]
                ],
                'POST' => [
                    'plugins/segment' => [
                        'status' => 'tested'
                    ],
                    'orders' => [
                        'id' => 'razorpay_order_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'USD',
                        'receipt' => '16',
                    ],
                    'orders/id' => [
                        'id' => 'razorpay_order_id',
                        'entity' => 'order',
                        'amount' => 0,
                        'amount_paid' => 0,
                        'amount_due' => 0,
                        'currency' => 'USD',
                        'receipt' => '16',
                    ]
                ]
            ]
        ];
    }
}
