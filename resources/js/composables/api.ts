import { reactive, ref } from 'vue'
import type { Bootstrap } from '../types'
const mount = document.getElementById('exception-monitor-app')!
export const base = mount.dataset.base!
const apiBase = mount.dataset.api!
export const bootstrap = ref<Bootstrap | null>(null)
export const feedback = reactive({ text: '', error: false })
export class ApiError extends Error {
  constructor(public status: number, message: string, public fields: Record<string, string[]> = {}) { super(message) }
}
export function notify(text: string, error = false) { feedback.text = text; feedback.error = error }
export async function api<T>(path: string, method = 'GET', body?: unknown, signal?: AbortSignal): Promise<T> {
  const response = await fetch(apiBase + path, {
    signal, method, credentials: 'same-origin', headers: {
      Accept: 'application/json', 'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
    }, body: body === undefined ? undefined : JSON.stringify(body),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new ApiError(response.status, data.error?.message ?? data.message ?? 'The request could not be completed. Try again.', data.errors ?? {})
  return (data.data ?? data.exception_monitor_private) as T
}
export async function loadBootstrap() { bootstrap.value = await api<Bootstrap>('/bootstrap') }
