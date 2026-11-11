import { api, ApiError } from './api'
import type { NoteDraft, ViewDefinition } from '../types'
export function useCollaborationAction(){
 let attempt:{intent:string;key:string}|null=null
 return async function<T>(path:string,method:string,values:Record<string,unknown>):Promise<T>{
  const intent=JSON.stringify([path,method,values]);if(attempt?.intent!==intent)attempt={intent,key:crypto.randomUUID()}
  try{const result=await api<T>(path,method,{...values,client_key:attempt.key});attempt=null;return result}
  catch(e){if(e instanceof ApiError && e.status<500)attempt=null;throw e}
 }
}
export function makeNoteDraft():NoteDraft{return {body:'',baseline:'',id:null,version:0,page:1}}
export function noteDirty(d:NoteDraft):boolean{return d.body!==d.baseline}
export { currentDefinition, definitionKey } from './viewState'

export function activityLabel(event:string):string {const labels:Record<string,string>={'error.created':'Error created','error.regressed':'Regressed','error.resolve':'Resolved','error.reopen':'Reopened','error.ignore':'Ignored','error.snooze':'Snoozed','error.unsnooze':'Unsnoozed','error.snooze_expired':'Snooze expired','note.added':'Note added','note.edited':'Note edited','note.deleted':'Note removed','error.assigned':'Assigned','error.unassigned':'Unassigned'};return labels[event]??event.replace(/[._]/g,' ')}
