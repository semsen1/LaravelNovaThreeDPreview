<template>
    <DefaultField
        :field="currentField"
        :errors="errors"
        :show-help-text="showHelpText"
        :full-width-content="true"
    >
        <template #field>
            <div class="tdp-form">
                <div
                    class="tdp-drop"
                    :class="{ 'is-over': dragging }"
                    @click="$refs.input.click()"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <input ref="input" type="file" class="tdp-hidden" :accept="config.accept" @change="onPick" />
                    <strong v-if="file">{{ file.name }} ({{ fileSize }})</strong>
                    <strong v-else>{{ texts.dropzone }}</strong>
                    <small>{{ t('allowedFormats', { list: config.extensions.join(', ') }) }}</small>
                </div>

                <p v-if="localError" class="tdp-error">{{ localError }}</p>

                <div class="tdp-actions">
                    <button v-if="file" type="button" class="tdp__btn" @click="clearSelection">
                        {{ texts.clearSelection }}
                    </button>
                    <button v-if="canDeleteExisting" type="button" class="tdp__btn tdp__btn--danger" :disabled="removing" @click="removeExisting">
                        {{ texts.deleteFile }}
                    </button>
                </div>

                <ThreeDViewer
                    v-if="file || existingEndpoint"
                    :config="config"
                    :file="file"
                    :endpoint="file ? null : existingEndpoint"
                    :texts="texts"
                />
            </div>
        </template>
    </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova'
import ThreeDViewer from './ThreeDViewer.vue'
import { formatBytes } from '../viewer/limits'

export default {
    components: { ThreeDViewer },
    mixins: [FormField, HandlesValidationErrors],
    props: ['resourceName', 'resourceId', 'field'],

    data: () => ({ file: null, localError: null, dragging: false, existingRemoved: false, removing: false }),

    computed: {
        texts() {
            return this.field.texts || {}
        },
        config() { return this.currentField.threeD },
        fileSize() { return this.file ? formatBytes(this.file.size) : '' },
        hasExisting() { return !!this.currentField.value && !this.existingRemoved && !!this.resourceId },
        existingEndpoint() {
            if (!this.hasExisting) return null
            return `/nova-vendor/semsen1/three-d-preview-field/${encodeURIComponent(this.resourceName)}/${encodeURIComponent(this.resourceId)}/${encodeURIComponent(this.fieldAttribute)}/source`
        },
        canDeleteExisting() { return this.hasExisting && this.currentField.deletable !== false },
    },

    methods: {
        t(key, replace = {}) {
            let str = this.texts[key] ?? key
            Object.keys(replace)
                .sort((a, b) => b.length - a.length)
                .forEach(name => {
                    str = str.split(`:${name}`).join(String(replace[name]))
                })
            return str
        },

        onPick(e) {
            this.setFile(e.target.files[0])
            e.target.value = ''
        },
        onDrop(e) {
            this.dragging = false
            this.setFile(e.dataTransfer.files[0])
        },
        setFile(f) {
            if (!f) return
            this.localError = null
            const ext = f.name.split('.').pop().toLowerCase()

            if (!this.config.extensions.includes(ext)) {
                this.localError = this.t('typeNotAllowed', { ext })
                return
            }
            if (this.config.maxUploadSize && f.size > this.config.maxUploadSize) {
                this.localError = this.t('tooLargeUpload', {
                    size: formatBytes(f.size),
                    limit: formatBytes(this.config.maxUploadSize),
                })
                return
            }
            this.file = f
        },
        clearSelection() {
            this.file = null
            this.localError = null
        },
        async removeExisting() {
            if (!window.confirm(this.t('confirmDelete'))) return
            this.removing = true
            try {
                await Nova.request().delete(
                    `/nova-api/${this.resourceName}/${this.resourceId}/field/${this.fieldAttribute}`
                )
                this.existingRemoved = true
                Nova.success(this.t('deleted'))
            } catch (e) {
                Nova.error(this.t('deleteError'))
            } finally {
                this.removing = false
            }
        },
        fill(formData) {
            if (this.file) formData.append(this.fieldAttribute, this.file, this.file.name)
        },
    },
}
</script>
