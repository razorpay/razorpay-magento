<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$functions_class_file = dirname( __FILE__ ) . '/Includes/OrderController.php';

if ( ! file_exists( $functions_class_file ) ) {
    echo PHP_EOL . "Error : unable to find " . $functions_class_file . PHP_EOL;
    exit( '' . PHP_EOL );
}

require_once $functions_class_file;

class OrderControllerTest extends TestCase
{
    function get_instance()
    {
        $this->order = new OrderController;

        return $this->order;
    }

    public function testGeneratePasswordNotEmpty()
    {
        $this->get_instance();
     
        $password = $this->order->generatePassword();

        $this->assertNotEmpty($password);
    }

    public function testGeneratePasswordLength()
    {
        $this->get_instance();

        $password = $this->order->generatePassword();

        $this->assertGreaterThanOrEqual(12, strlen($password));

        $this->assertLessThanOrEqual(16, strlen($password));
    }

    public function testGeneratePasswordNotEqual()
    {
        $this->get_instance();

        $password_first  = $this->order->generatePassword();
        $password_second = $this->order->generatePassword();

        $this->assertNotEquals($password_first, $password_second);
    }
}
