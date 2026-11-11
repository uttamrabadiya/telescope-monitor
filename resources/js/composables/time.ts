/** Package SQL timestamps are UTC, independently of the browser's timezone. */
export function parseInstant(value: string): Date {
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(value)
    ? value.replace(' ', 'T') + 'Z'
    : value
  return new Date(normalized.replace(/(\.\d{3})\d+(?=Z|[+-]\d{2}:\d{2}$)/, '$1'))
}
