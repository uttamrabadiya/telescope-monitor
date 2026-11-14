<script setup lang="ts">
import { ui } from "../ui"
defineProps<{ title: string; subtitle?: string }>()</script>
<template><div :class="ui('$all', '$div', 'page-heading')"><div :class="ui('$all', '$div')"><h1 :class="ui('$all', '$h1')">{{ title }}</h1><p :class="ui('$all', '$p')" v-if="subtitle">{{ subtitle }}</p></div><div :class="ui('$all', '$div', 'heading-actions')"><slot/></div></div></template>
