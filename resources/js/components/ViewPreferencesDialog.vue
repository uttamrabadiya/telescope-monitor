<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref } from 'vue'
import { ArrowDownIcon, ArrowUpIcon, PlusIcon, StarIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { api, ApiError, notify } from '../composables/api'
import { useCollaborationAction } from '../composables/collaboration'
import { moveFavorite, preferenceDraft, preferenceKey, preferencePayload } from '../composables/viewPreferences'
import type { ViewPreferenceDraft } from '../composables/viewPreferences'
import type { SavedView, ViewIndex, ViewPreferences } from '../types'
import { ui } from '../ui'

const props = defineProps<{ preferences: ViewPreferences; views: SavedView[]; builtins: string[] }>()
const emit = defineEmits<{ saved: [preferences: ViewPreferences]; closed: [] }>()
const dialog = ref<HTMLDialogElement | null>(null), opened = ref(false), saving = ref(false), loading = ref(false)
const draft = ref<ViewPreferenceDraft>({ default_view_ref: null, favorites: [] }), initial = ref(''), version = ref(0)
const addSelector = ref<HTMLSelectElement | null>(null)
const addition = ref(''), error = ref(''), conflict = ref(false), catalog = ref<SavedView[]>([]), page = ref(0), lastPage = ref(1)
const perform = useCollaborationAction()
let generation = 0, disposed = false
const dirty = computed(() => opened.value && initial.value !== preferenceKey(draft.value))
const options = computed(() => {
  const result = new Map<string, { id: string; label: string; available: boolean; kind: string }>()
  for (const id of props.builtins) result.set(id, { id, label: builtinTitle(id), available: true, kind: 'Built-in' })
  for (const view of [...props.views, ...catalog.value]) result.set(view.id, { id: view.id, label: view.name, available: view.state === 'ready', kind: view.visibility === 'shared' ? 'Shared view' : 'Personal view' })
  for (const [id, label] of Object.entries(props.preferences.labels ?? {})) if (!result.has(id)) result.set(id, { id, label, available: true, kind: id.startsWith('builtin:') ? 'Built-in' : 'Saved view' })
  for (const id of [...draft.value.favorites, ...(draft.value.default_view_ref ? [draft.value.default_view_ref] : [])]) if (!result.has(id)) result.set(id, { id, label: 'Unavailable view', available: false, kind: 'Unavailable' })
  return [...result.values()]
})
const addable = computed(() => options.value.filter(option => option.available && !draft.value.favorites.includes(option.id)))
const defaultSelection = computed({ get: () => draft.value.default_view_ref ?? 'builtin:unresolved', set: value => { draft.value.default_view_ref = value === 'builtin:unresolved' ? null : value } })
function builtinTitle(id: string) { const name = id.replace('builtin:', '').replaceAll('_', ' '); return name.charAt(0).toUpperCase() + name.slice(1) }
function option(id: string) { return options.value.find(item => item.id === id)! }
async function show() {
  if (opened.value) return
  draft.value = preferenceDraft(props.preferences); initial.value = preferenceKey(draft.value); version.value = props.preferences.version
  addition.value = ''; error.value = ''; conflict.value = false; opened.value = true; catalog.value = []; page.value = 0; lastPage.value = 1
  await nextTick(); dialog.value?.showModal(); await loadPage(1)
}
function cancel() { if (!saving.value) { opened.value = false; dialog.value?.close() } }
function closed() { opened.value = false; generation++; loading.value = false; emit('closed') }
async function loadPage(number: number) {
  loading.value = true; error.value = ''; const current = ++generation
  try {
    const result = await api<ViewIndex>('/views?page=' + number)
    if (disposed || current !== generation) return
    catalog.value = number === 1 ? result.items : [...catalog.value, ...result.items]
    page.value = result.page; lastPage.value = result.last_page
  } catch (e) { if (!disposed && current === generation) error.value = e instanceof Error ? e.message : 'Views could not be loaded.' }
  finally { if (!disposed && current === generation) loading.value = false }
}
async function add() {
  if (!addition.value || !addable.value.some(item => item.id === addition.value)) return
  if (draft.value.favorites.length >= 100) { error.value = 'You can keep up to 100 favorite views.'; return }
  draft.value.favorites.push(addition.value); addition.value = ''; error.value = ''
  await nextTick(); addSelector.value?.focus()
}
async function remove(id: string) { draft.value.favorites = draft.value.favorites.filter(ref => ref !== id); await nextTick(); addSelector.value?.focus() }
async function reorder(id: string, direction: number) {
  moveFavorite(draft.value, draft.value.favorites.indexOf(id), direction)
  await nextTick()
  const row = [...(dialog.value?.querySelectorAll<HTMLElement>('[data-favorite]') ?? [])].find(item => item.dataset.favorite === id)
  row?.querySelector<HTMLButtonElement>('button:not(:disabled)')?.focus()
}
async function save() {
  if (saving.value || !dirty.value) return
  saving.value = true; error.value = ''; conflict.value = false
  try {
    const payload = preferencePayload(draft.value, version.value)
    const result = await perform<{ version: number }>('/view-preferences', 'PUT', payload)
    const preferences: ViewPreferences = { ...payload, version: result.version, labels: Object.fromEntries(options.value.filter(item => item.available).map(item => [item.id, item.label])) }
    initial.value = preferenceKey(draft.value); emit('saved', preferences); notify('View preferences saved.'); dialog.value?.close()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'View preferences could not be saved.'
    conflict.value = e instanceof ApiError && e.status === 409
  } finally { saving.value = false }
}
async function reload() {
  saving.value = true; error.value = ''
  try { const latest = await api<ViewPreferences>('/view-preferences'); emit('saved', latest); draft.value = preferenceDraft(latest); version.value = latest.version; initial.value = preferenceKey(draft.value); conflict.value = false }
  catch (e) { error.value = e instanceof Error ? e.message : 'Preferences could not be reloaded.' }
  finally { saving.value = false }
}
onUnmounted(() => { disposed = true; generation++ })
defineExpose({ show, dirty })
</script>

<template>
  <Teleport to="body">
    <dialog ref="dialog" aria-labelledby="view-preferences-heading" aria-describedby="view-preferences-description" :class="ui('$all', '$dialog', 'm-auto w-[calc(100vw_-_32px)] max-w-[620px] max-h-[calc(100dvh_-_32px)] overflow-hidden rounded-[6px] border border-tm-border bg-tm-surface p-0 text-tm-text shadow-xl backdrop:bg-black/40 open:flex open:flex-col')" @cancel.prevent="cancel" @close="closed" @click.self="cancel">
      <header class="flex shrink-0 items-start justify-between gap-4 border-b border-tm-border px-6 py-5 max-[600px]:px-4">
        <div><h2 id="view-preferences-heading" :class="ui('$all', '$h2', '[font-size:21px]')">View preferences</h2><p id="view-preferences-description" class="mb-0 mt-1 text-sm leading-relaxed text-tm-muted">Customize your Errors page. These settings apply only to you.</p></div>
        <button type="button" :class="ui('$all', '$button', 'button icon-button shrink-0')" aria-label="Close view preferences" :disabled="saving" @click="cancel"><XMarkIcon class="size-5" aria-hidden="true"/></button>
      </header>
      <form id="view-preferences-form" class="min-h-0 flex-1 overflow-y-auto px-6 py-5 max-[600px]:px-4" @submit.prevent="save">
        <div v-if="error" :class="ui('$all', '$div', 'notice danger')" role="alert"><div><p class="m-0">{{ error }}</p><button v-if="conflict" type="button" :class="ui('$all', '$button', 'button mt-3')" :disabled="saving" @click="reload">Reload saved preferences</button><button v-else-if="page === 0" type="button" :class="ui('$all', '$button', 'button mt-3')" :disabled="loading || saving" @click="loadPage(1)">Retry loading views</button></div></div>
        <fieldset class="m-0 min-w-0 border-0 p-0" :disabled="saving">
          <label class="block text-[15px] font-semibold" for="default-error-view">Default error view</label>
          <p id="default-error-view-help" class="mb-3 mt-1 text-sm leading-relaxed text-tm-muted">The view that opens when you visit Errors.</p>
          <select id="default-error-view" v-model="defaultSelection" autofocus aria-describedby="default-error-view-help" :class="ui('$all', '$select', 'h-control w-full rounded-sm border border-tm-input-border bg-tm-surface px-3 [font-size:15px] text-tm-text disabled:cursor-not-allowed disabled:bg-tm-canvas disabled:text-tm-muted')">
            <option v-for="item in options" :key="item.id" :value="item.id" :disabled="!item.available">{{ item.label }}{{ !item.available ? ' · Unavailable' : item.kind === 'Built-in' ? '' : ' · ' + item.kind }}</option>
          </select>
          <section class="mt-7 border-t border-tm-border pt-6" aria-labelledby="favorite-views-heading">
            <h3 id="favorite-views-heading" class="m-0 flex items-center gap-2 text-[17px] font-semibold"><StarIcon class="size-5 text-tm-primary" aria-hidden="true"/>Favorite views</h3>
            <p class="mb-4 mt-1 text-sm leading-relaxed text-tm-muted">Shown in Saved views. Use the arrows to change their order.</p>
            <ol v-if="draft.favorites.length" class="m-0 list-none p-0">
              <li v-for="(id, position) in draft.favorites" :key="id" :data-favorite="id" class="flex items-center gap-3 border-b border-tm-border py-3 first:border-t">
                <span class="w-5 shrink-0 text-center text-xs tabular-nums text-tm-muted">{{ position + 1 }}</span>
                <div class="min-w-0 flex-1"><strong class="block break-words text-[15px] font-medium">{{ option(id).label }}</strong><span class="text-xs text-tm-muted">{{ option(id).kind }}</span></div>
                <div class="flex shrink-0 gap-1">
                  <button type="button" :class="ui('$all', '$button', 'button icon-button')" :disabled="position === 0" :aria-label="'Move ' + option(id).label + ' up'" title="Move up" @click="reorder(id, -1)"><ArrowUpIcon class="size-4" aria-hidden="true"/></button>
                  <button type="button" :class="ui('$all', '$button', 'button icon-button')" :disabled="position === draft.favorites.length - 1" :aria-label="'Move ' + option(id).label + ' down'" title="Move down" @click="reorder(id, 1)"><ArrowDownIcon class="size-4" aria-hidden="true"/></button>
                  <button type="button" :class="ui('$all', '$button', 'button icon-button')" :aria-label="'Remove ' + option(id).label + ' from favorites'" title="Remove from favorites" @click="remove(id)"><XMarkIcon class="size-4" aria-hidden="true"/></button>
                </div>
              </li>
            </ol>
            <p v-else class="my-4 rounded-sm bg-tm-subtle-surface px-4 py-5 text-sm leading-relaxed text-tm-muted">No favorites yet. Add the views you use most.</p>
            <div class="mt-4 flex items-end gap-2 max-[440px]:flex-wrap">
              <label class="min-w-0 flex-1 max-[440px]:basis-full"><span class="sr-only">View to add to favorites</span><select ref="addSelector" v-model="addition" :class="ui('$all', '$select', 'w-full rounded-sm border border-tm-input-border bg-tm-surface px-3 [font-size:15px] text-tm-text disabled:cursor-not-allowed disabled:bg-tm-canvas disabled:text-tm-muted')"><option value="">Choose a view to add</option><option v-for="item in addable" :key="item.id" :value="item.id">{{ item.label }}{{ item.kind === 'Built-in' ? '' : ' · ' + item.kind }}</option></select></label>
              <button type="button" :class="ui('$all', '$button', 'button whitespace-nowrap')" :disabled="!addition || draft.favorites.length >= 100" @click="add"><PlusIcon class="size-4" aria-hidden="true"/>Add favorite</button>
            </div>
            <p v-if="loading" role="status" class="mb-0 mt-3 text-sm text-tm-muted">Loading views…</p>
            <button v-else-if="page < lastPage" type="button" :class="ui('$all', '$button', 'text-link mt-2 text-sm')" @click="loadPage(page + 1)">Load more saved views</button>
          </section>
        </fieldset>
      </form>
      <footer class="flex shrink-0 items-center justify-between gap-3 border-t border-tm-border bg-tm-surface px-6 py-4 max-[600px]:flex-wrap max-[600px]:px-4">
        <span class="text-xs text-tm-muted max-[600px]:basis-full" role="status">{{ dirty ? 'Unsaved changes' : 'Preferences are up to date' }}</span>
        <div class="ml-auto flex gap-2"><button type="button" :class="ui('$all', '$button', 'button')" :disabled="saving" @click="cancel">Cancel</button><button type="submit" form="view-preferences-form" :class="ui('$all', '$button', 'button primary')" :disabled="saving || !dirty">{{ saving ? 'Saving…' : 'Save changes' }}</button></div>
      </footer>
    </dialog>
  </Teleport>
</template>
