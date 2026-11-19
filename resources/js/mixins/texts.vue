<script>
export default {
    methods: {
        t(key, params = {}) {
            const template = (this.texts && this.texts[key]) || key
            return template.replace(/:(\w+)/g, (m, name) => (name in params ? params[name] : m))
        },
    },
}
</script>
