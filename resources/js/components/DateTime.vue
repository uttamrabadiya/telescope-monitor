<script lang="ts">
import { ref } from 'vue'
const now = ref(Date.now())
let clock: ReturnType<typeof setInterval> | undefined
let viewers = 0
</script>

<script setup lang="ts">
import { ui } from "../ui"
import { computed, onMounted, onUnmounted } from 'vue'
import { date } from '../composables/investigation'
import { formatRelativeTime, parseInstant } from '../composables/time'
const props = defineProps<{ value?: string | null }>()
const absolute = computed(() => date(props.value))
const relative = computed(() => formatRelativeTime(props.value, now.value))
onMounted(() => {
  now.value = Date.now()
  if (viewers++ === 0) clock = setInterval(() => { now.value = Date.now() }, 1000)
})
onUnmounted(() => {
  if (--viewers === 0) { clearInterval(clock); clock = undefined }
})
</script>

<template><time :class="ui('$all', '$time')" v-if="value" :datetime="parseInstant(value).toISOString()" :title="absolute" :aria-label="relative + ' · ' + absolute">{{ relative }}</time><span :class="ui('$all', '$span')" v-else>Unavailable</span></template>
