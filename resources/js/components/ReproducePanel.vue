<script setup lang="ts">
import { ui } from "../ui"
import { computed, ref, watch } from 'vue'
import type { ReproduceEvidence } from '../types'
import { replayState, replayTemplate } from '../composables/reproduce'
const props = defineProps<{ reproduce?: ReproduceEvidence }>()
const recipe = computed(() => replayTemplate(props.reproduce))
const copying = ref(false)
const feedback = ref(''), failed = ref(false), details = ref<HTMLDetailsElement | null>(null), preview = ref<HTMLElement | null>(null)
const modes = { redacted: 'Redacted', 'selected-values': 'Selected values', 'full-payload': 'Full payload' }
const omissions: Record<string, string> = { credentials: 'Authentication credentials are excluded. Supply fresh authentication separately.', unsupported_body: 'The body format or file upload was not retained. Supply it manually.', body_limit: 'The request body exceeded the source limit and was omitted.', field_limit: 'Some fields were omitted or shortened by capture limits.' }
watch(() => props.reproduce, () => { feedback.value = ''; failed.value = false; copying.value = false; if (details.value) details.value.open = false })
async function copy() {
  if (!recipe.value || copying.value) return
  const source = props.reproduce, value = recipe.value
  copying.value = true
  try {
    await navigator.clipboard.writeText(value)
    if (props.reproduce === source && recipe.value === value) { feedback.value = 'Template copied'; failed.value = false }
  } catch {
    if (props.reproduce === source && recipe.value === value) { feedback.value = 'Clipboard unavailable. Select and copy the template below.'; failed.value = true; preview.value?.focus() }
  } finally { if (props.reproduce === source) copying.value = false }
}
</script>
<template>
  <section :class="ui('$all', '$section', 'reproduce-section')" aria-label="Reproduce">
    <div :class="ui('$all', '$div', 'reproduce-heading')"><h3 :class="ui('$all', '$h3')">Reproduce</h3><span v-if="reproduce?.mode" :class="ui('$all', '$span', 'badge')">{{modes[reproduce.mode]}}</span></div>
    <p :class="ui('$all', '$p', 'help')">{{replayState(reproduce)}}</p>
    <details v-if="recipe" ref="details" :class="ui('$all', '$details', 'reproduce-details')">
      <summary :class="ui('$all', '$summary')">{{reproduce?.kind==='http'?'View cURL template':'View Artisan command'}}</summary>
      <ul :class="ui('$all', '$ul', 'reproduce-omissions')"><li :class="ui('$all', '$li')" v-for="item in reproduce?.omissions??[]" :key="item">{{omissions[item]??'Some evidence was omitted.'}}</li></ul>
      <div :class="ui('$all', '$div', 'reproduce-actions')"><button :class="ui('$all', '$button', 'button')" type="button" :disabled="copying" @click="copy">{{reproduce?.kind==='http'?'Copy cURL':'Copy command'}}</button><span :role="failed?'alert':'status'" :class="ui('$all', '$span', 'help')">{{feedback}}</span></div>
      <pre :class="ui('$all', '$pre')" ref="preview" tabindex="0" aria-label="Replay template"><code :class="ui('$all', '$code')">{{recipe}}</code></pre>
      <p :class="ui('$all', '$p', 'help')">Replay template only. Database state, permissions, external services and missing values may differ.</p>
    </details>
  </section>
</template>
