import { ref, shallowRef, watch, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { formatDateTime } from './time'
import { api, bootstrap } from './api'
export function useInvestigation<T>(endpoint:()=>string) {
 const route=useRoute(), data=shallowRef<T|null>(null), loading=ref(false), error=ref('')
 let controller:AbortController|undefined, generation=0
 async function reload(){const current=++generation;controller?.abort();controller=new AbortController();data.value=null;loading.value=true;error.value=''
  try {const result=await api<T>(endpoint(),'GET',undefined,controller.signal);if(current===generation)data.value=result}
  catch(e){if(current===generation && !(e instanceof DOMException && e.name==='AbortError'))error.value=e instanceof Error?e.message:'Could not load monitoring data.'}
  finally{if(current===generation)loading.value=false}
 }
 watch(()=>route.fullPath,reload,{immediate:true});onUnmounted(()=>{generation++;controller?.abort()})
 return {data,loading,error,reload}
}
export function queryString(query:Record<string,unknown>, excluded:string[]=[]):string {const p=new URLSearchParams();for(const[key,value]of Object.entries(query))if(!excluded.includes(key)&&typeof value==='string'&&value!=='')p.set(key,value);return p.toString()}
export function date(value:string|null|undefined):string {return formatDateTime(value,zone())}
export function zone():string{return bootstrap.value?.application.timezone??'UTC'}
