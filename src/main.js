import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import App from './App.vue'

const app = createApp(App)

app.config.globalProperties.$t = (text, vars = {}) =>
    translate('roomvox', text, vars)

app.config.globalProperties.$n = (singular, plural, count, vars = {}) =>
    translatePlural('roomvox', singular, plural, count, vars)

app.mount('#app-roomvox')
