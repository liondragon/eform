<?php
/**
 * Integration tests for staged image validation and JPEG preview processing.
 *
 * Contract: Managed staged upload protocol
 * Contract: Uploads accept-token policy
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Uploads/UploadPolicy.php';

$tmp_dir = eforms_test_tmp_root( 'eforms-staged-images' );
mkdir( $tmp_dir, 0700, true );

function eforms_test_write_oriented_quadrant_jpeg( $path, $orientation ) {
    $image = imagecreatetruecolor( 80, 40 );
    if ( ! $image ) {
        return false;
    }
    $red = imagecolorallocate( $image, 255, 0, 0 );
    $green = imagecolorallocate( $image, 0, 255, 0 );
    $blue = imagecolorallocate( $image, 0, 0, 255 );
    $yellow = imagecolorallocate( $image, 255, 255, 0 );
    imagefilledrectangle( $image, 0, 0, 39, 19, $red );
    imagefilledrectangle( $image, 40, 0, 79, 19, $green );
    imagefilledrectangle( $image, 0, 20, 39, 39, $blue );
    imagefilledrectangle( $image, 40, 20, 79, 39, $yellow );
    ob_start();
    $encoded = imagejpeg( $image, null, 100 );
    $jpeg = ob_get_clean();
    imagedestroy( $image );
    if ( ! $encoded || ! is_string( $jpeg ) || substr( $jpeg, 0, 2 ) !== "\xFF\xD8" ) {
        return false;
    }

    // Minimal little-endian TIFF IFD0 carrying only the EXIF Orientation tag.
    $ifd = 'II' . pack( 'v', 42 ) . pack( 'V', 8 ) . pack( 'v', 1 );
    $ifd .= pack( 'v', 0x0112 ) . pack( 'v', 3 ) . pack( 'V', 1 ) . pack( 'v', $orientation ) . "\0\0";
    $ifd .= pack( 'V', 0 );
    $payload = "Exif\0\0" . $ifd;
    $segment = "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload;
    return file_put_contents( $path, substr( $jpeg, 0, 2 ) . $segment . substr( $jpeg, 2 ) ) !== false;
}

function eforms_test_preview_corner_colors( $path ) {
    $image = imagecreatefromjpeg( $path );
    if ( ! $image ) {
        return array();
    }
    $points = array( array( 10, 20 ), array( 30, 20 ), array( 10, 60 ), array( 30, 60 ) );
    $colors = array();
    foreach ( $points as $point ) {
        $colors[] = imagecolorsforindex( $image, imagecolorat( $image, $point[0], $point[1] ) );
    }
    imagedestroy( $image );
    return $colors;
}

function eforms_test_color_matches( $color, $name ) {
    if ( ! is_array( $color ) ) {
        return false;
    }
    if ( $name === 'red' ) {
        return $color['red'] > 160 && $color['green'] < 120 && $color['blue'] < 120;
    }
    if ( $name === 'green' ) {
        return $color['green'] > 140 && $color['red'] < 120 && $color['blue'] < 120;
    }
    if ( $name === 'blue' ) {
        return $color['blue'] > 160 && $color['red'] < 120 && $color['green'] < 120;
    }
    return $name === 'yellow' && $color['red'] > 150 && $color['green'] > 150 && $color['blue'] < 120;
}

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
);

$field = array(
    'type' => 'files',
    'upload_mode' => 'staged',
    'accept' => array( 'image' ),
    'max_file_bytes' => 10 * 1024 * 1024,
);
$readiness = array(
    'memory_limit' => -1,
    'execution_limit' => 0,
    'editor_support' => function ( $args ) {
        return isset( $args['mime_type'] ) && in_array( $args['mime_type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true );
    },
);

$backend_ready = UploadPolicy::staged_host_readiness( 'image/jpeg', $readiness );
if ( $backend_ready['ok'] ) {
    foreach ( $fixtures as $key => $fixture ) {
        $source = eforms_test_write_file( $tmp_dir, $fixture['name'], $fixture['bytes'] );
        $item = array(
            'tmp_name' => $source,
            'original_name' => '../' . $fixture['name'],
            'size' => 1,
            'error' => UPLOAD_ERR_OK,
        );
        $validated = UploadPolicy::validate_item( $item, $field, $readiness );
        eforms_test_assert( $validated['ok'] === true, strtoupper( $key ) . ' should pass staged validation.' );
        eforms_test_assert( $validated['bytes'] === filesize( $source ), 'Validation should own the actual source byte count.' );
        eforms_test_assert( $validated['mime'] === $fixture['mime'], 'Validation should use fileinfo MIME rather than browser hints.' );

        $destination = $tmp_dir . '/' . $key . '-preview.jpg';
        $preview = UploadPolicy::create_staged_preview( $validated, $destination );
        eforms_test_assert( $preview['ok'] === true, strtoupper( $key ) . ' should produce a staged JPEG preview.' );
        eforms_test_assert( $preview['mime'] === 'image/jpeg' && $preview['extension'] === 'jpg', 'Every staged preview should be JPEG.' );
        eforms_test_assert( $preview['bytes'] <= Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' ), 'Every staged preview should fit the fixed encoded-size ceiling.' );
        eforms_test_assert( UploadPolicy::detect_mime( $destination ) === 'image/jpeg', 'The preview bytes should agree with the JPEG result contract.' );
        eforms_test_assert( glob( $destination . '.attempt-*' ) === array(), 'Successful preview processing should remove every candidate file.' );

        if ( class_exists( 'Imagick' ) ) {
            $image = new Imagick( $destination );
            if ( $key === 'jpeg' ) {
                eforms_test_assert( $image->getImageWidth() === 60 && $image->getImageHeight() === 120, 'JPEG EXIF orientation should be normalized into preview dimensions.' );
                eforms_test_assert( ! in_array( 'comment', $image->getImageProperties( '*', false ), true ), 'Preview metadata should not retain the source comment.' );
                eforms_test_assert( $image->getImageProfiles( '*', false ) === array(), 'Preview metadata profiles should be stripped.' );
            }
            if ( $key === 'png' ) {
                $pixel = $image->getImagePixelColor( 0, 0 )->getColor();
                eforms_test_assert( $pixel['r'] >= 250 && $pixel['g'] >= 250 && $pixel['b'] >= 250, 'Transparent source pixels should flatten onto white.' );
            }
            $image->clear();
            $image->destroy();
        }
    }

    $source = $tmp_dir . '/alpha.png';
    $validated = UploadPolicy::validate_item(
        array( 'tmp_name' => $source, 'original_name' => 'alpha.png', 'size' => filesize( $source ), 'error' => UPLOAD_ERR_OK ),
        $field,
        $readiness
    );
    $missing_parent_destination = $tmp_dir . '/missing/preview.jpg';
    $failed_preview = UploadPolicy::create_staged_preview( $validated, $missing_parent_destination );
    eforms_test_assert( $failed_preview['ok'] === false, 'A derivative write failure should reject the preview.' );
    eforms_test_assert( ! file_exists( $missing_parent_destination ) && glob( $missing_parent_destination . '.attempt-*' ) === array(), 'A derivative write failure should leave no committed preview or candidate.' );

    if ( function_exists( 'imagecreatefromjpeg' ) && function_exists( 'imagejpeg' ) ) {
        $gd_validated = UploadPolicy::validate_item(
            array( 'tmp_name' => $tmp_dir . '/camera.jpg', 'original_name' => 'camera.jpg', 'size' => filesize( $tmp_dir . '/camera.jpg' ), 'error' => UPLOAD_ERR_OK ),
            $field,
            $readiness
        );
        $gd_validated['backend'] = 'gd';
        $gd_destination = $tmp_dir . '/forced-gd-preview.jpg';
        $gd_preview = UploadPolicy::create_staged_preview( $gd_validated, $gd_destination );
        eforms_test_assert( $gd_preview['ok'] === true && UploadPolicy::detect_mime( $gd_destination ) === 'image/jpeg', 'A forced GD preview should use the same JPEG result contract when Imagick is also installed.' );

        if ( function_exists( 'imageflip' ) && function_exists( 'exif_read_data' ) ) {
            $expected_orientations = array(
                5 => array( 'red', 'blue', 'green', 'yellow' ),
                7 => array( 'yellow', 'green', 'blue', 'red' ),
            );
            foreach ( $expected_orientations as $orientation => $expected_colors ) {
                $oriented_source = $tmp_dir . '/orientation-' . $orientation . '.jpg';
                eforms_test_assert( eforms_test_write_oriented_quadrant_jpeg( $oriented_source, $orientation ), 'The GD orientation fixture should be created.' );
                $exif = exif_read_data( $oriented_source );
                eforms_test_assert( is_array( $exif ) && (int) $exif['Orientation'] === $orientation, 'The GD orientation fixture should expose its EXIF tag.' );
                $oriented_validated = UploadPolicy::validate_item(
                    array( 'tmp_name' => $oriented_source, 'original_name' => basename( $oriented_source ), 'size' => filesize( $oriented_source ), 'error' => UPLOAD_ERR_OK ),
                    $field,
                    $readiness
                );
                $oriented_validated['backend'] = 'gd';
                $oriented_destination = $tmp_dir . '/orientation-' . $orientation . '-preview.jpg';
                $oriented_preview = UploadPolicy::create_staged_preview( $oriented_validated, $oriented_destination );
                eforms_test_assert( ! empty( $oriented_preview['ok'] ) && $oriented_preview['width'] === 40 && $oriented_preview['height'] === 80, 'Mirrored EXIF orientation ' . $orientation . ' should swap preview dimensions on GD.' );
                $actual_colors = eforms_test_preview_corner_colors( $oriented_destination );
                foreach ( $expected_colors as $index => $expected_color ) {
                    eforms_test_assert( isset( $actual_colors[ $index ] ) && eforms_test_color_matches( $actual_colors[ $index ], $expected_color ), 'EXIF orientation ' . $orientation . ' should map quadrant ' . $index . ' through its distinct GD transform.' );
                }
            }
        } else {
            echo "GD EXIF orientation checks skipped: EXIF support is unavailable.\n";
        }

        $gd_failed_destination = $tmp_dir . '/missing-gd/preview.jpg';
        $gd_failed = UploadPolicy::create_staged_preview( $gd_validated, $gd_failed_destination );
        eforms_test_assert( $gd_failed['ok'] === false, 'A forced GD write failure should return a normal processing failure.' );
        eforms_test_assert( ! file_exists( $gd_failed_destination ) && glob( $gd_failed_destination . '.attempt-*' ) === array(), 'A forced GD failure should leave no committed preview or partial candidate.' );
    } else {
        echo "Forced GD preview checks skipped: GD is unavailable.\n";
    }
} else {
    echo "Staged preview fixture checks skipped: no supported local image backend.\n";
}

$apng_path = eforms_test_write_file( $tmp_dir, 'animated.png', eforms_test_animated_png( $fixtures['png']['bytes'] ) );
$apng = UploadPolicy::validate_item(
    array( 'tmp_name' => $apng_path, 'original_name' => 'animated.png', 'size' => filesize( $apng_path ), 'error' => UPLOAD_ERR_OK ),
    $field,
    $readiness
);
eforms_test_assert( $apng['ok'] === false && $apng['code'] === 'EFORMS_ERR_UPLOAD_TYPE', 'Staged validation should reject APNG before backend-specific single-frame decoding.' );

$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
$gif_path = eforms_test_write_file( $tmp_dir, 'pixel.gif', $gif );
$gif_item = array( 'tmp_name' => $gif_path, 'original_name' => 'pixel.gif', 'size' => filesize( $gif_path ), 'error' => UPLOAD_ERR_OK );
$staged_gif = UploadPolicy::validate_item( $gif_item, $field, $readiness );
eforms_test_assert( $staged_gif['ok'] === false, 'Staged image validation should reject GIF.' );

$sync_field = array( 'type' => 'file', 'accept' => array( 'image' ) );
$sync_gif = UploadPolicy::validate_item( $gif_item, $sync_field );
eforms_test_assert( $sync_gif['ok'] === true, 'Synchronous image validation should retain GIF support.' );

$heic_field = $field;
$heic_field['accept'] = array( 'image', 'heic' );
$heic_policy = UploadPolicy::policy_for_tokens( $heic_field['accept'], 'staged' );
eforms_test_assert(
    in_array( 'image/heic', $heic_policy['mimes'], true )
        && in_array( 'image/heif', $heic_policy['mimes'], true )
        && in_array( 'heic', $heic_policy['extensions'], true )
        && in_array( 'heif', $heic_policy['extensions'], true ),
    'The explicit staged HEIC token should own both HEIC and HEIF MIME/extension hints.'
);
eforms_test_assert( UploadPolicy::resolve_tokens( array( 'heic' ), false, 'synchronous' ) === array(), 'HEIC should remain unavailable to synchronous upload fields.' );

$heic_ready = UploadPolicy::staged_host_readiness(
    'image/heic',
    array_merge(
        $readiness,
        array(
            'imagick_support' => function ( $mime ) {
                return in_array( $mime, array( 'image/heic', 'image/heif', 'image/jpeg' ), true );
            },
        )
    )
);
eforms_test_assert( ! empty( $heic_ready['ok'] ) && $heic_ready['backend'] === 'imagick', 'HEIC readiness should require the Imagick preview path.' );
$heic_unavailable = UploadPolicy::staged_host_readiness(
    'image/heic',
    array_merge( $readiness, array( 'imagick_support' => function () { return false; } ) )
);
eforms_test_assert( empty( $heic_unavailable['ok'] ) && $heic_unavailable['reason'] === 'backend', 'HEIC readiness should fail closed without an Imagick HEIC delegate.' );

$jpeg_path = eforms_test_write_file( $tmp_dir, 'spoof.heic', $fixtures['jpeg']['bytes'] );
$default_heic = UploadPolicy::validate_item(
    array( 'tmp_name' => $jpeg_path, 'original_name' => 'spoof.heic', 'size' => filesize( $jpeg_path ), 'error' => UPLOAD_ERR_OK ),
    $field,
    $readiness
);
eforms_test_assert( $default_heic['ok'] === false, 'Staged image validation should keep HEIC disabled unless the field opts in.' );
$spoofed_heic = UploadPolicy::validate_item(
    array( 'tmp_name' => $jpeg_path, 'original_name' => 'spoof.heic', 'size' => filesize( $jpeg_path ), 'error' => UPLOAD_ERR_OK ),
    $heic_field,
    $readiness
);
eforms_test_assert( $spoofed_heic['ok'] === false, 'HEIC opt-in should still reject a HEIC extension whose bytes are JPEG.' );

if ( class_exists( 'Imagick' ) && ! empty( Imagick::queryFormats( 'HEIC' ) ) ) {
    $generated_heic_path = $tmp_dir . '/camera.heic';
    $generated_heic = null;
    try {
        $generated_heic = new Imagick();
        $generated_heic->newImage( 12, 8, new ImagickPixel( '#336699' ) );
        $generated_heic->setImageFormat( 'heic' );
        $generated = $generated_heic->writeImage( $generated_heic_path );
    } catch ( Throwable $error ) {
        $generated = false;
    } finally {
        if ( $generated_heic instanceof Imagick ) {
            $generated_heic->clear();
            $generated_heic->destroy();
        }
    }

    if ( $generated && is_file( $generated_heic_path ) ) {
        $generated_mime = UploadPolicy::detect_mime( $generated_heic_path );
        if ( in_array( $generated_mime, array( 'image/heic', 'image/heif' ), true ) ) {
            $validated_heic = UploadPolicy::validate_item(
                array( 'tmp_name' => $generated_heic_path, 'original_name' => 'camera.heic', 'size' => filesize( $generated_heic_path ), 'error' => UPLOAD_ERR_OK ),
                $heic_field,
                $readiness
            );
            eforms_test_assert( ! empty( $validated_heic['ok'] ) && $validated_heic['backend'] === 'imagick', 'An opted-in HEIC image should pass staged validation through Imagick.' );
            eforms_test_assert( $validated_heic['width'] === 12 && $validated_heic['height'] === 8, 'HEIC dimensions should be bounded through the Imagick probe.' );

            $heic_preview_path = $tmp_dir . '/heic-preview.jpg';
            $heic_preview = UploadPolicy::create_staged_preview( $validated_heic, $heic_preview_path );
            eforms_test_assert( ! empty( $heic_preview['ok'] ) && UploadPolicy::detect_mime( $heic_preview_path ) === 'image/jpeg', 'An opted-in HEIC original should produce the canonical JPEG preview.' );
        } else {
            echo "HEIC conversion checks skipped: generated fixture is not recognized as HEIC by fileinfo.\n";
        }
    } else {
        echo "HEIC conversion checks skipped: local ImageMagick cannot write the fixture.\n";
    }
} else {
    echo "HEIC conversion checks skipped: local ImageMagick HEIC support is unavailable.\n";
}

$oversized_png = $fixtures['png']['bytes'];
$oversized_png = substr( $oversized_png, 0, 16 ) . pack( 'N', Anchors::get( 'STAGED_IMAGE_MAX_EDGE' ) + 1 ) . substr( $oversized_png, 20 );
$oversized_path = eforms_test_write_file( $tmp_dir, 'oversized.png', $oversized_png );
$oversized = UploadPolicy::validate_item(
    array( 'tmp_name' => $oversized_path, 'original_name' => 'oversized.png', 'size' => filesize( $oversized_path ), 'error' => UPLOAD_ERR_OK ),
    $field,
    $readiness
);
eforms_test_assert( $oversized['ok'] === false, 'Staged validation should reject a source above the maximum edge before decode.' );

foreach ( array(
    array( 'memory_limit' => '767M', 'execution_limit' => 0, 'editor_support' => $readiness['editor_support'] ),
    array( 'memory_limit' => '768M', 'execution_limit' => 59, 'editor_support' => $readiness['editor_support'] ),
    array( 'memory_limit' => -1, 'execution_limit' => 0, 'editor_support' => function () { return false; } ),
) as $host_failure ) {
    $png_path = $tmp_dir . '/alpha.png';
    if ( ! is_file( $png_path ) ) {
        $png_path = eforms_test_write_file( $tmp_dir, 'alpha.png', $fixtures['png']['bytes'] );
    }
    $failed = UploadPolicy::validate_item(
        array( 'tmp_name' => $png_path, 'original_name' => 'alpha.png', 'size' => filesize( $png_path ), 'error' => UPLOAD_ERR_OK ),
        $field,
        $host_failure
    );
    eforms_test_assert( $failed['ok'] === false, 'Each staged host-readiness violation should reject the source.' );
}

eforms_test_remove_tree( $tmp_dir );
echo "All staged image policy tests passed.\n";
