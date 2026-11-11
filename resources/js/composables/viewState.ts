import type { ViewDefinition } from '../types'
export const filterKeys=['status','environment','origin','class','search','tag','first_after','first_before','last_after','last_before','include_tests','assignee']
export function currentDefinition(query:Record<string,unknown>):ViewDefinition{
 const filters:Record<string,string|boolean>={};for(const key of filterKeys){const value=query[key];if(typeof value==='string'&&value!=='')filters[key]=key==='include_tests'?value==='1':value}
 return {name:'',visibility:'personal',filters,sort:typeof query.sort==='string'?query.sort:'last_seen',range:typeof query.range==='string'?query.range:'24h'}
}
export function definitionKey(d:ViewDefinition):string {const f={...d.filters};f.status??='unresolved';f.include_tests??=false;return JSON.stringify([Object.entries(f).sort(([a],[b])=>a.localeCompare(b)),d.sort,d.range])}
