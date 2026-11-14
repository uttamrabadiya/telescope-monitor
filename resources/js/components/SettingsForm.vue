<script setup lang="ts">
import { ui } from "../ui"
import DateTime from './DateTime.vue'
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { LockClosedIcon } from '@heroicons/vue/24/outline'
import { api, ApiError, bootstrap, loadBootstrap, notify } from '../composables/api'
import type { PreferenceGroup, SettingsGroup, RetentionPreview } from '../types'
const props = defineProps<{ group: PreferenceGroup; setup?: boolean }>()
const emit = defineEmits<{ saved: [] }>()
const current = ref<SettingsGroup | null>(null), draft = ref<SettingsGroup['values']>({}), initial = ref('')
const loading = ref(false), saving = ref(false), failure = ref(''), errors = ref<Record<string, string[]>>({}), conflict = ref(false)
const patterns = ref(''), previewOpen = ref(false), previewInput = ref('token=example-value'), previewText = ref(''), previewing = ref(false)
const retentionPreview=ref<RetentionPreview|null>(null),retentionSignature=ref(''),estimating=ref(false),estimateFailure=ref('')
const retentionFields=[{key:'occurrence_days',label:'Occurrence snapshots',min:1,max:90},{key:'delivery_days',label:'Completed delivery history',min:1,max:90},{key:'aggregate_days',label:'Hourly trend aggregates',min:30,max:365},{key:'activity_days',label:'Activity and security history',min:1,max:365}]
const retentionValues=()=>Object.fromEntries(retentionFields.map(f=>[f.key,Number(draft.value[f.key])]))
const reducing=computed(()=>props.group==='retention'&&retentionFields.some(f=>Number(draft.value[f.key])<Number(current.value?.values[f.key])))
const estimateCurrent=computed(()=>!!retentionPreview.value&&retentionSignature.value===JSON.stringify(retentionValues()))
async function estimate(){estimating.value=true;estimateFailure.value='';const values=retentionValues(),signature=JSON.stringify(values);try{const result=await api<RetentionPreview>('/retention/preview','POST',{values});if(signature===JSON.stringify(retentionValues())){retentionPreview.value=result;retentionSignature.value=signature;failure.value=''}}catch(e){retentionPreview.value=null;estimateFailure.value=e instanceof Error?e.message:'Impact estimate unavailable. No values were saved.'}finally{estimating.value=false}}
const dirty = computed(() => current.value !== null && (JSON.stringify(draft.value) !== initial.value || (props.group === 'privacy' && patterns.value !== ((current.value?.values.redact_patterns as string[] ?? []).join('\n')))))
let requestVersion = 0
async function load() {
  const version = ++requestVersion; loading.value = true; failure.value = ''; errors.value = {}; conflict.value = false
  try { const data = await api<SettingsGroup>('/settings/' + props.group); if (version !== requestVersion) return; current.value = data; draft.value = structuredClone(data.values); initial.value = JSON.stringify(data.values); patterns.value = (data.values.redact_patterns as string[] ?? []).join('\n') } catch (e) { if (version === requestVersion) failure.value = e instanceof Error ? e.message : 'Settings could not be read.' } finally { if (version === requestVersion) loading.value = false }
}
watch(() => props.group, load, { immediate: true })
const locked = (field: string) => current.value?.fields[field]?.locked ?? true
const error = (field: string) => errors.value['values.' + field]?.join(' ') ?? ''
async function save() {
  if (!current.value || saving.value) return
  if(reducing.value&&!estimateCurrent.value){failure.value='Preview the impact of these retention values before saving a reduction.';return}
  saving.value = true; errors.value = {}; failure.value = ''
  const values = { ...draft.value }
  if (props.group === 'privacy') values.redact_patterns = patterns.value.split('\n').map(x => x.trim()).filter(Boolean)
  for (const [key, field] of Object.entries(current.value.fields)) if (field.locked) delete values[key]
  try {
    const data = await api<SettingsGroup>('/settings/' + props.group, 'PATCH', { version: current.value.version, values })
    current.value = data; draft.value = structuredClone(data.values); initial.value = JSON.stringify(data.values); patterns.value = (data.values.redact_patterns as string[] ?? []).join('\n'); conflict.value = false
    await loadBootstrap(); notify('Preferences saved.'); emit('saved')
  } catch (e) { if (e instanceof ApiError) { errors.value = e.fields; conflict.value = e.status === 409; failure.value = e.message } else failure.value = 'Preferences could not be saved. Your draft is preserved.' } finally { saving.value = false }
}
async function reloadLatest() { if (window.confirm('Replace your unsaved draft with the latest saved values?')) await load() }
async function preview() { previewing.value = true; try { const result = await api<{text:string}>('/privacy/preview', 'POST', {telescope_monitor_private:{text:previewInput.value,patterns:patterns.value.split('\n').map(x=>x.trim()).filter(Boolean)}}); previewText.value = result.text } catch(e) { notify(e instanceof Error ? e.message : 'Preview failed.',true) } finally { previewing.value = false } }
function beforeUnload(e: BeforeUnloadEvent) { if (dirty.value) { e.preventDefault(); e.returnValue = '' } }
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => { requestVersion++; window.removeEventListener('beforeunload', beforeUnload) })
const confirmLeave = () => !dirty.value || window.confirm('Leave without saving your changes?')
onBeforeRouteLeave(confirmLeave)
onBeforeRouteUpdate(confirmLeave)
defineExpose({ dirty, confirmLeave })
</script>
<template>
  <div v-if="loading" :class="ui('$all', '$div', 'loading-inline')" role="status">Loading preferences…</div>
  <form :class="ui('$all', '$form')" v-else-if="current" @submit.prevent="save">
    <div v-if="failure" :class="ui('$all', '$div', 'notice danger')" role="alert"><div :class="ui('$all', '$div')"><strong :class="ui('$all', '$strong')">{{ conflict ? 'These settings changed since you opened them' : 'Check your changes' }}</strong><p :class="ui('$all', '$p')">{{ failure }}</p><button v-if="conflict" type="button" :class="ui('$all', '$button', 'text-link')" @click="reloadLatest">Reload latest values</button></div></div>
    <template v-if="group === 'general'">
      <h2 :class="ui('$all', '$h2')">Application preferences</h2>
      <label :class="ui('$all', '$label', 'field')">Display name<input :class="ui('$all', '$input')" v-model="draft.display_name" :disabled="locked('display_name')" maxlength="120" required :aria-invalid="!!error('display_name')"><span v-if="error('display_name')" :class="ui('$all', '$span', 'field-error')">{{ error('display_name') }}</span><small :class="ui('$all', '$small')" v-if="locked('display_name')"><LockClosedIcon :class="ui('$all', '$svg')"/> Managed by deployment</small></label>
      <label :class="ui('$all', '$label', 'field')">Canonical dashboard URL<input :class="ui('$all', '$input')" v-model="draft.dashboard_url" disabled><small :class="ui('$all', '$small')"><LockClosedIcon :class="ui('$all', '$svg')"/> Managed by {{ current.fields.dashboard_url?.reason }}</small></label>
      <label :class="ui('$all', '$label', 'field')">Display timezone<select :class="ui('$all', '$select')" v-model="draft.timezone" :disabled="locked('timezone')"><option :class="ui('$all', '$option')" v-for="timezone in bootstrap?.timezones ?? ['UTC']" :key="timezone" :value="timezone">{{ timezone }}</option></select><span v-if="error('timezone')" :class="ui('$all', '$span', 'field-error')">{{ error('timezone') }}</span></label>
    </template>
    <template v-if="group === 'privacy'">
      <h2 :class="ui('$all', '$h2')">Capture & privacy</h2><div :class="ui('$all', '$div', 'policy-notice')"><LockClosedIcon :class="ui('$all', '$svg')"/><div :class="ui('$all', '$div')"><div :class="ui('$all', '$div', 'policy-title')"><span :class="ui('$all', '$span', 'policy-tag')">Deployment policy</span><strong :class="ui('$all', '$strong')">Telescope capture policy is host-managed</strong></div><p :class="ui('$all', '$p')">These preferences restrict retained evidence. They do not enable watchers or broaden Telescope filters.</p></div></div>
      <div :class="ui('$all', '$div', 'check-list')"><label :class="ui('$all', '$label', 'check')"><input :class="ui('$all', '$input')" v-model="draft.retain_stack_trace" type="checkbox" :disabled="locked('retain_stack_trace')">Sanitized stack frames</label><label :class="ui('$all', '$label', 'check')"><input :class="ui('$all', '$input')" v-model="draft.retain_execution_context" type="checkbox" :disabled="locked('retain_execution_context')">Request route and execution metadata</label><label :class="ui('$all', '$label', 'check')"><input :class="ui('$all', '$input')" v-model="draft.retain_related_logs" type="checkbox" :disabled="locked('retain_related_logs')">Bounded related logs and activity</label><label :class="ui('$all', '$label', 'check')"><input :class="ui('$all', '$input')" v-model="draft.retain_sql_templates" type="checkbox" :disabled="locked('retain_sql_templates')||!draft.retain_execution_context">Sanitized SQL templates <small :class="ui('$all', '$small')">Off by default</small></label><label :class="ui('$all', '$label', 'check')"><input :class="ui('$all', '$input')" v-model="draft.identify_users" type="checkbox" :disabled="locked('identify_users')">Pseudonymous affected-user identifiers <small :class="ui('$all', '$small')">Off by default</small></label></div>
      <label :class="ui('$all', '$label', 'field')">Additional redaction patterns<textarea :class="ui('$all', '$textarea')" v-model="patterns" :disabled="locked('redact_patterns')" placeholder="Application-specific patterns" rows="4"></textarea><span v-for="(messages, key) in errors" :key="key" :class="ui('$all', '$span', 'field-error')">{{ messages.join(' ') }}</span></label>
      <p :class="ui('$all', '$p', 'quiet')">Always excluded by current policy</p><p :class="ui('$all', '$p', 'help')">SQL capture requires execution metadata. Templates retain schema identifiers, replace supported literal values with placeholders and exclude bindings/comments. Unsupported or oversized SQL is omitted.</p><div :class="ui('$all', '$div', 'excluded-categories')"><span :class="ui('$all', '$span')"><LockClosedIcon :class="ui('$all', '$svg')"/>Request bodies and cookies</span><span :class="ui('$all', '$span')"><LockClosedIcon :class="ui('$all', '$svg')"/>Authorization headers and query bindings</span></div>
    </template>
    <template v-if="group === 'retention'">
      <h2 :class="ui('$all', '$h2')">Evidence retention</h2><p :class="ui('$all', '$p', 'help')">Preview eligible rows before reducing a limit. Expiry is enforced on reads, and scheduled bounded sweeps remove retained bytes. Increasing a value does not restore expired evidence.</p>
      <label v-for="f in retentionFields" :key="f.key" :class="ui('$all', '$label', 'field')">{{f.label}} <small :class="ui('$all', '$small')">{{current.fields[f.key]?.min??f.min}}–{{current.fields[f.key]?.max??f.max}} days · {{current.fields[f.key]?.source}}</small><input :class="ui('$all', '$input')" v-model.number="draft[f.key]" type="number" :min="current.fields[f.key]?.min??f.min" :max="current.fields[f.key]?.max??f.max" :disabled="locked(f.key)" required><span v-if="locked(f.key)" :class="ui('$all', '$span', 'help')">Managed by {{current.fields[f.key]?.reason}}</span><span :class="ui('$all', '$span', 'field-error')">{{error(f.key)}}</span></label>
      <div :class="ui('$all', '$div', 'notice neutral')"><p :class="ui('$all', '$p')">Summaries, lifetime counts, notes, saved views, GitHub associations and minimal replay identity remain. Five-minute trends have a fixed 48-hour window.</p></div>
      <div :class="ui('$all', '$div', 'form-actions')"><button :class="ui('$all', '$button', 'button')" type="button" :disabled="estimating" @click="estimate">{{estimating?'Estimating…':'Preview retention impact'}}</button><RouterLink to="/settings/operations" :class="ui('$all', '$a', 'text-link')">Pruning history and deployment schedule</RouterLink></div><p v-if="estimateFailure" :class="ui('$all', '$p', 'notice danger')" role="alert">{{estimateFailure}}</p>
      <section v-if="retentionPreview" :class="ui('$all', '$section', 'retention-impact')"><h3 :class="ui('$all', '$h3')">Estimated retention impact</h3><p v-if="!estimateCurrent" :class="ui('$all', '$p', 'notice warning')">This preview belongs to earlier values. Refresh it before saving a reduction.</p><div :class="ui('$all', '$div', 'table-scroll')" tabindex="0" role="region" aria-label="Retention impact estimate"><table :class="ui('$all', '$table')"><caption :class="ui('$all', '$caption', 'sr-only')">Estimated retention impact</caption><thead :class="ui('$all', '$thead')"><tr :class="ui('$all', '$tr')"><th scope="col" :class="ui('$all', '$th')">Record family</th><th scope="col" :class="ui('$all', '$th', 'numeric')">Eligible rows</th><th scope="col" :class="ui('$all', '$th', 'numeric')">Proposed limit</th></tr></thead><tbody :class="ui('$all', '$tbody')"><tr :class="ui('$all', '$tr')" v-for="f in retentionFields" :key="f.key"><td :class="ui('$all', '$td')">{{f.label}}</td><td :class="ui('$all', '$td', 'numeric')">{{retentionPreview.counts[f.key]?.at_least?'At least ':''}}{{retentionPreview.counts[f.key]?.eligible_count}}</td><td :class="ui('$all', '$td', 'numeric')">{{retentionPreview.proposed[f.key]}} days</td></tr></tbody></table></div><p :class="ui('$all', '$p', 'help')">{{retentionPreview.note}}</p><p :class="ui('$all', '$p', 'help')">Next prune: {{retentionPreview.next_prune}}</p><p :class="ui('$all', '$p', 'help')">Estimate as of <DateTime :class="ui('$all', '$DateTime')" :value="retentionPreview.as_of"/>. This does not save or delete data.</p></section>
    </template>
    <div :class="ui('$all', '$div', 'form-actions')"><button :class="ui('$all', '$button', 'button primary')" type="submit" :disabled="saving||bootstrap?.installation?.state!=='active'">{{ saving ? 'Saving…' : setup ? 'Save and continue' : group === 'privacy' ? 'Save privacy policy' : group === 'retention' ? 'Save retention' : 'Save preferences' }}</button><button v-if="group === 'privacy' && !setup" :class="ui('$all', '$button', 'button')" type="button" @click="previewOpen = !previewOpen">Preview redaction</button><span v-if="dirty" :class="ui('$all', '$span', 'unsaved')">Unsaved changes</span></div>
    <p v-if="group === 'privacy'" :class="ui('$all', '$p', 'help')">Applies to future evidence. Telescope source records have a separate policy.</p><section v-if="previewOpen && group === 'privacy'" :class="ui('$all', '$section', 'preview-panel')"><h3 :class="ui('$all', '$h3')">Redaction preview</h3><label :class="ui('$all', '$label', 'field')">Text to test<textarea :class="ui('$all', '$textarea')" v-model="previewInput" maxlength="16384" rows="3"></textarea></label><button :class="ui('$all', '$button', 'button')" type="button" :disabled="previewing" @click="preview">{{ previewing ? 'Applying…' : 'Apply preview' }}</button><pre v-if="previewText" :class="ui('$all', '$pre', 'preview-result')" aria-live="polite">{{ previewText }}</pre><p :class="ui('$all', '$p', 'help')">Uses the patterns in this draft. The sample is not saved or sent to an external service.</p></section>
  </form>
  <div v-else :class="ui('$all', '$div', 'notice danger')" role="alert"><div :class="ui('$all', '$div')"><p :class="ui('$all', '$p')">{{ failure }}</p><button :class="ui('$all', '$button', 'button')" @click="load">Try again</button></div></div>
</template>
