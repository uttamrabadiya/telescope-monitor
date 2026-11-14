<script setup lang="ts">
import { ui } from "../ui"
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import OperationsPanel from '../components/OperationsPanel.vue'
import EmailDestinations from '../components/EmailDestinations.vue'
import ScheduledReports from '../components/ScheduledReports.vue'
import PageHeading from '../components/PageHeading.vue'
import SettingsForm from '../components/SettingsForm.vue'
import HealthTable from '../components/HealthTable.vue'
import { api, bootstrap, notify } from '../composables/api'
import type { Check, PreferenceGroup } from '../types'
const route = useRoute()
const section = computed(() => String(route.params.section || 'general'))
const editable = computed(() => ['general', 'privacy', 'retention'].includes(section.value))
const choices = [{ key:'general', label:'General' },{ key:'privacy', label:'Capture & privacy' },{ key:'retention', label:'Retention' },{ key:'email', label:'Email notifications' },{ key:'reports', label:'Scheduled reports' },{ key:'access', label:'Access & assignments' },{ key:'health', label:'System health' },{key:'operations',label:'Operations & maintenance'}]
const checks = ref<Check[] | null>(null), checking = ref(false)
async function runChecks() { checking.value = true; try { checks.value = await api<Check[]>('/health/check', 'POST'); notify('Configuration checks refreshed. Unverified processing remains unverified.') } catch (e) { notify(e instanceof Error ? e.message : 'Checks failed.', true) } finally { checking.value = false } }
</script>
<template>
  <PageHeading :class="ui('$all', '$PageHeading')" title="Settings" subtitle="Editable preferences, deployment policy and operational evidence."><span v-if="bootstrap?.capabilities.manage" :class="ui('$all', '$span', 'badge')">Administrator</span></PageHeading>
  <div v-if="!bootstrap?.capabilities.manage" :class="ui('$all', '$div', 'notice neutral')"><p :class="ui('$all', '$p')">Administrator access is required to inspect or change application configuration.</p></div>
  <div v-else-if="bootstrap.storage === 'ready'" :class="ui('$all', '$div', 'settings-layout')"><nav :class="ui('$all', '$nav', 'settings-nav')" aria-label="Settings sections"><RouterLink v-for="choice in choices" :key="choice.key" :to="'/settings/' + choice.key" :class="ui('$all', '$a', ({ active: section === choice.key }))">{{ choice.label }}</RouterLink></nav><div :class="ui('$all', '$div', 'settings-body')">
    <SettingsForm :class="ui('$all', '$SettingsForm')" v-if="editable" :key="section" :group="section as PreferenceGroup"/>
    <ScheduledReports v-else-if="section === 'reports' && bootstrap.features.alerts"/>
    <EmailDestinations :class="ui('$all', '$EmailDestinations')" v-else-if="section==='email'&&bootstrap.features.alerts"/><section :class="ui('$all', '$section')" v-else-if="section === 'access'"><h2 :class="ui('$all', '$h2')">Access & assignments</h2><div :class="ui('$all', '$div', 'panel spacing')"><table :class="ui('$all', '$table')"><caption :class="ui('$all', '$caption', 'sr-only')">Access and assignment capabilities</caption><thead :class="ui('$all', '$thead')"><tr :class="ui('$all', '$tr')"><th scope="col" :class="ui('$all', '$th')">Capability</th><th scope="col" :class="ui('$all', '$th')">Managed by</th></tr></thead><tbody :class="ui('$all', '$tbody')"><tr :class="ui('$all', '$tr')"><td :class="ui('$all', '$td')">Viewer / Operator / Administrator</td><td :class="ui('$all', '$td')">Host authorization</td></tr><tr :class="ui('$all', '$tr')"><td :class="ui('$all', '$td')">Login and user directory</td><td :class="ui('$all', '$td')">Host application</td></tr><tr :class="ui('$all', '$tr')"><td :class="ui('$all', '$td')">Assignments</td><td :class="ui('$all', '$td')">Optional host provider</td></tr></tbody></table></div><div :class="ui('$all', '$div', 'notice neutral')"><div :class="ui('$all', '$div')"><strong :class="ui('$all', '$strong')">{{bootstrap.assignment_configuration?.enabled?'Assignments are configured':'Assignments are disabled'}}</strong><p :class="ui('$all', '$p')">{{bootstrap.assignment_configuration?.detail}} Configure the host adapter in deployment configuration; no user records are created by this package.</p></div></div><p :class="ui('$all', '$p', 'help')">The dashboard cannot grant itself access or change host roles.</p></section>
    <OperationsPanel :class="ui('$all', '$OperationsPanel')" v-else-if="section==='operations'&&bootstrap.features.operations"/>
    <section :class="ui('$all', '$section')" v-else-if="section === 'health'"><h2 :class="ui('$all', '$h2')">System health</h2><div :class="ui('$all', '$div', 'panel spacing')"><HealthTable :class="ui('$all', '$HealthTable')" :checks="checks ?? bootstrap.health"/></div><div :class="ui('$all', '$div', 'form-actions')"><button :class="ui('$all', '$button', 'button')" :disabled="checking" @click="runChecks">{{ checking ? 'Checking…' : 'Refresh configuration checks' }}</button><RouterLink to="/setup" :class="ui('$all', '$a', 'text-link')">Open setup checklist</RouterLink></div><p :class="ui('$all', '$p', 'help')">A configured queue is not proof that a worker is running. This monitor shares the host application's failure domains.</p></section>
  </div></div>
</template>
