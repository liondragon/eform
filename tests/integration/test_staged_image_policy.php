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
$readiness = array(
    'memory_limit' => -1,
    'execution_limit' => 0,
);

$backend_ready = UploadPolicy::staged_host_readiness( $readiness );
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
        if ( $key === 'wide-gamut' ) {
            $profiled_source = new Imagick( $source );
            eforms_test_assert( $profiled_source->getImageProfile( 'icc' ) !== '', 'The wide-gamut source fixture should carry its embedded ICC profile.' );
            $profiled_source->clear();
            $profiled_source->destroy();
        }

        $destination = $tmp_dir . '/' . $key . '-derivatives';
        mkdir( $destination, 0700 );
        $derivatives = UploadPolicy::create_staged_derivatives( $validated, $destination );
        eforms_test_assert( $derivatives['ok'] === true, strtoupper( $key ) . ' should produce staged JPEG derivatives.' );
        eforms_test_assert( ! file_exists( $source ), 'Every terminal derivative result should remove the uploaded source.' );
        foreach ( array( 'master', 'preview' ) as $variant ) {
            $result = $derivatives[ $variant ];
            $path = $destination . '/' . $variant . '.jpg';
            $ceiling = $variant === 'master' ? Anchors::get( 'STAGED_MASTER_MAX_BYTES' ) : Anchors::get( 'STAGED_PREVIEW_MAX_BYTES' );
            eforms_test_assert( $result['mime'] === 'image/jpeg' && $result['extension'] === 'jpg', 'Every staged derivative should be JPEG.' );
            eforms_test_assert( $result['bytes'] <= $ceiling && hash_file( 'sha256', $path ) === $result['sha256'], 'Each derivative should satisfy its byte ceiling and digest contract.' );
            eforms_test_assert( UploadPolicy::detect_mime( $path ) === 'image/jpeg', 'Derivative bytes should agree with the JPEG result contract.' );
            eforms_test_assert( glob( $path . '.attempt-*' ) === array(), 'Successful processing should remove every candidate file.' );
        }

        if ( class_exists( 'Imagick' ) ) {
            $image = new Imagick( $destination . '/preview.jpg' );
            if ( $key === 'jpeg' ) {
                eforms_test_assert( $image->getImageWidth() === 60 && $image->getImageHeight() === 120, 'JPEG EXIF orientation should be normalized into preview dimensions.' );
                eforms_test_assert( ! in_array( 'comment', $image->getImageProperties( '*', false ), true ), 'Preview metadata should not retain the source comment.' );
            }
            eforms_test_assert( $image->getImageProfiles( '*', false ) === array(), 'Derivative metadata profiles should be stripped.' );
            eforms_test_assert( $image->getImageProperties( 'exif:GPS*', false ) === array(), 'Derivative metadata should expose no GPS properties.' );
            if ( $key === 'png' ) {
                $pixel = $image->getImagePixelColor( 0, 0 )->getColor();
                eforms_test_assert( $pixel['r'] >= 250 && $pixel['g'] >= 250 && $pixel['b'] >= 250, 'Transparent source pixels should flatten onto white.' );
            }
            if ( $key === 'wide-gamut' ) {
                eforms_test_assert( $image->getImageColorspace() === Imagick::COLORSPACE_SRGB, 'Wide-gamut input should be converted to the sRGB output colorspace.' );
                eforms_test_assert( $image->getImageProfiles( '*', false ) === array(), 'The converted derivative should not retain the source color profile.' );
                $pixel = $image->getImagePixelColor( 0, 0 )->getColor();
                eforms_test_assert( $pixel['b'] >= 130, 'Wide-gamut pixels should be transformed through the embedded ICC profile before it is stripped.' );
            }
            $image->clear();
            $image->destroy();
        }
    }

    $source = eforms_test_write_file( $tmp_dir, 'write-failure.png', $fixtures['png']['bytes'] );
    $validated = UploadPolicy::validate_item(
        array( 'tmp_name' => $source, 'original_name' => 'write-failure.png', 'size' => filesize( $source ), 'error' => UPLOAD_ERR_OK ),
        $field,
        $readiness
    );
    $failed = UploadPolicy::create_staged_derivatives( $validated, $tmp_dir . '/missing' );
    eforms_test_assert( $failed['ok'] === false && ! file_exists( $source ), 'A derivative setup failure should consume the source and leave no output.' );

    $locked_source_dir = $tmp_dir . '/locked-source';
    $locked_destination = $tmp_dir . '/locked-source-derivatives';
    mkdir( $locked_source_dir, 0700 );
    mkdir( $locked_destination, 0700 );
    $locked_source = eforms_test_write_file( $locked_source_dir, 'locked-source.png', $fixtures['png']['bytes'] );
    $locked_validated = UploadPolicy::validate_item(
        array( 'tmp_name' => $locked_source, 'original_name' => 'locked-source.png', 'size' => filesize( $locked_source ), 'error' => UPLOAD_ERR_OK ),
        $field,
        $readiness
    );
    chmod( $locked_source_dir, 0500 );
    $locked_failure = UploadPolicy::create_staged_derivatives( $locked_validated, $locked_destination );
    clearstatcache( true, $locked_source );
    $locked_source_retained = file_exists( $locked_source );
    $locked_master_created = file_exists( $locked_destination . '/master.jpg' );
    $locked_preview_created = file_exists( $locked_destination . '/preview.jpg' );
    chmod( $locked_source_dir, 0700 );
    @unlink( $locked_source );
    eforms_test_remove_tree( $locked_source_dir );
    eforms_test_remove_tree( $locked_destination );
    eforms_test_assert(
        empty( $locked_failure['ok'] )
            && isset( $locked_failure['reason'] )
            && $locked_failure['reason'] === 'source_cleanup_failed'
            && $locked_source_retained
            && ! $locked_master_created
            && ! $locked_preview_created,
        'Derivative processing should fail before commit when the decoded raw source cannot be removed.'
    );

    $large_source = $tmp_dir . '/large-landscape.jpg';
    $large = new Imagick();
    $large->newImage( 2000, 1000, new ImagickPixel( '#336699' ) );
    $large->setImageFormat( 'jpeg' );
    $large->writeImage( $large_source );
    $large->clear();
    $large->destroy();
    $large_validated = UploadPolicy::validate_item(
        array( 'tmp_name' => $large_source, 'original_name' => 'large-landscape.jpg', 'size' => filesize( $large_source ), 'error' => UPLOAD_ERR_OK ),
        $field,
        $readiness
    );
    $large_destination = $tmp_dir . '/large-landscape-derivatives';
    mkdir( $large_destination, 0700 );
    $large_derivatives = UploadPolicy::create_staged_derivatives( $large_validated, $large_destination );
    eforms_test_assert(
        ! empty( $large_derivatives['ok'] )
            && $large_derivatives['preview']['width'] === 1600
            && $large_derivatives['preview']['height'] === 800,
        'Derivative resizing should preserve a landscape source aspect ratio without square fill padding.'
    );

    $multi_source = $tmp_dir . '/multi-image.miff';
    $multi = new Imagick();
    $multi->newImage( 20, 20, new ImagickPixel( '#ff0000' ) );
    $multi->setImageFormat( 'miff' );
    $multi->newImage( 20, 20, new ImagickPixel( '#0000ff' ) );
    $multi->setImageFormat( 'miff' );
    $multi->writeImages( $multi_source, true );
    $multi->clear();
    $multi->destroy();
    $multi_guard = new ReflectionMethod( 'UploadPolicy', 'create_derivatives_imagick' );
    $multi_guard->setAccessible( true );
    $multi_failure = $multi_guard->invoke(
        null,
        $multi_source,
        'image/jpeg',
        $tmp_dir . '/multi-master.jpg',
        $tmp_dir . '/multi-preview.jpg'
    );
    eforms_test_assert(
        empty( $multi_failure['ok'] )
            && ! file_exists( $multi_source )
            && ! file_exists( $tmp_dir . '/multi-master.jpg' )
            && ! file_exists( $tmp_dir . '/multi-preview.jpg' ),
        'The non-HEIC derivative pipeline should reject multi-image input instead of silently selecting its first frame.'
    );

    $digest_guard = new ReflectionMethod( 'UploadPolicy', 'derivative_success' );
    $digest_guard->setAccessible( true );
    $digest_failure = $digest_guard->invoke( null, $tmp_dir . '/missing-derivative.jpg', 1, 1, 1, 0 );
    eforms_test_assert( empty( $digest_failure['ok'] ), 'A derivative whose digest cannot be computed must fail before its metadata reaches a manifest.' );
} else {
    echo "Staged derivative fixture checks skipped: Imagick does not support every required format.\n";
}

$apng_path = eforms_test_write_file( $tmp_dir, 'animated.png', eforms_test_animated_png( $fixtures['png']['bytes'] ) );
$apng = UploadPolicy::validate_item(
    array( 'tmp_name' => $apng_path, 'original_name' => 'animated.png', 'size' => filesize( $apng_path ), 'error' => UPLOAD_ERR_OK ),
    $field,
    $readiness
);
eforms_test_assert( $apng['ok'] === false && $apng['code'] === 'EFORMS_ERR_UPLOAD_TYPE', 'Staged validation should reject APNG before backend-specific single-frame decoding.' );

$animated_webp_bytes = base64_decode( 'UklGRsAAAABXRUJQVlA4WAoAAAACAAAAAQAAAQAAQU5JTQYAAAD/////AABBTk1GSAAAAAAAAAAAAAEAAAEAAGQAAAJWUDggMAAAANABAJ0BKgIAAgACADQloAJ0ugH4AAOwAP7wxAv/ILlhdcjX/yA/5Af8gP/48gAAAEFOTUZEAAAAAAAAAAAAAQAAAQAAZAAAAFZQOCAsAAAAlAEAnQEqAgACAAAANCWgAnS6AAOYAP75k2//kB//kB//kB//ID/iF3sgMAA=' );
$animated_webp_path = eforms_test_write_file( $tmp_dir, 'animated.webp', $animated_webp_bytes );
$animated_webp = UploadPolicy::validate_item(
    array( 'tmp_name' => $animated_webp_path, 'original_name' => 'animated.webp', 'size' => filesize( $animated_webp_path ), 'error' => UPLOAD_ERR_OK ),
    $field,
    $readiness
);
eforms_test_assert( $animated_webp['ok'] === false && $animated_webp['code'] === 'EFORMS_ERR_UPLOAD_TYPE', 'Staged validation should reject animated WebP before the primary-frame decode.' );

$gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );
$gif_path = eforms_test_write_file( $tmp_dir, 'pixel.gif', $gif );
$gif_item = array( 'tmp_name' => $gif_path, 'original_name' => 'pixel.gif', 'size' => filesize( $gif_path ), 'error' => UPLOAD_ERR_OK );
$staged_gif = UploadPolicy::validate_item( $gif_item, $field, $readiness );
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

$heic_ready = UploadPolicy::staged_host_readiness(
    array_merge(
        $readiness,
        array(
            'imagick_support' => function ( $mime ) {
                return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif' ), true );
            },
        )
    )
);
eforms_test_assert( ! empty( $heic_ready['ok'] ) && $heic_ready['backend'] === 'imagick', 'HEIC readiness should use the all-format Imagick path.' );
$heic_unavailable = UploadPolicy::staged_host_readiness(
    array_merge( $readiness, array( 'imagick_support' => function () { return false; } ) )
);
eforms_test_assert( empty( $heic_unavailable['ok'] ) && $heic_unavailable['reason'] === 'backend', 'HEIC readiness should fail closed without an Imagick HEIC delegate.' );

$jpeg_path = eforms_test_write_file( $tmp_dir, 'spoof.heic', $fixtures['jpeg']['bytes'] );
$spoofed_heic = UploadPolicy::validate_item(
    array( 'tmp_name' => $jpeg_path, 'original_name' => 'spoof.heic', 'size' => filesize( $jpeg_path ), 'error' => UPLOAD_ERR_OK ),
    $field,
    $readiness
);
eforms_test_assert( $spoofed_heic['ok'] === false, 'The image token should reject a HEIC extension whose bytes are JPEG.' );

$require_heic = getenv( 'EFORMS_REQUIRE_STAGED_HEIC' ) === '1';
$heic_bytes = eforms_test_fixture_bytes( 'staged-landscape.heic' );
eforms_test_assert( strlen( $heic_bytes ) === 2363, 'The genuine HEIC fixture byte length should remain stable.' );
eforms_test_assert( hash( 'sha256', $heic_bytes ) === '11ffd8eb6c8249ca473ea6856c27eefcdb866125a807e514dbd12e1c80d20a4d', 'The genuine HEIC fixture digest should remain stable.' );
$heic_probe_path = eforms_test_write_file( $tmp_dir, 'camera-probe.heic', $heic_bytes );
$heic_mime = UploadPolicy::detect_mime( $heic_probe_path );
$heic_backend = class_exists( 'Imagick' ) && ! empty( Imagick::queryFormats( 'HEIC' ) );

if ( $heic_backend && in_array( $heic_mime, array( 'image/heic', 'image/heif' ), true ) ) {
    foreach ( array( 'heic', 'heif' ) as $extension ) {
        $source = eforms_test_write_file( $tmp_dir, 'camera.' . $extension, $heic_bytes );
        $validated_heic = UploadPolicy::validate_item(
            array( 'tmp_name' => $source, 'original_name' => 'camera.' . $extension, 'size' => filesize( $source ), 'error' => UPLOAD_ERR_OK ),
            $field,
            $readiness
        );
        eforms_test_assert( ! empty( $validated_heic['ok'] ), 'A genuine ' . strtoupper( $extension ) . ' source should pass staged validation through Imagick.' );
        eforms_test_assert( $validated_heic['width'] === 120 && $validated_heic['height'] === 60, strtoupper( $extension ) . ' dimensions should be bounded through the Imagick probe.' );
        $output = $tmp_dir . '/' . $extension . '-derivatives';
        mkdir( $output, 0700 );
        $converted = UploadPolicy::create_staged_derivatives( $validated_heic, $output );
        eforms_test_assert( ! empty( $converted['ok'] ) && UploadPolicy::detect_mime( $output . '/master.jpg' ) === 'image/jpeg' && UploadPolicy::detect_mime( $output . '/preview.jpg' ) === 'image/jpeg', 'A genuine ' . strtoupper( $extension ) . ' source should produce both canonical JPEG derivatives.' );
    }
} elseif ( $require_heic ) {
    eforms_test_assert( false, 'Required staged HEIC checks need fileinfo HEIC detection and an Imagick HEIC decode delegate.' );
} else {
    echo "HEIC conversion checks skipped: local fileinfo or Imagick HEIC decode support is unavailable.\n";
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
    array( 'memory_limit' => '767M', 'execution_limit' => 0 ),
    array( 'memory_limit' => '768M', 'execution_limit' => 59 ),
    array( 'memory_limit' => -1, 'execution_limit' => 0, 'imagick_support' => function () { return false; } ),
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
