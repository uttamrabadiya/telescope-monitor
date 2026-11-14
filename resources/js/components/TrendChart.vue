<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue'
import { ui } from '../ui'
import type { OverviewComparison, TrendBucket } from '../types'
import { date, zone } from '../composables/investigation'
import { parseInstant } from '../composables/time'
import { chartScale, count, tickIndexes } from '../composables/overview'

const props = defineProps<{ buckets: TrendBucket[]; comparison: OverviewComparison; range: string }>()
const hovered = ref<number | null>(null), focused = ref<number | null>(null)
const dismissed = ref(false), navigationIndex = ref(0)
const chart = ref<SVGSVGElement | null>(null)
const tooltipId = useId()
const active = computed(() => dismissed.value ? null : hovered.value ?? focused.value)
const activePoint = computed(() => active.value !== null ? points.value[active.value] : undefined)
function reveal(index: number, source: 'pointer' | 'focus') {
  dismissed.value = false
  if (source === 'pointer') hovered.value = index
  else { hovered.value = null; focused.value = index; navigationIndex.value = index }
}
function dismissOutside(event: PointerEvent) {
  if (event.target instanceof Node && !chart.value?.contains(event.target)) {
    hovered.value = null
    focused.value = null
  }
}
onMounted(() => document.addEventListener('pointerdown', dismissOutside))
onBeforeUnmount(() => document.removeEventListener('pointerdown', dismissOutside))
function focusPoint(index: number) {
  const next = Math.max(0, Math.min(points.value.length - 1, index))
  navigationIndex.value = next
  chart.value?.querySelector<SVGGElement>(`[data-interval="${next}"]`)?.focus()
}
function timestamp(value: string) {
  return new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: zone() }).format(parseInstant(value))
}
const scale = computed(() => chartScale([...props.buckets.map(bucket => Number(bucket.occurrences)), ...props.comparison.buckets.map(bucket => Number(bucket.occurrences ?? 0))]))
const slot = computed(() => 734 / Math.max(1, props.buckets.length))
const barWidth = computed(() => Math.min(14, slot.value * .34))
const points = computed(() => props.buckets.map((bucket, index) => {
  const before = props.comparison.available ? props.comparison.buckets[index] : undefined
  const center = 50 + (index + .5) * slot.value
  const previous = before?.occurrences ?? null
  return { bucket, before, previous, center, x: center - (previous !== null ? barWidth.value + 1 : barWidth.value / 2), currentHeight: Number(bucket.occurrences) / scale.value.ceiling * 186, previousHeight: Number(previous ?? 0) / scale.value.ceiling * 186 }
}))
const ticks = computed(() => tickIndexes(points.value.length).map(index => ({ index, point: points.value[index]! })))
const empty = computed(() => props.buckets.every(bucket => Number(bucket.occurrences) === 0) && (!props.comparison.available || props.comparison.buckets.every(bucket => !Number(bucket.occurrences))))
function axis(value: string) {
  const options: Intl.DateTimeFormatOptions = { hour: 'numeric', minute: props.range === '1h' ? '2-digit' : undefined, hour12: true }
  return new Intl.DateTimeFormat('en-US', { ...options, timeZone: zone() }).format(parseInstant(value))
}
function description(index: number) {
  const point = points.value[index]!
  const selected = date(point.bucket.at) + ': ' + count(point.bucket.occurrences) + ' captured'
  return selected + (point.previous !== null && point.before ? '; previous ' + date(point.before.at) + ': ' + count(point.previous) : index === points.value.length - 1 ? ' (partial interval)' : '')
}
</script>

<template>
  <section class="min-w-0" aria-labelledby="overview-trend-heading">
    <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
      <h2 id="overview-trend-heading" :class="ui('$all', '$h2', '[font-size:21px]')">Captured occurrence trend</h2>
      <div class="flex flex-wrap items-center gap-4 text-xs text-tm-muted" aria-label="Chart legend">
        <span class="inline-flex items-center gap-2"><span class="size-2.5 rounded-[2px] bg-tm-primary" aria-hidden="true"></span>Selected {{ range }}</span>
        <span v-if="comparison.available" class="inline-flex items-center gap-2"><span class="size-2.5 rounded-[2px] bg-tm-primary opacity-25" aria-hidden="true"></span>Previous {{ range }}</span>
        <span v-else :title="comparison.detail">Previous period unavailable</span>
      </div>
    </div>
    <div class="relative">
    <div v-if="activePoint" :id="tooltipId" role="tooltip" class="pointer-events-none absolute right-0 top-0 z-30 flex w-max max-w-full flex-col gap-1.5 whitespace-nowrap rounded-sm border border-tm-border bg-tm-surface px-3 py-2 text-xs tabular-nums text-tm-text shadow-md">
      <span class="text-tm-muted">{{ date(activePoint.bucket.at).split(', ')[0] }}</span>
      <strong class="font-medium">{{ timestamp(activePoint.bucket.at) }}</strong>
      <div class="flex items-center gap-4">
      <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-[2px] bg-tm-primary" aria-hidden="true"></span><span class="sr-only">Current: </span>{{ count(activePoint.bucket.occurrences) }}</span>
      <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-[2px] bg-tm-primary opacity-25" aria-hidden="true"></span><span class="sr-only">Previous: </span>{{ activePoint.previous !== null ? count(activePoint.previous) : '—' }}</span>
      </div>
      <span v-if="activePoint.previous === null" class="sr-only">{{ !comparison.available ? comparison.detail : 'Previous count excluded while the current interval is in progress.' }}</span>
    </div>
    <div :class="ui('$all', '$div', 'overflow-x-auto')" role="region" tabindex="0" aria-label="Captured occurrence bar chart; scroll horizontally on narrow screens">
      <svg ref="chart" viewBox="0 0 800 246" class="block h-auto w-full min-w-[640px]" role="group" aria-label="Captured occurrences by interval. Use arrow keys to explore selected and previous periods.">
        <g v-for="tick in scale.ticks" :key="tick">
          <line x1="50" x2="784" :y1="204 - tick / scale.ceiling * 186" :y2="204 - tick / scale.ceiling * 186" class="stroke-tm-border stroke-1"/>
          <text x="38" :y="209 - tick / scale.ceiling * 186" class="fill-tm-muted font-sans [font-size:13px] [text-anchor:end]">{{ count(tick) }}</text>
        </g>
        <g v-for="(point, index) in points" :key="point.bucket.at" :data-interval="index" role="img" :aria-label="description(index)" :aria-describedby="active === index ? tooltipId : undefined" :tabindex="index === Math.min(navigationIndex, points.length - 1) ? 0 : -1" :class="ui('$all', '$g')" @focus="reveal(index, 'focus')" @blur="focused = null" @pointerenter="reveal(index, 'pointer')" @pointerleave="hovered = null" @click="reveal(index, 'focus')" @keydown.left.prevent="focusPoint(index - 1)" @keydown.right.prevent="focusPoint(index + 1)" @keydown.home.prevent="focusPoint(0)" @keydown.end.prevent="focusPoint(points.length - 1)" @keydown.esc.stop.prevent="dismissed = true">
          <rect :x="point.center - slot / 2" y="18" :width="slot" height="186" class="fill-transparent"/>
          <rect :x="point.x" :y="204 - point.currentHeight" :width="barWidth" :height="point.currentHeight" rx="1" class="fill-tm-primary" :class="{ 'opacity-60': index === points.length - 1 }"/>
          <rect v-if="point.previous !== null" :x="point.x + barWidth + 2" :y="204 - point.previousHeight" :width="barWidth" :height="point.previousHeight" rx="1" class="fill-tm-primary opacity-25"/>
        </g>
        <text v-if="empty" x="417" y="112" class="fill-tm-muted font-sans [font-size:14px] [text-anchor:middle]">No captured occurrences in this period.</text>
        <text v-for="(tick, index) in ticks" :key="tick.index" :x="tick.point.center" y="234" class="fill-tm-muted font-sans [font-size:13px]" :class="index === 0 ? '[text-anchor:start]' : index === ticks.length - 1 ? '[text-anchor:end]' : '[text-anchor:middle]'">{{ axis(tick.point.bucket.at) }}</text>
      </svg>
    </div>
    </div>
    <span v-if="!comparison.available" class="sr-only">{{ comparison.detail }}</span>
  </section>
</template>
