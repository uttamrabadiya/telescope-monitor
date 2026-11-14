<script setup lang="ts">
import { ui } from "../ui"
import DateTime from './DateTime.vue'
import type { ErrorGroup } from '../types'
import { bootstrap } from '../composables/api'
import { date } from '../composables/investigation'
import { useRoute } from 'vue-router'
defineProps<{ items: ErrorGroup[]; range: string; compact?: boolean; focused?: boolean }>()
const route = useRoute()
</script>
<template>
  <div tabindex="0" role="region" aria-label="Error table, scroll horizontally for more columns" :class="ui('$all', '$div', 'table-scroll errors-table', ({ compact, focused, 'has-assignee': !compact && bootstrap?.features.assignments }))" >
    <table :class="ui('$all', '$table')"><caption :class="ui('$all', '$caption', 'sr-only')">Captured error groups</caption>
      <thead :class="ui('$all', '$thead')"><tr :class="ui('$all', '$tr')"><th scope="col" :class="ui('$all', '$th')">Error</th><th scope="col" :class="ui('$all', '$th')" v-if="!focused">Status</th><th scope="col" :class="ui('$all', '$th')" v-if="!compact">Environment</th><th scope="col" :class="ui('$all', '$th')" v-if="!compact && bootstrap?.features.assignments">Assignee</th><th scope="col" :class="ui('$all', '$th', 'numeric')">Occurrences ({{ range }})</th><th scope="col" :class="ui('$all', '$th', 'numeric')" v-if="!compact && !focused">Lifetime</th><th scope="col" :class="ui('$all', '$th', 'table-time')">Last seen</th></tr></thead>
      <tbody :class="ui('$all', '$tbody')">
        <tr :class="ui('$all', '$tr')" v-for="issue in items" :key="issue.id">
          <td :class="ui('$all', '$td')">
            <div :class="ui('$all', '$div', 'error-row-heading')"><RouterLink :to="{ path: '/errors/' + issue.id, query: { range: route.query.range ?? '24h', returnTo: route.fullPath } }" :class="ui('$all', '$a', 'error-title')">{{ issue.message || issue.class }}</RouterLink><template v-if="focused"><span v-if="route.query.status === 'all'" :class="ui('$all', '$span', 'status-badge', ('status-' + issue.status))" >{{ issue.status }}</span><span v-if="issue.regressions" :class="ui('$all', '$span', 'badge')">Regression</span><span v-if="issue.is_test" :class="ui('$all', '$span', 'badge')">Test</span><span v-if="issue.recovered_only" :class="ui('$all', '$span', 'badge')" title="Only historical or recovered occurrences so far">Historical</span></template></div>
            <div :class="ui('$all', '$div', 'row-secondary')">{{ issue.class }}<span :class="ui('$all', '$span')" v-if="issue.origins?.length"> · {{ issue.origins.join(', ') }}</span></div>
            <div v-if="!compact" :class="ui('$all', '$div', 'code row-file')" :title="'First seen: ' + date(issue.first_seen_at)">{{ issue.file }}:{{ issue.line }}</div>
          </td>
          <td :class="ui('$all', '$td')" v-if="!focused"><span :class="ui('$all', '$span', 'status-badge', ('status-' + issue.status))" >{{ issue.status }}</span><span v-if="issue.regressions" :class="ui('$all', '$span', 'badge')">Regression</span><span v-if="issue.is_test" :class="ui('$all', '$span', 'badge')">Test</span><span v-if="issue.recovered_only" :class="ui('$all', '$span', 'badge')" title="Only historical or recovered occurrences so far">Historical</span></td>
          <td :class="ui('$all', '$td')" v-if="!compact">{{ issue.environment }}</td>
          <td :class="ui('$all', '$td')" v-if="!compact && bootstrap?.features.assignments">{{ issue.assignee?.label ?? 'Unassigned' }}</td>
          <td :class="ui('$all', '$td', 'numeric')"><strong :class="ui('$all', '$strong')" v-if="focused">{{ issue.range_count }}</strong><template v-else>{{ issue.range_count }}</template><small v-if="focused" :class="ui('$all', '$small', 'lifetime-count')">{{ issue.lifetime_count }} lifetime</small></td>
          <td v-if="!compact && !focused" :class="ui('$all', '$td', 'numeric')">{{ issue.lifetime_count }}</td>
          <td :class="ui('$all', '$td', 'time-cell')"><DateTime :class="ui('$all', '$DateTime')" :value="issue.last_seen_at"/></td>
        </tr>
        <tr :class="ui('$all', '$tr')" v-if="!items.length"><td :colspan="focused ? (bootstrap?.features.assignments ? 5 : 4) : compact ? 4 : bootstrap?.features.assignments ? 7 : 6" :class="ui('$all', '$td', 'table-empty')">No errors match these filters.</td></tr>
      </tbody>
    </table>
  </div>
</template>
