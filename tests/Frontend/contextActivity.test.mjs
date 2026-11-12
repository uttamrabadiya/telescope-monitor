import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import ts from 'typescript'
const source = await readFile(new URL('../../resources/js/composables/contextActivity.ts', import.meta.url), 'utf8')
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.ESNext } }).outputText
const { activityDescription, contextActivity, milliseconds, sqlAvailability } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'))
test('activity descriptions use retained details and explicit older-snapshot fallbacks',()=>{
 assert.equal(activityDescription({type:'request'},{method:'POST',path:'/api/widgets'}),'POST /api/widgets')
 assert.equal(activityDescription({type:'job',job_class:'App\\Jobs\\SendWidgetNotification'}),'Job: App\\Jobs\\SendWidgetNotification')
 assert.equal(activityDescription({type:'command',command:'notify:send'}),'Command: notify:send')
 assert.equal(activityDescription({type:'request'}),'Request details were not retained')
 assert.match(sqlAvailability({connection:'mysql',duration_ms:2}),/older snapshot/)
 assert.match(sqlAvailability({sql_state:'unsupported'}),/safely normalized/)
 assert.equal(milliseconds(1.98),'1.98 ms')
 assert.equal(milliseconds(Infinity),'Duration unavailable')
})
test('canonical queries/logs are not duplicated by their activity copies and legacy rows stay distinguishable',()=>{
 const query={type:'query',connection:'mysql',duration_ms:1.98}
 const result=contextActivity({execution:{},tags:{},coverage:{},queries:[query],logs:[],timeline:[{type:'request'},query]})
 assert.equal(result.queries.length,1);assert.equal(result.duration,1.98);assert.equal(result.hasLegacy,true);assert.equal(result.hasSql,false)
 assert.equal(result.entrypoints.length,1)
})
