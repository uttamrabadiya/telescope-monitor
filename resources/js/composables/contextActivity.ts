import type { Evidence } from '../types'
type Entry = Record<string, string | number>
export function milliseconds(value: unknown): string {
  const n = (typeof value === 'number' || (typeof value === 'string' && value.trim() !== '')) ? Number(value) : NaN
  return Number.isFinite(n) && n >= 0 ? `${new Intl.NumberFormat('en', { maximumFractionDigits: 2 }).format(n)} ms` : 'Duration unavailable'
}
export function activityDescription(event: Entry, execution: Entry = {}): string {
  const text = (key: string) => String(event[key] ?? execution[key] ?? '').trim()
  switch (event.type) {
    case 'request': return [text('method'), text('path_template') || text('path')].filter(Boolean).join(' ') || 'Request details were not retained'
    case 'job': return text('job_class') && text('job_class') !== '[unavailable]' ? `Job: ${text('job_class')}` : 'Job details were not retained'
    case 'command': return text('command') && text('command') !== '[unavailable]' ? `Command: ${text('command')}` : 'Command details were not retained'
    default: return 'Related activity'
  }
}
export function sqlAvailability(query: Entry): string {
  switch (query.sql_state) {
    case 'disabled': return 'SQL capture is disabled by the current privacy policy.'
    case 'unsupported': return 'SQL details could not be safely normalized.'
    case 'too_large': return 'SQL exceeded the capture limit and was not retained.'
    default: return 'SQL was not retained in this older snapshot.'
  }
}
export function contextActivity(evidence: Evidence) {
  const timeline = evidence.timeline ?? []
  const queries = evidence.queries?.length ? evidence.queries : timeline.filter(e => e.type === 'query')
  const logs = evidence.logs?.length ? evidence.logs : timeline.filter(e => e.type === 'log')
  return {
    entrypoints: timeline.filter(e => ['request', 'job', 'command'].includes(String(e.type))),
    queries, logs,
    duration: queries.some(q => milliseconds(q.duration_ms) !== 'Duration unavailable') ? queries.reduce((total, query) => { const n = Number(query.duration_ms); return total + (milliseconds(query.duration_ms) !== 'Duration unavailable' ? n : 0) }, 0) : null,
    hasSql: queries.some(q => typeof q.sql_template === 'string' && q.sql_state === 'retained'),
    hasLegacy: queries.some(q => !q.sql_state),
  }
}
