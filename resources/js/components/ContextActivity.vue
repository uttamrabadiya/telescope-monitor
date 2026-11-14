<script setup lang="ts">
import { ui } from "../ui"
import { computed } from 'vue'
import ReproducePanel from './ReproducePanel.vue'
import type { Evidence } from '../types'
import { activityDescription, contextActivity, milliseconds, sqlAvailability } from '../composables/contextActivity'
const props = defineProps<{ evidence: Evidence; telescopeUrl?: string | null }>()
const activity = computed(() => contextActivity(props.evidence))
const sqlQueries = computed(() => activity.value.queries.filter(q => q.sql_template && q.sql_state === 'retained'))
const measurements = computed(() => activity.value.queries.filter(q => !q.sql_template || q.sql_state !== 'retained'))
const execution = computed(() => Object.entries(props.evidence.execution).filter(([key,value]) => value !== '' && value !== null && value !== undefined && !(key === 'status' && !value) && !(key === 'path' && props.evidence.execution.path_template)))
const labels: Record<string, string> = { method:'Method',path:'Path',controller:'Controller',status:'HTTP status',job_class:'Job',command:'Command',route_name:'Route name',path_template:'Route template' }
const availability: Record<string, string> = { trace:'Stack trace',message:'Exception message',execution:'Execution context',logs:'Related logs',user:'Affected-user identification',timeline:'Related activity',queries:'Database queries',reportability:'Original reportability' }
const state: Record<string, string> = { available:'Retained',excluded:'Excluded by privacy policy',unavailable:'Not retained or unavailable',truncated:'Partially retained',pseudonymous:'Pseudonymous identifiers only' }
</script>
<template>
  <div :class="ui('$all', '$div', 'context-activity')">
    <ReproducePanel :class="ui('$all', '$ReproducePanel')" :reproduce="evidence.reproduce"/>
    <section :class="ui('$all', '$section')" aria-label="Execution context">
      <h3 :class="ui('$all', '$h3')">Execution context</h3>
      <dl v-if="execution.length" :class="ui('$all', '$dl', 'context-list')"><div :class="ui('$all', '$div')" v-for="[key,value] in execution" :key="key"><dt :class="ui('$all', '$dt')">{{labels[key]??key}}</dt><dd :class="ui('$all', '$dd')">{{value}}</dd></div></dl>
      <p v-else :class="ui('$all', '$p', 'help')">Execution details were not retained or were excluded by privacy policy.</p>
    </section>
    <section :class="ui('$all', '$section')" v-if="activity.entrypoints.length" aria-label="Related activity">
      <h3 :class="ui('$all', '$h3')">Related activity</h3>
      <p :class="ui('$all', '$p', 'help')">Captured from the same Telescope batch. Entries are grouped; individual event timestamps are unavailable.</p>
      <ul :class="ui('$all', '$ul', 'related-entrypoints')"><li :class="ui('$all', '$li')" v-for="(event,index) in activity.entrypoints" :key="index"><span :class="ui('$all', '$span', 'badge')">{{event.type}}</span><span :class="ui('$all', '$span')">{{activityDescription(event,evidence.execution)}}</span><span v-if="Number(event.status)>0" :class="ui('$all', '$span', 'badge')">HTTP {{event.status}}</span></li></ul>
    </section>
    <section :class="ui('$all', '$section')" aria-label="Database activity">
      <h3 :class="ui('$all', '$h3')">Database activity</h3>
      <template v-if="activity.queries.length">
        <p :class="ui('$all', '$p', 'query-summary')"><strong :class="ui('$all', '$strong')">{{activity.queries.length}} captured {{activity.queries.length===1?'query':'queries'}}</strong><span :class="ui('$all', '$span')">{{activity.duration===null?'No duration measurements retained':milliseconds(activity.duration)+' combined captured duration'}}</span></p>
        <p v-if="evidence.coverage.queries==='truncated'||evidence.coverage.timeline==='truncated'" :class="ui('$all', '$p', 'help')">Only a bounded subset of related activity was retained. Counts and durations describe the retained entries.</p><p v-if="activity.hasLegacy" :class="ui('$all', '$p', 'help')">Older query entries retain connection and duration only. SQL was not retained; enabling capture does not reconstruct it.</p>
        <p v-if="!activity.hasSql&&!activity.hasLegacy" :class="ui('$all', '$p', 'help')">SQL templates were not retained. They may be disabled, unsupported or over the capture limit; expand the measurements for details.</p><details :class="ui('$all', '$details', 'captured-query-list')" :open="activity.hasSql"><summary :class="ui('$all', '$summary')">{{activity.hasSql?'View '+sqlQueries.length+' SQL templates':'View '+activity.queries.length+' query measurements'}}</summary>
          <article v-for="query in (activity.hasSql?sqlQueries:activity.queries)" :key="activity.queries.indexOf(query)" :class="ui('$all', '$article', 'captured-query')"><header :class="ui('$all', '$header')"><strong :class="ui('$all', '$strong')">Query {{activity.queries.indexOf(query)+1}}</strong><span :class="ui('$all', '$span')">{{query.connection||'Connection unavailable'}} · {{milliseconds(query.duration_ms)}}</span></header><pre :class="ui('$all', '$pre')" v-if="query.sql_template&&query.sql_state==='retained'"><code :class="ui('$all', '$code')">{{query.sql_template}}</code></pre><p v-else :class="ui('$all', '$p', 'help')">{{sqlAvailability(query)}}</p></article>
        </details>
        <details v-if="activity.hasSql&&measurements.length" :class="ui('$all', '$details', 'additional-query-measurements')"><summary :class="ui('$all', '$summary')">{{measurements.length}} additional measurements without SQL</summary><article v-for="query in measurements" :key="activity.queries.indexOf(query)" :class="ui('$all', '$article', 'captured-query')"><header :class="ui('$all', '$header')"><strong :class="ui('$all', '$strong')">Query {{activity.queries.indexOf(query)+1}}</strong><span :class="ui('$all', '$span')">{{query.connection||'Connection unavailable'}} · {{milliseconds(query.duration_ms)}}</span></header><p :class="ui('$all', '$p', 'help')">{{sqlAvailability(query)}}</p></article></details>
        <a v-if="activity.hasLegacy&&telescopeUrl" :href="telescopeUrl" :class="ui('$all', '$a', 'text-link')" target="_blank" rel="noopener noreferrer">Inspect original evidence in Telescope, if still retained</a>
      </template>
      <p v-else :class="ui('$all', '$p', 'help')">No database query measurements were retained.</p>
    </section>
    <section :class="ui('$all', '$section')" aria-label="Related logs"><h3 :class="ui('$all', '$h3')">Related logs</h3><ul v-if="activity.logs.length" :class="ui('$all', '$ul', 'related-logs')"><li :class="ui('$all', '$li')" v-for="(log,index) in activity.logs" :key="index"><span :class="ui('$all', '$span', 'badge')">{{log.level||'Log'}}</span><p :class="ui('$all', '$p')">{{log.message||'Log message unavailable'}}</p></li></ul><p v-else :class="ui('$all', '$p', 'help')">No related logs were retained.</p></section>
    <section :class="ui('$all', '$section')" v-if="Object.keys(evidence.tags).length"><h3 :class="ui('$all', '$h3')">Tags</h3><dl :class="ui('$all', '$dl', 'context-list')"><div :class="ui('$all', '$div')" v-for="(value,key) in evidence.tags" :key="key"><dt :class="ui('$all', '$dt')">{{key}}</dt><dd :class="ui('$all', '$dd')">{{value}}</dd></div></dl></section>
    <details :class="ui('$all', '$details', 'data-availability')"><summary :class="ui('$all', '$summary')">Data availability</summary><p :class="ui('$all', '$p', 'help')">Cookies, authentication credentials, SQL bindings and arbitrary log context are excluded. Replay fields follow their capture mode and current deployment/privacy policy. SQL templates keep schema identifiers and mask supported literals.</p><dl :class="ui('$all', '$dl', 'context-list')"><div :class="ui('$all', '$div')" v-for="(value,key) in evidence.coverage" :key="key"><dt :class="ui('$all', '$dt')">{{availability[key]??key}}</dt><dd :class="ui('$all', '$dd')">{{state[value]??'Not retained or unavailable'}}</dd></div></dl></details>
  </div>
</template>
