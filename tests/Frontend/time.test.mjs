import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import ts from 'typescript'
const source = await readFile(new URL('../../resources/js/composables/time.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
const { parseInstant, formatDateTime, formatChartDateTime, formatRelativeTime } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))
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

test('hover timestamps use abbreviated dates and 12-hour AM/PM times', () => {
  assert.equal(formatDateTime('2026-11-13 14:30:00'), 'Nov 13 2026, 2:30:00 PM')
  assert.equal(formatDateTime('2026-11-01 00:00:00'), 'Nov 1 2026, 12:00:00 AM')
  assert.equal(formatDateTime('2026-11-02 12:00:00'), 'Nov 2 2026, 12:00:00 PM')
  for (const day of [3, 11, 12, 21, 22, 23, 31]) {
    assert.equal(formatDateTime(`2026-12-${String(day).padStart(2, '0')} 13:05:09`), `Dec ${day} 2026, 1:05:09 PM`)
  }
  assert.equal(formatDateTime('2026-11-13 20:47:52.897544', 'Asia/Kolkata'), 'Nov 14 2026, 2:17:52 AM')
  for (const value of [null, undefined, '']) assert.equal(formatDateTime(value), 'Unavailable')
})

test('chart labels use AM/PM and the same UTC parsing and application timezone', () => {
  assert.equal(formatChartDateTime('2026-11-13 20:47:52', 'Asia/Kolkata'), 'Nov 14, 2:17 AM')
  assert.equal(formatChartDateTime('2026-11-13T12:00:00Z'), 'Nov 13, 12:00 PM')
  assert.equal(formatChartDateTime('2026-11-13T00:00:00Z'), 'Nov 13, 12:00 AM')
})

test('relative timestamps cover elapsed and future time with correct unit boundaries', () => {
  const now = Date.parse('2026-11-13T12:00:00Z')
  for (const [seconds, label] of [[0, 'just now'], [4, 'just now'], [5, '5s ago'], [59, '59s ago'], [60, '1m ago'], [3599, '59m ago'], [3600, '1h ago'], [36000, '10h ago'], [86400, '1d ago'], [2592000, '1mo ago'], [31536000, '1y ago'], [-3600, 'in 1h']]) {
    assert.equal(formatRelativeTime(new Date(now - seconds * 1000).toISOString(), now), label)
  }
  assert.equal(formatRelativeTime('2026-11-13 02:00:00', now), '10h ago')
  for (const value of [null, undefined, '', 'invalid']) assert.equal(formatRelativeTime(value, now), 'Unavailable')
})
