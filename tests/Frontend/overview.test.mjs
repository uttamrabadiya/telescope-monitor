import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import ts from 'typescript'
const source = await readFile(new URL('../../resources/js/composables/overview.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
const { count, ratio, share, comparisonLabel, chartScale, tickIndexes } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))
test('overview counts retain large integer precision and avoid misleading shares', () => {
  assert.equal(count('9007199254740993'), '9,007,199,254,740,993')
  assert.equal(share('1', '3'), '33.3%')
  assert.equal(share('0', '0'), '—')
  assert.equal(share('3', '2'), '—')
  assert.equal(ratio('3', '2'), 0)
  assert.equal(ratio('2', '8'), 25)
})
test('comparison labels distinguish missing coverage, zero baselines, and completed-interval changes', () => {
  assert.equal(comparisonLabel({ available: false }), 'Previous period unavailable')
  assert.equal(comparisonLabel({ available: true, current_occurrences: '0', change_percent: null }), 'No events in complete intervals')
  assert.equal(comparisonLabel({ available: true, current_occurrences: '3', change_percent: null }), 'New activity in complete intervals')
  assert.equal(comparisonLabel({ available: true, change_percent: -12.5 }), '12.5% fewer in complete intervals')
  assert.equal(comparisonLabel({ available: true, change_percent: 0 }), 'Unchanged in complete intervals')
})
test('bar scales and sparse ticks handle empty, small, and peak datasets without duplicate labels', () => {
  assert.deepEqual(chartScale([]), { ceiling: 4, ticks: [0, 1, 2, 3, 4] })
  assert.equal(chartScale([186, 24]).ceiling, 200)
  for (const length of [0, 1, 2, 12, 24, 28, 30]) {
    const ticks = tickIndexes(length)
    assert.equal(ticks.length, new Set(ticks).size)
    assert.ok(ticks.length <= 5)
    assert.ok(ticks.every(index => index >= 0 && index < length))
    if (length) assert.equal(ticks.at(-1), length - 1)
  }
})
