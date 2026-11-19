import * as THREE from 'three'
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js'
import { OBJLoader } from 'three/examples/jsm/loaders/OBJLoader.js'
import { PLYLoader } from 'three/examples/jsm/loaders/PLYLoader.js'
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js'
import { ThreeMFLoader } from 'three/examples/jsm/loaders/3MFLoader.js'
import { FBXLoader } from 'three/examples/jsm/loaders/FBXLoader.js'

const KEEP_MATERIALS = ['glb', 'gltf', '3mf', 'fbx']

const parseGltf = buffer =>
    new Promise((resolve, reject) =>
        new GLTFLoader().parse(buffer, '', gltf => resolve(gltf.scene), reject)
    )

export const parsers = {
    stl: async buf => new STLLoader().parse(buf),
    obj: async buf => new OBJLoader().parse(new TextDecoder().decode(buf)),
    ply: async buf => new PLYLoader().parse(buf),
    glb: parseGltf,
    gltf: parseGltf,
    '3mf': async buf => new ThreeMFLoader().parse(buf),
    fbx: async buf => new FBXLoader().parse(buf, ''),
}

function makeMaterial(cfg, vertexColors) {
    return new THREE.MeshStandardMaterial({
        color: vertexColors ? 0xffffff : cfg.modelColor,
        metalness: 0.1,
        roughness: 0.65,
        side: THREE.DoubleSide,
        wireframe: cfg.wireframe,
        vertexColors,
    })
}

function plyHasFaces(buffer) {
    const head = new TextDecoder().decode(new Uint8Array(buffer, 0, Math.min(buffer.byteLength, 4096)))
    return /element\s+face\s+[1-9]/i.test(head)
}

export function buildObject(ext, parsed, cfg, buffer) {
    if (parsed.isBufferGeometry) {
        const hasColor = !!parsed.getAttribute('color')

        if (ext === 'ply' && !plyHasFaces(buffer)) {
            parsed.computeBoundingBox()
            const diag = parsed.boundingBox.getSize(new THREE.Vector3()).length() || 1
            return new THREE.Points(
                parsed,
                new THREE.PointsMaterial({
                    size: diag / 300,
                    color: hasColor ? 0xffffff : cfg.modelColor,
                    vertexColors: hasColor,
                })
            )
        }

        if (!parsed.getAttribute('normal')) parsed.computeVertexNormals()
        return new THREE.Mesh(parsed, makeMaterial(cfg, hasColor))
    }

    if (!KEEP_MATERIALS.includes(ext)) {
        parsed.traverse(o => {
            if (o.isMesh) {
                if (!o.geometry.getAttribute('normal')) o.geometry.computeVertexNormals()
                o.material = makeMaterial(cfg, false)
            }
        })
    }
    return parsed
}

export function countTriangles(object) {
    let n = 0
    object.traverse(o => {
        if (o.isMesh && o.geometry) {
            const g = o.geometry
            n += (g.index ? g.index.count : g.attributes.position.count) / 3
        }
    })
    return Math.floor(n)
}

export function disposeObject(object) {
    object.traverse(o => {
        if (o.geometry) o.geometry.dispose()
        const materials = Array.isArray(o.material) ? o.material : o.material ? [o.material] : []
        materials.forEach(m => {
            Object.values(m).forEach(v => v && v.isTexture && v.dispose())
            m.dispose()
        })
    })
}
