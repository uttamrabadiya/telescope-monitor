<script setup lang="ts">
import { ui } from "../ui"
import { computed } from 'vue'
import { PuzzlePieceIcon } from '@heroicons/vue/24/outline'
import PageHeading from '../components/PageHeading.vue'
const props = defineProps<{ area:'errors'|'alerts'|'integrations' }>()
const text = computed(()=>({errors:{title:'Errors',subtitle:'Investigate grouped exceptions and their occurrences.',message:'Exception ingestion and retained evidence are not available in this foundation build.'},alerts:{title:'Alert rules',subtitle:'Choose when to notify and where each alert goes.',message:'Alert rules and delivery recovery are not available in this foundation build.'},integrations:{title:'Integrations',subtitle:'Notification destinations and issue tracking, in one place.',message:'Email destinations and delivery tests are available in settings. GitHub Issues, Slack and signed webhooks arrive in Phase 6.'}}[props.area]))
</script>
<template><PageHeading :class="ui('$all', '$PageHeading')" :title="text.title" :subtitle="text.subtitle"/><div :class="ui('$all', '$div', 'panel')"><div :class="ui('$all', '$div', 'empty-state')"><PuzzlePieceIcon :class="ui('$all', '$svg')"/><h2 :class="ui('$all', '$h2')">{{area==='integrations'?'Email is available · more integrations are coming':'This capability is not connected yet'}}</h2><p :class="ui('$all', '$p')">{{ text.message }} Your installation and privacy preferences can already be configured.</p><RouterLink :to="area==='integrations'?'/settings/email':'/setup'" :class="ui('$all', '$a', 'button')">{{area==='integrations'?'Manage email destinations':'Review setup'}}</RouterLink></div></div></template>
