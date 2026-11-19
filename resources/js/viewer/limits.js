const MB = 1024 * 1024

export class LimitError extends Error {
    constructor(size) {
        super('limit exceeded')
        this.size = size
    }
}

export function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < MB) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / MB).toFixed(1)} MB`
}

export function memoryBudget(cfg) {
    let budget = cfg.maxEstimatedMemory
    const deviceGb = typeof navigator !== 'undefined' ? navigator.deviceMemory : undefined
    if (cfg.deviceMemoryFraction > 0 && deviceGb) {
        budget = Math.min(budget, deviceGb * 1024 * MB * cfg.deviceMemoryFraction)
    }
    return budget
}

export function checkBeforeLoad(ext, size, cfg) {
    if (!cfg.extensions.includes(ext)) {
        return { code: 'unsupported', params: { ext: `.${ext}` } }
    }
    if (size > cfg.maxPreviewSize) {
        return {
            code: 'tooLarge',
            params: { size: formatBytes(size), limit: formatBytes(cfg.maxPreviewSize) },
        }
    }
    const factor = cfg.memoryFactors[ext] ?? cfg.defaultMemoryFactor
    const estimate = size * factor
    const budget = memoryBudget(cfg)
    if (estimate > budget) {
        return {
            code: 'tooMuchMemory',
            params: { estimate: formatBytes(estimate), budget: formatBytes(budget) },
        }
    }
    return null
}

export function checkTriangles(count, cfg) {
    if (count > cfg.maxTriangles) {
        return {
            code: 'tooManyTriangles',
            params: { count: count.toLocaleString(), limit: cfg.maxTriangles.toLocaleString() },
        }
    }
    return null
}


export function stlTriangles(buffer) {
    if (buffer.byteLength < 84) return null
    const n = new DataView(buffer).getUint32(80, true)
    return 84 + n * 50 === buffer.byteLength ? n : null
}


export async function fetchBuffer(url, limit, signal, onProgress) {
    const res = await fetch(url, { signal, credentials: 'same-origin' })
    if (!res.ok) throw new Error(`HTTP ${res.status}`)

    const length = Number(res.headers.get('Content-Length')) || 0
    if (length > limit) throw new LimitError(length)

    const reader = res.body.getReader()
    const chunks = []
    let received = 0

    for (;;) {
        const { done, value } = await reader.read()
        if (done) break
        received += value.length
        if (received > limit) {
            await reader.cancel()
            throw new LimitError(received)
        }
        chunks.push(value)
        if (length && onProgress) onProgress(received / length)
    }

    const out = new Uint8Array(received)
    let offset = 0
    for (const c of chunks) {
        out.set(c, offset)
        offset += c.length
    }
    return out.buffer
}
