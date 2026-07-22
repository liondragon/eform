<?php
/**
 * Bounded ISO-BMFF inspection for HEIC/HEIF primary-image dimensions.
 *
 * UploadPolicy owns acceptance and dimension policy. This collaborator parses
 * only the primary item's associated ispe/clap/irot properties without
 * decoding pixels or requiring an image-processing extension.
 */

require_once __DIR__ . '/../Anchors.php';

final class HeifInspector {
    public static function inspect( $path ) {
        if ( ! is_string( $path ) || $path === '' || is_link( $path ) || ! is_file( $path ) ) {
            return null;
        }
        $length = @filesize( $path );
        if ( ! is_int( $length ) || $length < 16 || $length > Anchors::get( 'MANAGED_ARTIFACT_MAX_BYTES' ) ) {
            return null;
        }
        $handle = @fopen( $path, 'rb' );
        if ( $handle === false ) {
            return null;
        }

        try {
            $remaining_boxes = Anchors::get( 'MANAGED_HEIF_MAX_BOXES' );
            $offset = 0;
            $compatible = false;
            $meta = null;
            $media_ranges = array();
            while ( $offset < $length ) {
                $box = self::next_box( $handle, $offset, $length, $remaining_boxes );
                if ( ! is_array( $box ) ) {
                    return null;
                }
                if ( $box['type'] === 'ftyp' ) {
                    if ( $compatible || ! self::compatible_ftyp( $handle, $box ) ) {
                        return null;
                    }
                    $compatible = true;
                } elseif ( $box['type'] === 'meta' ) {
                    if ( $meta !== null ) {
                        return null;
                    }
                    $meta = $box;
                } elseif ( $box['type'] === 'mdat' ) {
                    if ( $box['data'] === $box['end'] ) {
                        return null;
                    }
                    $media_ranges[] = array( 'start' => $box['data'], 'end' => $box['end'] );
                } elseif ( in_array( $box['type'], array( 'moov', 'moof' ), true ) ) {
                    // Staged uploads accept one still primary image. Track or
                    // fragmented-track containers are image sequences even
                    // when they also carry otherwise valid item metadata.
                    return null;
                }
                $offset = $box['end'];
            }
            return $compatible && is_array( $meta ) && ! empty( $media_ranges )
                ? self::inspect_meta( $handle, $meta, $media_ranges, $remaining_boxes )
                : null;
        } finally {
            fclose( $handle );
        }
    }

    private static function compatible_ftyp( $handle, $box ) {
        $length = $box['end'] - $box['data'];
        $brand_limit = Anchors::get( 'MANAGED_HEIF_MAX_BOXES' );
        if ( $length < 8 || ( $length - 8 ) % 4 !== 0 || intdiv( $length - 8, 4 ) > $brand_limit ) {
            return false;
        }
        $data = self::read_exact( $handle, $box['data'], $length );
        if ( $data === null ) {
            return false;
        }
        $brands = array( substr( $data, 0, 4 ) );
        for ( $offset = 8; $offset < $length; $offset += 4 ) {
            $brands[] = substr( $data, $offset, 4 );
        }
        $sequence = array( 'hevc', 'hevx', 'hevm', 'hevs' );
        $allowed = array( 'heic', 'heix', 'heim', 'heis', 'mif1', 'miaf' );
        return empty( array_intersect( $brands, $sequence ) )
            && ! empty( array_intersect( $brands, $allowed ) );
    }

    private static function inspect_meta( $handle, $meta, $media_ranges, &$remaining_boxes ) {
        if ( $meta['end'] - $meta['data'] < 4 ) {
            return null;
        }
        $full = self::read_exact( $handle, $meta['data'], 4 );
        if ( $full === null || ord( $full[0] ) !== 0 ) {
            return null;
        }
        $offset = $meta['data'] + 4;
        $primary_id = null;
        $iinf = null;
        $iref = null;
        $idat = null;
        $iloc = null;
        $iprp = null;
        while ( $offset < $meta['end'] ) {
            $box = self::next_box( $handle, $offset, $meta['end'], $remaining_boxes );
            if ( ! is_array( $box ) ) {
                return null;
            }
            if ( $box['type'] === 'pitm' ) {
                if ( $primary_id !== null ) {
                    return null;
                }
                $primary_id = self::primary_item_id( $handle, $box );
                if ( $primary_id === null ) {
                    return null;
                }
            } elseif ( $box['type'] === 'iinf' ) {
                if ( $iinf !== null ) {
                    return null;
                }
                $iinf = $box;
            } elseif ( $box['type'] === 'iref' ) {
                if ( $iref !== null ) {
                    return null;
                }
                $iref = $box;
            } elseif ( $box['type'] === 'idat' ) {
                if ( $idat !== null || $box['data'] === $box['end'] ) {
                    return null;
                }
                $idat = $box;
            } elseif ( $box['type'] === 'iloc' ) {
                if ( $iloc !== null ) {
                    return null;
                }
                $iloc = $box;
            } elseif ( $box['type'] === 'iprp' ) {
                if ( $iprp !== null ) {
                    return null;
                }
                $iprp = $box;
            }
            $offset = $box['end'];
        }
        if ( $primary_id === null || ! is_array( $iinf ) || ! is_array( $iloc ) || ! is_array( $iprp ) ) {
            return null;
        }
        $item_types = self::image_item_types( $handle, $iinf, $remaining_boxes );
        $locations = self::item_locations( $handle, $iloc );
        $dimensions = self::inspect_properties( $handle, $iprp, $primary_id, $remaining_boxes );
        if ( ! is_array( $item_types )
            || ! isset( $item_types[ $primary_id ] )
            || ! is_array( $locations )
            || ! isset( $locations[ $primary_id ] )
            || ! is_array( $dimensions )
        ) {
            return null;
        }
        $item_type = $item_types[ $primary_id ];
        if ( in_array( $item_type, array( 'hvc1', 'hev1' ), true ) ) {
            return self::item_extents_in_ranges( $locations[ $primary_id ], 0, $media_ranges ) ? $dimensions : null;
        }
        return $item_type === 'grid'
            && is_array( $iref )
            && is_array( $idat )
            && self::valid_grid_primary(
                $handle,
                $primary_id,
                $item_types,
                $locations,
                $iref,
                $idat,
                $media_ranges,
                $dimensions,
                $remaining_boxes
            )
                ? $dimensions
                : null;
    }

    private static function primary_item_id( $handle, $box ) {
        $full = self::read_exact( $handle, $box['data'], 4 );
        if ( $full === null ) {
            return null;
        }
        $version = ord( $full[0] );
        if ( $version === 0 ) {
            return self::read_uint16( $handle, $box['data'] + 4, $box['end'] );
        }
        return $version === 1
            ? self::read_uint32( $handle, $box['data'] + 4, $box['end'] )
            : null;
    }

    private static function image_item_types( $handle, $iinf, &$remaining_boxes ) {
        if ( $iinf['end'] - $iinf['data'] < 6 ) {
            return null;
        }
        $full = self::read_exact( $handle, $iinf['data'], 4 );
        if ( $full === null ) {
            return null;
        }
        $version = ord( $full[0] );
        $offset = $iinf['data'] + 4;
        $count = $version === 0
            ? self::read_uint_width( $handle, $offset, $iinf['end'], 2 )
            : ( $version === 1 ? self::read_uint_width( $handle, $offset, $iinf['end'], 4 ) : null );
        if ( $count === null || $count < 1 || $count > Anchors::get( 'MANAGED_HEIF_MAX_BOXES' ) ) {
            return null;
        }
        $types = array();
        for ( $entry = 0; $entry < $count; $entry++ ) {
            $box = self::next_box( $handle, $offset, $iinf['end'], $remaining_boxes );
            if ( ! is_array( $box ) || $box['type'] !== 'infe' ) {
                return null;
            }
            $definition = self::image_item_definition( $handle, $box );
            if ( $definition === null ) {
                return null;
            }
            if ( isset( $types[ $definition['item_id'] ] ) ) {
                return null;
            }
            $types[ $definition['item_id'] ] = $definition['item_type'];
            $offset = $box['end'];
        }
        return $offset === $iinf['end'] ? $types : null;
    }

    private static function image_item_definition( $handle, $box ) {
        $full = self::read_exact( $handle, $box['data'], 4 );
        if ( $full === null ) {
            return null;
        }
        $version = ord( $full[0] );
        if ( $version === 2 ) {
            if ( $box['end'] - $box['data'] < 12 ) {
                return null;
            }
            $data = self::read_exact( $handle, $box['data'] + 4, 8 );
            return $data === null || self::uint16( substr( $data, 2, 2 ) ) !== 0 ? null : array(
                'item_id' => self::uint16( substr( $data, 0, 2 ) ),
                'item_type' => substr( $data, 4, 4 ),
            );
        }
        if ( $version === 3 ) {
            if ( $box['end'] - $box['data'] < 14 ) {
                return null;
            }
            $data = self::read_exact( $handle, $box['data'] + 4, 10 );
            return $data === null || self::uint16( substr( $data, 4, 2 ) ) !== 0 ? null : array(
                'item_id' => self::uint32( substr( $data, 0, 4 ) ),
                'item_type' => substr( $data, 6, 4 ),
            );
        }
        return null;
    }

    private static function item_locations( $handle, $iloc ) {
        if ( $iloc['end'] - $iloc['data'] < 8 ) {
            return false;
        }
        $header = self::read_exact( $handle, $iloc['data'], 6 );
        if ( $header === null ) {
            return false;
        }
        $version = ord( $header[0] );
        if ( $version > 2 ) {
            return false;
        }
        $offset_size = ord( $header[4] ) >> 4;
        $length_size = ord( $header[4] ) & 0x0f;
        $base_offset_size = ord( $header[5] ) >> 4;
        $index_size = $version === 0 ? 0 : ord( $header[5] ) & 0x0f;
        if ( $offset_size > 8 || $length_size < 1 || $length_size > 8 || $base_offset_size > 8 || $index_size > 8 ) {
            return null;
        }
        $offset = $iloc['data'] + 6;
        $item_count = self::read_uint_width( $handle, $offset, $iloc['end'], $version < 2 ? 2 : 4 );
        if ( $item_count === null || $item_count < 1 || $item_count > Anchors::get( 'MANAGED_HEIF_MAX_BOXES' ) ) {
            return null;
        }
        $remaining_extents = Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATIONS' );
        $locations = array();
        for ( $item = 0; $item < $item_count; $item++ ) {
            $item_id = self::read_uint_width( $handle, $offset, $iloc['end'], $version < 2 ? 2 : 4 );
            if ( $item_id === null || isset( $locations[ $item_id ] ) ) {
                return null;
            }
            $construction_method = 0;
            if ( $version > 0 ) {
                $method = self::read_uint_width( $handle, $offset, $iloc['end'], 2 );
                if ( $method === null ) {
                    return null;
                }
                $construction_method = $method & 0x0f;
            }
            $data_reference = self::read_uint_width( $handle, $offset, $iloc['end'], 2 );
            $base_offset = self::read_uint_width( $handle, $offset, $iloc['end'], $base_offset_size );
            $extent_count = self::read_uint_width( $handle, $offset, $iloc['end'], 2 );
            if ( $data_reference === null
                || $base_offset === null
                || $extent_count === null
                || $extent_count > $remaining_extents
            ) {
                return null;
            }
            $remaining_extents -= $extent_count;
            $extents = array();
            for ( $extent = 0; $extent < $extent_count; $extent++ ) {
                if ( $version > 0 && $index_size > 0 && self::read_uint_width( $handle, $offset, $iloc['end'], $index_size ) === null ) {
                    return null;
                }
                $extent_offset = self::read_uint_width( $handle, $offset, $iloc['end'], $offset_size );
                $extent_length = self::read_uint_width( $handle, $offset, $iloc['end'], $length_size );
                if ( $extent_offset === null || $extent_length === null ) {
                    return null;
                }
                $extents[] = array( 'offset' => $extent_offset, 'length' => $extent_length );
            }
            $locations[ $item_id ] = array(
                'construction_method' => $construction_method,
                'data_reference' => $data_reference,
                'base_offset' => $base_offset,
                'extents' => $extents,
            );
        }
        return $offset === $iloc['end'] ? $locations : null;
    }

    private static function item_extents_in_ranges( $location, $construction_method, $ranges ) {
        if ( ! is_array( $location )
            || $location['construction_method'] !== $construction_method
            || $location['data_reference'] !== 0
            || empty( $location['extents'] )
        ) {
            return false;
        }
        foreach ( $location['extents'] as $extent ) {
            if ( ! self::extent_is_media_backed(
                $location['base_offset'],
                $extent['offset'],
                $extent['length'],
                $ranges
            ) ) {
                return false;
            }
        }
        return true;
    }

    private static function valid_grid_primary( $handle, $primary_id, $item_types, $locations, $iref, $idat, $media_ranges, $dimensions, &$remaining_boxes ) {
        $idat_length = $idat['end'] - $idat['data'];
        $idat_ranges = array( array( 'start' => 0, 'end' => $idat_length ) );
        $location = $locations[ $primary_id ];
        if ( count( $location['extents'] ) !== 1 || ! self::item_extents_in_ranges( $location, 1, $idat_ranges ) ) {
            return false;
        }
        $extent = $location['extents'][0];
        if ( $location['base_offset'] > PHP_INT_MAX - $extent['offset'] ) {
            return false;
        }
        $descriptor = self::grid_descriptor(
            $handle,
            $idat['data'] + $location['base_offset'] + $extent['offset'],
            $extent['length']
        );
        if ( ! is_array( $descriptor )
            || $descriptor['width'] !== $dimensions['coded_width']
            || $descriptor['height'] !== $dimensions['coded_height']
        ) {
            return false;
        }
        $references = self::grid_references( $handle, $iref, $primary_id, $remaining_boxes );
        if ( ! is_array( $references ) || count( $references ) !== $descriptor['tiles'] ) {
            return false;
        }
        $seen = array();
        foreach ( $references as $item_id ) {
            if ( $item_id === $primary_id
                || isset( $seen[ $item_id ] )
                || ! isset( $item_types[ $item_id ], $locations[ $item_id ] )
                || ! in_array( $item_types[ $item_id ], array( 'hvc1', 'hev1' ), true )
                || ! self::item_extents_in_ranges( $locations[ $item_id ], 0, $media_ranges )
            ) {
                return false;
            }
            $seen[ $item_id ] = true;
        }
        return true;
    }

    private static function grid_descriptor( $handle, $offset, $length ) {
        if ( ! in_array( $length, array( 8, 12 ), true ) ) {
            return null;
        }
        $data = self::read_exact( $handle, $offset, $length );
        if ( $data === null || ord( $data[0] ) !== 0 || ( ord( $data[1] ) & 0xfe ) !== 0 ) {
            return null;
        }
        $wide = ( ord( $data[1] ) & 1 ) !== 0;
        if ( $length !== ( $wide ? 12 : 8 ) ) {
            return null;
        }
        $rows = ord( $data[2] ) + 1;
        $columns = ord( $data[3] ) + 1;
        $tiles = $rows * $columns;
        if ( $tiles < 1 || $tiles > Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATIONS' ) ) {
            return null;
        }
        $width = $wide ? self::uint32( substr( $data, 4, 4 ) ) : self::uint16( substr( $data, 4, 2 ) );
        $height = $wide ? self::uint32( substr( $data, 8, 4 ) ) : self::uint16( substr( $data, 6, 2 ) );
        return $width > 0 && $height > 0
            ? array( 'width' => $width, 'height' => $height, 'tiles' => $tiles )
            : null;
    }

    private static function grid_references( $handle, $iref, $primary_id, &$remaining_boxes ) {
        if ( $iref['end'] - $iref['data'] < 4 ) {
            return null;
        }
        $full = self::read_exact( $handle, $iref['data'], 4 );
        if ( $full === null || ( ord( $full[0] ) !== 0 && ord( $full[0] ) !== 1 ) ) {
            return null;
        }
        $version = ord( $full[0] );
        $offset = $iref['data'] + 4;
        $found = null;
        while ( $offset < $iref['end'] ) {
            $box = self::next_box( $handle, $offset, $iref['end'], $remaining_boxes );
            if ( ! is_array( $box ) ) {
                return null;
            }
            if ( $box['type'] === 'dimg' ) {
                $references = self::dimg_references( $handle, $box, $primary_id, $version );
                if ( $references === false || ( is_array( $references ) && $found !== null ) ) {
                    return null;
                }
                if ( is_array( $references ) ) {
                    $found = $references;
                }
            }
            $offset = $box['end'];
        }
        return $offset === $iref['end'] ? $found : null;
    }

    private static function dimg_references( $handle, $box, $primary_id, $version ) {
        $offset = $box['data'];
        $item_id = self::read_uint_width( $handle, $offset, $box['end'], $version === 0 ? 2 : 4 );
        $count = self::read_uint_width( $handle, $offset, $box['end'], 2 );
        if ( $item_id === null || $count === null || $count > Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATIONS' ) ) {
            return false;
        }
        $bytes_per_id = $version === 0 ? 2 : 4;
        if ( $count > intdiv( $box['end'] - $offset, $bytes_per_id ) ) {
            return false;
        }
        if ( $item_id !== $primary_id ) {
            return null;
        }
        $references = array();
        for ( $index = 0; $index < $count; $index++ ) {
            $reference = self::read_uint_width( $handle, $offset, $box['end'], $bytes_per_id );
            if ( $reference === null ) {
                return false;
            }
            $references[] = $reference;
        }
        return $offset === $box['end'] ? $references : false;
    }

    private static function extent_is_media_backed( $base_offset, $extent_offset, $extent_length, $media_ranges ) {
        if ( $extent_length < 1
            || $base_offset > PHP_INT_MAX - $extent_offset
            || $base_offset + $extent_offset > PHP_INT_MAX - $extent_length
        ) {
            return false;
        }
        $start = $base_offset + $extent_offset;
        $end = $start + $extent_length;
        foreach ( $media_ranges as $range ) {
            if ( $start >= $range['start'] && $end <= $range['end'] ) {
                return true;
            }
        }
        return false;
    }

    private static function inspect_properties( $handle, $iprp, $primary_id, &$remaining_boxes ) {
        $offset = $iprp['data'];
        $ipco = null;
        $ipma_boxes = array();
        while ( $offset < $iprp['end'] ) {
            $box = self::next_box( $handle, $offset, $iprp['end'], $remaining_boxes );
            if ( ! is_array( $box ) ) {
                return null;
            }
            if ( $box['type'] === 'ipco' ) {
                if ( $ipco !== null ) {
                    return null;
                }
                $ipco = $box;
            } elseif ( $box['type'] === 'ipma' ) {
                $ipma_boxes[] = $box;
            }
            $offset = $box['end'];
        }
        if ( $ipco === null || empty( $ipma_boxes ) ) {
            return null;
        }

        $properties = self::property_table( $handle, $ipco, $remaining_boxes );
        if ( ! is_array( $properties ) ) {
            return null;
        }
        $associations = null;
        $remaining_entries = Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATION_ENTRIES' );
        $remaining_associations = Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATIONS' );
        foreach ( $ipma_boxes as $ipma ) {
            $one = self::primary_associations(
                $handle,
                $ipma,
                $primary_id,
                $properties['count'],
                $remaining_entries,
                $remaining_associations
            );
            if ( $one === false || ( is_array( $one ) && $associations !== null ) ) {
                return null;
            }
            if ( is_array( $one ) ) {
                $associations = $one;
            }
        }
        if ( ! is_array( $associations ) ) {
            return null;
        }

        $ispe = null;
        $clap = null;
        $rotation = 0;
        $has_rotation = false;
        foreach ( $associations as $index ) {
            if ( ! isset( $properties['recognized'][ $index ] ) ) {
                continue;
            }
            $property = $properties['recognized'][ $index ];
            if ( $property['type'] === 'ispe' ) {
                if ( $ispe !== null ) {
                    return null;
                }
                $ispe = $property;
            } elseif ( $property['type'] === 'clap' ) {
                if ( $clap !== null ) {
                    return null;
                }
                $clap = $property;
            } elseif ( $property['type'] === 'irot' ) {
                if ( $has_rotation ) {
                    return null;
                }
                $has_rotation = true;
                $rotation = $property['rotation'];
            }
        }
        if ( $ispe === null ) {
            return null;
        }

        $width = $ispe['width'];
        $height = $ispe['height'];
        if ( $clap !== null ) {
            if ( $clap['width'] > $width || $clap['height'] > $height ) {
                return null;
            }
            $width = $clap['width'];
            $height = $clap['height'];
        }
        if ( $rotation % 2 === 1 ) {
            $swap = $width;
            $width = $height;
            $height = $swap;
        }
        return array(
            'width' => $width,
            'height' => $height,
            'coded_width' => $ispe['width'],
            'coded_height' => $ispe['height'],
        );
    }

    private static function property_table( $handle, $ipco, &$remaining_boxes ) {
        $offset = $ipco['data'];
        $index = 0;
        $recognized = array();
        while ( $offset < $ipco['end'] ) {
            $box = self::next_box( $handle, $offset, $ipco['end'], $remaining_boxes );
            if ( ! is_array( $box ) ) {
                return null;
            }
            $index++;
            if ( $box['type'] === 'ispe' ) {
                if ( $box['end'] - $box['data'] < 12 ) {
                    return null;
                }
                $data = self::read_exact( $handle, $box['data'], 12 );
                if ( $data === null || ord( $data[0] ) !== 0 ) {
                    return null;
                }
                $width = self::uint32( substr( $data, 4, 4 ) );
                $height = self::uint32( substr( $data, 8, 4 ) );
                if ( $width < 1 || $height < 1 ) {
                    return null;
                }
                $recognized[ $index ] = array( 'type' => 'ispe', 'width' => $width, 'height' => $height );
            } elseif ( $box['type'] === 'clap' ) {
                if ( $box['end'] - $box['data'] < 32 ) {
                    return null;
                }
                $data = self::read_exact( $handle, $box['data'], 16 );
                if ( $data === null ) {
                    return null;
                }
                $width = self::positive_rational_ceiling( substr( $data, 0, 8 ) );
                $height = self::positive_rational_ceiling( substr( $data, 8, 8 ) );
                if ( $width === null || $height === null ) {
                    return null;
                }
                $recognized[ $index ] = array( 'type' => 'clap', 'width' => $width, 'height' => $height );
            } elseif ( $box['type'] === 'irot' ) {
                if ( $box['end'] - $box['data'] < 1 ) {
                    return null;
                }
                $data = self::read_exact( $handle, $box['data'], 1 );
                if ( $data === null || ( ord( $data[0] ) & 0xfc ) !== 0 ) {
                    return null;
                }
                $recognized[ $index ] = array( 'type' => 'irot', 'rotation' => ord( $data[0] ) & 0x03 );
            }
            $offset = $box['end'];
        }
        return array( 'count' => $index, 'recognized' => $recognized );
    }

    private static function primary_associations( $handle, $box, $primary_id, $property_count, &$remaining_entries, &$remaining_associations ) {
        if ( $box['end'] - $box['data'] < 8 ) {
            return false;
        }
        $full = self::read_exact( $handle, $box['data'], 8 );
        if ( $full === null ) {
            return false;
        }
        $version = ord( $full[0] );
        if ( $version > 1 ) {
            return false;
        }
        $flags = ( ord( $full[1] ) << 16 ) | ( ord( $full[2] ) << 8 ) | ord( $full[3] );
        $wide = ( $flags & 1 ) !== 0;
        $entries = self::uint32( substr( $full, 4, 4 ) );
        $offset = $box['data'] + 8;
        $minimum = ( $version === 0 ? 2 : 4 ) + 1;
        if ( ! is_int( $remaining_entries )
            || $remaining_entries < 0
            || $entries > $remaining_entries
            || $entries > intdiv( $box['end'] - $offset, $minimum )
        ) {
            return false;
        }
        $remaining_entries -= $entries;
        $found = null;
        for ( $entry = 0; $entry < $entries; $entry++ ) {
            $item_id = $version === 0
                ? self::read_uint16( $handle, $offset, $box['end'] )
                : self::read_uint32( $handle, $offset, $box['end'] );
            if ( $item_id === null ) {
                return false;
            }
            $offset += $version === 0 ? 2 : 4;
            if ( $box['end'] - $offset < 1 ) {
                return false;
            }
            $count_data = self::read_exact( $handle, $offset, 1 );
            if ( $count_data === null ) {
                return false;
            }
            $association_count = ord( $count_data[0] );
            $offset++;
            $entry_bytes = $wide ? 2 : 1;
            if ( ! is_int( $remaining_associations )
                || $remaining_associations < 0
                || $association_count > $remaining_associations
                || $association_count > intdiv( $box['end'] - $offset, $entry_bytes )
            ) {
                return false;
            }
            $remaining_associations -= $association_count;
            $one = array();
            for ( $association = 0; $association < $association_count; $association++ ) {
                $data = self::read_exact( $handle, $offset, $entry_bytes );
                if ( $data === null ) {
                    return false;
                }
                $value = $wide ? self::uint16( $data ) : ord( $data[0] );
                $index = $value & ( $wide ? 0x7fff : 0x7f );
                if ( $index > $property_count ) {
                    return false;
                }
                if ( $index > 0 ) {
                    $one[] = $index;
                }
                $offset += $entry_bytes;
            }
            if ( $item_id === $primary_id ) {
                if ( $found !== null ) {
                    return false;
                }
                $found = $one;
            }
        }
        return $offset === $box['end'] ? $found : false;
    }

    private static function next_box( $handle, $offset, $end, &$remaining_boxes ) {
        if ( ! is_int( $remaining_boxes ) || $remaining_boxes < 1 || $offset < 0 || $end - $offset < 8 ) {
            return false;
        }
        $header = self::read_exact( $handle, $offset, 8 );
        if ( $header === null ) {
            return false;
        }
        $remaining_boxes--;
        $size = self::uint32( substr( $header, 0, 4 ) );
        $header_bytes = 8;
        if ( $size === 1 ) {
            $extended = self::read_exact( $handle, $offset + 8, 8 );
            if ( $extended === null || self::uint32( substr( $extended, 0, 4 ) ) !== 0 ) {
                return false;
            }
            $size = self::uint32( substr( $extended, 4, 4 ) );
            $header_bytes = 16;
        } elseif ( $size === 0 ) {
            $size = $end - $offset;
        }
        if ( $size < $header_bytes || $size > $end - $offset ) {
            return false;
        }
        return array(
            'type' => substr( $header, 4, 4 ),
            'data' => $offset + $header_bytes,
            'end' => $offset + $size,
        );
    }

    private static function positive_rational_ceiling( $data ) {
        if ( ! is_string( $data ) || strlen( $data ) !== 8 ) {
            return null;
        }
        $numerator = self::uint32( substr( $data, 0, 4 ) );
        $denominator = self::uint32( substr( $data, 4, 4 ) );
        if ( $numerator < 1 || $denominator < 1 ) {
            return null;
        }
        return intdiv( $numerator - 1, $denominator ) + 1;
    }

    private static function read_uint_width( $handle, &$offset, $end, $width ) {
        if ( ! is_int( $width ) || $width < 0 || $width > 8 || $end - $offset < $width ) {
            return null;
        }
        $data = self::read_exact( $handle, $offset, $width );
        if ( $data === null ) {
            return null;
        }
        $offset += $width;
        $value = 0;
        for ( $index = 0; $index < $width; $index++ ) {
            $byte = ord( $data[ $index ] );
            if ( $value > intdiv( PHP_INT_MAX - $byte, 256 ) ) {
                return null;
            }
            $value = ( $value * 256 ) + $byte;
        }
        return $value;
    }

    private static function read_uint16( $handle, $offset, $end ) {
        if ( $end - $offset < 2 ) {
            return null;
        }
        $data = self::read_exact( $handle, $offset, 2 );
        return $data === null ? null : self::uint16( $data );
    }

    private static function read_uint32( $handle, $offset, $end ) {
        if ( $end - $offset < 4 ) {
            return null;
        }
        $data = self::read_exact( $handle, $offset, 4 );
        return $data === null ? null : self::uint32( $data );
    }

    private static function read_exact( $handle, $offset, $length ) {
        if ( ! is_resource( $handle ) || ! is_int( $offset ) || ! is_int( $length ) || $offset < 0 || $length < 0 || @fseek( $handle, $offset, SEEK_SET ) !== 0 ) {
            return null;
        }
        $data = $length === 0 ? '' : @fread( $handle, $length );
        return is_string( $data ) && strlen( $data ) === $length ? $data : null;
    }

    private static function uint16( $data ) {
        $value = unpack( 'nvalue', $data );
        return isset( $value['value'] ) ? (int) $value['value'] : 0;
    }

    private static function uint32( $data ) {
        $value = unpack( 'Nvalue', $data );
        return isset( $value['value'] ) ? (int) $value['value'] : 0;
    }
}
