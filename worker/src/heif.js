/**
 * Bounded ISO-BMFF validation for the authoritative HEIC/HEIF artifact.
 *
 * Cloudflare Images proves that pixels decode, while this parser enforces the
 * same still-image container invariants as the local PHP HeifInspector.
 */

const ALLOWED_BRANDS = new Set(['heic', 'heix', 'heim', 'heis', 'mif1', 'miaf']);
const SEQUENCE_BRANDS = new Set(['hevc', 'hevx', 'hevm', 'hevs']);

export function inspectHeif(bytes, entryLimit) {
  if (!(bytes instanceof Uint8Array) || bytes.byteLength < 16
    || !Number.isSafeInteger(entryLimit) || entryLimit < 1) return null;
  try {
    return new HeifParser(bytes, entryLimit).inspect();
  } catch {
    return null;
  }
}

class HeifParser {
  constructor(bytes, limit) {
    this.bytes = bytes;
    this.view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    this.entryLimit = limit;
    this.remainingBoxes = limit;
  }

  inspect() {
    let offset = 0;
    let compatible = false;
    let meta = null;
    const mediaRanges = [];
    while (offset < this.bytes.byteLength) {
      const box = this.nextBox(offset, this.bytes.byteLength);
      if (box.type === 'ftyp') {
        if (compatible || !this.compatibleFtyp(box)) this.fail();
        compatible = true;
      } else if (box.type === 'meta') {
        if (meta) this.fail();
        meta = box;
      } else if (box.type === 'mdat') {
        if (box.data === box.end) this.fail();
        mediaRanges.push({ start: box.data, end: box.end });
      } else if (box.type === 'moov' || box.type === 'moof') {
        this.fail();
      }
      offset = box.end;
    }
    if (!compatible || !meta || mediaRanges.length === 0) this.fail();
    return this.inspectMeta(meta, mediaRanges);
  }

  compatibleFtyp(box) {
    const length = box.end - box.data;
    if (length < 8 || (length - 8) % 4 !== 0 || (length - 8) / 4 > this.entryLimit) return false;
    const brands = [this.ascii(box.data, 4)];
    for (let offset = box.data + 8; offset < box.end; offset += 4) brands.push(this.ascii(offset, 4));
    return !brands.some((brand) => SEQUENCE_BRANDS.has(brand))
      && brands.some((brand) => ALLOWED_BRANDS.has(brand));
  }

  inspectMeta(meta, mediaRanges) {
    if (meta.end - meta.data < 4 || this.byte(meta.data) !== 0) this.fail();
    let offset = meta.data + 4;
    let primaryId = null;
    let iinf = null;
    let iref = null;
    let idat = null;
    let iloc = null;
    let iprp = null;
    while (offset < meta.end) {
      const box = this.nextBox(offset, meta.end);
      if (box.type === 'pitm') {
        if (primaryId !== null) this.fail();
        primaryId = this.primaryItemId(box);
      } else if (box.type === 'iinf') {
        if (iinf) this.fail();
        iinf = box;
      } else if (box.type === 'iref') {
        if (iref) this.fail();
        iref = box;
      } else if (box.type === 'idat') {
        if (idat || box.data === box.end) this.fail();
        idat = box;
      } else if (box.type === 'iloc') {
        if (iloc) this.fail();
        iloc = box;
      } else if (box.type === 'iprp') {
        if (iprp) this.fail();
        iprp = box;
      }
      offset = box.end;
    }
    if (primaryId === null || !iinf || !iloc || !iprp) this.fail();
    const itemTypes = this.imageItemTypes(iinf);
    const locations = this.itemLocations(iloc);
    const dimensions = this.inspectProperties(iprp, primaryId);
    if (!itemTypes.has(primaryId) || !locations.has(primaryId) || !dimensions) this.fail();
    const itemType = itemTypes.get(primaryId);
    if (itemType === 'hvc1' || itemType === 'hev1') {
      if (!this.extentsInRanges(locations.get(primaryId), 0, mediaRanges)) this.fail();
      return dimensions;
    }
    if (itemType !== 'grid' || !iref || !idat
      || !this.validGridPrimary(primaryId, itemTypes, locations, iref, idat, mediaRanges, dimensions)) this.fail();
    return dimensions;
  }

  primaryItemId(box) {
    if (box.end - box.data < 6) this.fail();
    const version = this.byte(box.data);
    if (version === 0) return this.uint(box.data + 4, 2, box.end);
    if (version === 1) return this.uint(box.data + 4, 4, box.end);
    this.fail();
  }

  imageItemTypes(iinf) {
    if (iinf.end - iinf.data < 6) this.fail();
    const version = this.byte(iinf.data);
    let offset = iinf.data + 4;
    const width = version === 0 ? 2 : (version === 1 ? 4 : 0);
    if (!width) this.fail();
    const count = this.uint(offset, width, iinf.end);
    offset += width;
    if (count < 1 || count > this.entryLimit) this.fail();
    const types = new Map();
    for (let entry = 0; entry < count; entry += 1) {
      const box = this.nextBox(offset, iinf.end);
      if (box.type !== 'infe') this.fail();
      const definition = this.imageItemDefinition(box);
      if (types.has(definition.itemId)) this.fail();
      types.set(definition.itemId, definition.itemType);
      offset = box.end;
    }
    if (offset !== iinf.end) this.fail();
    return types;
  }

  imageItemDefinition(box) {
    const version = this.byte(box.data);
    if (version === 2) {
      if (box.end - box.data < 12 || this.uint(box.data + 6, 2, box.end) !== 0) this.fail();
      return { itemId: this.uint(box.data + 4, 2, box.end), itemType: this.ascii(box.data + 8, 4) };
    }
    if (version === 3) {
      if (box.end - box.data < 14 || this.uint(box.data + 8, 2, box.end) !== 0) this.fail();
      return { itemId: this.uint(box.data + 4, 4, box.end), itemType: this.ascii(box.data + 10, 4) };
    }
    this.fail();
  }

  itemLocations(iloc) {
    if (iloc.end - iloc.data < 8) this.fail();
    const version = this.byte(iloc.data);
    if (version > 2) this.fail();
    const sizes = this.byte(iloc.data + 4);
    const sizes2 = this.byte(iloc.data + 5);
    const offsetSize = sizes >> 4;
    const lengthSize = sizes & 0x0f;
    const baseOffsetSize = sizes2 >> 4;
    const indexSize = version === 0 ? 0 : sizes2 & 0x0f;
    if (offsetSize > 8 || lengthSize < 1 || lengthSize > 8 || baseOffsetSize > 8 || indexSize > 8) this.fail();
    let offset = iloc.data + 6;
    const countWidth = version < 2 ? 2 : 4;
    const itemCount = this.uint(offset, countWidth, iloc.end);
    offset += countWidth;
    if (itemCount < 1 || itemCount > this.entryLimit) this.fail();
    let remainingExtents = this.entryLimit;
    const locations = new Map();
    for (let item = 0; item < itemCount; item += 1) {
      const idWidth = version < 2 ? 2 : 4;
      const itemId = this.uint(offset, idWidth, iloc.end);
      offset += idWidth;
      if (locations.has(itemId)) this.fail();
      let constructionMethod = 0;
      if (version > 0) {
        constructionMethod = this.uint(offset, 2, iloc.end) & 0x0f;
        offset += 2;
      }
      const dataReference = this.uint(offset, 2, iloc.end);
      offset += 2;
      const baseOffset = this.uint(offset, baseOffsetSize, iloc.end);
      offset += baseOffsetSize;
      const extentCount = this.uint(offset, 2, iloc.end);
      offset += 2;
      if (extentCount > remainingExtents) this.fail();
      remainingExtents -= extentCount;
      const extents = [];
      for (let extent = 0; extent < extentCount; extent += 1) {
        if (version > 0 && indexSize > 0) {
          this.uint(offset, indexSize, iloc.end);
          offset += indexSize;
        }
        const extentOffset = this.uint(offset, offsetSize, iloc.end);
        offset += offsetSize;
        const extentLength = this.uint(offset, lengthSize, iloc.end);
        offset += lengthSize;
        extents.push({ offset: extentOffset, length: extentLength });
      }
      locations.set(itemId, { constructionMethod, dataReference, baseOffset, extents });
    }
    if (offset !== iloc.end) this.fail();
    return locations;
  }

  extentsInRanges(location, constructionMethod, ranges) {
    if (!location || location.constructionMethod !== constructionMethod
      || location.dataReference !== 0 || location.extents.length === 0) return false;
    return location.extents.every((extent) => {
      const start = location.baseOffset + extent.offset;
      const end = start + extent.length;
      return Number.isSafeInteger(start) && Number.isSafeInteger(end) && extent.length > 0
        && ranges.some((range) => start >= range.start && end <= range.end);
    });
  }

  validGridPrimary(primaryId, itemTypes, locations, iref, idat, mediaRanges, dimensions) {
    const location = locations.get(primaryId);
    if (!location || location.extents.length !== 1
      || !this.extentsInRanges(location, 1, [{ start: 0, end: idat.end - idat.data }])) return false;
    const extent = location.extents[0];
    const descriptorOffset = idat.data + location.baseOffset + extent.offset;
    const descriptor = this.gridDescriptor(descriptorOffset, extent.length);
    if (!descriptor || descriptor.width !== dimensions.codedWidth || descriptor.height !== dimensions.codedHeight) return false;
    const references = this.gridReferences(iref, primaryId);
    if (!references || references.length !== descriptor.tiles) return false;
    const seen = new Set();
    for (const itemId of references) {
      if (itemId === primaryId || seen.has(itemId) || !itemTypes.has(itemId) || !locations.has(itemId)
        || !['hvc1', 'hev1'].includes(itemTypes.get(itemId))
        || !this.extentsInRanges(locations.get(itemId), 0, mediaRanges)) return false;
      seen.add(itemId);
    }
    return true;
  }

  gridDescriptor(offset, length) {
    if (![8, 12].includes(length) || offset < 0 || offset + length > this.bytes.byteLength
      || this.byte(offset) !== 0 || (this.byte(offset + 1) & 0xfe) !== 0) return null;
    const wide = (this.byte(offset + 1) & 1) !== 0;
    if (length !== (wide ? 12 : 8)) return null;
    const tiles = (this.byte(offset + 2) + 1) * (this.byte(offset + 3) + 1);
    if (tiles < 1 || tiles > this.entryLimit) return null;
    const width = this.uint(offset + 4, wide ? 4 : 2, offset + length);
    const height = this.uint(offset + (wide ? 8 : 6), wide ? 4 : 2, offset + length);
    return width > 0 && height > 0 ? { width, height, tiles } : null;
  }

  gridReferences(iref, primaryId) {
    if (iref.end - iref.data < 4) this.fail();
    const version = this.byte(iref.data);
    if (version !== 0 && version !== 1) this.fail();
    let offset = iref.data + 4;
    let found = null;
    while (offset < iref.end) {
      const box = this.nextBox(offset, iref.end);
      if (box.type === 'dimg') {
        const references = this.dimgReferences(box, primaryId, version);
        if (references !== null) {
          if (found !== null) this.fail();
          found = references;
        }
      }
      offset = box.end;
    }
    if (offset !== iref.end) this.fail();
    return found;
  }

  dimgReferences(box, primaryId, version) {
    let offset = box.data;
    const width = version === 0 ? 2 : 4;
    const itemId = this.uint(offset, width, box.end);
    offset += width;
    const count = this.uint(offset, 2, box.end);
    offset += 2;
    if (count > this.entryLimit || count > Math.floor((box.end - offset) / width)) this.fail();
    if (itemId !== primaryId) return null;
    const references = [];
    for (let index = 0; index < count; index += 1) {
      references.push(this.uint(offset, width, box.end));
      offset += width;
    }
    if (offset !== box.end) this.fail();
    return references;
  }

  inspectProperties(iprp, primaryId) {
    let offset = iprp.data;
    let ipco = null;
    const ipmaBoxes = [];
    while (offset < iprp.end) {
      const box = this.nextBox(offset, iprp.end);
      if (box.type === 'ipco') {
        if (ipco) this.fail();
        ipco = box;
      } else if (box.type === 'ipma') ipmaBoxes.push(box);
      offset = box.end;
    }
    if (!ipco || ipmaBoxes.length === 0) this.fail();
    const properties = this.propertyTable(ipco);
    let associations = null;
    let remainingEntries = this.entryLimit;
    let remainingAssociations = this.entryLimit;
    for (const ipma of ipmaBoxes) {
      const result = this.primaryAssociations(
        ipma, primaryId, properties.count, remainingEntries, remainingAssociations,
      );
      remainingEntries = result.remainingEntries;
      remainingAssociations = result.remainingAssociations;
      if (result.found !== null) {
        if (associations !== null) this.fail();
        associations = result.found;
      }
    }
    if (associations === null) this.fail();
    let ispe = null;
    let clap = null;
    let rotation = null;
    for (const index of associations) {
      const property = properties.recognized.get(index);
      if (!property) continue;
      if (property.type === 'ispe') {
        if (ispe) this.fail();
        ispe = property;
      } else if (property.type === 'clap') {
        if (clap) this.fail();
        clap = property;
      } else if (property.type === 'irot') {
        if (rotation !== null) this.fail();
        rotation = property.rotation;
      }
    }
    if (!ispe) this.fail();
    let width = ispe.width;
    let height = ispe.height;
    if (clap) {
      if (clap.width > width || clap.height > height) this.fail();
      width = clap.width;
      height = clap.height;
    }
    if (rotation !== null && rotation % 2 === 1) [width, height] = [height, width];
    return { width, height, codedWidth: ispe.width, codedHeight: ispe.height };
  }

  propertyTable(ipco) {
    let offset = ipco.data;
    let index = 0;
    const recognized = new Map();
    while (offset < ipco.end) {
      const box = this.nextBox(offset, ipco.end);
      index += 1;
      if (box.type === 'ispe') {
        if (box.end - box.data < 12 || this.byte(box.data) !== 0) this.fail();
        const width = this.uint(box.data + 4, 4, box.end);
        const height = this.uint(box.data + 8, 4, box.end);
        if (width < 1 || height < 1) this.fail();
        recognized.set(index, { type: 'ispe', width, height });
      } else if (box.type === 'clap') {
        if (box.end - box.data < 32) this.fail();
        const width = this.positiveRationalCeiling(box.data);
        const height = this.positiveRationalCeiling(box.data + 8);
        recognized.set(index, { type: 'clap', width, height });
      } else if (box.type === 'irot') {
        if (box.end - box.data < 1 || (this.byte(box.data) & 0xfc) !== 0) this.fail();
        recognized.set(index, { type: 'irot', rotation: this.byte(box.data) & 0x03 });
      }
      offset = box.end;
    }
    return { count: index, recognized };
  }

  primaryAssociations(box, primaryId, propertyCount, remainingEntries, remainingAssociations) {
    if (box.end - box.data < 8) this.fail();
    const version = this.byte(box.data);
    if (version > 1) this.fail();
    const flags = (this.byte(box.data + 1) << 16) | (this.byte(box.data + 2) << 8) | this.byte(box.data + 3);
    const wide = (flags & 1) !== 0;
    const entries = this.uint(box.data + 4, 4, box.end);
    let offset = box.data + 8;
    const minimum = (version === 0 ? 2 : 4) + 1;
    if (entries > remainingEntries || entries > Math.floor((box.end - offset) / minimum)) this.fail();
    remainingEntries -= entries;
    let found = null;
    for (let entry = 0; entry < entries; entry += 1) {
      const idWidth = version === 0 ? 2 : 4;
      const itemId = this.uint(offset, idWidth, box.end);
      offset += idWidth;
      const associationCount = this.byte(offset);
      offset += 1;
      const entryBytes = wide ? 2 : 1;
      if (associationCount > remainingAssociations
        || associationCount > Math.floor((box.end - offset) / entryBytes)) this.fail();
      remainingAssociations -= associationCount;
      const one = [];
      for (let association = 0; association < associationCount; association += 1) {
        const value = this.uint(offset, entryBytes, box.end);
        const index = value & (wide ? 0x7fff : 0x7f);
        if (index > propertyCount) this.fail();
        if (index > 0) one.push(index);
        offset += entryBytes;
      }
      if (itemId === primaryId) {
        if (found !== null) this.fail();
        found = one;
      }
    }
    if (offset !== box.end) this.fail();
    return { found, remainingEntries, remainingAssociations };
  }

  nextBox(offset, end) {
    if (this.remainingBoxes < 1 || offset < 0 || end - offset < 8) this.fail();
    this.remainingBoxes -= 1;
    let size = this.uint(offset, 4, end);
    const type = this.ascii(offset + 4, 4);
    let headerBytes = 8;
    if (size === 1) {
      if (this.uint(offset + 8, 4, end) !== 0) this.fail();
      size = this.uint(offset + 12, 4, end);
      headerBytes = 16;
    } else if (size === 0) size = end - offset;
    if (size < headerBytes || size > end - offset) this.fail();
    return { type, data: offset + headerBytes, end: offset + size };
  }

  positiveRationalCeiling(offset) {
    const numerator = this.uint(offset, 4, this.bytes.byteLength);
    const denominator = this.uint(offset + 4, 4, this.bytes.byteLength);
    if (numerator < 1 || denominator < 1) this.fail();
    return Math.floor((numerator - 1) / denominator) + 1;
  }

  uint(offset, width, end) {
    if (!Number.isSafeInteger(offset) || !Number.isSafeInteger(width) || width < 0 || width > 8
      || offset < 0 || end - offset < width) this.fail();
    let value = 0;
    for (let index = 0; index < width; index += 1) {
      value = (value * 256) + this.byte(offset + index);
      if (!Number.isSafeInteger(value)) this.fail();
    }
    return value;
  }

  byte(offset) {
    if (!Number.isSafeInteger(offset) || offset < 0 || offset >= this.bytes.byteLength) this.fail();
    return this.view.getUint8(offset);
  }

  ascii(offset, length) {
    if (offset < 0 || length < 0 || offset + length > this.bytes.byteLength) this.fail();
    return String.fromCharCode(...this.bytes.subarray(offset, offset + length));
  }

  fail() {
    throw new Error('invalid_heif');
  }
}
