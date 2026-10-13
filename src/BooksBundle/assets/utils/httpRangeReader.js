/**
 * Creates a Blob/File-like random-access reader for remote EPUB files using HTTP Range requests.
 * Buffers data in 256 KB chunks with a local LRU cache to eliminate micro-requests on images and metadata.
 *
 * @param {string} url The remote endpoint URL (e.g. .../books/676/book.epub)
 * @param {number} chunkSize Chunk size in bytes (default 256 KB)
 * @returns {Promise<File>} Compatible file-like object for foliate-js
 */
export async function makeHttpRangeReader(url, chunkSize = 256 * 1024) {
    // 1. Fetch headers to retrieve size and check range support
    const headResponse = await fetch(url, { method: 'HEAD' });
    if (!headResponse.ok) {
        throw new Error(`Failed to fetch file headers: HTTP ${headResponse.status}`);
    }

    const contentLength = headResponse.headers.get('Content-Length');
    if (!contentLength) {
        throw new Error("Server did not return Content-Length header");
    }

    const totalSize = parseInt(contentLength, 10);
    const contentType = headResponse.headers.get('Content-Type') || 'application/epub+zip';
    const rawFileName = url.split('/').pop()?.split('?')[0] || 'book.epub';
    const fileName = rawFileName.endsWith('.epub') ? rawFileName : `${rawFileName}.epub`;

    // In-memory cache for downloaded chunks (max 32 chunks = ~8 MB)
    const chunkCache = new Map();

    async function fetchChunk(chunkIndex) {
        if (chunkCache.has(chunkIndex)) {
            return chunkCache.get(chunkIndex);
        }

        const start = chunkIndex * chunkSize;
        const end = Math.min(start + chunkSize, totalSize) - 1;

        const res = await fetch(url, {
            headers: {
                'Range': `bytes=${start}-${end}`
            }
        });

        if (res.status !== 206 && res.status !== 200) {
            throw new Error(`Range request failed: HTTP ${res.status}`);
        }

        const buffer = await res.arrayBuffer();
        const uint8 = new Uint8Array(buffer);

        // Simple LRU eviction to prevent memory growth
        if (chunkCache.size >= 32) {
            const oldestKey = chunkCache.keys().next().value;
            chunkCache.delete(oldestKey);
        }

        chunkCache.set(chunkIndex, uint8);
        return uint8;
    }

    return {
        name: fileName,
        type: contentType,
        size: totalSize,
        slice(start = 0, end = totalSize) {
            const sliceStart = Math.max(0, start);
            const sliceEnd = Math.min(totalSize, end);
            const sliceSize = Math.max(0, sliceEnd - sliceStart);

            return {
                name: fileName,
                type: contentType,
                size: sliceSize,
                async arrayBuffer() {
                    if (sliceSize === 0) {
                        return new ArrayBuffer(0);
                    }

                    const startChunk = Math.floor(sliceStart / chunkSize);
                    const endChunk = Math.floor((sliceEnd - 1) / chunkSize);

                    // Case A: The slice falls completely inside a single 256 KB chunk
                    if (startChunk === endChunk) {
                        const chunkData = await fetchChunk(startChunk);
                        const offsetStart = sliceStart - (startChunk * chunkSize);
                        const offsetEnd = offsetStart + sliceSize;
                        return chunkData.slice(offsetStart, offsetEnd).buffer;
                    }

                    // Case B: The slice spans multiple chunks
                    const output = new Uint8Array(sliceSize);
                    let written = 0;

                    for (let c = startChunk; c <= endChunk; c++) {
                        const chunkData = await fetchChunk(c);
                        const chunkStartByte = c * chunkSize;
                        const chunkEndByte = Math.min(chunkStartByte + chunkSize, totalSize);

                        const readStart = Math.max(sliceStart, chunkStartByte);
                        const readEnd = Math.min(sliceEnd, chunkEndByte);
                        const readLen = readEnd - readStart;

                        const chunkOffset = readStart - chunkStartByte;
                        output.set(chunkData.subarray(chunkOffset, chunkOffset + readLen), written);
                        written += readLen;
                    }

                    return output.buffer;
                }
            };
        }
    };
}
