import type { ViewPreferences } from '../types'

export interface ViewPreferenceDraft { default_view_ref: string | null; favorites: string[] }
export function preferenceDraft(value: ViewPreferences): ViewPreferenceDraft {
  return {
    default_view_ref: value.default_view_ref === 'builtin:unresolved' ? null : value.default_view_ref,
    favorites: [...value.shortcuts].sort((a, b) => a.position - b.position).filter(item => item.favorite).map(item => item.ref),
  }
}
export function preferenceKey(value: ViewPreferenceDraft): string {
  return JSON.stringify([value.default_view_ref === 'builtin:unresolved' ? null : value.default_view_ref, value.favorites])
}
export function preferencePayload(value: ViewPreferenceDraft, version: number) {
  return {
    version,
    default_view_ref: value.default_view_ref === 'builtin:unresolved' ? null : value.default_view_ref,
    shortcuts: value.favorites.map((ref, position) => ({ ref, favorite: true, position })),
  }
}
export function moveFavorite(value: ViewPreferenceDraft, position: number, direction: number) {
  const destination = position + direction
  if (position < 0 || position >= value.favorites.length || destination < 0 || destination >= value.favorites.length) return
  const [ref] = value.favorites.splice(position, 1)
  value.favorites.splice(destination, 0, ref!)
}
