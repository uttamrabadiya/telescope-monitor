<script setup lang="ts">
import { ui } from "./ui"
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ChartBarIcon, ExclamationTriangleIcon, BellIcon, PuzzlePieceIcon, Cog6ToothIcon, ClipboardDocumentCheckIcon, Bars3Icon, XMarkIcon, ArrowPathIcon } from '@heroicons/vue/24/outline'
import { SparklesIcon } from '@heroicons/vue/24/solid'
import { bootstrap, feedback, loadBootstrap } from './composables/api'
const route = useRoute()
const failed = ref(''), loading = ref(true), menu = ref(false)
const links = [
  { to: '/overview', label: 'Overview', icon: ChartBarIcon },
  { to: '/errors', label: 'Errors', icon: ExclamationTriangleIcon },
  { to: '/alerts', label: 'Alerts', icon: BellIcon },
  { to: '/integrations', label: 'Integrations', icon: PuzzlePieceIcon },
  { to: '/settings', label: 'Settings', icon: Cog6ToothIcon },
]
async function reload() { loading.value = true; failed.value = ''; try { await loadBootstrap() } catch (e) { failed.value = e instanceof Error ? e.message : 'Monitoring could not be loaded.' } finally { loading.value = false } }
onMounted(reload)
</script>
<template>
  <div :class="ui('$all', '$div', 'tm-root')">
    <a :class="ui('$all', '$a', 'skip-link')" href="#tm-main">Skip to content</a>
    <div :class="ui('$all', '$div', 'app-container')">
    <header :class="ui('$all', '$header', 'app-header')">
      <div :class="ui('$all', '$div', 'brand')"><span :class="ui('$all', '$span', 'brand-mark')"><SparklesIcon :class="ui('$all', '$svg')" /></span><strong :class="ui('$all', '$strong')">Telescope Monitor</strong></div>
      <span v-if="bootstrap" :class="ui('$all', '$span', 'badge environment')">{{ bootstrap.environment }}</span>
      <div :class="ui('$all', '$div', 'header-end')"><button :class="ui('$all', '$button', 'icon-button mobile-menu')" :aria-expanded="menu" aria-label="Toggle navigation" @click="menu = !menu"><XMarkIcon :class="ui('$all', '$svg')" v-if="menu"/><Bars3Icon :class="ui('$all', '$svg')" v-else/></button></div>
    </header>
    <div :class="ui('$all', '$div', 'app-layout')">
      <aside :class="ui('$all', '$aside', 'sidebar', ({ 'menu-open': menu }))"  aria-label="Main navigation">
        <nav :class="ui('$all', '$nav')"><RouterLink v-for="link in links" :key="link.to" :to="link.to" :class="ui('$all', '$a', ({ active: route.path.startsWith(link.to) }))" @click="menu = false"><component :class="ui('$all', '$svg')" :is="link.icon"/><span :class="ui('$all', '$span')">{{ link.label }}</span></RouterLink></nav>
        <div :class="ui('$all', '$div', 'sidebar-bottom')"><RouterLink to="/setup" :class="ui('$all', '$a', ({ active: route.path === '/setup' }))" @click="menu = false"><ClipboardDocumentCheckIcon :class="ui('$all', '$svg')"/><span :class="ui('$all', '$span')">Setup checklist</span></RouterLink></div>
      </aside>
      <main :class="ui('$all', '$main')" id="tm-main" tabindex="-1">
        <div v-if="loading" :class="ui('$all', '$div', 'load-state')" role="status"><ArrowPathIcon :class="ui('$all', '$svg', 'spin')"/><h1 :class="ui('$all', '$h1')">Loading monitoring</h1></div>
        <div v-else-if="failed" :class="ui('$all', '$div', 'empty-state')"><ExclamationTriangleIcon :class="ui('$all', '$svg')"/><h1 :class="ui('$all', '$h1')">Monitoring could not be loaded</h1><p :class="ui('$all', '$p')">{{ failed }}</p><button :class="ui('$all', '$button', 'button primary')" @click="reload">Try again</button></div>
        <template v-else-if="bootstrap">
          <div v-if="feedback.text" :class="ui('$all', '$div', 'notice feedback-notice', ({ danger: feedback.error }))"  role="status"><span :class="ui('$all', '$span')">{{ feedback.text }}</span><button :class="ui('$all', '$button', 'icon-button')" aria-label="Dismiss message" @click="feedback.text = ''"><XMarkIcon :class="ui('$all', '$svg')"/></button></div>
          <div v-if="bootstrap.storage !== 'ready'" :class="ui('$all', '$div', 'notice warning')" role="alert"><div :class="ui('$all', '$div')"><strong :class="ui('$all', '$strong')">{{ bootstrap.storage === 'prototype_detected' ? 'Prototype database detected' : 'Package migrations are required' }}</strong><p :class="ui('$all', '$p')">{{ bootstrap.storage === 'prototype_detected' ? 'Back up and review conversion or use a fresh database. Existing tables have not been changed.' : 'Publish package migrations and run artisan telescope-monitor:migrate in the host application.' }}</p></div></div>
          <div v-if="bootstrap?.installation&&bootstrap.installation.state!=='active'&&!route.path.endsWith('/settings/operations')" :class="ui('$all', '$div', 'notice warning')"><div :class="ui('$all', '$div')"><strong :class="ui('$all', '$strong')">Monitoring maintenance · {{bootstrap.installation.state}}</strong><p :class="ui('$all', '$p')">New package claims are paused. Existing external calls may already have been accepted.</p><RouterLink to="/settings/operations" :class="ui('$all', '$a', 'text-link')">Inspect maintenance status and recovery guidance</RouterLink></div></div>
          <div :class="ui('$all', '$div', 'page-card')"><RouterView :class="ui('$all', '$RouterView')"/></div>
          <footer :class="ui('$all', '$footer', 'page-footer')"><span :class="ui('$all', '$span')">Telescope Monitor</span><span :class="ui('$all', '$span')">One application · host authentication</span></footer>
        </template>
      </main>
    </div>
    </div>
  </div>
</template>
