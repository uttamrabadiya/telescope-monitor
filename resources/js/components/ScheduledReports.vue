<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { api, ApiError, bootstrap, notify } from '../composables/api'
import { ui } from '../ui'
import type { AlertConfiguration, EmailDestination } from '../types'

type Kind = 'daily' | 'weekly'
type Definition = { enabled: boolean; connections: string[]; environments: string[]; time: string; timezone: string; skip_empty: boolean; weekday?: number }
type Group = { values: Record<Kind, Definition>; version: number; fields: Record<Kind, { locked: boolean; reason: string | null }> }
type Preview = { queue_asynchronous: boolean; deliveries: { id: string; status: string }[] }
const group = ref<Group | null>(null), draft = ref<Record<Kind, Definition> | null>(null), config = ref<AlertConfiguration | null>(null)
const loading = ref(true), saving = ref(false), previewing = ref<Kind | null>(null), error = ref(''), fields = ref<Record<string, string[]>>({})
const baseline = ref(''), preview = ref<Preview | null>(null), confirmation = ref<Kind | null>(null)
const kinds: Kind[] = ['daily', 'weekly']
const checkbox = 'flex items-start gap-2 text-[15px] [&_input]:mt-1 [&_input]:size-[18px] [&_input]:shrink-0 [&_input]:accent-tm-checkbox'
const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const dirty = computed(() => !!draft.value && JSON.stringify(draft.value) !== baseline.value)
const busy = computed(() => saving.value || previewing.value !== null)
const recipients = computed(() => config.value?.connections.filter(c => c.provider === 'email') ?? [])
const selectedRecipients = computed(() => confirmation.value && draft.value ? recipients.value.filter(c => draft.value![confirmation.value!].connections.includes(c.id)) : [])
const timezones = computed(() => [...new Set([...(bootstrap.value?.timezones ?? []), ...kinds.map(k => draft.value?.[k].timezone ?? 'UTC')])].sort())
const previewKeys: Partial<Record<Kind, string>> = {}

function leave() { return !busy.value && (!dirty.value || window.confirm('Discard unsaved report settings?')) }
onBeforeRouteLeave(leave)
onBeforeRouteUpdate(leave)
const unload = (event: BeforeUnloadEvent) => { if (dirty.value || busy.value) { event.preventDefault(); event.returnValue = '' } }
onMounted(() => { window.addEventListener('beforeunload', unload); load() })
onUnmounted(() => window.removeEventListener('beforeunload', unload))
function fail(e: unknown) { error.value = e instanceof Error ? e.message : 'Report settings are unavailable. Try again.'; fields.value = e instanceof ApiError ? e.fields : {} }
async function load() {
  loading.value = true; error.value = ''; fields.value = {}
  try {
    const [preferences, alerting] = await Promise.all([api<Group>('/settings/reports'), api<AlertConfiguration>('/alerting')])
    group.value = preferences; config.value = alerting; draft.value = structuredClone(preferences.values); baseline.value = JSON.stringify(draft.value)
    delete previewKeys.daily; delete previewKeys.weekly
  } catch (e) { fail(e) } finally { loading.value = false }
}
async function reload() { if (leave()) await load() }
async function save() {
  if (!draft.value || !group.value || busy.value) return
  saving.value = true; error.value = ''; fields.value = {}; confirmation.value = null
  const values = Object.fromEntries(kinds.filter(k => !group.value!.fields[k].locked).map(k => [k, draft.value![k]]))
  try {
    group.value = await api<Group>('/settings/reports', 'PATCH', { version: group.value.version, values })
    draft.value = JSON.parse(JSON.stringify(group.value.values)); baseline.value = JSON.stringify(draft.value)
    delete previewKeys.daily; delete previewKeys.weekly
    notify('Scheduled report settings saved.')
  } catch (e) { fail(e) } finally { saving.value = false }
}
function unavailable(c: EmailDestination) { return !c.enabled || c.disconnected }
async function sendPreview() {
  const kind = confirmation.value
  if (!kind || !group.value || dirty.value || busy.value) return
  previewing.value = kind; error.value = ''; fields.value = {}
  previewKeys[kind] ??= crypto.randomUUID()
  try {
    preview.value = await api<Preview>('/reports/' + kind + '/preview', 'POST', { client_key: previewKeys[kind], version: group.value.version })
    delete previewKeys[kind]; confirmation.value = null
    notify('Report preview queued. Follow its status in delivery history.')
  } catch (e) { fail(e) } finally { previewing.value = null }
}
</script>

<template>
  <section :class="ui('$all', 'min-w-0')" aria-labelledby="reports-heading">
    <h2 id="reports-heading" :class="ui('$all', '$h2')">Scheduled reports</h2>
    <p :class="ui('$all', '$p', 'help', 'max-w-[65ch]')">A daily briefing for triage and a weekly summary for progress and planning. Each report has its own recipients and schedule.</p>
    <div v-if="error" :class="ui('$all', 'notice danger', 'mt-5')" role="alert">
      <div><p :class="ui('$all', '$p')">{{ error }}</p><ul v-if="Object.keys(fields).length" :class="ui('$all', 'mt-2 pl-5')"><li v-for="(messages, key) in fields" :key="key">{{ key.replace('values.', '') }}: {{ messages.join(' ') }}</li></ul><button type="button" :class="ui('$all', '$button', 'button', 'mt-3')" :disabled="busy" @click="reload">Reload report settings</button></div>
    </div>
    <p v-if="loading" :class="ui('$all', '$p', 'mt-6')" role="status">Loading report settings…</p>
    <form v-else-if="draft && group && config" @submit.prevent="save" :class="ui('$all', 'mt-6')">
      <p v-if="!config.enabled" :class="ui('$all', '$p', 'notice warning', 'mb-5')">Email delivery is disabled by deployment policy. Reports can be configured, but cannot send.</p>
      <p v-if="!config.queue_asynchronous" :class="ui('$all', '$p', 'notice warning', 'mb-5')">An asynchronous delivery queue is not configured. Prepared reports remain pending until a delivery worker or the explicit delivery command processes them.</p>
      <fieldset v-for="kind in kinds" :key="kind" :disabled="busy || group.fields[kind].locked" :class="ui('$all', 'm-0 min-w-0 border-0 border-t border-solid border-tm-border px-0 pb-7 pt-5')">
        <legend :class="ui('$all', 'px-0 pr-3 text-lg font-semibold')">{{ kind === 'daily' ? 'Daily report' : 'Weekly report' }}</legend>
        <p :class="ui('$all', '$p', 'help', 'mb-4')">{{ kind === 'daily' ? 'Yesterday’s occurrences, new errors, regressions and errors needing attention.' : 'Last Monday–Sunday’s trends, resolutions, recurring errors and aging backlog.' }}</p>
        <p v-if="group.fields[kind].locked" :class="ui('$all', '$p', 'help', 'mb-4')">Managed by deployment configuration: {{ group.fields[kind].reason }}</p>
        <label :class="ui('$all', '$label', 'checkbox-row', checkbox)"><input :class="ui('$all', '$input')" type="checkbox" v-model="draft[kind].enabled">Enable {{ kind }} email report</label>
        <div :class="ui('$all', 'mt-5 grid min-w-0 grid-cols-1 gap-x-5 gap-y-4 min-[1050px]:grid-cols-2')">
          <label :class="ui('$all', '$label', 'field', 'm-0')">Delivery time<input :class="ui('$all', '$input')" type="time" required v-model="draft[kind].time"></label>
          <label :class="ui('$all', '$label', 'field', 'm-0')">Timezone<select :class="ui('$all', '$select', 'w-full min-w-0')" required v-model="draft[kind].timezone"><option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option></select></label>
          <label v-if="kind === 'weekly'" :class="ui('$all', '$label', 'field', 'm-0')">Delivery day<select :class="ui('$all', '$select')" v-model.number="draft[kind].weekday"><option v-for="(day, index) in days" :key="day" :value="index + 1">{{ day }}</option></select></label>
        </div>
        <fieldset :class="ui('$all', 'm-0 mt-5 min-w-0 border-0 p-0')">
          <legend :class="ui('$all', 'mb-2 font-medium')">Recipients</legend>
          <label v-for="recipient in recipients" :key="recipient.id" :class="ui('$all', '$label', 'checkbox-row', checkbox, 'mb-2 break-words')"><input :class="ui('$all', '$input')" type="checkbox" v-model="draft[kind].connections" :value="recipient.id" :disabled="unavailable(recipient) && !draft[kind].connections.includes(recipient.id)"><span :class="ui('$all', 'min-w-0 [overflow-wrap:anywhere]')">{{ recipient.name }} · {{ recipient.email ?? recipient.display }}{{ unavailable(recipient) ? ' · Unavailable' : '' }}</span></label>
          <label v-for="id in draft[kind].connections.filter(id => !recipients.some(c => c.id === id))" :key="id" :class="ui('$all', '$label', 'checkbox-row', checkbox)"><input :class="ui('$all', '$input')" type="checkbox" v-model="draft[kind].connections" :value="id">Unavailable recipient — uncheck to remove</label>
          <p v-if="!recipients.length" :class="ui('$all', '$p', 'help')">No email recipients configured.</p>
          <RouterLink to="/settings/email" :class="ui('$all', '$a', 'text-link', 'block w-fit mt-3')">Manage email recipients</RouterLink>
        </fieldset>
        <fieldset :class="ui('$all', 'm-0 mt-5 min-w-0 border-0 p-0')">
          <legend :class="ui('$all', 'mb-2 font-medium')">Environments</legend>
          <div :class="ui('$all', 'flex flex-wrap gap-x-5 gap-y-2')"><label v-for="env in [...new Set([...config.allowed_environments, ...draft[kind].environments])]" :key="env" :class="ui('$all', '$label', 'checkbox-row', checkbox, 'm-0')"><input :class="ui('$all', '$input')" type="checkbox" v-model="draft[kind].environments" :value="env">{{ env }}{{ !config.allowed_environments.includes(env) ? ' · No longer allowed' : '' }}</label></div>
        </fieldset>
        <label :class="ui('$all', '$label', 'checkbox-row', checkbox, 'mt-5')"><input :class="ui('$all', '$input')" type="checkbox" v-model="draft[kind].skip_empty">Skip when no activity</label>
        <p :class="ui('$all', '$p', 'help')">Skip periods with no occurrences, new groups, regressions or resolutions. Reports with incomplete history are still sent.</p>
        <button type="button" :class="ui('$all', '$button', 'button', 'mt-4')" :disabled="dirty || !config.enabled || !draft[kind].connections.length || !draft[kind].environments.length" @click="confirmation = kind; preview = null">Send {{ kind }} preview</button>
      <div v-if="confirmation === kind" :class="ui('$all', 'notice warning', 'mt-5')" role="status"><div><strong :class="ui('$all', '$strong')">Send {{ confirmation }} report preview</strong><p :class="ui('$all', '$p', 'help')">This queues a labeled email with actual captured data to:</p><ul :class="ui('$all', 'my-3 pl-5')"><li v-for="recipient in selectedRecipients" :key="recipient.id">{{ recipient.name }} · {{ recipient.email ?? recipient.display }}</li></ul><div :class="ui('$all', 'flex flex-wrap gap-3')"><button type="button" :class="ui('$all', '$button', 'button')" :disabled="busy || dirty" @click="sendPreview">{{ previewing ? 'Queuing preview…' : 'Send preview email' }}</button><button type="button" :class="ui('$all', '$button', 'button')" :disabled="busy" @click="confirmation = null">Cancel</button></div></div></div>
      </fieldset>
      <p v-if="dirty" :class="ui('$all', '$p', 'help', 'mb-3')">Save changes before sending a preview.</p>
      <div :class="ui('$all', 'flex flex-wrap items-center gap-3')"><button :class="ui('$all', '$button', 'button primary')" :disabled="busy || !dirty">{{ saving ? 'Saving…' : 'Save report settings' }}</button><button type="button" :class="ui('$all', '$button', 'button')" :disabled="busy || !dirty" @click="reload">Discard changes</button><RouterLink to="/alerts/history" :class="ui('$all', '$a', 'text-link')">Delivery history</RouterLink></div>

      <div v-if="preview" :class="ui('$all', 'notice neutral', 'mt-5')" role="status"><div><strong :class="ui('$all', '$strong')">Preview prepared</strong><ul :class="ui('$all', 'my-2 pl-5')"><li v-for="delivery in preview.deliveries" :key="delivery.id"><RouterLink :to="'/alerts/history/' + delivery.id" :class="ui('$all', '$a', 'text-link')">View preview delivery · {{ delivery.status }}</RouterLink></li></ul><p :class="ui('$all', '$p', 'help')">Queued does not mean delivered. Open a delivery to check transport acceptance.</p></div></div>
      <p :class="ui('$all', '$p', 'help', 'mt-6 max-w-[65ch]')">Daily reports cover the previous calendar day. Weekly reports cover the previous Monday–Sunday week. Your host scheduler must run the report command every minute; late runs catch up on the scheduled local day.</p>
    </form>
  </section>
</template>
