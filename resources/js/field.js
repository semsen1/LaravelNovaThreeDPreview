import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'

Nova.booting((app, store) => {
  app.component('index-three-d-preview-field', IndexField)
  app.component('detail-three-d-preview-field', DetailField)
  app.component('form-three-d-preview-field', FormField)
})
