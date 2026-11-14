import type { OverviewComparison } from '../types'

export function count(value: string | number): string {
  const raw = String(value)
  return /^\d+$/.test(raw) ? BigInt(raw).toLocaleString('en-US') : Number(value).toLocaleString('en-US', { maximumFractionDigits: 1 })
}
export function ratio(value: string | number, total: string | number): number {
  const numerator = Number(value), denominator = Number(total)
  return Number.isFinite(numerator) && Number.isFinite(denominator) && denominator > 0 && numerator >= 0 && numerator <= denominator ? numerator / denominator * 100 : 0
}
export function share(value: string | number, total: string | number): string {
  if (Number(total) <= 0 || Number(value) > Number(total)) return '—'
  return ratio(value, total).toLocaleString('en-US', { maximumFractionDigits: 1 }) + '%'
}
export function comparisonLabel(comparison: OverviewComparison): string {
  if (!comparison.available) return 'Previous period unavailable'
  if (comparison.change_percent === null) return Number(comparison.current_occurrences) > 0 ? 'New activity in complete intervals' : 'No events in complete intervals'
  if (comparison.change_percent === 0) return 'Unchanged in complete intervals'
  return Math.abs(comparison.change_percent).toLocaleString('en-US', { maximumFractionDigits: 1 }) + '% ' + (comparison.change_percent < 0 ? 'fewer' : 'more') + ' in complete intervals'
}
export function chartScale(values: number[]): { ceiling: number; ticks: number[] } {
  const maximum = Math.max(4, ...values.filter(Number.isFinite))
  const power = 10 ** Math.floor(Math.log10(maximum / 4))
  const step = [1, 2, 5, 10].find(value => value * power >= maximum / 4)! * power
  return { ceiling: step * 4, ticks: [0, 1, 2, 3, 4].map(value => value * step) }
}
export function tickIndexes(length: number): number[] {
  if (length <= 0) return []
  return [...new Set([0, 1, 2, 3, 4].map(value => Math.round(value * (length - 1) / 4)))]
}
