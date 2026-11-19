<template>
    <div class="tdp" :style="{ height: config.height + 'px' }">
        <div v-show="status === 'ready'" ref="stage" class="tdp__stage"></div>

        <div v-if="status === 'ready' && config.showToolbar" class="tdp__toolbar">
            <button type="button" :title="t('resetView')" @click="resetView">⟲</button>
            <button type="button" :class="{ active: wireframe }" :title="t('wireframe')" @click="toggleWireframe">▦
            </button>
            <button type="button" :class="{ active: autoRotate }" :title="t('autoRotate')" @click="toggleRotate">↻
            </button>
        </div>

        <div v-if="status === 'ready' && info" class="tdp__info">
            {{ info.name }} · {{ info.size }} · {{ t('triangles') }}: {{ info.triangles }}
        </div>

        <div v-if="status !== 'ready'" class="tdp__overlay">
            <template v-if="status === 'loading'">
                <span>{{ t('loading') }}</span>
                <div v-if="progress" class="tdp__bar"><i :style="{ width: Math.round(progress * 100) + '%' }"></i></div>
            </template>
            <template v-else-if="status === 'manual'">
                <button type="button" class="tdp__btn" @click="loadNow">
                    {{ t('showPreview', { size: pendingSize }) }}
                </button>
            </template>
            <template v-else-if="status === 'blocked'">
                <span class="tdp__warn">{{ blockMessage }}</span>
            </template>
        </div>
    </div>
</template>

<script>
import {ThreeDScene} from '../viewer/ThreeDScene'
import {parsers, buildObject, countTriangles, disposeObject} from '../viewer/loaders'
import {
    LimitError, checkBeforeLoad, checkTriangles, stlTriangles, fetchBuffer, formatBytes,
} from '../viewer/limits'
import textsMixin from '../mixins/texts'


const BLOCK_CODES = [
    'unsupported', 'tooLarge', 'tooMuchMemory', 'tooManyTriangles', 'parseFailed', 'webgl', 'network',
]

export default {
    mixins: [textsMixin],

    props: {
        config: {type: Object, required: true},
        file: {default: null},
        endpoint: {type: String, default: null}, 
        texts: {type: Object, default: () => ({})},
    },

    data() {
        return {
            status: 'idle',
            reason: null,
            info: null,
            progress: 0,
            pendingSize: '',
            wireframe: this.config.wireframe,
            autoRotate: this.config.autoRotate,
        }
    },

    computed: {
        blockMessage() {
            if (!this.reason) return ''
            const code = BLOCK_CODES.includes(this.reason.code) ? this.reason.code : 'parseFailed'
            return this.t(code, this.reason.params || {})
        },
    },

    watch: {
        file() {
            this.start()
        },
        endpoint() {
            this.start()
        },
    },

    mounted() {
        this.start()
    },
    beforeUnmount() {
        this.teardown()
    },

    methods: {
        teardown() {
            this.token = (this.token || 0) + 1
            if (this.abortController) this.abortController.abort()
            this.abortController = null
            if (this.sceneInstance) this.sceneInstance.dispose()
            this.sceneInstance = null
        },

        block(code, params = {}) {
            this.status = 'blocked'
            this.reason = {code, params}
        },

        async start() {
            this.teardown()
            const token = this.token
            this.reason = null
            this.info = null
            this.progress = 0
            this.pending = null

            if (!this.file && !this.endpoint) {
                this.status = 'idle'
                return
            }

            try {
                this.status = 'loading'
                const src = await this.describeSource()
                if (token !== this.token) return
                if (src.blocked) return this.block(src.blocked.code, src.blocked.params)

                const blocked = checkBeforeLoad(src.ext, src.size, this.config)
                if (blocked) return this.block(blocked.code, blocked.params)

                this.pending = src
                if (src.size > this.config.manualLoadThreshold) {
                    this.pendingSize = formatBytes(src.size)
                    this.status = 'manual'
                    return
                }
                await this.load(token)
            } catch (e) {
                if (token === this.token) this.block('network')
            }
        },

        loadNow() {
            this.load(this.token)
        },

        async describeSource() {
            if (this.file) {
                const ext = this.file.name.split('.').pop().toLowerCase()
                return {ext, size: this.file.size, name: this.file.name, file: this.file}
            }
            const {data} = await Nova.request().get(this.endpoint)
            if (data.status === 'unsupported') {
                return {blocked: {code: 'unsupported', params: {ext: `.${data.extension}`}}}
            }
            if (data.status === 'too_large') {
                return {
                    blocked: {
                        code: 'tooLarge',
                        params: {size: formatBytes(data.size), limit: formatBytes(this.config.maxPreviewSize)},
                    },
                }
            }
            return {ext: data.extension, size: data.size, name: data.name, url: data.url}
        },

        async load(token) {
            const src = this.pending
            if (!src) return
            this.status = 'loading'
            let stage = 'network'

            try {
                this.abortController = new AbortController()
                const buffer = src.file
                    ? await src.file.arrayBuffer()
                    : await fetchBuffer(src.url, this.config.maxPreviewSize, this.abortController.signal, p => (this.progress = p))
                if (token !== this.token) return

                if (src.ext === 'stl') {
                    const tri = stlTriangles(buffer)
                    const blocked = tri !== null ? checkTriangles(tri, this.config) : null
                    if (blocked) return this.block(blocked.code, blocked.params)
                }

                stage = 'parseFailed'
                const parsed = await parsers[src.ext](buffer)
                if (token !== this.token) return
                const object = buildObject(src.ext, parsed, this.config, buffer)

                const triangles = countTriangles(object)
                const blocked = checkTriangles(triangles, this.config)
                if (blocked) {
                    disposeObject(object)
                    return this.block(blocked.code, blocked.params)
                }

                this.status = 'ready'
                await this.$nextTick()
                if (token !== this.token) return

                stage = 'webgl'
                this.sceneInstance = new ThreeDScene(this.$refs.stage, this.config)
                this.sceneInstance.setObject(object, this.config.zUpExtensions.includes(src.ext))
                this.info = {name: src.name, size: formatBytes(src.size), triangles: triangles.toLocaleString()}
            } catch (e) {
                if (e && e.name === 'AbortError') return
                if (e instanceof LimitError) {
                    return this.block('tooLarge', {
                        size: formatBytes(e.size), limit: formatBytes(this.config.maxPreviewSize),
                    })
                }
                if (token === this.token) this.block(stage)
            }
        },

        resetView() {
            this.sceneInstance && this.sceneInstance.resetView()
        },
        toggleWireframe() {
            this.wireframe = !this.wireframe
            this.sceneInstance && this.sceneInstance.setWireframe(this.wireframe)
        },
        toggleRotate() {
            this.autoRotate = !this.autoRotate
            this.sceneInstance && this.sceneInstance.setAutoRotate(this.autoRotate)
        },
    },
}
</script>
