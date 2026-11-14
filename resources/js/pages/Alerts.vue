<script setup lang="ts">
import { ui } from "../ui"
import { computed, ref, onMounted, onUnmounted, watch, nextTick } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { ArrowLeftIcon, ArrowRightIcon, ArrowPathIcon, BellIcon, BoltIcon, ChartBarIcon, CheckIcon, PlusIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import PageHeading from '../components/PageHeading.vue'
import CheckboxDropdown from '../components/CheckboxDropdown.vue'
import RuleActionsMenu from '../components/RuleActionsMenu.vue'
import { api, bootstrap, notify } from '../composables/api'
import { useCollaborationAction } from '../composables/collaboration'
import type { AlertConfiguration, AlertRuleDefinition, AlertRuleItem } from '../types'

const config = ref<AlertConfiguration | null>(null), draft = ref<AlertRuleDefinition | null>(null)
const editing = ref<AlertRuleItem | null>(null), baseline = ref(''), error = ref('')
const loading = ref(false), saving = ref(false), previewing = ref(false), step = ref(1)
const preview = ref<{ text: string; detail: string } | null>(null), example = ref('')
const search = ref(''), status = ref('all'), form = ref<HTMLFormElement | null>(null), stepHeading = ref<HTMLElement | null>(null)
const perform = useCollaborationAction(), steps = ['Events', 'Conditions', 'Destinations', 'Review']
const primary = [
  { key: 'error.created', title: 'New error', description: 'The first occurrence of a new error group.', icon: BoltIcon },
  { key: 'error.regressed', title: 'Regression', description: 'A resolved error happens again.', icon: ArrowPathIcon },
  { key: 'error.threshold_reached', title: 'Frequency threshold', description: 'An error reaches a set count within a time window.', icon: ChartBarIcon },
]
const dirty = computed(() => !!draft.value && baseline.value !== JSON.stringify(draft.value))
const filtered = computed(() => config.value?.rules.filter(rule =>
  rule.name.toLowerCase().includes(search.value.toLowerCase().trim()) &&
  (status.value === 'all' || (status.value === 'enabled' ? rule.enabled : !rule.enabled))) ?? [])
const enabledCount = computed(() => config.value?.rules.filter(rule => rule.enabled).length ?? 0)
const destinations = computed(() => config.value?.connections.filter(c => draft.value?.connections.includes(c.id)) ?? [])
const missingDestinations = computed(() => draft.value?.connections.filter(id => !config.value?.connections.some(c => c.id === id)) ?? [])
const summary = computed(() => draft.value ? `${draft.value.events.map(event => config.value?.events[event] ?? event).join(', ') || 'Choose notification events'}${draft.value.conditions.environments.length ? ' in ' + draft.value.conditions.environments.join(', ') : ''}.` : '')
function cooldown(seconds: number) { return seconds === 0 ? 'No cooldown' : seconds % 3600 === 0 ? `${seconds / 3600} hour${seconds === 3600 ? '' : 's'} cooldown` : `${seconds / 60} min cooldown` }
function connectionNames(rule: AlertRuleItem) { return rule.connections.map(id => config.value?.connections.find(c => c.id === id)?.name ?? 'Unavailable destination').join(', ') || 'No destinations' }
function eventNames(rule: AlertRuleItem) { return rule.events.map(event => event === 'error.threshold_reached' ? `${rule.threshold_count} occurrences / ${(rule.window_seconds ?? 0) / 60} min` : config.value?.events[event] ?? event).join(' · ') }
function confirmLeave() { return !dirty.value || window.confirm('Discard this unsaved rule draft?') }
onBeforeRouteLeave(() => !saving.value && confirmLeave())
const unload = (event: BeforeUnloadEvent) => { if (dirty.value || saving.value) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => { window.addEventListener('beforeunload', unload); if (bootstrap.value?.capabilities.manage) load() })
onUnmounted(() => window.removeEventListener('beforeunload', unload))
watch(draft, () => { preview.value = null }, { deep: true })
watch(example, () => { preview.value = null })
async function load() {
  loading.value = true
  error.value = ''
  try { config.value = await api<AlertConfiguration>('/alerting') }
  catch (e) { error.value = e instanceof Error ? e.message : 'Rules unavailable.' }
  finally { loading.value = false }
}
function choose(rule: AlertRuleItem | null, copy = false) {
  if (saving.value || !config.value || !confirmLeave()) return
  editing.value = copy ? null : rule
  draft.value = JSON.parse(JSON.stringify(rule ? {
    name: rule.name, enabled: rule.enabled, events: rule.events, conditions: rule.conditions,
    connections: rule.connections, threshold_count: rule.threshold_count,
    window_seconds: rule.window_seconds, cooldown_seconds: rule.cooldown_seconds,
  } : config.value.starter))
  if (copy) { draft.value!.name = draft.value!.name.slice(0, 110) + ' copy'; draft.value!.enabled = false }
  baseline.value = copy ? '' : JSON.stringify(draft.value)
  step.value = 1; preview.value = null; example.value = ''; error.value = ''
  nextTick(() => stepHeading.value?.focus())
}
function cancel() {
  if (saving.value || !confirmLeave()) return
  draft.value = null; editing.value = null; baseline.value = ''; error.value = ''; preview.value = null
}
function validation(target: number): string {
  const rule = draft.value!
  if (target === 1) {
    if (!rule.name.trim()) return 'Enter a rule name.'
    if (!rule.events.length) return 'Choose at least one notification event.'
    if (rule.events.includes('error.threshold_reached') && (!Number.isInteger(rule.threshold_count) || Number(rule.threshold_count) < 1 || ![300, 900, 3600].includes(Number(rule.window_seconds)))) return 'Choose a positive crossing count and a time window.'
  }
  if (target === 2 && !rule.conditions.environments.length) return 'Choose at least one environment.'
  return ''
}
async function goTo(target: number) {
  if (saving.value) return
  error.value = ''
  if (target > step.value) {
    if (!form.value?.reportValidity()) return
    for (let current = 1; current < target; current++) {
      const message = validation(current)
      if (message) { step.value = current; error.value = message; return }
    }
  }
  step.value = target
  await nextTick(); stepHeading.value?.focus()
}
async function save() {
  if (!draft.value || saving.value) return
  for (let current = 1; current <= 2; current++) {
    const message = validation(current)
    if (message) { step.value = current; error.value = message; return }
  }
  if (draft.value.enabled && (!draft.value.connections.length || missingDestinations.value.length || destinations.value.some(c => !c.enabled || c.disconnected))) {
    error.value = 'Choose an available destination before enabling this rule, or save it as a draft.'; return
  }
  saving.value = true; error.value = ''
  try {
    await perform('/alerting/rules' + (editing.value ? '/' + editing.value.id : ''), editing.value ? 'PATCH' : 'POST', { ...draft.value, ...(editing.value ? { version: editing.value.version } : {}) })
    draft.value = null; editing.value = null; baseline.value = ''; search.value = ''; status.value = 'all'
    notify('Rule saved. Existing pending work is revalidated before sending.')
    await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Rule could not be saved.' }
  finally { saving.value = false }
}
async function remove(rule: AlertRuleItem) {
  if (saving.value || !confirmLeave() || !window.confirm('Delete this rule? Its pending support is removed; history remains.')) return
  saving.value = true; error.value = ''
  try {
    await perform('/alerting/rules/' + rule.id, 'DELETE', { version: rule.version })
    draft.value = null; editing.value = null; baseline.value = ''; notify('Rule deleted. Delivery history remains.'); await load()
  } catch (e) { error.value = e instanceof Error ? e.message : 'Delete failed.' }
  finally { saving.value = false }
}
async function showPreview() {
  if (!draft.value || previewing.value) return
  error.value = ''; previewing.value = true
  const definition = JSON.stringify(draft.value), issue = example.value
  try {
    const result = await api<{ text: string; detail: string }>('/alerting/preview', 'POST', { definition: draft.value, ...(issue ? { issue } : {}) })
    if (definition === JSON.stringify(draft.value) && issue === example.value) preview.value = result
  } catch (e) { error.value = e instanceof Error ? e.message : 'Preview unavailable.' }
  finally { previewing.value = false }
}
async function reload() {
  if (saving.value || !confirmLeave()) return
  draft.value = null; editing.value = null; baseline.value = ''; await load()
}
</script>

<template>
  <template v-if="!draft">
    <PageHeading :class="ui('$all', '$PageHeading')" title="Alert rules" subtitle="Control when your team gets notified.">
      <RouterLink v-if="bootstrap?.capabilities.manage" to="/settings/reports" :class="ui('$all', '$a', 'button')">Scheduled reports</RouterLink>
      <RouterLink to="/alerts/history" :class="ui('$all', '$a', 'button')">Delivery history</RouterLink>
      <button v-if="config?.rules.length && bootstrap?.capabilities.manage" :class="ui('$all', '$button', 'button primary')" :disabled="saving || loading" @click="choose(null)"><PlusIcon :class="ui('$all', '$svg')" aria-hidden="true"/>Create rule</button>
    </PageHeading>
  </template>
  <div v-else :class="ui('$all', '$div', 'rule-builder-heading')">
    <button :class="ui('$all', '$button', 'back-link')" :disabled="saving" @click="cancel"><ArrowLeftIcon :class="ui('$all', '$svg')" aria-hidden="true"/>Alert rules</button>
    <h1 :class="ui('$all', '$h1')">{{ editing ? 'Edit alert rule' : 'Create alert rule' }}</h1>
    <p :class="ui('$all', '$p')">{{ ['Choose what should trigger a notification.', 'Choose which errors this rule applies to.', 'Choose where notifications should go.', 'Review your rule before saving.'][step - 1] }}</p>
  </div>
  <div v-if="!bootstrap?.features.alerts" :class="ui('$all', '$div', 'notice warning')">Publish and run the alerting migration to enable this module.</div>
  <div v-else-if="!bootstrap?.capabilities.manage" :class="ui('$all', '$div', 'notice neutral')"><p :class="ui('$all', '$p')">Administrators configure rules and recipients. You can inspect sanitized delivery history.</p></div>
  <template v-else>
    <p v-if="loading" role="status" :class="ui('$all', '$p', 'loading-message')">Loading alert configuration…</p>
    <div v-if="error" :class="ui('$all', '$div', 'notice danger')" role="alert"><p :class="ui('$all', '$p')">{{ error }}</p><button :class="ui('$all', '$button', 'button')" :disabled="saving || loading" @click="reload">Reload configuration</button></div>
    <template v-if="config && !loading && !draft">
      <section v-if="!config.rules.length" :class="ui('$all', '$section', 'rules-empty')" aria-labelledby="rules-empty-title">
        <span :class="ui('$all', '$span', 'rules-empty-icon')"><BellIcon :class="ui('$all', '$svg')" aria-hidden="true"/></span>
        <h2 :class="ui('$all', '$h2')" id="rules-empty-title">No alert rules yet</h2>
        <p :class="ui('$all', '$p')">Create a rule to notify your team about new errors,<br :class="ui('$all', '$br', 'rules-desktop-break')"> regressions, or a rise in error frequency.</p>
        <button :class="ui('$all', '$button', 'button primary')" :disabled="saving" @click="choose(null)"><PlusIcon :class="ui('$all', '$svg')" aria-hidden="true"/>Create rule</button>
        <small :class="ui('$all', '$small')">Choose events, environments, and where notifications go.</small>
      </section>
      <template v-else>
        <div :class="ui('$all', '$div', 'rules-toolbar')">
          <label :class="ui('$all', '$label', 'rules-search')"><MagnifyingGlassIcon :class="ui('$all', '$svg')" aria-hidden="true"/><span :class="ui('$all', '$span', 'sr-only')">Search rules</span><input :class="ui('$all', '$input')" v-model="search" type="search" placeholder="Search rules"></label>
          <label :class="ui('$all', '$label')"><span :class="ui('$all', '$span', 'sr-only')">Filter rules by status</span><select :class="ui('$all', '$select')" v-model="status"><option :class="ui('$all', '$option')" value="all">All statuses</option><option :class="ui('$all', '$option')" value="enabled">Enabled</option><option :class="ui('$all', '$option')" value="draft">Draft</option></select></label>
        </div>
        <div v-if="filtered.length" :class="ui('$all', '$div', 'table-scroll rules-table')" tabindex="0" role="region" aria-label="Alert rules, scroll horizontally for more columns">
          <table :class="ui('$all', '$table')"><caption :class="ui('$all', '$caption', 'sr-only')">Alert rules</caption><thead :class="ui('$all', '$thead')"><tr :class="ui('$all', '$tr')"><th scope="col" :class="ui('$all', '$th')">Rule</th><th scope="col" :class="ui('$all', '$th')">Events</th><th scope="col" :class="ui('$all', '$th')">Environment</th><th scope="col" :class="ui('$all', '$th')">Destinations</th><th scope="col" :class="ui('$all', '$th')">Status</th><th scope="col" :class="ui('$all', '$th')"><span :class="ui('$all', '$span', 'sr-only')">Actions</span></th></tr></thead>
            <tbody :class="ui('$all', '$tbody')"><tr :class="ui('$all', '$tr')" v-for="rule in filtered" :key="rule.id">
              <td :class="ui('$all', '$td')"><button :class="ui('$all', '$button', 'rule-name')" :disabled="saving" @click="choose(rule)">{{ rule.name }}</button><small :class="ui('$all', '$small')">{{ cooldown(rule.cooldown_seconds) }}</small></td>
              <td :class="ui('$all', '$td')">{{ eventNames(rule) }}</td><td :class="ui('$all', '$td')">{{ rule.conditions.environments.join(', ') }}</td><td :class="ui('$all', '$td')">{{ connectionNames(rule) }}</td>
              <td :class="ui('$all', '$td')"><span :class="ui('$all', '$span', 'badge', ({ passed: rule.enabled }))" >{{ rule.enabled ? 'Enabled' : 'Draft' }}</span></td>
              <td :class="ui('$all', '$td')"><RuleActionsMenu :class="ui('$all', '$RuleActionsMenu')" :label="rule.name" :disabled="saving" @edit="choose(rule)" @duplicate="choose(rule, true)" @remove="remove(rule)"/></td>
            </tr></tbody>
          </table>
        </div>
        <div v-else :class="ui('$all', '$div', 'rules-no-results')"><h2 :class="ui('$all', '$h2')">No matching rules</h2><p :class="ui('$all', '$p', 'help')">Try a different name or status.</p><button :class="ui('$all', '$button', 'button')" @click="search = ''; status = 'all'">Clear filters</button></div>
        <p :class="ui('$all', '$p', 'rules-count')">{{ config.rules.length }} {{ config.rules.length === 1 ? 'rule' : 'rules' }} · {{ enabledCount }} enabled<span :class="ui('$all', '$span')" v-if="search || status !== 'all'"> · {{ filtered.length }} shown</span></p>
      </template>
    </template>
    <form v-if="draft && config" ref="form" :class="ui('$all', '$form', 'rule-builder')" @submit.prevent="step < 4 ? goTo(step + 1) : save()">
      <nav :class="ui('$all', '$nav', 'rule-steps')" aria-label="Rule creation steps"><ol :class="ui('$all', '$ol')"><li v-for="(label, index) in steps" :key="label" :class="ui('$all', '$li', ({ current: step === index + 1, complete: step > index + 1 }))"><button :class="ui('$all', '$button')" type="button" :aria-current="step === index + 1 ? 'step' : undefined" :disabled="saving" @click="goTo(index + 1)"><span :class="ui('$all', '$span')"><CheckIcon :class="ui('$all', '$svg')" v-if="step > index + 1" aria-hidden="true"/><template v-else>{{ index + 1 }}</template></span>{{ label }}</button></li></ol></nav>
      <fieldset :class="ui('$all', '$fieldset', 'rule-builder-fields')" :disabled="saving">
        <div :class="ui('$all', '$div', 'rule-builder-content')">
          <div :class="ui('$all', '$div', 'rule-step-content')">
            <h2 ref="stepHeading" tabindex="-1" :class="ui('$all', '$h2', 'sr-only')">{{ steps[step - 1] }}</h2>
            <template v-if="step === 1">
              <label :class="ui('$all', '$label', 'field')">Rule name<input :class="ui('$all', '$input')" v-model="draft.name" required maxlength="120" placeholder="e.g. Production errors"></label>
              <fieldset :class="ui('$all', '$fieldset', 'rule-event-options')"><legend :class="ui('$all', '$legend')">When should we notify you?</legend>
                <label v-for="event in primary" :key="event.key" :class="ui('$all', '$label', 'rule-event', ({ selected: draft.events.includes(event.key) }))" ><input :class="ui('$all', '$input')" v-model="draft.events" type="checkbox" :value="event.key"><component :class="ui('$all', '$svg')" :is="event.icon" aria-hidden="true"/><span :class="ui('$all', '$span')"><strong :class="ui('$all', '$strong')">{{ event.title }}</strong><small :class="ui('$all', '$small')">{{ event.description }}</small></span></label>
              </fieldset>
              <div v-if="draft.events.includes('error.threshold_reached')" :class="ui('$all', '$div', 'threshold-fields')"><label :class="ui('$all', '$label', 'field')">Crossing count<input :class="ui('$all', '$input')" v-model.number="draft.threshold_count" type="number" min="1" required></label><label :class="ui('$all', '$label', 'field')">Time window<select :class="ui('$all', '$select')" v-model.number="draft.window_seconds" required><option :class="ui('$all', '$option')" :value="null" disabled>Select a window</option><option :class="ui('$all', '$option')" :value="300">5 minutes</option><option :class="ui('$all', '$option')" :value="900">15 minutes</option><option :class="ui('$all', '$option')" :value="3600">1 hour</option></select></label><p :class="ui('$all', '$p', 'help')">One crossing per error in each fixed UTC window. Historical replay does not trigger notifications.</p></div>
              <details :class="ui('$all', '$details', 'activity-rule-events')"><summary :class="ui('$all', '$summary')">Activity notifications</summary><p :class="ui('$all', '$p', 'help')">Choose additional error lifecycle and investigation updates.</p><template v-for="(label, event) in config.events" :key="event"><label v-if="!primary.some(item => item.key === event)" :class="ui('$all', '$label', 'checkbox-row')"><input :class="ui('$all', '$input')" v-model="draft.events" type="checkbox" :value="event" :disabled="event === 'assignment.changed' && !bootstrap?.features.assignments">{{ label }}<small :class="ui('$all', '$small')" v-if="event === 'assignment.changed' && !bootstrap?.features.assignments">Requires host directory</small></label></template></details>
            </template>
            <template v-else-if="step === 2">
              <h2 :class="ui('$all', '$h2')">Which errors should match?</h2><p :class="ui('$all', '$p', 'help')">Select environments, then narrow the rule if needed.</p>
              <CheckboxDropdown :class="ui('$all', '$CheckboxDropdown')" v-model="draft.conditions.environments" label="Environment" :options="config.allowed_environments" :disabled="saving"/>
              <details :class="ui('$all', '$details', 'rule-conditions')"><summary :class="ui('$all', '$summary')">Advanced conditions</summary><label :class="ui('$all', '$label', 'field')">Exact exception class<input :class="ui('$all', '$input')" v-model="draft.conditions.class" maxlength="512" placeholder="Any exception class"></label><label :class="ui('$all', '$label', 'field')">Origin<select :class="ui('$all', '$select')" v-model="draft.conditions.origin"><option :class="ui('$all', '$option')" :value="null">Any origin</option><option :class="ui('$all', '$option')" v-for="origin in ['web', 'job', 'command', 'unknown']" :key="origin">{{ origin }}</option></select></label><label v-if="config.allowed_tags.length" :class="ui('$all', '$label', 'field')">Permitted tag (key:value)<input :class="ui('$all', '$input')" v-model="draft.conditions.tag" maxlength="129"><small :class="ui('$all', '$small')">{{ config.allowed_tags.join(', ') }}</small></label><p :class="ui('$all', '$p', 'help')">All selected conditions must match. Activity conditions use the latest captured occurrence.</p></details>
            </template>
            <template v-else-if="step === 3">
              <h2 :class="ui('$all', '$h2')">Where should alerts go?</h2><p :class="ui('$all', '$p', 'help')">Select one or more configured destinations.</p>
              <div v-if="!config.connections.length" :class="ui('$all', '$div', 'rule-destinations-empty')"><BellIcon :class="ui('$all', '$svg')" aria-hidden="true"/><h3 :class="ui('$all', '$h3')">No destinations configured</h3><p :class="ui('$all', '$p', 'help')">Add an integration to receive notifications. You can also finish this rule and save it as a draft.</p><RouterLink to="/integrations" :class="ui('$all', '$a', 'button')">Add a destination</RouterLink></div>
              <label v-for="connection in config.connections" :key="connection.id" :class="ui('$all', '$label', 'rule-destination', ({ unavailable: !connection.enabled || connection.disconnected }))" ><input :class="ui('$all', '$input')" v-model="draft.connections" type="checkbox" :value="connection.id" :disabled="(!connection.enabled || connection.disconnected) && !draft.connections.includes(connection.id)"><span :class="ui('$all', '$span')"><strong :class="ui('$all', '$strong')">{{ connection.name }}</strong><small :class="ui('$all', '$small')">{{ connection.provider }} · {{ connection.email ?? connection.display }}{{ !connection.enabled || connection.disconnected ? ' · Unavailable' : '' }}</small></span></label>
              <label v-for="id in missingDestinations" :key="id" :class="ui('$all', '$label', 'rule-destination unavailable')"><input :class="ui('$all', '$input')" v-model="draft.connections" type="checkbox" :value="id"><span :class="ui('$all', '$span')">Unavailable destination<small :class="ui('$all', '$small')">Uncheck to remove this missing connection.</small></span></label>
              <RouterLink v-if="config.connections.length" to="/integrations" :class="ui('$all', '$a', 'text-link')">Manage integrations</RouterLink>
              <p :class="ui('$all', '$p', 'help')">You can save a draft without destinations. Overlapping rules share the destination cooldown.</p>
            </template>
            <template v-else>
              <div :class="ui('$all', '$div', 'section-heading')"><h2 :class="ui('$all', '$h2')">{{ draft.name }}</h2><span :class="ui('$all', '$span', 'badge')">{{ draft.enabled ? 'Enable after saving' : 'Draft' }}</span></div>
              <dl :class="ui('$all', '$dl', 'rule-review')"><div :class="ui('$all', '$div')"><dt :class="ui('$all', '$dt')">Events<button type="button" :class="ui('$all', '$button', 'text-link')" @click="goTo(1)">Edit</button></dt><dd :class="ui('$all', '$dd')">{{ draft.events.map(event => config!.events[event]).join(', ') }}<small :class="ui('$all', '$small')" v-if="draft.events.includes('error.threshold_reached')">{{ draft.threshold_count }} occurrences in {{ Number(draft.window_seconds) / 60 }} minutes</small></dd></div><div :class="ui('$all', '$div')"><dt :class="ui('$all', '$dt')">Conditions<button type="button" :class="ui('$all', '$button', 'text-link')" @click="goTo(2)">Edit</button></dt><dd :class="ui('$all', '$dd')">{{ draft.conditions.environments.join(', ') }}<small :class="ui('$all', '$small')" v-if="draft.conditions.class">{{ draft.conditions.class }}</small><small :class="ui('$all', '$small')" v-if="draft.conditions.origin">Origin: {{ draft.conditions.origin }}</small><small :class="ui('$all', '$small')" v-if="draft.conditions.tag">Tag: {{ draft.conditions.tag }}</small></dd></div><div :class="ui('$all', '$div')"><dt :class="ui('$all', '$dt')">Destinations<button type="button" :class="ui('$all', '$button', 'text-link')" @click="goTo(3)">Edit</button></dt><dd :class="ui('$all', '$dd')">{{ destinations.map(c => c.name).join(', ') || 'No destinations selected' }}<small :class="ui('$all', '$small')" v-if="missingDestinations.length">{{ missingDestinations.length }} unavailable</small></dd></div></dl>
              <label :class="ui('$all', '$label', 'field')">Per-error, per-destination cooldown<select :class="ui('$all', '$select')" v-model.number="draft.cooldown_seconds"><option :class="ui('$all', '$option')" :value="0">No cooldown</option><option :class="ui('$all', '$option')" v-for="minutes in [5, 15, 30, 60, 240, 1440].filter(m => m * 60 <= config!.max_cooldown_seconds)" :key="minutes" :value="minutes * 60">{{ minutes < 60 ? minutes + ' minutes' : minutes / 60 + (minutes === 60 ? ' hour' : ' hours') }}</option><option :class="ui('$all', '$option')" v-if="draft.cooldown_seconds && ![300, 900, 1800, 3600, 14400, 86400].includes(draft.cooldown_seconds)" :value="draft.cooldown_seconds">{{ draft.cooldown_seconds }} seconds</option></select></label>
              <label :class="ui('$all', '$label', 'checkbox-row')"><input :class="ui('$all', '$input')" v-model="draft.enabled" type="checkbox">Enable after saving</label><p v-if="!draft.connections.length" :class="ui('$all', '$p', 'help')">No destinations selected. Leave this rule disabled to save it as a draft.</p><p v-if="!config.enabled" :class="ui('$all', '$p', 'notice warning')">Delivery is disabled by deployment policy. Saved rules will not send until delivery is enabled.</p>
              <details :class="ui('$all', '$details', 'rule-preview')"><summary :class="ui('$all', '$summary')">Notification preview</summary><p :class="ui('$all', '$p', 'help')">Preview a sanitized example. Nothing is sent.</p><label :class="ui('$all', '$label', 'field')">Example error UUID (optional)<input :class="ui('$all', '$input')" v-model="example" placeholder="Use synthetic example"></label><button type="button" :class="ui('$all', '$button', 'button')" :disabled="previewing" @click="showPreview">{{ previewing ? 'Generating preview…' : 'Preview notification' }}</button><div v-if="preview" :class="ui('$all', '$div', 'notification-preview')" role="status"><pre :class="ui('$all', '$pre')">{{ preview.text }}</pre><p :class="ui('$all', '$p', 'help')">{{ preview.detail }}</p></div><p :class="ui('$all', '$p', 'help')">Request payloads, SQL, user identities and note text are excluded.</p><RouterLink to="/settings/email" :class="ui('$all', '$a', 'text-link')">Send synthetic test</RouterLink></details>
            </template>
          </div>
          <aside :class="ui('$all', '$aside', 'rule-builder-summary')"><h2 :class="ui('$all', '$h2')">Your rule</h2><p :class="ui('$all', '$p')">{{ summary }}</p><template v-if="draft.connections.length"><h3 :class="ui('$all', '$h3')">Destinations</h3><p :class="ui('$all', '$p')">{{ destinations.map(c => c.name).join(', ') || 'Unavailable destinations' }}</p></template><template v-if="step < 4"><h3 :class="ui('$all', '$h3')">Next steps</h3><ul :class="ui('$all', '$ul')"><li :class="ui('$all', '$li')" v-for="(label, index) in ['Choose events', 'Choose environments and conditions', 'Select notification destinations', 'Review and enable'] .slice(step)" :key="index"><span :class="ui('$all', '$span', 'rule-next-dot')" aria-hidden="true"></span>{{ label }}</li></ul></template><p v-else :class="ui('$all', '$p', 'help')">{{ cooldown(draft.cooldown_seconds) }}. Overlapping rules coalesce by destination.</p><span v-if="dirty" :class="ui('$all', '$span', 'unsaved')">Unsaved changes</span></aside>
        </div>
      </fieldset>
      <footer :class="ui('$all', '$footer', 'rule-builder-footer')"><div :class="ui('$all', '$div')"><button type="button" :class="ui('$all', '$button', 'button')" :disabled="saving" @click="cancel">Cancel</button><button v-if="step > 1" type="button" :class="ui('$all', '$button', 'button')" :disabled="saving" @click="goTo(step - 1)"><ArrowLeftIcon :class="ui('$all', '$svg')" aria-hidden="true"/>Back</button></div><span :class="ui('$all', '$span', 'help')">Step {{ step }} of 4</span><button v-if="step < 4" type="submit" :class="ui('$all', '$button', 'button primary')" :disabled="saving">Continue to {{ steps[step]!.toLowerCase() }}<ArrowRightIcon :class="ui('$all', '$svg')" aria-hidden="true"/></button><button v-else type="submit" :class="ui('$all', '$button', 'button primary')" :disabled="saving">{{ saving ? 'Saving…' : draft.enabled ? 'Save and enable rule' : 'Save draft' }}</button></footer>
      <div v-if="editing" :class="ui('$all', '$div', 'rule-editor-actions')"><button type="button" :class="ui('$all', '$button', 'text-link')" :disabled="saving" @click="choose(editing, true)">Duplicate rule</button><button type="button" :class="ui('$all', '$button', 'text-link danger-link')" :disabled="saving" @click="remove(editing)">Delete rule</button></div>
    </form>
  </template>
</template>
