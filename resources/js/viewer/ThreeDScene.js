import * as THREE from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import { disposeObject } from './loaders'

export class ThreeDScene {
  constructor(container, cfg) {
    this.cfg = cfg
    this.container = container
    this.dirty = true
    this.visible = true

    this.scene = new THREE.Scene()
    this.scene.background = new THREE.Color(cfg.backgroundColor)

    this.camera = new THREE.PerspectiveCamera(cfg.cameraFov, 1, 0.1, 1000)
    this.scene.add(this.camera)

    // Свет привязан к камере: модель всегда освещена со стороны зрителя.
    this.camera.add(new THREE.AmbientLight(0xffffff, 0.7))
    const key = new THREE.DirectionalLight(0xffffff, 1.4)
    key.position.set(1, 1.5, 2)
    this.camera.add(key)

    this.renderer = new THREE.WebGLRenderer({ antialias: cfg.antialias })
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, cfg.maxPixelRatio))
    container.appendChild(this.renderer.domElement)

    this.controls = new OrbitControls(this.camera, this.renderer.domElement)
    this.controls.enableZoom = cfg.enableZoom
    this.controls.enablePan = cfg.enablePan
    this.controls.enableDamping = cfg.enableDamping
    this.controls.autoRotate = cfg.autoRotate
    this.controls.autoRotateSpeed = cfg.autoRotateSpeed
    this.controls.addEventListener('change', () => (this.dirty = true))

    this.resizeObserver = new ResizeObserver(() => this.resize())
    this.resizeObserver.observe(container)

    this.intersection = new IntersectionObserver(entries => {
      this.visible = entries[0].isIntersecting
      if (this.visible) this.dirty = true
    })
    this.intersection.observe(container)

    this.loop = this.loop.bind(this)
    this.raf = requestAnimationFrame(this.loop)
    this.resize()
  }

  setObject(object, zUp) {
    this.clearObject()

    const pivot = new THREE.Group()
    pivot.add(object)
    if (zUp) pivot.rotation.x = -Math.PI / 2
    pivot.updateMatrixWorld(true)

    const box = new THREE.Box3().setFromObject(pivot)
    pivot.position.sub(box.getCenter(new THREE.Vector3()))
    pivot.updateMatrixWorld(true)
    box.setFromObject(pivot)

    const size = box.getSize(new THREE.Vector3())
    this.maxDim = Math.max(size.x, size.y, size.z) || 1
    this.floorY = box.min.y
    this.pivot = pivot
    this.scene.add(pivot)

    if (this.cfg.showGrid) {
      const grid = new THREE.GridHelper(this.maxDim * 2.5, 20, 0x94a3b8, 0xcbd5e1)
      grid.position.y = this.floorY
      grid.material.transparent = true
      grid.material.opacity = 0.6
      this.grid = grid
      this.scene.add(grid)
    }
    if (this.cfg.showAxes) {
      const axes = new THREE.AxesHelper(this.maxDim * 0.7)
      axes.position.y = this.floorY
      this.axes = axes
      this.scene.add(axes)
    }

    this.resetView()
  }

  resetView() {
    const dist = this.maxDim * 1.9
    this.camera.near = dist / 100
    this.camera.far = dist * 100
    this.camera.updateProjectionMatrix()
    this.camera.position.set(1, 0.8, 1.2).normalize().multiplyScalar(dist)
    this.controls.target.set(0, 0, 0)
    this.controls.minDistance = this.maxDim * 0.1
    this.controls.maxDistance = dist * 6
    this.controls.update()
    this.dirty = true
  }

  setWireframe(value) {
    this.pivot?.traverse(o => {
      const list = Array.isArray(o.material) ? o.material : o.material ? [o.material] : []
      list.forEach(m => {
        if ('wireframe' in m && !o.isPoints) m.wireframe = value
      })
    })
    this.dirty = true
  }

  setAutoRotate(value) {
    this.controls.autoRotate = value
    this.dirty = true
  }

  resize() {
    const w = this.container.clientWidth
    const h = this.container.clientHeight
    if (!w || !h) return
    this.renderer.setSize(w, h, false)
    this.camera.aspect = w / h
    this.camera.updateProjectionMatrix()
    this.dirty = true
  }

  loop() {
    this.raf = requestAnimationFrame(this.loop)
    if (!this.visible || document.hidden) return
    this.controls.update()
    if (this.dirty) {
      this.renderer.render(this.scene, this.camera)
      this.dirty = false
    }
  }

  clearObject() {
    ;[this.grid, this.axes].forEach(h => {
      if (h) {
        this.scene.remove(h)
        h.geometry.dispose()
        h.material.dispose()
      }
    })
    this.grid = this.axes = null
    if (this.pivot) {
      this.scene.remove(this.pivot)
      disposeObject(this.pivot)
      this.pivot = null
    }
  }

  dispose() {
    cancelAnimationFrame(this.raf)
    this.resizeObserver.disconnect()
    this.intersection.disconnect()
    this.controls.dispose()
    this.clearObject()
    this.renderer.dispose()
    this.renderer.forceContextLoss()
    this.renderer.domElement.remove()
  }
}
