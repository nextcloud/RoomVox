import { createApp } from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import PersonalSettings from './views/PersonalSettings.vue'

const app = createApp(PersonalSettings)

// See main.js — components call t('roomvox', …) directly so the Nextcloud
// translation bot can extract the strings; these globals take the app id too.
app.config.globalProperties.$t = (app_, text, vars = {}) =>
    translate(app_, text, vars)

app.config.globalProperties.$n = (app_, singular, plural, count, vars = {}) =>
    translatePlural(app_, singular, plural, count, vars)

app.mount('#app-roomvox-personal')
