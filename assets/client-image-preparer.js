(function () {
    'use strict';

    var SOF_MARKERS = {
        0xc0: true, 0xc1: true, 0xc2: true, 0xc3: true,
        0xc5: true, 0xc6: true, 0xc7: true,
        0xc9: true, 0xca: true, 0xcb: true,
        0xcd: true, 0xce: true, 0xcf: true
    };

    function post(type, requestId, values) {
        var payload = values && typeof values === 'object' ? values : {};
        payload.type = type;
        payload.requestId = requestId;
        self.postMessage(payload);
    }

    function readExifOrientation(view, offset, length, maxEntries) {
        if (length < 4 || view.getUint32(offset, false) !== 0x45786966) {
            return { state: 'not_exif', orientation: null };
        }
        if (length < 6 || view.getUint16(offset + 4, false) !== 0 || length < 14) {
            return { state: 'malformed', orientation: null };
        }
        var tiff = offset + 6;
        var little = view.getUint16(tiff, false) === 0x4949;
        if (!little && view.getUint16(tiff, false) !== 0x4d4d) {
            return { state: 'malformed', orientation: null };
        }
        if (view.getUint16(tiff + 2, little) !== 42) {
            return { state: 'malformed', orientation: null };
        }
        var ifdOffset = view.getUint32(tiff + 4, little);
        var ifd = tiff + ifdOffset;
        var end = offset + length;
        if (ifdOffset < 8 || ifd < tiff || ifd + 2 > end) {
            return { state: 'malformed', orientation: null };
        }
        var entries = view.getUint16(ifd, little);
        if (entries > maxEntries || ifd + 2 + entries * 12 + 4 > end) {
            return { state: 'malformed', orientation: null };
        }
        var found = null;
        for (var index = 0; index < entries; index += 1) {
            var entry = ifd + 2 + index * 12;
            if (view.getUint16(entry, little) !== 0x0112) {
                continue;
            }
            if (view.getUint16(entry + 2, little) !== 3
                || view.getUint32(entry + 4, little) !== 1) {
                return { state: 'malformed', orientation: null };
            }
            var orientation = view.getUint16(entry + 8, little);
            if (orientation < 1 || orientation > 8 || (found !== null && found !== orientation)) {
                return { state: 'malformed', orientation: null };
            }
            found = orientation;
        }
        return { state: 'exif', orientation: found };
    }

    function inspectJpeg(buffer, recipe) {
        var view = new DataView(buffer);
        if (view.byteLength < 4 || view.getUint16(0, false) !== 0xffd8) {
            return null;
        }
        var offset = 2;
        var orientation = null;
        var dimensions = null;
        while (offset + 4 <= view.byteLength) {
            while (offset < view.byteLength && view.getUint8(offset) === 0xff) {
                offset += 1;
            }
            if (offset >= view.byteLength) {
                break;
            }
            var marker = view.getUint8(offset);
            offset += 1;
            if (marker === 0xd9) {
                return null;
            }
            if (marker === 0xda) {
                return dimensions ? {
                    width: dimensions.width,
                    height: dimensions.height,
                    orientation: orientation === null ? 1 : orientation
                } : null;
            }
            if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
                continue;
            }
            if (offset + 2 > view.byteLength) {
                break;
            }
            var segmentLength = view.getUint16(offset, false);
            if (segmentLength < 2 || offset + segmentLength > view.byteLength) {
                break;
            }
            var dataOffset = offset + 2;
            var dataLength = segmentLength - 2;
            if (marker === 0xe1) {
                var candidateOrientation = readExifOrientation(view, dataOffset, dataLength, recipe.exifMaxEntries);
                if (candidateOrientation.state === 'malformed'
                    || (candidateOrientation.orientation !== null
                        && orientation !== null
                        && orientation !== candidateOrientation.orientation)) {
                    return null;
                }
                if (candidateOrientation.orientation !== null) {
                    orientation = candidateOrientation.orientation;
                }
            }
            if (SOF_MARKERS[marker]) {
                if (dataLength < 6) {
                    return null;
                }
                var candidateDimensions = {
                    width: view.getUint16(dataOffset + 3, false),
                    height: view.getUint16(dataOffset + 1, false)
                };
                if (dimensions && (dimensions.width !== candidateDimensions.width
                    || dimensions.height !== candidateDimensions.height)) {
                    return null;
                }
                dimensions = candidateDimensions;
            }
            offset += segmentLength;
        }
        return null;
    }

    function validRecipe(recipe) {
        var keys = [
            'version', 'slots', 'jpegTriggerBytes', 'jpegTriggerEdge',
            'inputMaxBytes', 'inputMaxPixels', 'inputMaxEdge', 'outputMaxEdge',
            'jpegQuality', 'minimumSavingsPercent', 'timeoutMs', 'headerScanBytes',
            'exifMaxEntries'
        ];
        return recipe && keys.every(function (key) {
            return Number.isInteger(recipe[key]) && recipe[key] > 0;
        }) && recipe.slots === 1
            && recipe.jpegQuality < 100
            && recipe.minimumSavingsPercent < 100;
    }

    function release(bitmap, canvas) {
        if (bitmap && typeof bitmap.close === 'function') {
            bitmap.close();
        }
        if (canvas) {
            canvas.width = 0;
            canvas.height = 0;
        }
    }

    function orientContext(context, orientation, width, height) {
        if (orientation === 5) {
            context.setTransform(0, 1, 1, 0, 0, 0);
        } else if (orientation === 6) {
            context.setTransform(0, 1, -1, 0, width, 0);
        } else if (orientation === 7) {
            context.setTransform(0, -1, -1, 0, width, height);
        } else if (orientation === 8) {
            context.setTransform(0, -1, 1, 0, 0, height);
        }
    }

    function probe(requestId, recipe) {
        if (!validRecipe(recipe)
            || typeof OffscreenCanvas !== 'function'
            || typeof createImageBitmap !== 'function') {
            post('use_source', requestId);
            return;
        }
        var canvas;
        try {
            canvas = new OffscreenCanvas(1, 1);
            var context = canvas.getContext('2d');
            if (!context || typeof canvas.convertToBlob !== 'function') {
                post('use_source', requestId);
                return;
            }
            context.fillStyle = '#000';
            context.fillRect(0, 0, 1, 1);
            canvas.convertToBlob({ type: 'image/jpeg', quality: recipe.jpegQuality / 100 }).then(function (blob) {
                release(null, canvas);
                if (blob && blob.type === 'image/jpeg' && blob.size > 0) {
                    post('ready', requestId);
                } else {
                    post('use_source', requestId);
                }
            }).catch(function () {
                release(null, canvas);
                post('use_source', requestId);
            });
        } catch (error) {
            release(null, canvas);
            post('use_source', requestId);
        }
    }

    function prepare(requestId, file, recipe, maxOutputBytes) {
        if (!validRecipe(recipe)
            || !(file instanceof Blob)
            || file.type !== 'image/jpeg'
            || !Number.isInteger(maxOutputBytes)
            || maxOutputBytes < 1
            || file.size < 1
            || file.size > recipe.inputMaxBytes) {
            post('use_source', requestId);
            return;
        }
        file.slice(0, Math.min(file.size, recipe.headerScanBytes)).arrayBuffer().then(function (header) {
            var inspected = inspectJpeg(header, recipe);
            if (!inspected) {
                throw new Error('unsafe_input');
            }
            if (inspected.width < 1 || inspected.height < 1
                || inspected.width > recipe.inputMaxEdge
                || inspected.height > recipe.inputMaxEdge
                || inspected.width * inspected.height > recipe.inputMaxPixels) {
                post('reject_source', requestId);
                return null;
            }
            var shouldPrepare = file.size >= recipe.jpegTriggerBytes
                || Math.max(inspected.width, inspected.height) > recipe.jpegTriggerEdge
                || file.size > maxOutputBytes;
            if (!shouldPrepare) {
                post('use_source', requestId);
                return null;
            }
            if ((inspected.orientation >= 2 && inspected.orientation <= 4)
                || (inspected.orientation >= 5 && inspected.orientation <= 8 && inspected.width === inspected.height)) {
                post('use_source', requestId);
                return null;
            }
            post('preparing', requestId);
            return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bitmap) {
                var swapsAxes = inspected.orientation >= 5 && inspected.orientation <= 8;
                var browserOriented = swapsAxes
                    && bitmap.width === inspected.height
                    && bitmap.height === inspected.width;
                var rawOrientation = bitmap.width === inspected.width && bitmap.height === inspected.height;
                if (!browserOriented && !rawOrientation) {
                    release(bitmap, null);
                    throw new Error('orientation');
                }
                var displayWidth = swapsAxes ? inspected.height : inspected.width;
                var displayHeight = swapsAxes ? inspected.width : inspected.height;
                var scale = Math.min(1, recipe.outputMaxEdge / Math.max(displayWidth, displayHeight));
                var sourceWidth = Math.max(1, Math.round(bitmap.width * scale));
                var sourceHeight = Math.max(1, Math.round(bitmap.height * scale));
                var width = browserOriented ? sourceWidth : (swapsAxes ? sourceHeight : sourceWidth);
                var height = browserOriented ? sourceHeight : (swapsAxes ? sourceWidth : sourceHeight);
                var canvas = new OffscreenCanvas(width, height);
                var context = canvas.getContext('2d', { alpha: false });
                if (!context) {
                    release(bitmap, canvas);
                    throw new Error('context');
                }
                if (!browserOriented) {
                    orientContext(context, inspected.orientation, width, height);
                }
                context.drawImage(bitmap, 0, 0, sourceWidth, sourceHeight);
                return canvas.convertToBlob({
                    type: 'image/jpeg',
                    quality: recipe.jpegQuality / 100
                }).then(function (blob) {
                    release(bitmap, canvas);
                    var maximumSavedSize = Math.floor(file.size * (100 - recipe.minimumSavingsPercent) / 100);
                    if (!blob || blob.type !== 'image/jpeg' || blob.size < 1
                        || blob.size > maxOutputBytes || blob.size > maximumSavedSize) {
                        post('use_source', requestId);
                        return;
                    }
                    post('prepared', requestId, {
                        blob: blob
                    });
                }, function () {
                    release(bitmap, canvas);
                    throw new Error('encode');
                });
            });
        }).catch(function () {
            post('use_source', requestId);
        });
    }

    self.addEventListener('message', function (event) {
        var message = event && event.data;
        if (!message || typeof message.requestId !== 'string') {
            return;
        }
        if (message.type === 'probe') {
            probe(message.requestId, message.recipe);
            return;
        }
        if (message.type === 'prepare') {
            prepare(message.requestId, message.file, message.recipe, message.maxOutputBytes);
        }
    });
}());
