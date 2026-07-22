<?php
/**
 * Integration tests for bounded authoritative-artifact image validation.
 *
 * Contract: Managed staged upload protocol
 * Contract: Uploads accept-token policy
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadPolicy.php';

$tmp_dir = eforms_test_tmp_root( 'eforms-staged-images' );
mkdir( $tmp_dir, 0700, true );

function eforms_test_png_chunk( $type, $data ) {
    return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
}

function eforms_test_animated_png( $png ) {
    $ihdr = substr( $png, 16, 13 );
    $width_height = substr( $ihdr, 0, 8 );
    $animation = eforms_test_png_chunk( 'acTL', pack( 'NN', 2, 0 ) );
    $first_frame = eforms_test_png_chunk( 'fcTL', pack( 'N', 0 ) . $width_height . pack( 'NNnnCC', 0, 0, 1, 10, 0, 0 ) );

    $offset = 8;
    $idat = '';
    $iend_offset = null;
    while ( $offset + 12 <= strlen( $png ) ) {
        $length = unpack( 'Nlength', substr( $png, $offset, 4 ) )['length'];
        $type = substr( $png, $offset + 4, 4 );
        if ( $type === 'IDAT' ) {
            $idat .= substr( $png, $offset + 8, $length );
        }
        if ( $type === 'IEND' ) {
            $iend_offset = $offset;
            break;
        }
        $offset += 12 + $length;
    }
    if ( $iend_offset === null || $idat === '' ) {
        return '';
    }

    $second_frame = eforms_test_png_chunk( 'fcTL', pack( 'N', 1 ) . $width_height . pack( 'NNnnCC', 0, 0, 1, 10, 0, 0 ) );
    $second_data = eforms_test_png_chunk( 'fdAT', pack( 'N', 2 ) . $idat );
    return substr( $png, 0, 33 ) . $animation . $first_frame . substr( $png, 33, $iend_offset - 33 ) . $second_frame . $second_data . substr( $png, $iend_offset );
}

function eforms_test_bmff_box( $type, $data ) {
    return pack( 'N', 8 + strlen( $data ) ) . $type . $data;
}

function eforms_test_heif_with_ipma( $ipma_entries ) {
    $ftyp = eforms_test_bmff_box( 'ftyp', 'heic' . pack( 'N', 0 ) . 'mif1' );
    $pitm = eforms_test_bmff_box( 'pitm', "\0\0\0\0" . pack( 'n', 65535 ) );
    $ispe = eforms_test_bmff_box( 'ispe', "\0\0\0\0" . pack( 'NN', 120, 60 ) );
    $ipco = eforms_test_bmff_box( 'ipco', $ispe );
    $ipma = eforms_test_bmff_box( 'ipma', "\0\0\0\0" . pack( 'N', count( $ipma_entries ) ) . implode( '', $ipma_entries ) );
    $iprp = eforms_test_bmff_box( 'iprp', $ipco . $ipma );
    return $ftyp . eforms_test_bmff_box( 'meta', "\0\0\0\0" . $pitm . $iprp );
}

function eforms_test_tiled_heif( $referenced_tiles = array( 1, 2 ), $definition_version = 2, $primary_protection_index = 0 ) {
    $ftyp = eforms_test_bmff_box( 'ftyp', 'heic' . pack( 'N', 0 ) . 'mif1' );
    $pitm = eforms_test_bmff_box( 'pitm', "\0\0\0\0" . pack( 'n', 3 ) );
    $definitions = '';
    foreach ( array( 1 => 'hvc1', 2 => 'hvc1', 3 => 'grid' ) as $item_id => $item_type ) {
        $protection_index = $item_id === 3 ? $primary_protection_index : 0;
        $definition = $definition_version === 3
            ? "\3\0\0\0" . pack( 'Nn', $item_id, $protection_index ) . $item_type
            : "\2\0\0\0" . pack( 'nn', $item_id, $protection_index ) . $item_type;
        $definitions .= eforms_test_bmff_box( 'infe', $definition );
    }
    $iinf = eforms_test_bmff_box( 'iinf', "\0\0\0\0" . pack( 'n', 3 ) . $definitions );
    $dimg = pack( 'nn', 3, count( $referenced_tiles ) );
    foreach ( $referenced_tiles as $tile_id ) {
        $dimg .= pack( 'n', $tile_id );
    }
    $iref = eforms_test_bmff_box( 'iref', "\0\0\0\0" . eforms_test_bmff_box( 'dimg', $dimg ) );
    $ispe = eforms_test_bmff_box( 'ispe', "\0\0\0\0" . pack( 'NN', 4, 2 ) );
    $ipco = eforms_test_bmff_box( 'ipco', $ispe );
    $ipma = eforms_test_bmff_box( 'ipma', "\0\0\0\0" . pack( 'NnCC', 1, 3, 1, 1 ) );
    $iprp = eforms_test_bmff_box( 'iprp', $ipco . $ipma );
    $idat = eforms_test_bmff_box( 'idat', "\0\0\0\1\0\4\0\2" );
    $iloc_for = function ( $first_tile_offset ) {
        $items = pack( 'nnnnNN', 1, 0, 0, 1, $first_tile_offset, 1 )
            . pack( 'nnnnNN', 2, 0, 0, 1, $first_tile_offset + 1, 1 )
            . pack( 'nnnnNN', 3, 1, 0, 1, 0, 8 );
        return eforms_test_bmff_box( 'iloc', "\1\0\0\0\104\0" . pack( 'n', 3 ) . $items );
    };
    $iloc = $iloc_for( 0 );
    $meta = eforms_test_bmff_box( 'meta', "\0\0\0\0" . $pitm . $iinf . $iref . $iprp . $idat . $iloc );
    $media_offset = strlen( $ftyp ) + strlen( $meta ) + 8;
    $iloc = $iloc_for( $media_offset );
    $meta = eforms_test_bmff_box( 'meta', "\0\0\0\0" . $pitm . $iinf . $iref . $iprp . $idat . $iloc );
    return $ftyp . $meta . eforms_test_bmff_box( 'mdat', "\1\2" );
}

$fixtures = array(
    'jpeg' => array(
        'name' => 'camera.jpg',
        'mime' => 'image/jpeg',
        'bytes' => eforms_test_fixture_bytes( 'oriented-landscape.jpg' ),
    ),
    'png' => array(
        'name' => 'alpha.png',
        'mime' => 'image/png',
        'bytes' => eforms_test_fixture_bytes( 'staged-landscape.png' ),
    ),
    'webp' => array(
        'name' => 'browser.webp',
        'mime' => 'image/webp',
        'bytes' => base64_decode( 'UklGRlwAAABXRUJQVlA4IFAAAADQBACdASp4ADwAPpFIoUylpCMiIQgAsBIJaQDWIoAACLUSrzW19OnTp06dOnTfAAD+6zb/+1MEkZAH/0KNlEn3UZOi2BEtUYcQLgQAAAAAAA==' ),
    ),
    'wide-gamut' => array(
        'name' => 'wide-gamut.jpg',
        'mime' => 'image/jpeg',
        'bytes' => eforms_test_fixture_bytes( 'staged-wide-gamut.jpg' ),
    ),
);

$field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 10 * 1024 * 1024,
);
$oriented_source = eforms_test_write_file( $tmp_dir, 'oriented-inspection.jpg', $fixtures['jpeg']['bytes'] );
$oriented_inspection = UploadPolicy::inspect_staged_artifact( $oriented_source, 'oriented-inspection.jpg', $field );
eforms_test_assert(
    ! empty( $oriented_inspection['ok'] )
        && $oriented_inspection['width'] === 60
        && $oriented_inspection['height'] === 120,
    'Authoritative JPEG facts should report display dimensions after EXIF orientation.'
);
unlink( $oriented_source );

foreach ( $fixtures as $key => $fixture ) {
    $source = eforms_test_write_file( $tmp_dir, $fixture['name'], $fixture['bytes'] );
    $validated = UploadPolicy::validate_item(
        array(
            'tmp_name' => $source,
            'original_name' => '../' . $fixture['name'],
            'size' => 1,
            'error' => UPLOAD_ERR_OK,
        ),
        $field
    );
    eforms_test_assert( ! empty( $validated['ok'] ), strtoupper( $key ) . ' should pass authoritative-artifact inspection.' );
    eforms_test_assert( $validated['bytes'] === filesize( $source ), 'Inspection should own the actual source byte count.' );
    eforms_test_assert( $validated['mime'] === $fixture['mime'], 'Inspection should use fileinfo MIME rather than browser hints.' );
    eforms_test_assert( is_file( $source ), 'Inspection must retain the authoritative source for storage.' );
}

$apng_path = eforms_test_write_file( $tmp_dir, 'animated.png', eforms_test_animated_png( $fixtures['png']['bytes'] ) );
$apng = UploadPolicy::validate_item(
    array( 'tmp_name' => $apng_path, 'original_name' => 'animated.png', 'size' => filesize( $apng_path ), 'error' => UPLOAD_ERR_OK ),
    $field
);
eforms_test_assert( $apng['ok'] === false && $apng['code'] === 'EFORMS_ERR_UPLOAD_TYPE', 'Staged validation should reject APNG before backend-specific single-frame decoding.' );

$animated_webp_bytes = base64_decode( 'UklGRsAAAABXRUJQVlA4WAoAAAACAAAAAQAAAQAAQU5JTQYAAAD/////AABBTk1GSAAAAAAAAAAAAAEAAAEAAGQAAAJWUDggMAAAANABAJ0BKgIAAgACADQloAJ0ugH4AAOwAP7wxAv/ILlhdcjX/yA/5Af8gP/48gAAAEFOTUZEAAAAAAAAAAAAAQAAAQAAZAAAAFZQOCAsAAAAlAEAnQEqAgACAAAANCWgAnS6AAOYAP75k2//kB//kB//kB//ID/iF3sgMAA=' );
$animated_webp_path = eforms_test_write_file( $tmp_dir, 'animated.webp', $animated_webp_bytes );
$animated_webp = UploadPolicy::validate_item(
    array( 'tmp_name' => $animated_webp_path, 'original_name' => 'animated.webp', 'size' => filesize( $animated_webp_path ), 'error' => UPLOAD_ERR_OK ),
    $field
);
eforms_test_assert( $animated_webp['ok'] === false && $animated_webp['code'] === 'EFORMS_ERR_UPLOAD_TYPE', 'Staged validation should reject animated WebP before the primary-frame decode.' );

$chunk_limit = (int) Anchors::get( 'MANAGED_IMAGE_CONTAINER_MAX_CHUNKS' );
$png_chunk_guard = new ReflectionMethod( 'UploadPolicy', 'png_animation_state' );
$png_chunk_guard->setAccessible( true );
$png_chunk_flood = "\x89PNG\r\n\x1a\n" . eforms_test_png_chunk( 'IHDR', pack( 'NNCCCCC', 1, 1, 8, 2, 0, 0, 0 ) );
for ( $chunk_index = 1; $chunk_index <= $chunk_limit; $chunk_index++ ) {
    $png_chunk_flood .= eforms_test_png_chunk( 'tEXt', '' );
}
$png_chunk_flood_path = eforms_test_write_file( $tmp_dir, 'chunk-flood.png', $png_chunk_flood );
eforms_test_assert(
    $png_chunk_guard->invoke( null, $png_chunk_flood_path ) === null,
    'PNG animation inspection should fail closed when its container-chunk budget is exhausted.'
);

$webp_chunk_guard = new ReflectionMethod( 'UploadPolicy', 'webp_animation_state' );
$webp_chunk_guard->setAccessible( true );
$webp_chunk_flood_payload = str_repeat( 'JUNK' . pack( 'V', 0 ), $chunk_limit + 1 );
$webp_chunk_flood = 'RIFF' . pack( 'V', strlen( $webp_chunk_flood_payload ) + 4 ) . 'WEBP' . $webp_chunk_flood_payload;
$webp_chunk_flood_path = eforms_test_write_file( $tmp_dir, 'chunk-flood.webp', $webp_chunk_flood );
eforms_test_assert(
    $webp_chunk_guard->invoke( null, $webp_chunk_flood_path ) === null,
    'WebP animation inspection should fail closed when its container-chunk budget is exhausted.'
);

$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
$gif_path = eforms_test_write_file( $tmp_dir, 'pixel.gif', $gif );
$gif_item = array( 'tmp_name' => $gif_path, 'original_name' => 'pixel.gif', 'size' => filesize( $gif_path ), 'error' => UPLOAD_ERR_OK );
$staged_gif = UploadPolicy::validate_item( $gif_item, $field );
eforms_test_assert( $staged_gif['ok'] === false, 'Staged image validation should reject GIF.' );

$sync_field = array( 'type' => 'file', 'accept' => array( 'image' ) );
$sync_gif = UploadPolicy::validate_item( $gif_item, $sync_field );
eforms_test_assert( $sync_gif['ok'] === true, 'Synchronous image validation should retain GIF support.' );

$heic_policy = UploadPolicy::policy_for_tokens( array( 'image' ), 'staged' );
eforms_test_assert(
    in_array( 'image/heic', $heic_policy['mimes'], true )
        && in_array( 'image/heif', $heic_policy['mimes'], true )
        && in_array( 'heic', $heic_policy['extensions'], true )
        && in_array( 'heif', $heic_policy['extensions'], true ),
    'The sole staged image token should own both HEIC and HEIF MIME/extension hints.'
);
eforms_test_assert( UploadPolicy::resolve_tokens( array( 'heic' ), false, 'synchronous' ) === array(), 'HEIC should remain unavailable to synchronous upload fields.' );

$jpeg_path = eforms_test_write_file( $tmp_dir, 'spoof.heic', $fixtures['jpeg']['bytes'] );
$spoofed_heic = UploadPolicy::validate_item(
    array( 'tmp_name' => $jpeg_path, 'original_name' => 'spoof.heic', 'size' => filesize( $jpeg_path ), 'error' => UPLOAD_ERR_OK ),
    $field
);
eforms_test_assert( $spoofed_heic['ok'] === false, 'The image token should reject a HEIC extension whose bytes are JPEG.' );

$require_heic = getenv( 'EFORMS_REQUIRE_STAGED_HEIC' ) === '1';
$heic_bytes = eforms_test_fixture_bytes( 'staged-landscape.heic' );
eforms_test_assert( strlen( $heic_bytes ) === 2363, 'The genuine HEIC fixture byte length should remain stable.' );
eforms_test_assert( hash( 'sha256', $heic_bytes ) === '11ffd8eb6c8249ca473ea6856c27eefcdb866125a807e514dbd12e1c80d20a4d', 'The genuine HEIC fixture digest should remain stable.' );
$heic_probe_path = eforms_test_write_file( $tmp_dir, 'camera-probe.heic', $heic_bytes );
$heic_mime = UploadPolicy::detect_mime( $heic_probe_path );
$heic_dimensions = HeifInspector::inspect( $heic_probe_path );
eforms_test_assert(
    $heic_dimensions === array( 'width' => 120, 'height' => 60, 'coded_width' => 120, 'coded_height' => 64 ),
    'Bounded HEIF container inspection should resolve the primary image clean-aperture dimensions without decoding pixels.'
);
foreach ( array( 'hevc', 'hevx', 'hevm', 'hevs' ) as $sequence_brand ) {
    $sequence_brand_bytes = substr_replace( $heic_bytes, $sequence_brand, 8, 4 );
    $sequence_brand_path = eforms_test_write_file( $tmp_dir, 'camera-sequence-' . $sequence_brand . '.heic', $sequence_brand_bytes );
    eforms_test_assert(
        HeifInspector::inspect( $sequence_brand_path ) === null,
        'HEIF sequence brand ' . $sequence_brand . ' must remain outside the staged still-image contract even when compatible still-image brands are present.'
    );
}
$track_sequence_path = eforms_test_write_file(
    $tmp_dir,
    'camera-track-sequence.heic',
    $heic_bytes . eforms_test_bmff_box( 'moov', '' )
);
eforms_test_assert(
    HeifInspector::inspect( $track_sequence_path ) === null,
    'A HEIF track container must not pass by presenting a valid primary still item alongside sequence metadata.'
);
$tiled_heic_path = eforms_test_write_file( $tmp_dir, 'camera-tiled.heic', eforms_test_tiled_heif() );
eforms_test_assert(
    HeifInspector::inspect( $tiled_heic_path ) === array( 'width' => 4, 'height' => 2, 'coded_width' => 4, 'coded_height' => 2 ),
    'Bounded HEIF inspection should accept an iPhone-style grid descriptor only when all referenced HEVC tiles are media-backed.'
);
$tiled_v3_heic_path = eforms_test_write_file( $tmp_dir, 'camera-tiled-v3.heic', eforms_test_tiled_heif( array( 1, 2 ), 3 ) );
eforms_test_assert(
    HeifInspector::inspect( $tiled_v3_heic_path ) === array( 'width' => 4, 'height' => 2, 'coded_width' => 4, 'coded_height' => 2 ),
    'Bounded HEIF inspection should accept an unprotected version-3 item definition.'
);
foreach ( array( 2, 3 ) as $protected_version ) {
    $protected_path = eforms_test_write_file(
        $tmp_dir,
        'camera-protected-v' . $protected_version . '.heic',
        eforms_test_tiled_heif( array( 1, 2 ), $protected_version, 1 )
    );
    eforms_test_assert(
        HeifInspector::inspect( $protected_path ) === null,
        'A protected version-' . $protected_version . ' primary item must not become an authoritative still artifact.'
    );
}
$incomplete_tiled_heic_path = eforms_test_write_file( $tmp_dir, 'camera-tiled-incomplete.heic', eforms_test_tiled_heif( array( 1 ) ) );
eforms_test_assert(
    HeifInspector::inspect( $incomplete_tiled_heic_path ) === null,
    'A tiled HEIF primary item must reference the exact bounded tile count declared by its grid descriptor.'
);
if ( in_array( $heic_mime, array( 'image/heic', 'image/heif' ), true ) ) {
    $inspected_heic = UploadPolicy::inspect_staged_artifact( $heic_probe_path, 'camera-probe.heic', $field );
    eforms_test_assert(
        ! empty( $inspected_heic['ok'] ) && $inspected_heic['width'] === 120 && $inspected_heic['height'] === 60,
        'Authoritative HEIC acceptance should use bounded container inspection without requiring Imagick.'
    );
}
$truncated_heic_path = eforms_test_write_file( $tmp_dir, 'camera-truncated.heic', substr( $heic_bytes, 0, 400 ) );
eforms_test_assert( HeifInspector::inspect( $truncated_heic_path ) === null, 'Bounded HEIF container inspection should reject a truncated box tree.' );
$metadata_only_heic_path = eforms_test_write_file(
    $tmp_dir,
    'camera-metadata-only.heic',
    eforms_test_heif_with_ipma( array( pack( 'nCC', 65535, 1, 1 ) ) )
);
eforms_test_assert(
    HeifInspector::inspect( $metadata_only_heic_path ) === null,
    'HEIF inspection must reject a primary item that has dimensions but no supported image definition or media-data extent.'
);
$excess_entry_records = array();
for ( $entry = 1; $entry <= Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATION_ENTRIES' ); $entry++ ) {
    $excess_entry_records[] = pack( 'nC', $entry, 0 );
}
$excess_entry_records[] = pack( 'nCC', 65535, 1, 1 );
$excess_entries_path = eforms_test_write_file(
    $tmp_dir,
    'camera-excess-ipma-entries.heic',
    eforms_test_heif_with_ipma( $excess_entry_records )
);
eforms_test_assert( HeifInspector::inspect( $excess_entries_path ) === null, 'HEIF inspection must reject an ipma table above the fixed entry bound before iterating it.' );

$excess_association_records = array();
$remaining_associations = Anchors::get( 'MANAGED_HEIF_MAX_ASSOCIATIONS' ) + 1;
$association_item_id = 1;
while ( $remaining_associations > 0 ) {
    $association_count = min( 255, $remaining_associations );
    $excess_association_records[] = pack( 'nC', $association_item_id, $association_count ) . str_repeat( chr( 1 ), $association_count );
    $association_item_id++;
    $remaining_associations -= $association_count;
}
$excess_association_records[] = pack( 'nCC', 65535, 1, 1 );
$excess_associations_path = eforms_test_write_file(
    $tmp_dir,
    'camera-excess-ipma-associations.heic',
    eforms_test_heif_with_ipma( $excess_association_records )
);
eforms_test_assert( HeifInspector::inspect( $excess_associations_path ) === null, 'HEIF inspection must reject cumulative ipma associations above the fixed bound.' );
if ( in_array( $heic_mime, array( 'image/heic', 'image/heif' ), true ) ) {
    foreach ( array( 'heic', 'heif' ) as $extension ) {
        $source = eforms_test_write_file( $tmp_dir, 'camera.' . $extension, $heic_bytes );
        $validated_heic = UploadPolicy::validate_item(
            array( 'tmp_name' => $source, 'original_name' => 'camera.' . $extension, 'size' => filesize( $source ), 'error' => UPLOAD_ERR_OK ),
            $field
        );
        eforms_test_assert(
            ! empty( $validated_heic['ok'] )
                && $validated_heic['width'] === 120
                && $validated_heic['height'] === 60
                && is_file( $source ),
            'A genuine ' . strtoupper( $extension ) . ' source should become the retained authoritative artifact without a decoder.'
        );
    }
} elseif ( $require_heic ) {
    eforms_test_assert( false, 'Required staged HEIC checks need fileinfo HEIC detection.' );
} else {
    echo "HEIC MIME checks skipped: local fileinfo HEIC detection is unavailable.\n";
}

$oversized_png = $fixtures['png']['bytes'];
$oversized_png = substr( $oversized_png, 0, 16 ) . pack( 'N', Anchors::get( 'MANAGED_ARTIFACT_MAX_EDGE' ) + 1 ) . substr( $oversized_png, 20 );
$oversized_path = eforms_test_write_file( $tmp_dir, 'oversized.png', $oversized_png );
$oversized = UploadPolicy::validate_item(
    array( 'tmp_name' => $oversized_path, 'original_name' => 'oversized.png', 'size' => filesize( $oversized_path ), 'error' => UPLOAD_ERR_OK ),
    $field
);
eforms_test_assert( $oversized['ok'] === false, 'Staged validation should reject a source above the maximum edge before decode.' );

eforms_test_remove_tree( $tmp_dir );
echo "All staged image policy tests passed.\n";
