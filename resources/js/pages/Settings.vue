<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import OperationsPanel from '../components/OperationsPanel.vue'
import EmailDestinations from '../components/EmailDestinations.vue'
import PageHeading from '../components/PageHeading.vue'
import SettingsForm from '../components/SettingsForm.vue'
import HealthTable from '../components/HealthTable.vue'
import { api, bootstrap, notify } from '../composables/api'
import type { Check, PreferenceGroup } from '../types'
const route = useRoute()
const section = computed(() => String(route.params.section || 'general'))
const editable = computed(() => ['general', 'privacy', 'retention'].includes(section.value))
const choices = [{ key:'general', label:'General' },{ key:'privacy', label:'Capture & privacy' },{ key:'retention', label:'Retention' },{ key:'email', label:'Email notifications' },{ key:'access', label:'Access & assignments' },{ key:'health', label:'System health' },{key:'operations',label:'Operations & maintenance'}]
const checks = ref<Check[] | null>(null), checking = ref(false)
async function runChecks() { checking.value = true; try { checks.value = await api<Check[]>('/health/check', 'POST'); notify('Configuration checks refreshed. Unverified processing remains unverified.') } catch (e) { notify(e instanceof Error ? e.message : 'Checks failed.', true) } finally { checking.value = false } }
</script>
<template>
  <PageHeading title="Settings" subtitle="Editable preferences, deployment policy and operational evidence."><span v-if="bootstrap?.capabilities.manage" class="badge">Administrator</span></PageHeading>
  <div v-if="!bootstrap?.capabilities.manage" class="notice neutral"><p>Administrator access is required to inspect or change application configuration.</p></div>
  <div v-else-if="bootstrap.storage === 'ready'" class="settings-layout"><nav class="settings-nav" aria-label="Settings sections"><RouterLink v-for="choice in choices" :key="choice.key" :to="'/settings/' + choice.key" :class="{ active: section === choice.key }">{{ choice.label }}</RouterLink></nav><div class="settings-body">
    <SettingsForm v-if="editable" :key="section" :group="section as PreferenceGroup"/>
    <EmailDestinations v-else-if="section==='email'&&bootstrap.features.alerts"/><section v-else-if="section === 'access'"><h2>Access & assignments</h2><div class="panel spacing"><table><thead><tr><th>Capability</th><th>Managed by</th></tr></thead><tbody><tr><td>Viewer / Operator / Administrator</td><td>Host authorization</td></tr><tr><td>Login and user directory</td><td>Host application</td></tr><tr><td>Assignments</td><td>Optional host provider</td></tr></tbody></table></div><div class="notice neutral"><div><strong>{{bootstrap.assignment_configuration?.enabled?'Assignments are configured':'Assignments are disabled'}}</strong><p>{{bootstrap.assignment_configuration?.detail}} Configure the host adapter in deployment configuration; no user records are created by this package.</p></div></div><p class="help">The dashboard cannot grant itself access or change host roles.</p></section>
    <OperationsPanel v-else-if="section==='operations'&&bootstrap.features.operations"/>
    <section v-else-if="section === 'health'"><h2>System health</h2><div class="panel spacing"><HealthTable :checks="checks ?? bootstrap.health"/></div><div class="form-actions"><button class="button" :disabled="checking" @click="runChecks">{{ checking ? 'Checking…' : 'Refresh configuration checks' }}</button><RouterLink to="/setup" class="text-link">Open setup checklist</RouterLink></div><p class="help">A configured queue is not proof that a worker is running. This monitor shares the host application's failure domains.</p></section>
  </div></div>
</template>
