<script setup lang="ts">
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
  <div class="tm-root">
    <a class="skip-link" href="#tm-main">Skip to content</a>
    <div class="app-container">
    <header class="app-header">
      <div class="brand"><span class="brand-mark"><SparklesIcon /></span><strong>Telescope Monitor</strong></div>
      <span v-if="bootstrap" class="badge environment">{{ bootstrap.environment }}</span>
      <div class="header-end"><button class="icon-button mobile-menu" :aria-expanded="menu" aria-label="Toggle navigation" @click="menu = !menu"><XMarkIcon v-if="menu"/><Bars3Icon v-else/></button></div>
    </header>
    <div class="app-layout">
      <aside class="sidebar" :class="{ 'menu-open': menu }" aria-label="Main navigation">
        <nav><RouterLink v-for="link in links" :key="link.to" :to="link.to" :class="{ active: route.path.startsWith(link.to) }" @click="menu = false"><component :is="link.icon"/><span>{{ link.label }}</span></RouterLink></nav>
        <div class="sidebar-bottom"><RouterLink to="/setup" :class="{ active: route.path === '/setup' }" @click="menu = false"><ClipboardDocumentCheckIcon/><span>Setup checklist</span></RouterLink></div>
      </aside>
      <main id="tm-main" tabindex="-1">
        <div v-if="loading" class="load-state" role="status"><ArrowPathIcon class="spin"/><h1>Loading monitoring</h1></div>
        <div v-else-if="failed" class="empty-state"><ExclamationTriangleIcon/><h1>Monitoring could not be loaded</h1><p>{{ failed }}</p><button class="button primary" @click="reload">Try again</button></div>
        <template v-else-if="bootstrap">
          <div v-if="feedback.text" class="notice feedback-notice" :class="{ danger: feedback.error }" role="status"><span>{{ feedback.text }}</span><button class="icon-button" aria-label="Dismiss message" @click="feedback.text = ''"><XMarkIcon/></button></div>
          <div v-if="bootstrap.storage !== 'ready'" class="notice warning" role="alert"><div><strong>{{ bootstrap.storage === 'prototype_detected' ? 'Prototype database detected' : 'Package migrations are required' }}</strong><p>{{ bootstrap.storage === 'prototype_detected' ? 'Back up and review conversion or use a fresh database. Existing tables have not been changed.' : 'Publish package migrations and run artisan exception-monitor:migrate in the host application.' }}</p></div></div>
          <div v-if="bootstrap?.installation&&bootstrap.installation.state!=='active'&&!route.path.endsWith('/settings/operations')" class="notice warning"><div><strong>Monitoring maintenance · {{bootstrap.installation.state}}</strong><p>New package claims are paused. Existing external calls may already have been accepted.</p><RouterLink to="/settings/operations" class="text-link">Inspect maintenance status and recovery guidance</RouterLink></div></div>
          <div class="page-card"><RouterView/></div>
          <footer class="page-footer"><span>Telescope Monitor</span><span>One application · host authentication</span></footer>
        </template>
      </main>
    </div>
    </div>
  </div>
</template>
