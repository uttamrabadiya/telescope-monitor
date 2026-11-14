<script setup lang="ts">
import { ui } from "../ui"
import { computed, reactive, ref, useId, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { FunnelIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import AssigneeFilter from './AssigneeFilter.vue'
import SavedViews from './SavedViews.vue'
import { bootstrap } from '../composables/api'

defineProps<{ allowedTags?: string[] }>()
const route = useRoute(), router = useRouter(), id = useId(), expanded = ref(false), filterToggle = ref<HTMLButtonElement | null>(null)
const draft = reactive<Record<string, string>>({})
const extraKeys = ['origin', 'assignee', 'class', 'tag', 'first_after', 'first_before', 'last_after', 'last_before', 'include_tests', 'per_page']
const active = computed(() => extraKeys.filter(key => typeof route.query[key] === 'string' && route.query[key] !== '' && (key !== 'include_tests' || route.query[key] === '1') && (key !== 'per_page' || route.query[key] !== '25')).length)
watch(() => route.query, () => {
  for (const key of Object.keys(draft)) delete draft[key]
  for (const [key, value] of Object.entries(route.query)) if (typeof value === 'string') draft[key] = value
  draft.sort ??= 'last_seen'; draft.per_page ??= '25'; draft.origin ??= ''
}, { immediate: true })
function apply() {
  const query = Object.fromEntries(Object.entries(draft).filter(([key, value]) => value !== '' && !(key === 'per_page' && value === '25') && !(key === 'include_tests' && value === '0')))
  router.replace({ query: { ...query, page: undefined } })
}
function closeFilters() { expanded.value = false; filterToggle.value?.focus() }
function reset() {
  for (const key of extraKeys) delete draft[key]
  apply()
}
function applySearch() { router.replace({ query: { ...route.query, search: draft.search || undefined, page: undefined } }) }
function sort() { router.replace({ query: { ...route.query, sort: draft.sort, page: undefined } }) }
</script>

<template>
  <section :class="ui('$all', '$section', 'error-filter-area')" aria-label="Error filters">
    <div :class="ui('$all', '$div', 'error-list-toolbar')">
      <form :class="ui('$all', '$form', 'error-list-search')" role="search" @submit.prevent="applySearch">
        <label :class="ui('$all', '$label', 'error-search-control')"><MagnifyingGlassIcon :class="ui('$all', '$svg')" aria-hidden="true"/><span :class="ui('$all', '$span', 'sr-only')">Search exception class or message</span><input :class="ui('$all', '$input')" v-model="draft.search" type="search" placeholder="Search errors" :aria-describedby="id + '-search-history'" @search="applySearch"></label>
      </form>
      <button ref="filterToggle" type="button" :class="ui('$all', '$button', 'button error-filter-toggle', ({ selected: expanded || active }))"  :aria-expanded="expanded" :aria-controls="id + '-advanced'" @click="expanded = !expanded"><FunnelIcon :class="ui('$all', '$svg')" aria-hidden="true"/>Filters<span v-if="active" :class="ui('$all', '$span', 'filter-count')">{{ active }}</span></button>
      <label :class="ui('$all', '$label', 'error-sort')"><span :class="ui('$all', '$span', 'sr-only')">Sort errors</span><select :class="ui('$all', '$select')" v-model="draft.sort" @change="sort"><option :class="ui('$all', '$option')" value="last_seen">Last seen</option><option :class="ui('$all', '$option')" value="first_seen">First seen</option><option :class="ui('$all', '$option')" value="occurrences">Occurrences in range</option></select></label>
      <SavedViews :class="ui('$all', '$SavedViews')" v-if="bootstrap?.features.collaboration"/>
    </div>
    <p :id="id + '-search-history'" :class="ui('$all', '$p', 'search-disclosure')">Press Enter to search. Search terms appear in authenticated URLs and browser history.</p>
    <form v-if="expanded" :id="id + '-advanced'" :class="ui('$all', '$form', 'error-advanced-panel')" @submit.prevent="apply">
      <div :class="ui('$all', '$div', 'section-heading')"><h2 :class="ui('$all', '$h2')">Filter errors</h2><button type="button" :class="ui('$all', '$button', 'text-link')" @click="reset">Reset advanced filters</button></div>
      <div :class="ui('$all', '$div', 'filter-grid')">
        <label :class="ui('$all', '$label', 'field')">Origin<select :class="ui('$all', '$select')" v-model="draft.origin"><option :class="ui('$all', '$option')" value="">Any origin</option><option :class="ui('$all', '$option')" v-for="origin in ['web', 'job', 'command', 'unknown']" :key="origin">{{ origin }}</option></select></label>
        <AssigneeFilter :class="ui('$all', '$AssigneeFilter')" v-if="bootstrap?.features.assignments" v-model="draft.assignee"/>
        <label :class="ui('$all', '$label', 'field')">Exact exception class<input :class="ui('$all', '$input')" v-model="draft.class" placeholder="Any exception class"></label>
        <label v-if="allowedTags?.length" :class="ui('$all', '$label', 'field')">Permitted tag (key:value)<input :class="ui('$all', '$input')" v-model="draft.tag"><small :class="ui('$all', '$small')">{{ allowedTags.join(', ') }}</small></label>
        <label v-for="field in ['first_after', 'first_before', 'last_after', 'last_before']" :key="field" :class="ui('$all', '$label', 'field')">{{ field.replace('_', ' seen ') }} (UTC)<input :class="ui('$all', '$input')" v-model="draft[field]" type="date"></label>
        <label :class="ui('$all', '$label', 'field')">Rows per page<select :class="ui('$all', '$select')" v-model="draft.per_page"><option :class="ui('$all', '$option')" v-for="size in [25, 50, 100]" :key="size">{{ size }}</option></select></label>
      </div>
      <label :class="ui('$all', '$label', 'checkbox-row')"><input :class="ui('$all', '$input')" type="checkbox" :checked="draft.include_tests === '1'" @change="draft.include_tests = ($event.target as HTMLInputElement).checked ? '1' : '0'">Include test events</label>
      <div :class="ui('$all', '$div', 'inline-actions')"><button type="submit" :class="ui('$all', '$button', 'button primary')">Apply filters</button><button type="button" :class="ui('$all', '$button', 'button')" @click="closeFilters">Close filters</button></div>
    </form>
  </section>
</template>
