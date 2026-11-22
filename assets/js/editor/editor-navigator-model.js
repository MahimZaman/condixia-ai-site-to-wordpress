/* Condixia Smart Outline: pure projection of stable imported element identities.
 * Every displayed node keeps its original DOM parent in `parent`; only
 * `viewParent` and `depth` change. Never persist this projection as markup.
 */
(() => {
  'use strict';

  const CARD = /^(?:product\s+)?card(?:\b|\s|\u00b7)/i;
  const IMPORTANT = new Set([
    'header', 'footer', 'nav', 'main', 'section', 'article', 'aside',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'form',
  ]);

  const build = (input, { mode = 'smart', query = '', selectedId = '' } = {}) => {
    const records = Array.isArray(input) ? input.slice(0, 4000) : [];
    const byId = new Map();
    const rawChildren = new Map();
    for (const source of records) {
      if (!source || typeof source.id !== 'string' || byId.has(source.id)) continue;
      const entry = { ...source,
        parent: typeof source.parent === 'string' ? source.parent : '',
        label: String(source.label || source.tag || 'Element').slice(0, 110),
        tag: String(source.tag || '').toLowerCase(),
        badge: String(source.badge || '').slice(0, 24),
        classes: String(source.classes || '').slice(0, 220),
        domId: String(source.domId || '').slice(0, 90),
        meaningful: source.meaningful === true,
        movable: source.movable === true,
      };
      byId.set(entry.id, entry);
      const parent = entry.parent;
      if (!rawChildren.has(parent)) rawChildren.set(parent, []);
      rawChildren.get(parent).push(entry.id);
    }

    // One visible Navigator item must identify exactly one DOM element.
    // Never replace a collection of repeated cards with a clickable sample.
    // The parent group can stay collapsed until the user expands it.

    const sortableParents = new Set();
    for (const [id, entry] of byId) {
      const siblings = rawChildren.get(id) || [];
      if (siblings.length >= 2 && siblings.every((child) => byId.get(child)?.movable)) {
        sortableParents.add(id);
      }
    }

    const term = String(query).trim().toLowerCase().slice(0, 80).replace(/^[.#]/, '');
    const search = term !== '';
    const show = new Set();
    if (search) {
      for (const entry of byId.values()) {
        const haystack = `${entry.label} ${entry.tag} ${entry.badge} ${entry.domId} ${entry.classes}`.toLowerCase();
        if (!haystack.includes(term)) continue;
        let id = entry.id;
        for (let n = 0; id && n < 90 && byId.has(id); n += 1) {
          if (show.has(id)) break;
          show.add(id);
          id = byId.get(id).parent;
        }
      }
    } else if (mode === 'all') {
      for (const id of byId.keys()) show.add(id);
    } else {
      for (const entry of byId.values()) {
        if (entry.id === selectedId || entry.movable || entry.meaningful ||
            IMPORTANT.has(entry.tag) || sortableParents.has(entry.id) ||
            CARD.test(entry.label)) {
          show.add(entry.id);
        }
      }
      if (byId.has(selectedId)) show.add(selectedId);
    }

    const nodes = [];
    for (const entry of byId.values()) {
      if (!show.has(entry.id)) continue;
      let parent = entry.parent;
      for (let n = 0; parent && n < 90 && !show.has(parent); n += 1) {
        parent = byId.get(parent)?.parent || '';
      }
      if (parent && !byId.has(parent)) parent = '';
      const parentNode = nodes.find((node) => node.id === parent);
      const depth = parentNode ? parentNode.depth + 1 : 0;
      // A structural parent stays a parent: never relabel it as a
      // pseudo-collection whose click would select the wrong element.
      const label = entry.label;
      nodes.push({ ...entry, label, depth, viewParent: parent,
        originalLabel: entry.label, repeatCount: 0, sortableParent: sortableParents.has(entry.id),
      });
    }

    // An eligible jQuery Sortable UL has exactly all the real siblings, all
    // movable, in their original parent. No reordered projection is trusted.
    const sortableGroups = new Set();
    for (const [parent, ids] of rawChildren) {
      if (ids.length < 2 || !ids.every((id) => byId.get(id)?.movable)) continue;
      const shown = nodes.filter((node) => node.viewParent === parent).map((node) => node.id);
      if (shown.length === ids.length && shown.every((id, i) => id === ids[i])) {
        sortableGroups.add(parent);
      }
    }

    return { nodes, byId, rawChildren, sortableGroups, count: byId.size,
      shown: nodes.length, matching: search ? [...show].filter((id) => {
        const entry = byId.get(id);
        return `${entry?.label || ''} ${entry?.tag || ''} ${entry?.classes || ''} ${entry?.domId || ''}`
          .toLowerCase().includes(term);
      }).length : nodes.length,
      search, mode: search ? 'search' : mode };
  };

  if (typeof window !== 'undefined') window.CondixiaNavigatorModel = { build };
  if (typeof module !== 'undefined' && module.exports) module.exports = { build };
})();
