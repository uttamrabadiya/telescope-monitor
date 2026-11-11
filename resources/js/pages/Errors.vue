<script setup lang="ts">
import { useRoute,useRouter } from 'vue-router'
import { ArrowPathIcon } from '@heroicons/vue/24/outline'
import { bootstrap } from '../composables/api'
import SavedViews from '../components/SavedViews.vue'
import PageHeading from '../components/PageHeading.vue'
import ErrorsTable from '../components/ErrorsTable.vue'
import InvestigationFilters from '../components/InvestigationFilters.vue'
import { useInvestigation,queryString,date } from '../composables/investigation'
import type { InboxResult } from '../types'
const route=useRoute(),router=useRouter(),{data,loading,error,reload}=useInvestigation<InboxResult>(()=>'/issues?'+queryString(route.query))
function page(n:number){router.replace({query:{...route.query,page:String(n)}})}
</script>
<template><PageHeading title="Errors" subtitle="Investigate and triage captured exceptions"><InvestigationFilters :environments="data?.environments"/><button class="button icon-button" aria-label="Refresh errors" :disabled="loading" @click="reload"><ArrowPathIcon/></button></PageHeading><nav class="built-in-views" aria-label="Built-in error views"><RouterLink v-for="status in ['unresolved','resolved','ignored','snoozed']" :key="status" :class="{selected:(route.query.status??'unresolved')===status}" :aria-current-value="(route.query.status??'unresolved')===status?'page':'false'" :to="{query:{...route.query,status,page:undefined}}">{{ status }}</RouterLink><RouterLink v-if="bootstrap?.features.assignments" :to="{query:{...route.query,status:'unresolved',assignee:'me',view:undefined,page:undefined}}">Assigned to me</RouterLink></nav><SavedViews v-if="bootstrap?.features.collaboration"/><div v-else class="notice neutral">Publish and run the collaboration migration to enable saved views.</div><InvestigationFilters advanced :allowed-tags="data?.allowed_tags"/><div v-if="error" class="notice danger" role="alert">{{ error }}<button class="button" @click="reload">Try again</button><RouterLink to="/errors" class="text-link">Clear filters</RouterLink></div><p v-if="loading" role="status" class="loading-message">Loading errors…</p><template v-if="data"><ErrorsTable :items="data.items" :range="data.window.range"/><div class="pagination"><span>{{ data.total }} {{data.total==='1'?'error':'errors'}} · page {{ data.page }} of {{ data.last_page }}</span><button class="button" :disabled="data.page<=1" @click="page(data.page-1)">Previous</button><button class="button" :disabled="data.page>=data.last_page" @click="page(data.page+1)">Next</button><RouterLink v-if="!data.items.length" to="/errors" class="text-link">Clear filters</RouterLink></div><p class="data-caption">Data as of {{ date(data.window.as_of) }}<br>{{ data.coverage }}</p></template></template>
