import type { ReproduceEvidence, ReproduceValue } from '../types'

// POSIX shell quoting: every captured value is data, never shell syntax.
export function shellQuote(value: string): string {
  return "'" + value.replace(/'/g, "'\\''") + "'"
}

function parameters(values: Record<string, ReproduceValue>): string {
  const result = new URLSearchParams()
  const add = (key: string, value: ReproduceValue): void => {
    if (value !== null && typeof value === 'object') {
      for (const [child, item] of Object.entries(value)) add(`${key}[${child}]`, item)
    } else result.append(key, value === null ? '' : String(value))
  }
  for (const [key, value] of Object.entries(values)) add(key, value)
  return result.toString()
}

function jsonBody(data: ReproduceEvidence): string {
  const objects = new Set(data.objects ?? [])
  const restore = (value: ReproduceValue, path: string): ReproduceValue => {
    if (value === null || typeof value !== 'object') return value
    const entries = Object.entries(value).map(([key, child]) => [key, restore(child, path + '/' + key.replace(/~/g, '~0').replace(/\//g, '~1'))] as const)
    return Array.isArray(value) && !objects.has(path) ? entries.map(([, child]) => child) : Object.fromEntries(entries)
  }
  return JSON.stringify(restore(data.body ?? {}, ''))
}

export function replayTemplate(data?: ReproduceEvidence): string | null {
  if (!data || data.state !== 'ready') return null
  if (data.kind === 'http') {
    try {
      const origin = new URL(data.origin ?? '')
      if (!['https:', 'http:'].includes(origin.protocol) || origin.username || origin.password || !data.route?.startsWith('/') || !/^[A-Z]{1,16}$/.test(data.method ?? '')) return null
      const path = data.route.replace(/\{([^}?]+)\??\}/g, (_, key: string) => encodeURIComponent(String(Object.hasOwn(data.parameters ?? {}, key) ? data.parameters![key] : `[${key}]`)))
      const query = parameters(data.query ?? {})
      const lines = [`curl --request ${shellQuote(data.method!)} --url ${shellQuote(origin.origin + path + (query ? '?' + query : ''))}`]
      for (const [name, value] of Object.entries(data.headers ?? {})) lines.push(`  --header ${shellQuote(name + ': ' + value)}`)
      if (data.encoding === 'json') {
        if (!Object.keys(data.headers ?? {}).some(name => name.toLowerCase() === 'content-type')) lines.push(`  --header ${shellQuote('Content-Type: application/json')}`)
        lines.push(`  --data-raw ${shellQuote(jsonBody(data))}`)
      }
      if (data.encoding === 'form') lines.push(`  --data-raw ${shellQuote(parameters(data.body ?? {}))}`)
      return lines.join(' \\\n')
    } catch { return null }
  }
  if (data.kind === 'command' && data.selector) {
    const parts = ['php', 'artisan', shellQuote(data.selector)]
    const string = (value: ReproduceValue): string => value !== null && typeof value === 'object' ? JSON.stringify(value) : String(value ?? '')
    for (const [name, value] of Object.entries(data.options ?? {})) {
      if (!/^[a-zA-Z0-9_-]+$/.test(name)) return null
      for (const item of Array.isArray(value) ? value : [value]) {
        if (item !== false && item !== null) parts.push(shellQuote('--' + name + (item === true ? '' : '=' + string(item))))
      }
    }
    const args: string[] = []
    for (const value of Object.values(data.arguments ?? {})) {
      for (const item of Array.isArray(value) ? value : [value]) args.push(shellQuote(string(item)))
    }
    if (args.length) parts.push('--', ...args)
    return parts.join(' ')
  }
  return null
}

export function replayState(data?: ReproduceEvidence): string {
  if (!data) return 'This older snapshot has no replay evidence. Enabling capture cannot reconstruct discarded values.'
  return {
    ready: 'Review the target environment and placeholders before running. Replaying may change application data.',
    excluded: 'Replay capture is disabled or excluded by the current privacy policy.',
    unavailable: 'Replay evidence was not available for this capture or could not be read.',
    unsupported: 'Automatic replay is unavailable for this execution type. Job payloads are never serialized for replay.',
    too_large: 'Replay evidence exceeded the capture limit and was omitted.',
    restricted: 'Replay evidence is available only to monitoring operators and administrators.',
  }[data.state] ?? 'Replay evidence is unavailable.'
}
