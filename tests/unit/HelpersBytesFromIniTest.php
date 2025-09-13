<?php
use EForms\Helpers;

class HelpersBytesFromIniTest extends BaseTestCase
{
    public function testConversions(): void
    {
        $this->assertEquals(PHP_INT_MAX, Helpers::bytes_from_ini('0'));
        $this->assertEquals(131072, Helpers::bytes_from_ini('128K'));
        $this->assertEquals(2097152, Helpers::bytes_from_ini('2M'));
        $this->assertEquals(1610612736, Helpers::bytes_from_ini('1.5G'));
        $this->assertEquals(0, Helpers::bytes_from_ini('junk'));
    }
}
