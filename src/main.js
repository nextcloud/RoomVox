import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import App from './App.vue'

const app = createApp(App)

app.config.globalProperties.$t = (text, vars = {}) =>
    translate('roombooking', text, vars)

app.config.globalProperties.$n = (singular, plural, count, vars = {}) =>
    translatePlural('roombooking', singular, plural, count, vars)

app.mount('#app-roombooking')
