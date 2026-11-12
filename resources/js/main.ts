import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import '@fontsource/figtree/latin-400.css'
import '@fontsource/figtree/latin-500.css'
import '@fontsource/figtree/latin-600.css'
import '../css/app.css'
import App from './App.vue'
import Alerts from './pages/Alerts.vue'
import DeliveryHistory from './pages/DeliveryHistory.vue'
import DeliveryDetails from './pages/DeliveryDetails.vue'
import Errors from './pages/Errors.vue'
import ErrorDetails from './pages/ErrorDetails.vue'
import Overview from './pages/Overview.vue'
import Setup from './pages/Setup.vue'
import Settings from './pages/Settings.vue'
import Integrations from './pages/Integrations.vue'
import Unavailable from './pages/Unavailable.vue'
import { base } from './composables/api'
const router = createRouter({ history: createWebHistory(base + '/'), routes: [
  { path: '/', redirect: '/overview' },
  { path: '/setup', component: Setup },
  { path: '/overview', component: Overview },
  { path: '/errors', component: Errors },
  { path: '/errors/:issue', component: ErrorDetails },
  { path: '/alerts', component: Alerts },
  { path: '/alerts/history', component: DeliveryHistory },
  { path: '/alerts/history/:delivery', component: DeliveryDetails },
  { path: '/integrations', component: Integrations },
  { path: '/settings/:section?', component: Settings },
] })
createApp(App).use(router).mount('#telescope-monitor-app')
