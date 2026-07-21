<?php
/**
 * Marker value for REST response bodies that must bypass JSON serialization.
 */
final class RawRestBody {
    /** @var string */
    private $bytes;

    public function __construct( $bytes ) {
        $this->bytes = (string) $bytes;
    }

    public function bytes() {
        return $this->bytes;
    }
}
