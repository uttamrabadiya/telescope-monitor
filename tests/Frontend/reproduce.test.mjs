import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { spawnSync } from 'node:child_process'
import ts from 'typescript'
const source = await readFile(new URL('../../resources/js/composables/reproduce.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
const { shellQuote, replayTemplate, replayState } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))

test('captured strings remain literal POSIX arguments, including quotes, substitutions and newlines', () => {
  for (const value of ["O'Brien", '$(printf INJECTED)', '`printf INJECTED`', 'a\nb', '; printf INJECTED', '@private-file']) {
    const result = spawnSync('sh', ['-c', `printf '%s' ${shellQuote(value)}`], { encoding: 'utf8' })
    assert.equal(result.status, 0)
    assert.equal(result.stdout, value)
  }
})

test('cURL uses safe data-raw, bracket query parameters and exact JSON object/array shapes', () => {
  const data = { version: 1, state: 'ready', kind: 'http', mode: 'full-payload', method: 'POST', origin: 'https://example.test', route: '/groups/{group}', parameters: { group: '42' }, query: { filters: { currency: 'INR' } }, headers: {}, encoding: 'json', body: { empty: [], list: [], numeric: ['x'], bool: false, nil: null, memo: "O'Brien $(printf INJECTED)" }, objects: ['', '/empty', '/numeric'] }
  const recipe = replayTemplate(data)
  assert.match(recipe, /groups\/42\?filters%5Bcurrency%5D=INR/)
  assert.match(recipe, /Content-Type: application\/json/)
  assert.match(recipe, /--data-raw /)
  const result = spawnSync('sh', ['-c', `curl() { printf '%s\\n' "$@"; }; ${recipe}`], { encoding: 'utf8' })
  assert.equal(result.status, 0)
  const body = JSON.parse(result.stdout.trim().split('\n').at(-1))
  assert.deepEqual(body, { empty: {}, list: [], numeric: { 0: 'x' }, bool: false, nil: null, memo: "O'Brien $(printf INJECTED)" })
  assert.equal(result.stdout.includes('\nINJECTED\n'), false)
})

test('Artisan output keeps ordered arguments, repeated options and actual boolean flags', () => {
  const recipe = replayTemplate({ version: 1, state: 'ready', kind: 'command', selector: 'groups:process', arguments: { group: "42' $(printf INJECTED)", ids: ['first', 'second'] }, options: { 'dry-run': true, token: '[REDACTED]', tag: ['a', 'b'], quiet: false } })
  assert.match(recipe, /'--dry-run'/)
  assert.equal(recipe.includes('--dry-run='), false)
  assert.match(recipe, /'--tag=a' '--tag=b'/)
  assert.equal(recipe.includes('--quiet'), false)
  const flagLikeArgument = replayTemplate({version:1,state:'ready',kind:'command',selector:'groups:process',arguments:{group:'--force'},options:{'dry-run':true}})
  assert.match(flagLikeArgument, /'--dry-run' -- '--force'$/)
})

test('legacy, restricted and unsupported evidence do not offer fabricated replay commands', () => {
  assert.equal(replayTemplate(), null)
  assert.match(replayState(), /older snapshot/)
  for (const state of ['excluded', 'restricted', 'unsupported', 'unavailable', 'too_large']) {
    assert.equal(replayTemplate({ version: 1, state }), null)
    assert.ok(replayState({ version: 1, state }))
  }
  assert.equal(replayTemplate({ version: 1, state: 'ready', kind: 'http', origin: 'javascript:alert(1)', route: '/', method: 'GET' }), null)
  assert.equal(replayTemplate({ version: 1, state: 'ready', kind: 'http', origin: 'https://user:secret@example.test', route: '/', method: 'GET' }), null)
})
