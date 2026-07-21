# Image fixture provenance

`staged-landscape.heic.b64` is a self-generated HEIC encoding of
`staged-landscape.png.b64`, created for decode-only integration tests.

- Encoder: Ubuntu `libheif-examples` 1.17.6 with the x265 plugin
- Command policy: `heif-enc -q 80`
- Decoded dimensions: 120 x 60
- Encoded bytes: 2363
- SHA-256: `11ffd8eb6c8249ca473ea6856c27eefcdb866125a807e514dbd12e1c80d20a4d`

The fixture is stored as base64 so the repository can validate its exact bytes
before writing it to a temporary test path. Release tests require `fileinfo`
to identify it as `image/heic` and Imagick to decode it.
