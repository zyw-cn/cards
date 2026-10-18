/** @typedef {{ value: string|number, label: string, desc?: string }} RuleOption */

/**
 * @param {Array<Record<string, string|undefined>>|Record<string, string|undefined>|undefined} list
 * @returns {RuleOption[]}
 */
export function parseRuleKvList(list) {
  if (!list) return []
  if (Array.isArray(list)) {
    const out = []
    for (const item of list) {
      if (!item || typeof item !== 'object') continue
      for (const [value, label] of Object.entries(item)) {
        if (label == null) continue
        out.push({ value, label: String(label) })
      }
    }
    return out
  }
  return Object.entries(list)
    .filter(([, label]) => label != null)
    .map(([value, label]) => ({ value, label: String(label) }))
}
