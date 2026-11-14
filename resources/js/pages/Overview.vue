<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowPathIcon, BellIcon, CircleStackIcon } from '@heroicons/vue/24/outline'
import PageHeading from '../components/PageHeading.vue'
import TrendChart from '../components/TrendChart.vue'
import InvestigationFilters from '../components/InvestigationFilters.vue'
import DateTime from '../components/DateTime.vue'
import { bootstrap } from '../composables/api'
import { activityLabel } from '../composables/collaboration'
import { count, comparisonLabel, ratio, share } from '../composables/overview'
import { useInvestigation, queryString, zone } from '../composables/investigation'
import type { OverviewResult } from '../types'
import { ui } from '../ui'

const route = useRoute()
const { data, loading, error, reload } = useInvestigation<OverviewResult>(() => '/overview?' + queryString(route.query))
const rangeText = computed(() => ({ '1h': 'Last hour', '24h': 'Last 24h', '7d': 'Last 7 days', '30d': 'Last 30 days' }[data.value?.window.range ?? '24h'] ?? 'Selected period'))
const origins = computed(() => (data.value?.origins.items ?? []).filter(item => item.value !== 'unknown' || Number(item.count) > 0))
const attention = computed(() => data.value?.errors.items.slice(0, 3) ?? [])
const comparisonTitle = computed(() => !data.value ? '' : !data.value.comparison.available ? data.value.comparison.detail : `${count(data.value.comparison.current_occurrences ?? '0')} selected vs ${count(data.value.comparison.previous_occurrences ?? '0')} previous, across matching complete intervals. ${data.value.comparison.detail}`)
function originLabel(origin: string) { return ({ web: 'Web', job: 'Jobs', command: 'Commands', unknown: 'Unknown' } as Record<string, string>)[origin] ?? 'Unknown' }
function inbox(status = 'unresolved', extra: Record<string, string> = {}) {
  const keys = ['range', 'environment', 'include_tests', 'class', 'search', 'origin', 'tag', 'assignee', 'first_after', 'first_before', 'last_after', 'last_before']
  const filters = Object.fromEntries(keys.flatMap(key => typeof route.query[key] === 'string' ? [[key, route.query[key]]] : []))
  return { path: '/errors', query: { ...filters, status, sort: 'occurrences', ...extra } }
}
function investigation(id: string) { return { path: '/errors/' + id, query: { range: data.value?.window.range ?? '24h', returnTo: route.fullPath } } }
</script>

<template>
  <PageHeading title="Overview" subtitle="Prioritize errors and track captured activity.">
    <InvestigationFilters :environments="data?.errors.environments"/>
    <button type="button" :class="ui('$all', '$button', 'button icon-button')" aria-label="Refresh overview" :disabled="loading" @click="reload"><ArrowPathIcon class="size-5" aria-hidden="true"/></button>
  </PageHeading>
  <div v-if="error" :class="ui('$all', '$div', 'notice danger')" role="alert"><p class="m-0">{{ error }}</p><button type="button" :class="ui('$all', '$button', 'button')" @click="reload">Try again</button></div>
  <p v-if="loading" role="status" :class="ui('$all', '$p', 'loading-message')">Loading captured activity…</p>
  <template v-if="data">
    <div v-if="data.capture && (!data.capture.source_enabled || !data.capture.last_received_at || data.capture.failed || data.capture.pending)" :class="ui('$all', '$div', 'notice warning')" role="status">
      <p class="m-0">{{ !data.capture.source_enabled ? 'Capture is disabled.' : !data.capture.last_received_at ? 'Capture has not been verified.' : data.capture.failed ? count(data.capture.failed) + ' capture items failed processing.' : count(data.capture.pending) + ' capture items are waiting for processing.' }} Counts reflect captured events.</p>
      <RouterLink v-if="bootstrap?.capabilities.manage" :to="data.capture.source_enabled && data.capture.last_received_at ? '/settings/health' : '/setup'" :class="ui('$all', '$a', 'text-link shrink-0')">{{ data.capture.source_enabled && data.capture.last_received_at ? 'Inspect system health' : 'Review setup' }}</RouterLink>
    </div>
    <section class="grid grid-cols-2 gap-x-6 gap-y-5 border-y border-tm-border py-3 lg:grid-cols-[.85fr_1.3fr_1fr_1fr]" aria-label="Error summary">
      <div class="min-w-0 lg:border-r lg:border-tm-border lg:pr-5"><h2 class="m-0 text-sm font-medium text-tm-muted">Open error groups</h2><RouterLink :to="inbox()" class="mt-1 inline-block text-[30px] font-semibold leading-tight tabular-nums text-tm-text hover:text-tm-primary hover:underline">{{ count(data.totals.open_groups) }}</RouterLink><p class="mb-0 mt-1 text-xs text-tm-muted">Current</p></div>
      <div class="min-w-0 lg:border-r lg:border-tm-border lg:pr-5"><h2 class="m-0 text-sm font-medium text-tm-muted">Captured occurrences</h2><strong class="mt-1 block text-[30px] font-semibold leading-tight tabular-nums">{{ count(data.totals.occurrences) }}</strong><p class="mb-0 mt-1 text-xs leading-relaxed text-tm-muted" :title="comparisonTitle">{{ rangeText }} · {{ comparisonLabel(data.comparison) }}</p></div>
      <div class="min-w-0 lg:border-r lg:border-tm-border lg:pr-5"><h2 class="m-0 text-sm font-medium text-tm-muted">New groups</h2><strong class="mt-1 block text-[30px] font-semibold leading-tight tabular-nums">{{ count(data.totals.new_groups) }}</strong><p class="mb-0 mt-1 text-xs text-tm-muted">{{ rangeText }}</p></div>
      <div class="min-w-0"><h2 class="m-0 text-sm font-medium text-tm-muted">Regressions</h2><strong class="mt-1 block text-[30px] font-semibold leading-tight tabular-nums">{{ count(data.totals.regressions) }}</strong><p class="mb-0 mt-1 text-xs text-tm-muted">{{ rangeText }}</p></div>
    </section>
    <div class="mt-5 grid min-w-0 gap-6 border-b border-tm-border pb-5 xl:grid-cols-[minmax(0,2.3fr)_minmax(240px,1fr)]">
      <TrendChart :buckets="data.buckets" :comparison="data.comparison" :range="data.window.range"/>
      <section class="min-w-0 border-t border-tm-border pt-5 xl:border-t-0 xl:border-l xl:pl-7 xl:pt-0" aria-labelledby="overview-origin-heading">
        <h2 id="overview-origin-heading" :class="ui('$all', '$h2', '[font-size:21px]')">By origin</h2>
        <ul class="mb-0 mt-5 list-none space-y-5 p-0">
          <li v-for="origin in origins" :key="origin.value">
            <div class="mb-2 flex items-baseline justify-between gap-3"><RouterLink :to="inbox('all', { origin: origin.value })" class="text-[15px] font-medium text-tm-text hover:text-tm-primary hover:underline">{{ originLabel(origin.value) }}</RouterLink><span class="whitespace-nowrap text-sm tabular-nums">{{ count(origin.count) }} <span class="text-tm-muted">({{ share(origin.count, data.origins.total) }})</span></span></div>
            <svg viewBox="0 0 100 8" preserveAspectRatio="none" class="block h-2 w-full" aria-hidden="true"><rect width="100" height="8" rx="1" class="fill-tm-subtle-surface"/><rect :width="ratio(origin.count, data.origins.total)" height="8" rx="1" class="fill-tm-primary"/></svg>
          </li>
        </ul>
        <p v-if="Number(data.origins.total) === 0" class="mb-0 mt-4 text-xs text-tm-muted">No captured origins in this period.</p>
        <p class="mb-0 mt-4 text-xs leading-relaxed text-tm-muted" :title="data.origins.coverage">{{ data.origins.matches_aggregates ? 'Captured events in the selected period.' : data.origins.coverage }}</p>
      </section>
    </div>
    <section class="mt-5" aria-labelledby="overview-attention-heading">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3"><h2 id="overview-attention-heading" :class="ui('$all', '$h2', '[font-size:21px]')">Needs attention</h2><RouterLink :to="inbox()" :class="ui('$all', '$a', 'text-link text-sm')">View all {{ count(data.totals.open_groups) }} open groups</RouterLink></div>
      <div :class="ui('$all', '$div', 'table-scroll')" tabindex="0" role="region" aria-label="Errors needing attention; scroll horizontally for more columns">
        <table :class="ui('$all', '$table', 'min-w-[720px] table-dense')"><caption :class="ui('$all', '$caption', 'sr-only')">Errors needing attention, ordered by captured occurrences in the selected period</caption><thead :class="ui('$all', '$thead')"><tr :class="ui('$all', '$tr')"><th scope="col" :class="ui('$all', '$th', 'w-[50%]')">Error</th><th scope="col" :class="ui('$all', '$th', 'numeric')">Captured ({{ data.window.range }})</th><th scope="col" :class="ui('$all', '$th', 'numeric')">Share of captured</th><th scope="col" :class="ui('$all', '$th', 'table-time')">Last seen</th></tr></thead><tbody :class="ui('$all', '$tbody')">
          <tr v-for="issue in attention" :key="issue.id" :class="ui('$all', '$tr')"><td :class="ui('$all', '$td')"><div class="flex flex-wrap items-baseline gap-2"><RouterLink :to="investigation(issue.id)" class="line-clamp-2 text-[16px] font-semibold text-tm-primary underline underline-offset-2">{{ issue.message || issue.class }}</RouterLink><span v-if="issue.regressions" :class="ui('$all', '$span', 'badge bg-tm-section-active text-tm-primary')">Regression</span><span v-if="issue.is_test" :class="ui('$all', '$span', 'badge')">Test</span><span v-if="issue.recovered_only" :class="ui('$all', '$span', 'badge')">Historical</span></div><p class="mb-0 mt-1 truncate text-[13px] text-tm-muted" :title="issue.class + ' · ' + issue.file + ':' + issue.line">{{ issue.class }}<span v-if="issue.origins?.length"> · {{ issue.origins.map(originLabel).join(', ') }}</span></p></td><td :class="ui('$all', '$td', 'numeric')">{{ count(issue.range_count) }}</td><td :class="ui('$all', '$td', 'numeric')">{{ share(issue.range_count, data.totals.occurrences) }}</td><td :class="ui('$all', '$td', 'table-time')"><DateTime :value="issue.last_seen_at"/></td></tr>
          <tr v-if="!attention.length" :class="ui('$all', '$tr')"><td colspan="4" :class="ui('$all', '$td', 'table-empty')">No open error groups match this selection. <RouterLink :to="inbox('all')" :class="ui('$all', '$a', 'text-link')">Browse captured errors</RouterLink></td></tr>
        </tbody></table>
      </div>
    </section>
    <section class="mt-5 grid gap-5 rounded-[6px] border border-tm-border p-4 lg:grid-cols-2" aria-label="Monitoring observations">
      <div class="flex min-w-0 items-start gap-3 lg:border-r lg:border-tm-border lg:pr-5"><CircleStackIcon class="mt-1 size-5 shrink-0 text-tm-muted" aria-hidden="true"/><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="m-0 text-[17px] font-semibold">Capture</h3><RouterLink v-if="bootstrap?.capabilities.manage" to="/settings/health" :class="ui('$all', '$a', 'text-link text-sm')">System health</RouterLink></div><p v-if="data.capture" class="mb-0 mt-1 text-sm leading-relaxed"><span v-if="!data.capture.source_enabled" class="text-tm-warning-text">Disabled · </span><span :class="{ 'text-tm-warning-text': data.capture.pending > 0 }">{{ count(data.capture.pending) }} pending</span> · <span :class="{ 'text-tm-danger-text': data.capture.failed > 0 }">{{ count(data.capture.failed) }} failed</span> · Last processing <DateTime :value="data.capture.last_processed_at"/></p><p v-else class="mb-0 mt-1 text-sm text-tm-muted">Capture observations unavailable.</p></div></div>
      <div class="flex min-w-0 items-start gap-3"><BellIcon class="mt-1 size-5 shrink-0 text-tm-muted" aria-hidden="true"/><div class="min-w-0 flex-1"><div class="flex flex-wrap items-center justify-between gap-2"><h3 class="m-0 text-[17px] font-semibold">Notifications</h3><RouterLink v-if="data.delivery.state === 'available'" to="/alerts/history" :class="ui('$all', '$a', 'text-link text-sm')">Delivery history</RouterLink></div><p v-if="data.delivery.observations" class="mb-0 mt-1 text-sm leading-relaxed">{{ count(data.delivery.observations.pending) }} pending · <span :class="{ 'text-tm-danger-text': data.delivery.observations.failed > 0 }">{{ count(data.delivery.observations.failed) }} failed</span> · <span :class="{ 'text-tm-warning-text': data.delivery.observations.needs_review > 0 }">{{ count(data.delivery.observations.needs_review) }} need review</span></p><p v-else class="mb-0 mt-1 text-sm text-tm-muted">{{ data.delivery.detail }}</p></div></div>
    </section>
    <p class="mb-0 mt-3 text-xs leading-relaxed text-tm-muted">Captured events only · Data as of <DateTime :value="data.window.as_of"/> · {{ zone() }}. Capture and delivery observations are installation-wide. Delivery acceptance does not confirm inbox receipt.</p>
    <details class="mt-5 border-t border-tm-border pt-3"><summary class="cursor-pointer text-sm font-medium text-tm-primary">Recent activity ({{ data.activity.length }})</summary><ul class="m-0 list-none divide-y divide-tm-border p-0"><li v-for="(activity, index) in data.activity" :key="index" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm"><RouterLink :to="investigation(activity.issue_id)" class="min-w-0 text-tm-text hover:text-tm-primary hover:underline">{{ activityLabel(activity.type) }} · {{ activity.summary }}</RouterLink><DateTime :value="activity.at"/></li><li v-if="!data.activity.length" class="py-3 text-sm text-tm-muted">No recent activity.</li></ul></details>
  </template>
</template>
