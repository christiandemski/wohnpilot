import type { DueStatus, MeterSummary } from './types'
import { days, formatDay } from './format'

export const MEDIA: Record<string, string> = {
  strom: 'Strom',
  wasser: 'Wasser',
  gas: 'Gas',
  waerme: 'Wärme',
  pv: 'PV-Einspeisung',
  gartenwasser: 'Gartenwasser',
  oel: 'Heizöl',
  sonstiges: 'Sonstiges',
}

export const DEFAULT_UNITS: Record<string, string> = {
  strom: 'kWh',
  pv: 'kWh',
  waerme: 'kWh',
  gas: 'm³',
  wasser: 'm³',
  gartenwasser: 'm³',
  oel: 'l',
}

export const DEFAULT_DECIMALS: Record<string, number> = {
  strom: 1,
  pv: 1,
  waerme: 0,
  gas: 3,
  wasser: 3,
  gartenwasser: 3,
  oel: 0,
}

export const INTERVALS: Record<string, string> = {
  monatlich: 'monatlich',
  quartalsweise: 'vierteljährlich',
  halbjaehrlich: 'halbjährlich',
  jaehrlich: 'jährlich',
}

export const OCCASIONS = ['regulär', 'Stichtag', 'Einzug', 'Auszug', 'Zählerwechsel'] as const

/** Room suggestions to tap during setup. */
export const ROOMS: Record<string, string> = {
  keller: 'Keller',
  kueche: 'Küche',
  bad: 'Bad',
  'gaeste-wc': 'Gäste-WC',
  wohnzimmer: 'Wohnzimmer',
  schlafzimmer: 'Schlafzimmer',
  kinderzimmer: 'Kinderzimmer',
  arbeitszimmer: 'Arbeitszimmer',
  flur: 'Flur',
  hauswirtschaftsraum: 'Hauswirtschaftsraum',
  dachboden: 'Dachboden',
  garage: 'Garage',
  garten: 'Garten',
  balkon: 'Balkon',
}

export function roomName(room: string): string {
  return ROOMS[room] ?? room.charAt(0).toUpperCase() + room.slice(1).replace(/[-_]/g, ' ')
}

export function mediumName(medium: string): string {
  return MEDIA[medium] ?? medium
}

/** "Strom", or "Strom · Hauptzähler" when the meter has its own name. */
export function meterTitle(meter: Pick<MeterSummary, 'medium' | 'name'>): string {
  return meter.name ? `${mediumName(meter.medium)} · ${meter.name}` : mediumName(meter.medium)
}

/** "1ESY1160123456" → "1ESY 1160 1234 56" for reading at the meter */
export function groupMeterNumber(number: string): string {
  return number.replace(/\s+/g, '').replace(/(.{4})(?=.)/g, '$1 ')
}

/** Chip text and status for the next reading. */
export function dueChip(schedule: { dueDate: string; daysUntil: number; status: DueStatus }, today: string): string {
  if (schedule.daysUntil < 0) return `Überfällig · ${days(-schedule.daysUntil)}`
  if (schedule.daysUntil === 0) return schedule.dueDate === today ? 'Heute fällig' : 'Fällig'
  if (schedule.status === 'soon') return `noch ${days(schedule.daysUntil)}`
  return `ab ${formatDay(schedule.dueDate)}`
}
