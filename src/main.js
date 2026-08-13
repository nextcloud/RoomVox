import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import App from './App.vue'

const app = createApp(App)

// Components call t('roomvox', …) / n('roomvox', …) directly — the only form
// the Nextcloud translation bot extracts. These globals stay for third-party
// components that still expect $t/$n, and take the app id explicitly so no
// call site can hide it from the extractor.
app.config.globalProperties.$t = (app_, text, vars = {}) =>
    translate(app_, text, vars)

app.config.globalProperties.$n = (app_, singular, plural, count, vars = {}) =>
    translatePlural(app_, singular, plural, count, vars)

app.mount('#app-roomvox')
