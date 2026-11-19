<template>
    <PanelItem :index="index" :field="field">
        <template #value>
            <div v-if="field.value">
                <ThreeDViewer :config="field.threeD" :endpoint="endpoint" :texts="field.texts || {}" />
                <p class="tdp-meta">
                    {{ fileName }}
                    <a v-if="field.downloadable" :href="downloadUrl" class="tdp-link">{{ __('Download') }}</a>
                </p>
            </div>
            <span v-else>—</span>
        </template>
    </PanelItem>
</template>

<script>
import ThreeDViewer from './ThreeDViewer.vue'

export default {
    components: { ThreeDViewer },
    props: ['index', 'resource', 'resourceName', 'resourceId', 'field'],
    computed: {
        fileName() { return String(this.field.value).split('/').pop() },
        endpoint() {
            return `/nova-vendor/semsen1/three-d-preview-field/${encodeURIComponent(this.resourceName)}/${encodeURIComponent(this.resourceId)}/${encodeURIComponent(this.field.attribute)}/source`
        },
        downloadUrl() {
            return `/nova-api/${this.resourceName}/${this.resourceId}/download/${this.field.attribute}`
        },
    },
}
</script>
