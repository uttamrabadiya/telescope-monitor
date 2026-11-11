import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import ts from 'typescript'
const source = await readFile(new URL('../../resources/js/composables/time.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
const { parseInstant } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))
test('UTC database observations display the same instant in a non-UTC browser', () => {
  const previous = process.env.TZ
  process.env.TZ = 'Asia/Kolkata'
  try {
    assert.equal(parseInstant('2026-11-10 20:47:52.897544').toISOString(), '2026-11-10T20:47:52.897Z')
    assert.equal(parseInstant('2026-11-10 20:47:52').toISOString(), '2026-11-10T20:47:52.000Z')
    assert.equal(parseInstant('2026-11-11T02:17:52+05:30').toISOString(), '2026-11-10T20:47:52.000Z')
    assert.equal(parseInstant('2026-11-10T20:47:52.123456Z').toISOString(), '2026-11-10T20:47:52.123Z')
  } finally { if (previous === undefined) delete process.env.TZ; else process.env.TZ = previous }
})
