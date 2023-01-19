<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

class MagentoTest extends TestCase {
    public function testEmpty(): array
    {
        $stack = [];
        $this->assertEmpty($stack);

        return $stack;
    }
}