<template>
    <span class="whitespace-nowrap">{{ fileName }}</span>
</template>

<script>
export default {
    props: ['resourceName', 'field'],
    computed: {
        fileName() {
            return this.field.value ? String(this.field.value).split('/').pop() : '—'
        },
    },
}
</script>
