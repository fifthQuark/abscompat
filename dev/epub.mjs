// A tiny EPUB for tests: a ZIP with stored (uncompressed) entries.
import { crc32 } from 'node:zlib'

export const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64')

export function zip(entries) {
  const local = [], central = []
  let offset = 0
  for (const [name, content] of Object.entries(entries)) {
    const n = Buffer.from(name), d = Buffer.from(content)
    const head = Buffer.alloc(26)
    head.writeUInt16LE(20, 0)
    head.writeUInt32LE(crc32(d), 10)
    head.writeUInt32LE(d.length, 14)
    head.writeUInt32LE(d.length, 18)
    head.writeUInt16LE(n.length, 22)
    local.push(Buffer.from('PK\x03\x04'), head, n, d)
    const tail = Buffer.alloc(14)
    tail.writeUInt32LE(offset, 10)
    central.push(Buffer.from('PK\x01\x02'), Buffer.from([20, 0]), head, tail, n)
    offset += 30 + n.length + d.length
  }
  const c = Buffer.concat(central), end = Buffer.alloc(18)
  end.writeUInt16LE(Object.keys(entries).length, 4)
  end.writeUInt16LE(Object.keys(entries).length, 6)
  end.writeUInt32LE(c.length, 8)
  end.writeUInt32LE(offset, 12)
  return Buffer.concat([...local, c, Buffer.from('PK\x05\x06'), end])
}

export function makeEpub({ title, author = '', series = '', seq = '', cover = false }) {
  return zip({
    mimetype: 'application/epub+zip',
    'META-INF/container.xml': '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>',
    'OEBPS/content.opf': `<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="id"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier id="id">urn:isbn:9780000000002</dc:identifier><dc:title>${title}</dc:title>${author ? `<dc:creator>${author}</dc:creator>` : ''}<dc:language>en</dc:language>${series ? `<meta name="calibre:series" content="${series}"/><meta name="calibre:series_index" content="${seq}"/>` : ''}</metadata><manifest><item id="c" href="c.xhtml" media-type="application/xhtml+xml"/>${cover ? '<item id="cov" href="images/cover.png" media-type="image/png" properties="cover-image"/>' : ''}</manifest><spine><itemref idref="c"/></spine></package>`,
    'OEBPS/c.xhtml': '<html xmlns="http://www.w3.org/1999/xhtml"><head><title>c</title></head><body><p>Text</p></body></html>',
    ...(cover ? { 'OEBPS/images/cover.png': PNG } : {}),
  })
}
