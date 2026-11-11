<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate } from 'vue-router'
import { LockClosedIcon } from '@heroicons/vue/24/outline'
import {date} from '../composables/investigation'
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
async function preview() { previewing.value = true; try { const result = await api<{text:string}>('/privacy/preview', 'POST', {exception_monitor_private:{text:previewInput.value,patterns:patterns.value.split('\n').map(x=>x.trim()).filter(Boolean)}}); previewText.value = result.text } catch(e) { notify(e instanceof Error ? e.message : 'Preview failed.',true) } finally { previewing.value = false } }
function beforeUnload(e: BeforeUnloadEvent) { if (dirty.value) { e.preventDefault(); e.returnValue = '' } }
window.addEventListener('beforeunload', beforeUnload)
onBeforeUnmount(() => { requestVersion++; window.removeEventListener('beforeunload', beforeUnload) })
const confirmLeave = () => !dirty.value || window.confirm('Leave without saving your changes?')
onBeforeRouteLeave(confirmLeave)
onBeforeRouteUpdate(confirmLeave)
defineExpose({ dirty, confirmLeave })
</script>
<template>
  <div v-if="loading" class="loading-inline" role="status">Loading preferences…</div>
  <form v-else-if="current" @submit.prevent="save">
    <div v-if="failure" class="notice danger" role="alert"><div><strong>{{ conflict ? 'These settings changed since you opened them' : 'Check your changes' }}</strong><p>{{ failure }}</p><button v-if="conflict" type="button" class="text-link" @click="reloadLatest">Reload latest values</button></div></div>
    <template v-if="group === 'general'">
      <h2>Application preferences</h2>
      <label class="field">Display name<input v-model="draft.display_name" :disabled="locked('display_name')" maxlength="120" required :aria-invalid="!!error('display_name')"><span v-if="error('display_name')" class="field-error">{{ error('display_name') }}</span><small v-if="locked('display_name')"><LockClosedIcon/> Managed by deployment</small></label>
      <label class="field">Canonical dashboard URL<input v-model="draft.dashboard_url" disabled><small><LockClosedIcon/> Managed by {{ current.fields.dashboard_url?.reason }}</small></label>
      <label class="field">Display timezone<select v-model="draft.timezone" :disabled="locked('timezone')"><option v-for="timezone in bootstrap?.timezones ?? ['UTC']" :key="timezone" :value="timezone">{{ timezone }}</option></select><span v-if="error('timezone')" class="field-error">{{ error('timezone') }}</span></label>
    </template>
    <template v-if="group === 'privacy'">
      <h2>Capture & privacy</h2><div class="policy-notice"><LockClosedIcon/><div><div class="policy-title"><span class="policy-tag">Deployment policy</span><strong>Telescope capture policy is host-managed</strong></div><p>These preferences restrict retained evidence. They do not enable watchers or broaden Telescope filters.</p></div></div>
      <div class="check-list"><label class="check"><input v-model="draft.retain_stack_trace" type="checkbox" :disabled="locked('retain_stack_trace')">Sanitized stack frames</label><label class="check"><input v-model="draft.retain_execution_context" type="checkbox" :disabled="locked('retain_execution_context')">Request route and execution metadata</label><label class="check"><input v-model="draft.retain_related_logs" type="checkbox" :disabled="locked('retain_related_logs')">Bounded related logs and timeline</label><label class="check"><input v-model="draft.identify_users" type="checkbox" :disabled="locked('identify_users')">Pseudonymous affected-user identifiers <small>Off by default</small></label></div>
      <label class="field">Additional redaction patterns<textarea v-model="patterns" :disabled="locked('redact_patterns')" placeholder="Application-specific patterns" rows="4"></textarea><span v-for="(messages, key) in errors" :key="key" class="field-error">{{ messages.join(' ') }}</span></label>
      <p class="quiet">Always excluded by current policy</p><div class="excluded-categories"><span><LockClosedIcon/>Request bodies and cookies</span><span><LockClosedIcon/>Authorization headers and query bindings</span></div>
    </template>
    <template v-if="group === 'retention'">
      <h2>Evidence retention</h2><p class="help">Preview eligible rows before reducing a limit. Expiry is enforced on reads, and scheduled bounded sweeps remove retained bytes. Increasing a value does not restore expired evidence.</p>
      <label v-for="f in retentionFields" :key="f.key" class="field">{{f.label}} <small>{{current.fields[f.key]?.min??f.min}}–{{current.fields[f.key]?.max??f.max}} days · {{current.fields[f.key]?.source}}</small><input v-model.number="draft[f.key]" type="number" :min="current.fields[f.key]?.min??f.min" :max="current.fields[f.key]?.max??f.max" :disabled="locked(f.key)" required><span v-if="locked(f.key)" class="help">Managed by {{current.fields[f.key]?.reason}}</span><span class="field-error">{{error(f.key)}}</span></label>
      <div class="notice neutral"><p>Summaries, lifetime counts, notes, saved views, GitHub associations and minimal replay identity remain. Five-minute trends have a fixed 48-hour window.</p></div>
      <div class="form-actions"><button class="button" type="button" :disabled="estimating" @click="estimate">{{estimating?'Estimating…':'Preview retention impact'}}</button><RouterLink to="/settings/operations" class="text-link">Pruning history and deployment schedule</RouterLink></div><p v-if="estimateFailure" class="notice danger" role="alert">{{estimateFailure}}</p>
      <section v-if="retentionPreview" class="retention-impact"><h3>Estimated retention impact</h3><p v-if="!estimateCurrent" class="notice warning">This preview belongs to earlier values. Refresh it before saving a reduction.</p><div class="table-scroll" tabindex="0" role="region" aria-label="Retention impact estimate"><table><thead><tr><th>Record family</th><th>Eligible rows</th><th>Proposed limit</th></tr></thead><tbody><tr v-for="f in retentionFields" :key="f.key"><td>{{f.label}}</td><td>{{retentionPreview.counts[f.key]?.at_least?'At least ':''}}{{retentionPreview.counts[f.key]?.eligible_count}}</td><td>{{retentionPreview.proposed[f.key]}} days</td></tr></tbody></table></div><p class="help">{{retentionPreview.note}}</p><p class="help">Next prune: {{retentionPreview.next_prune}}</p><p class="help">Estimate as of {{date(retentionPreview.as_of)}}. This does not save or delete data.</p></section>
    </template>
    <div class="form-actions"><button class="button primary" type="submit" :disabled="saving||bootstrap?.installation?.state!=='active'">{{ saving ? 'Saving…' : setup ? 'Save and continue' : group === 'privacy' ? 'Save privacy policy' : group === 'retention' ? 'Save retention' : 'Save preferences' }}</button><button v-if="group === 'privacy' && !setup" class="button" type="button" @click="previewOpen = !previewOpen">Preview redaction</button><span v-if="dirty" class="unsaved">Unsaved changes</span></div>
    <p v-if="group === 'privacy'" class="help">Applies to future evidence. Telescope source records have a separate policy.</p><section v-if="previewOpen && group === 'privacy'" class="preview-panel"><h3>Redaction preview</h3><label class="field">Text to test<textarea v-model="previewInput" maxlength="16384" rows="3"></textarea></label><button class="button" type="button" :disabled="previewing" @click="preview">{{ previewing ? 'Applying…' : 'Apply preview' }}</button><pre v-if="previewText" class="preview-result" aria-live="polite">{{ previewText }}</pre><p class="help">Uses the patterns in this draft. The sample is not saved or sent to an external service.</p></section>
  </form>
  <div v-else class="notice danger" role="alert"><div><p>{{ failure }}</p><button class="button" @click="load">Try again</button></div></div>
</template>
