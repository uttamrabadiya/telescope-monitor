<script setup lang="ts">
import { ui } from "../ui"
import { nextTick, ref, useId, watch } from 'vue'
import { EllipsisHorizontalIcon } from '@heroicons/vue/24/outline'

const props = defineProps<{ label: string; disabled?: boolean }>()
const emit = defineEmits<{ edit: []; duplicate: []; remove: [] }>()
const id = useId(), trigger = ref<HTMLButtonElement | null>(null), panel = ref<HTMLDivElement | null>(null), open = ref(false)
function close(restoreFocus = false) {
  panel.value?.hidePopover()
  if (restoreFocus) trigger.value?.focus()
}
function toggle() {
  if (props.disabled) return
  if (open.value) close(true)
  else trigger.value?.click()
}
async function toggled(event: Event) {
  open.value = (event as ToggleEvent).newState === 'open'
  if (open.value) { await nextTick(); panel.value?.querySelector<HTMLButtonElement>('button')?.focus() }
}
function select(action: 'edit' | 'duplicate' | 'remove') {
  close(true)
  if (action === 'edit') emit('edit')
  else if (action === 'duplicate') emit('duplicate')
  else emit('remove')
}
function keydown(event: KeyboardEvent) {
  if (event.key === 'Escape') { event.preventDefault(); close(true); return }
  if (event.key === 'Tab') { close(true); return }
  if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return
  event.preventDefault()
  const items = [...(panel.value?.querySelectorAll<HTMLButtonElement>('button') ?? [])]
  const current = items.indexOf(document.activeElement as HTMLButtonElement)
  const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (current + (event.key === 'ArrowUp' ? -1 : 1) + items.length) % items.length
  items[next]?.focus()
}
function focusout(event: FocusEvent) {
  if (event.relatedTarget instanceof Node && !panel.value?.contains(event.relatedTarget) && !trigger.value?.contains(event.relatedTarget)) close()
}
watch(() => props.disabled, disabled => { if (disabled) close() })
</script>

<template>
  <button ref="trigger" type="button" :class="ui('$all', '$button', 'rule-actions-trigger')" :aria-label="'Actions for ' + label" aria-haspopup="menu" :aria-expanded="open" :aria-controls="id" :disabled="disabled" :popovertarget="id" @keydown.down.prevent="toggle"><EllipsisHorizontalIcon :class="ui('$all', '$svg')" aria-hidden="true"/></button>
  <Teleport to="body">
    <div :id="id" ref="panel" popover="auto" role="menu" :aria-label="'Actions for ' + label" :class="ui('$all', '$div', 'rule-actions-popover')" @toggle="toggled" @keydown="keydown" @focusout="focusout">
      <button :class="ui('$all', '$button')" type="button" role="menuitem" @click="select('edit')">Edit rule</button>
      <button :class="ui('$all', '$button')" type="button" role="menuitem" @click="select('duplicate')">Duplicate</button>
      <button type="button" role="menuitem" :class="ui('$all', '$button', 'danger-link')" @click="select('remove')">Delete rule</button>
    </div>
  </Teleport>
</template>
