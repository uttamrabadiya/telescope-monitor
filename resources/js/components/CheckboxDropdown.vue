<script setup lang="ts">
import { ui } from "../ui"
import { computed, nextTick, onMounted, onUnmounted, ref, useId, watch } from 'vue'
import { ChevronDownIcon } from '@heroicons/vue/24/outline'

const props = withDefaults(defineProps<{
  modelValue: string[]
  options: string[]
  label: string
  disabled?: boolean
}>(), { disabled: false })
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>()
const id = useId(), open = ref(false), root = ref<HTMLElement | null>(null), trigger = ref<HTMLButtonElement | null>(null)
const summary = computed(() => props.modelValue.length === 0 ? 'Select environments' : props.modelValue.length === 1 ? props.modelValue[0] : `${props.modelValue.length} environments selected`)
const choices = computed(() => [...new Set([...props.options, ...props.modelValue])])

function close(restoreFocus = false) {
  open.value = false
  if (restoreFocus) trigger.value?.focus()
}
async function show() {
  if (props.disabled) return
  open.value = true
  await nextTick()
  root.value?.querySelector<HTMLInputElement>('input:not(:disabled)')?.focus()
}
function toggle(value: string, selected: boolean) {
  emit('update:modelValue', selected ? [...new Set([...props.modelValue, value])] : props.modelValue.filter(item => item !== value))
}
function outside(event: PointerEvent) {
  if (event.target instanceof Node && !root.value?.contains(event.target)) close()
}
function leave(event: FocusEvent) {
  if (event.relatedTarget instanceof Node && !root.value?.contains(event.relatedTarget)) close()
}
watch(() => props.disabled, disabled => { if (disabled) close() })
onMounted(() => document.addEventListener('pointerdown', outside, true))
onUnmounted(() => document.removeEventListener('pointerdown', outside, true))
</script>

<template>
  <div ref="root" :class="ui('$all', '$div', 'field checkbox-dropdown')" @focusout="leave" @keydown.esc.stop.prevent="close(true)">
    <span :class="ui('$all', '$span')" :id="id + '-label'">{{ label }}</span>
    <button ref="trigger" type="button" :class="ui('$all', '$button', 'checkbox-dropdown-trigger')" :disabled="disabled" :aria-expanded="open" :aria-controls="id + '-options'" :aria-labelledby="id + '-label ' + id + '-summary'" @click="open ? close() : show()" @keydown.down.prevent="show">
      <span :id="id + '-summary'" :class="ui('$all', '$span', 'checkbox-dropdown-summary')" aria-live="polite">{{ summary }}</span>
      <ChevronDownIcon :class="ui('$all', '$svg')" aria-hidden="true" />
    </button>
    <div v-if="open" :id="id + '-options'" :class="ui('$all', '$div', 'checkbox-dropdown-options')" role="group" :aria-labelledby="id + '-label'">
      <label v-for="option in choices" :key="option" :class="ui('$all', '$label', 'checkbox-dropdown-option')">
        <input :class="ui('$all', '$input')" type="checkbox" :value="option" :checked="modelValue.includes(option)" :disabled="disabled" @change="toggle(option, ($event.target as HTMLInputElement).checked)">
        <span :class="ui('$all', '$span')">{{ option }}<small :class="ui('$all', '$small')" v-if="!options.includes(option)"> · unavailable</small></span>
      </label>
      <p v-if="!choices.length" :class="ui('$all', '$p', 'help')">No environments available.</p>
    </div>
  </div>
</template>
