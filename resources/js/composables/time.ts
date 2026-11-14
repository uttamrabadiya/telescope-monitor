/** Package SQL timestamps are UTC, independently of the browser's timezone. */
export function parseInstant(value: string): Date {
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value)
    ? value.replace(' ', 'T') + 'Z'
    : value
  return new Date(normalized.replace(/(\.\d{3})\d+(?=Z|[+-]\d{2}:\d{2}$)/, '$1'))
}

/** Match Telescope's human-readable date format in the application's timezone. */
export function formatDateTime(value: string | null | undefined, timeZone = 'UTC'): string {
  if (!value) return 'Unavailable'
  const parts = new Intl.DateTimeFormat('en-US', {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true, timeZone,
  }).formatToParts(parseInstant(value))
  const part = (type: Intl.DateTimeFormatPartTypes) => parts.find(part => part.type === type)!.value
  const day = Number(part('day'))
  return `${part('month')} ${day} ${part('year')}, ${part('hour')}:${part('minute')}:${part('second')} ${part('dayPeriod')}`
}

export function formatChartDateTime(value: string, timeZone = 'UTC'): string {
  return new Intl.DateTimeFormat('en-US', {
    month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, timeZone,
  }).format(parseInstant(value))
}

export function formatRelativeTime(value: string | null | undefined, now = Date.now()): string {
  if (!value) return 'Unavailable'
  const delta = now - parseInstant(value).getTime()
  if (!Number.isFinite(delta)) return 'Unavailable'
  const seconds = Math.floor(Math.abs(delta) / 1000)
  if (seconds < 5) return 'just now'
  const units: [number, string][] = [[31536000, 'y'], [2592000, 'mo'], [86400, 'd'], [3600, 'h'], [60, 'm'], [1, 's']]
  const [size, unit] = units.find(([size]) => seconds >= size)!
  const duration = `${Math.floor(seconds / size)}${unit}`
  return delta < 0 ? `in ${duration}` : `${duration} ago`
}
