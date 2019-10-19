<?php
/**
 * Test that testing is in place.
 */
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PHPUnitTest extends TestCase
{
    public function testHasPhpUnitTestingInPlace(): void
    {
        $this->assertTrue(true);
        $this->assertInstanceOf(PHPUnitTest::class, $this);
        $this->assertInstanceOf(TestCase::class, $this);
    }
}
