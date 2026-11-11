import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import ts from 'typescript'
async function load(name) { const source=await readFile(new URL('../../resources/js/composables/'+name+'.ts',import.meta.url),'utf8');const code=ts.transpileModule(source,{compilerOptions:{module:ts.ModuleKind.ESNext}}).outputText;return import('data:text/javascript;base64,'+Buffer.from(code).toString('base64')) }
const {noteParts}=await load('noteLinks'),{currentDefinition,definitionKey}=await load('viewState')
test('plain note text stays intact and only safe credential-free HTTP links become anchors',()=>{
 const input=`<script>alert('x')</script>\nRead https://example.test/runbook. javascript:alert(1) https://user:password@host.test/private`;
 const parts=noteParts(input);assert.equal(parts.map(p=>p.text).join(''),input);assert.deepEqual(parts.filter(p=>p.href).map(p=>p.href),['https://example.test/runbook']);assert.ok(parts.some(p=>p.text.includes('<script>')))
})
test('view definitions exclude routing state and compare default scalar values canonically',()=>{
 const current=currentDefinition({view:'uuid',page:'2',tab:'activity',returnTo:'/errors',search:'payment',include_tests:'0'});
 assert.deepEqual(current.filters,{search:'payment',include_tests:false});assert.equal(current.range,'24h');assert.equal(current.sort,'last_seen');
 assert.equal(definitionKey(current),definitionKey({name:'Saved',visibility:'shared',filters:{status:'unresolved',search:'payment'},range:'24h',sort:'last_seen'}));
 assert.notEqual(definitionKey(current),definitionKey({...current,range:'7d'}))
})
