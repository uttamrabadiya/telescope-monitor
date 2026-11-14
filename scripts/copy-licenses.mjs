import { mkdir, copyFile } from 'node:fs/promises'
await mkdir('dist/licenses', { recursive: true })
for (const [name, source] of Object.entries({
  'vue.txt': 'node_modules/vue/LICENSE',
  'vue-router.txt': 'node_modules/vue-router/LICENSE',
  'heroicons.txt': 'node_modules/@heroicons/vue/LICENSE',
  'tailwindcss.txt': 'node_modules/tailwindcss/LICENSE',
  'tailwind-merge.txt': 'node_modules/tailwind-merge/LICENSE.md',
  'figtree.txt': 'node_modules/@fontsource/figtree/LICENSE',
})) await copyFile(source, `dist/licenses/${name}`)
