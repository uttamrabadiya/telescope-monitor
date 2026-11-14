import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import ts from 'typescript'
const source = await readFile(new URL('../../resources/js/composables/viewPreferences.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
const { preferenceDraft, preferenceKey, preferencePayload, moveFavorite } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))

test('preference editing copies saved data and presents favorites in their saved order', () => {
  const saved = { version: 4, default_view_ref: null, shortcuts: [{ ref: 'later', favorite: true, position: 2 }, { ref: 'unused', favorite: false, position: 1 }, { ref: 'first', favorite: true, position: 0 }] }
  const original = structuredClone(saved)
  const draft = preferenceDraft(saved)
  assert.deepEqual(draft.favorites, ['first', 'later'])
  moveFavorite(draft, 1, -1)
  draft.default_view_ref = 'later'
  assert.deepEqual(saved, original, 'Editing or cancelling a draft must not mutate the saved preferences')
})

test('default and favorite changes are saved together with dense order and the current version', () => {
  const draft = { default_view_ref: 'second', favorites: ['first', 'second', 'third'] }
  moveFavorite(draft, 1, -1)
  assert.deepEqual(preferencePayload(draft, 7), { version: 7, default_view_ref: 'second', shortcuts: [{ ref: 'second', favorite: true, position: 0 }, { ref: 'first', favorite: true, position: 1 }, { ref: 'third', favorite: true, position: 2 }] })
  draft.favorites = draft.favorites.filter(id => id !== 'second')
  assert.equal(preferencePayload(draft, 7).default_view_ref, 'second', 'The default view is independent of favorite membership')
})

test('boundary moves are harmless and dirty state tracks order and default independently', () => {
  const draft = { default_view_ref: null, favorites: ['first', 'second'] }
  const key = preferenceKey(draft)
  moveFavorite(draft, 0, -1); moveFavorite(draft, 1, 1); moveFavorite(draft, -1, 1)
  assert.equal(preferenceKey(draft), key)
  moveFavorite(draft, 0, 1)
  assert.notEqual(preferenceKey(draft), key)
  moveFavorite(draft, 0, 1)
  assert.equal(preferenceKey(draft), key)
  draft.default_view_ref = 'second'
  assert.notEqual(preferenceKey(draft), key)
  assert.equal(preferenceKey({ ...draft, default_view_ref: 'builtin:unresolved' }), preferenceKey({ ...draft, default_view_ref: null }))
})
