<?php
use PHPUnit\Framework\TestCase;

class TemplateValidatorTest extends TestCase {
    public function test_unknown_key_triggers_error() {
        $validator = new TemplateValidator();
        $config = [ 'fields' => [ [ 'key' => 'name', 'type' => 'text', 'bogus' => true ] ] ];
        $result = $validator->validate( $config );
        $this->assertFalse( $result['valid'] );
        $this->assertSame( TemplateValidator::ERR_UNKNOWN_KEY, $result['code'] );
    }

    public function test_invalid_enum_triggers_error() {
        $validator = new TemplateValidator();
        $config = [ 'fields' => [ [ 'key' => 'name', 'type' => 'bogus' ] ] ];
        $result = $validator->validate( $config );
        $this->assertFalse( $result['valid'] );
        $this->assertSame( TemplateValidator::ERR_ENUM, $result['code'] );
    }

    public function test_required_combo_triggers_error() {
        $validator = new TemplateValidator();
        $config = [ 'fields' => [ [ 'type' => 'row_group', 'mode' => 'start' ] ] ];
        $result = $validator->validate( $config );
        $this->assertFalse( $result['valid'] );
        $this->assertSame( TemplateValidator::ERR_REQUIRED_COMBO, $result['code'] );
    }

    public function test_row_group_shape_triggers_error() {
        $validator = new TemplateValidator();
        $config = [ 'fields' => [ [ 'type' => 'row_group', 'mode' => 'start', 'tag' => 'div' ] ] ];
        $result = $validator->validate( $config );
        $this->assertFalse( $result['valid'] );
        $this->assertSame( TemplateValidator::ERR_ROW_GROUP_SHAPE, $result['code'] );
    }

    public function test_accept_intersection_triggers_error() {
        $validator = new TemplateValidator();
        $config = [ 'fields' => [ [ 'key' => 'upload', 'type' => 'file', 'accept' => [ 'evil/type' ] ] ] ];
        $result = $validator->validate( $config );
        $this->assertFalse( $result['valid'] );
        $this->assertSame( TemplateValidator::ERR_ACCEPT_INTERSECTION, $result['code'] );
    }
}
