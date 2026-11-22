(() => {
  "use strict";

  const config = window.CondixiaEditorConfig;

  if (!config || typeof config !== "object") {
    return;
  }

  const frame = document.querySelector("[data-cdx-editor-frame]");

  const stage = document.querySelector("[data-cdx-editor-stage]");

  const status = document.querySelector("[data-cdx-editor-status]");

  const empty = document.querySelector("[data-cdx-editor-empty]");

  const controls = document.querySelector("[data-cdx-editor-controls]");

  const shell = document.querySelector(".cdx-stw-editor-shell");
  const navigatorElement = document.querySelector(
    "[data-cdx-navigator-element]",
  );
  const panelButtons = Array.from(
    document.querySelectorAll("[data-cdx-panel-toggle]"),
  );

  const propertyControls = Array.from(
    document.querySelectorAll("[data-cdx-property]"),
  );

  const viewportButtons = Array.from(
    document.querySelectorAll("[data-cdx-viewport]"),
  );

  const mediaButtons = Array.from(
    document.querySelectorAll("[data-cdx-media]"),
  );

  const canvas = document.querySelector("[data-cdx-editor-canvas]");
  const frameWrap = document.querySelector(".cdx-stw-editor-frame-wrap");
  const zoomLabel = document.querySelector("[data-cdx-zoom-label]");
  const zoomButtons = Array.from(document.querySelectorAll("[data-cdx-zoom]"));
  // Compact SVG toolbar icons, without any external icon dependency.
  // Existing data-cdx-* attributes remain unchanged.
  const iconPaths = {
    '[data-cdx-panel-toggle="navigator"]':
      "M3 4h18v16H3z M10 4v16 M6 8h1 M6 12h1",

    '[data-cdx-panel-toggle="inspector"]':
      "M3 4h18v16H3z M14 4v16 M17 8h1 M17 12h1",

    '[data-cdx-viewport="desktop"]': "M3 4h18v13H3z M8 21h8 M12 17v4",

    '[data-cdx-viewport="tablet"]':
      "M6 2h12a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z M11 19h2",

    '[data-cdx-viewport="mobile"]':
      "M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z M11 19h2",

    '[data-cdx-zoom="out"]': "M5 12h14",

    '[data-cdx-zoom="in"]': "M5 12h14 M12 5v14",

    '[data-cdx-zoom="fit"]': "M8 3H3v5 M16 3h5v5 M3 16v5h5 M21 16v5h-5",

    '[data-cdx-zoom="actual"]': "M4 4h16v16H4z M8 8h8v8H8z",

    '[data-cdx-editor-action="undo"]': "M9 14 4 9l5-5 M4 9h9a6 6 0 0 1 0 12h-2",

    '[data-cdx-editor-action="redo"]':
      "m15 14 5-5-5-5 M20 9h-9a6 6 0 0 0 0 12h2",

    '[data-cdx-editor-action="restore-element"]':
      "M4 10a8 8 0 1 1 2 8 M4 4v6h6 M12 7v5l3 2",

    '[data-cdx-editor-action="restore-page"]': "M5 5l14 14 M19 5 5 19",

    '[data-cdx-editor-action="restore-revision"]':
      "M12 7v5l3 2 M4 10a8 8 0 1 1 2 8 M4 4v6h6",

    '[data-cdx-editor-action="restore-original"]':
      "M4 10a8 8 0 1 1 2 8 M4 4v6h6",

    "> a.button":
      "M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h4 M13 7l5 5-5 5 M7 12h11",
  };

  const makeIcon = (pathData) => {
    const namespace = "http://www.w3.org/2000/svg";
    const svg = document.createElementNS(namespace, "svg");
    const path = document.createElementNS(namespace, "path");

    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    svg.setAttribute("focusable", "false");
    svg.classList.add("cdx-stw-editor-icon");

    path.setAttribute("d", pathData);
    svg.appendChild(path);

    return svg;
  };

  Object.entries(iconPaths).forEach(([selector, pathData]) => {
    const control = document.querySelector(
      `.cdx-stw-editor-actions ${selector}`,
    );

    if (!control) {
      return;
    }

    const label = control.textContent.trim() || "Editor action";

    control.setAttribute("aria-label", label);
    control.setAttribute("title", label);
    control.classList.add("cdx-stw-editor-icon-button");

    control.replaceChildren(makeIcon(pathData));
  });

  // Keep Save visible and recognizable.
  const saveControl = document.querySelector('[data-cdx-editor-action="save"]');

  if (saveControl) {
    saveControl.prepend(makeIcon("M4 3h13l3 3v15H4z M8 3v7h8V3 M8 21v-8h8v8"));

    saveControl.title = "Save changes";
  }

  // Expose the active responsive viewport to assistive technology.
  viewportButtons.forEach((button) => {
    button.setAttribute(
      "aria-pressed",
      String(button.classList.contains("is-active")),
    );
  });
  const hexInputs = Array.from(
    document.querySelectorAll("[data-cdx-color-hex]"),
  );

  if (
    !frame ||
    !stage ||
    !status ||
    !empty ||
    !controls ||
    !shell ||
    !canvas ||
    !frameWrap ||
    !zoomLabel
  ) {
    return;
  }

  let selectedId = "";
  let selectedCapabilities = {};
  let mediaFrame = null;
  let mediaMode = "";

  const setStatus = (text) => {
    status.textContent = text;
  };

  const send = (type, payload = {}) => {
    if (!frame.contentWindow) {
      return;
    }

    frame.contentWindow.postMessage(
      {
        channel: "condixia-ai-stw-editor",
        type,
        payload,
      },
      "*",
    );
  };

  const field = (property) =>
    document.querySelector(`[data-cdx-field="${property}"]`);

  const setFieldVisibility = (property, visible) => {
    const wrapper = field(property);

    if (wrapper) {
      wrapper.hidden = !visible;
    }
  };

  const setValue = (property, value) => {
    const control = document.querySelector(`[data-cdx-property="${property}"]`);

    if (!control) {
      return;
    }

    if (control.type === "color") {
      control.value = /^#[0-9a-f]{6}$/i.test(value || "") ? value : "#000000";

      const hex = document.querySelector(`[data-cdx-color-hex="${property}"]`);
      if (hex) {
        hex.value = control.value.toUpperCase();
      }

      const swatch = control.closest(".cdx-stw-editor-color-swatch");
      if (swatch) {
        swatch.style.backgroundColor = control.value;
      }

      return;
    }

    control.value = typeof value === "string" ? value : "";
  };

  const updateMediaThumbnail = (mode, value) => {
    const thumb = document.querySelector(
      `[data-cdx-media-thumbnail="${mode}"]`,
    );
    const placeholder = document.querySelector(
      `[data-cdx-media-placeholder="${mode}"]`,
    );

    if (!thumb || !placeholder) {
      return;
    }

    let url = "";

    try {
      const parsed = new URL(String(value || ""), config.targetUrl);

      if (["http:", "https:"].includes(parsed.protocol) && value) {
        url = parsed.href;
      }
    } catch (error) {
      // No usable thumbnail.
    }

    thumb.hidden = !url;
    placeholder.hidden = Boolean(url);

    if (url) {
      thumb.src = url;
    } else {
      thumb.removeAttribute("src");
    }
  };

  // Step 19A: Smart Outline is only a view over immutable source element IDs.
  const layerTree = document.querySelector("[data-cdx-layer-tree]");
  const layerSearch = document.querySelector("[data-cdx-layer-search]");
  const layerSummary = document.querySelector("[data-cdx-layer-summary]");
  const layerCrumbs = document.querySelector("[data-cdx-layer-breadcrumbs]");
  const layerModeButtons = Array.from(
    document.querySelectorAll("[data-cdx-layer-mode]"),
  );
  const navigatorModel = window.CondixiaNavigatorModel;
  const layerRecords = new Map();
  const layerItems = new Map();
  const layerGroups = new Map();
  const layerToggles = new Map();
  const expandedLayers = new Set();
  const navigatorExtraBadges = {
    navigation: new Set(),
    sitepart: new Map(),
    grid: new Set(),
  };
  let activeLayerId = "";
  let layerMode = "smart";
  let layerSnapshot = null;
  let layerProjection = null;
  let projectedSelectedId = "";

  const chooseLayer = (id) => {
    if (!layerRecords.has(id)) return;
    // Do not optimistically change the Inspector to a guessed element.
    // Only the preview's matching ID acknowledgement changes selection.
    send("condixia/editor/select-layer", { id });
  };

  const expandLayer = (id, expanded) => {
    const group = layerGroups.get(id);
    const toggle = layerToggles.get(id);
    if (!group || !toggle) return;
    group.hidden = !expanded;
    toggle.setAttribute("aria-expanded", String(expanded));
    toggle.setAttribute(
      "aria-label",
      `${expanded ? "Collapse" : "Expand"} ${layerRecords.get(id)?.label || "section"}`,
    );
    toggle.textContent = expanded ? "⌄" : "›";
    if (expanded) expandedLayers.add(id);
    else expandedLayers.delete(id);
  };

  const badgeIn = (choose, className, label) => {
    let badge = choose.querySelector(`.${className}`);
    if (!label) {
      badge?.remove();
      return;
    }
    if (!badge) {
      badge = document.createElement("span");
      badge.className = className;
      choose.append(badge);
    }
    badge.textContent = label;
  };

  const repaintNavigatorBadges = () => {
    layerItems.forEach((item, id) => {
      const choose = item.querySelector(".cdx-stw-layer-select");
      if (!choose) return;
      badgeIn(
        choose,
        "cdx-stw-sitepart-badge",
        navigatorExtraBadges.sitepart.get(id) || "",
      );
      badgeIn(
        choose,
        "cdx-stw-grid-badge",
        navigatorExtraBadges.grid.has(id) ? "Dynamic Grid" : "",
      );
      if (navigatorExtraBadges.navigation.has(id)) {
        badgeIn(choose, "cdx-stw-layer-badge", "Navigation");
      }
    });
  };

  const clearNavigatorSortables = () => {
    const $ = window.jQuery;
    if (!$?.fn?.sortable || !layerTree) return;
    $(layerTree)
      .find("ul.cdx-stw-is-sortable")
      .addBack("ul.cdx-stw-is-sortable")
      .each(function () {
        if ($(this).data("ui-sortable")) $(this).sortable("destroy");
      });
  };

  const initNavigatorSortables = () => {
    const $ = window.jQuery;
    if (!layerProjection || layerProjection.search) return;
    layerGroups.forEach((group, parentId) => {
      if (!layerProjection.sortableGroups.has(parentId)) return;
      const direct = Array.from(group.children).filter((item) =>
        item.matches("li[data-cdx-layer-id]"),
      );
      const original = layerProjection.rawChildren.get(parentId) || [];
      if (
        direct.length < 2 ||
        direct.length !== original.length ||
        direct.some(
          (item, index) =>
            item.dataset.cdxLayerId !== original[index] ||
            item.dataset.cdxMovable !== "1",
        )
      )
        return;
      direct.forEach((item) => {
        const handle = item.querySelector(
          ":scope > .cdx-stw-layer-row > .cdx-stw-layer-drag-handle",
        );
        if (handle) handle.hidden = false;
      });
      if (!$?.fn?.sortable) return; // Keyboard reorder is still available.
      group.classList.add("cdx-stw-is-sortable");
      $(group).sortable({
        items: "> li[data-cdx-movable='1']",
        handle: ".cdx-stw-layer-drag-handle",
        // jQuery UI mouse excludes <button> by default. Our accessible
        // drag handle IS a button, so explicitly allow it to initiate.
        cancel: "input, textarea, select, option",
        axis: "y",
        tolerance: "pointer",
        scroll: true,
        scrollSensitivity: 45,
        scrollSpeed: 15,
        placeholder: "cdx-stw-sortable-placeholder",
        helper: (_event, item) => {
          const helper = item.clone();
          helper.children("ul").remove();
          helper.width(item.width());
          return helper;
        },
        start: (_event, ui) => {
          ui.placeholder.height(
            ui.item.children(".cdx-stw-layer-row").outerHeight() || 32,
          );
        },
        update: (_event, ui) => {
          const id = ui.item[0]?.dataset.cdxLayerId || "";
          const proposed = Array.from(group.children)
            .filter((item) => item.matches("li[data-cdx-layer-id]"))
            .map((item) => item.dataset.cdxLayerId);
          const from = original.indexOf(id);
          const to = proposed.indexOf(id);
          // jQuery Sortable must never persist the projected Navigator DOM.
          $(group).sortable("cancel");
          if (
            from < 0 ||
            to < 0 ||
            from === to ||
            proposed.length !== original.length ||
            original.some((entry) => !proposed.includes(entry))
          )
            return;
          const targetId = proposed[to + 1] || proposed[to - 1] || "";
          const position = to < proposed.length - 1 ? "before" : "after";
          if (targetId) requestStructuralMove(id, targetId, position);
        },
      });
    });
  };

  const rebuildNavigator = (preserveScroll = false) => {
    if (!layerTree || !layerSnapshot || !navigatorModel?.build) return;
    const previousScroll = layerTree.parentElement?.scrollTop || 0;
    clearNavigatorSortables();
    const projection = navigatorModel.build(layerSnapshot.layers, {
      mode: layerMode,
      query: layerSearch?.value || "",
      selectedId,
    });
    layerProjection = projection;
    projectedSelectedId = selectedId;
    layerRecords.clear();
    projection.byId.forEach((record, id) => layerRecords.set(id, record));
    layerItems.clear();
    layerGroups.clear();
    layerToggles.clear();
    layerTree.replaceChildren();
    layerModeButtons.forEach((button) => {
      const on = button.dataset.cdxLayerMode === layerMode;
      button.setAttribute("aria-pressed", String(on));
      button.classList.toggle("is-active", on);
    });

    const root = document.createDocumentFragment();
    projection.nodes.forEach((record) => {
      const item = document.createElement("li");
      item.dataset.cdxLayerId = record.id;
      item.dataset.cdxMovable = record.movable ? "1" : "0";
      item.className = "cdx-stw-layer-item";
      const row = document.createElement("div");
      row.className = "cdx-stw-layer-row";
      row.style.paddingInlineStart = `${Math.min(4, record.depth) * 8}px`;
      const toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "cdx-stw-layer-expand";
      toggle.hidden = true;
      toggle.setAttribute("aria-label", `Expand ${record.label}`);
      toggle.addEventListener("click", () => {
        expandLayer(record.id, toggle.getAttribute("aria-expanded") !== "true");
      });
      const handle = document.createElement("button");
      handle.type = "button";
      handle.className = "cdx-stw-layer-drag-handle";
      handle.textContent = "⠿";
      handle.hidden = true;
      handle.title = `Drag ${record.label} to reorder. Keyboard: Alt+Arrow Up/Down.`;
      handle.setAttribute(
        "aria-label",
        `Reorder ${record.label}; press Alt+Up or Alt+Down`,
      );
      handle.addEventListener("keydown", (event) => {
        if (!event.altKey || !["ArrowUp", "ArrowDown"].includes(event.key))
          return;
        event.preventDefault();
        requestStructuralMove(
          record.id,
          "",
          event.key === "ArrowUp" ? "up" : "down",
        );
      });

      const choose = document.createElement("button");
      choose.type = "button";
      choose.className = "cdx-stw-layer-select";
      choose.dataset.cdxLayerId = record.id;
      choose.title = record.label + (record.domId ? ` (#${record.domId})` : "");
      const icon = makeIcon(
        {
          img: "M4 4h16v16H4z M4 16l5-5 4 4 3-3 4 4",
          nav: "M4 6h16 M4 12h16 M4 18h16",
          header: "M3 4h18v16H3z M3 9h18",
          footer: "M3 4h18v16H3z M3 16h18",
          section: "M4 4h16v16H4z M4 9h16",
        }[record.tag] || "M4 4h16v16H4z",
      );
      icon.classList.add("cdx-stw-layer-tag");
      const label = document.createElement("span");
      label.className = "cdx-stw-layer-label";
      label.textContent = record.label;
      choose.append(icon, label);
      if (record.badge) badgeIn(choose, "cdx-stw-layer-badge", record.badge);
      choose.addEventListener("click", () => chooseLayer(record.id));
      row.append(toggle, handle, choose);
      item.append(row);
      layerItems.set(record.id, item);
      layerToggles.set(record.id, toggle);
      const parent = record.viewParent;
      if (parent && layerItems.has(parent)) {
        let group = layerGroups.get(parent);
        if (!group) {
          group = document.createElement("ul");
          group.className = "cdx-stw-layer-children";
          layerGroups.set(parent, group);
          layerItems.get(parent).append(group);
          const parentToggle = layerToggles.get(parent);
          parentToggle.hidden = false;
          expandLayer(
            parent,
            expandedLayers.has(parent) ||
              projection.search ||
              record.depth <= 1,
          );
        }
        group.append(item);
      } else {
        root.append(item);
      }
    });
    layerTree.append(root);
    layerItems
      .get(selectedId)
      ?.querySelector(".cdx-stw-layer-select")
      ?.setAttribute("aria-current", "true");
    repaintNavigatorBadges();
    initNavigatorSortables();
    if (layerSummary) {
      layerSummary.textContent = projection.search
        ? `${projection.matching} matching elements · ${projection.shown} including ancestors`
        : `${projection.shown} useful layers · ${projection.count} total` +
          (layerSnapshot.truncated ? " (first 1,800 indexed)" : "");
    }
    if (preserveScroll && layerTree.parentElement)
      layerTree.parentElement.scrollTop = previousScroll;
  };

  const filterLayers = () => rebuildNavigator(true);

  let navigatorReindexPending = false;

  const revealLayer = (id) => {
    // A clicked runtime element may not yet be in the Navigator snapshot.
    if (!layerRecords.has(id)) {
      if (id && !navigatorReindexPending) {
        navigatorReindexPending = true;
        send("condixia/editor/layers-request");
      }
      return;
    }
    const wasSearching = Boolean(layerSearch?.value);
    if (wasSearching && layerSearch) layerSearch.value = "";
    if (wasSearching || projectedSelectedId !== id || !layerItems.has(id))
      rebuildNavigator(true);
    layerItems
      .get(activeLayerId)
      ?.querySelector(".cdx-stw-layer-select")
      ?.removeAttribute("aria-current");
    activeLayerId = id;
    // Smart Outline compresses wrappers: expand visible view parents, not
    // hidden raw DOM parents, or the selected row remains collapsed.
    // Projected parents belong to nodes, not the raw byId records.
    const visibleNodes = new Map(
      (layerProjection?.nodes || []).map((node) => [node.id, node]),
    );

    let ancestor = visibleNodes.get(id)?.viewParent || "";

    for (let depth = 0; ancestor && depth < 90; depth += 1) {
      expandLayer(ancestor, true);
      ancestor = visibleNodes.get(ancestor)?.viewParent || "";
    }
    const item = layerItems.get(id);
    item
      ?.querySelector(".cdx-stw-layer-select")
      ?.setAttribute("aria-current", "true");
    item
      ?.querySelector(".cdx-stw-layer-select")
      ?.scrollIntoView({ block: "nearest" });
    if (!layerCrumbs) return;
    layerCrumbs.replaceChildren();
    const trail = [];
    let current = id;
    for (let n = 0; current && n < 90; n += 1) {
      trail.unshift(current);
      current = layerRecords.get(current)?.parent || "";
    }
    trail.slice(-7).forEach((part, index) => {
      if (index) layerCrumbs.append(document.createTextNode(" › "));
      const crumb = document.createElement("button");
      crumb.type = "button";
      crumb.textContent = layerRecords.get(part)?.label || "Element";
      crumb.title = crumb.textContent;
      crumb.addEventListener("click", () => chooseLayer(part));
      layerCrumbs.append(crumb);
    });
  };

  const renderLayers = (data) => {
    if (!data || !Array.isArray(data.layers)) return;

    navigatorReindexPending = false;
    layerSnapshot = data;
    // Structural moves change the imported page order but not source identities.
    rebuildNavigator();
    if (selectedId && layerRecords.has(selectedId)) revealLayer(selectedId);
  };

  layerSearch?.addEventListener("input", filterLayers);
  layerModeButtons.forEach((button) =>
    button.addEventListener("click", () => {
      layerMode = button.dataset.cdxLayerMode === "all" ? "all" : "smart";
      expandedLayers.clear();
      rebuildNavigator();
      if (selectedId && layerRecords.has(selectedId)) revealLayer(selectedId);
    }),
  );

  const inspectorTabs = Array.from(
    document.querySelectorAll("[data-cdx-inspector-tab]"),
  );
  const inspectorPanels = Array.from(
    document.querySelectorAll("[data-cdx-inspector-panel]"),
  );
  const inspectorName = document.querySelector("[data-cdx-inspector-element]");
  const inspectorHint = document.querySelector("[data-cdx-inspector-hint]");
  const inspectorTag = document.querySelector("[data-cdx-inspector-tag]");
  const inspectorReference = document.querySelector(
    "[data-cdx-inspector-reference]",
  );
  const nestedTextWarning = document.querySelector(
    "[data-cdx-nested-text-warning]",
  );

  // Text-segment editor shares Save/Undo/Redo/revisions with ordinary properties.
  const richRoot = document.querySelector("[data-cdx-richtext]");
  const richFields = document.querySelector("[data-cdx-richtext-fields]");
  const richFeedback = document.querySelector("[data-cdx-richtext-feedback]");
  let richState = [];

  const updateRichTextFields = (value) => {
    if (!richFields) return;
    let parts;
    try {
      parts = JSON.parse(value);
    } catch (error) {
      return;
    }
    if (
      !Array.isArray(parts) ||
      parts.length !== richState.length ||
      !parts.every(
        (p, i) => p && p.p === richState[i].p && typeof p.t === "string",
      )
    )
      return;
    richState = parts;
    richFields
      .querySelectorAll("textarea[data-cdx-rich-segment]")
      .forEach((input, i) => {
        if (input !== document.activeElement) input.value = richState[i].t;
      });
  };

  const showRichTextFields = (payload) => {
    if (!richRoot || !richFields) return;
    richRoot.hidden = !selectedCapabilities.richText;
    richFields.replaceChildren();
    richState = [];
    if (richFeedback) richFeedback.textContent = "";
    if (!selectedCapabilities.richText) return;

    let parsed;
    try {
      parsed = JSON.parse(payload.richText);
    } catch (error) {
      return;
    }
    if (!Array.isArray(parsed) || !parsed.length || parsed.length > 100) return;
    richState = parsed.map((part) => ({ p: part.p, t: part.t }));
    parsed.forEach((part, index) => {
      const wrapper = document.createElement("label");
      const caption = document.createElement("span");
      caption.textContent = `Segment ${index + 1} · ${String(payload.richLabels?.[index] || "text").slice(0, 48)}`;
      const input = document.createElement("textarea");
      input.rows = 2;
      input.maxLength = 4000;
      input.dataset.cdxRichSegment = String(index);
      input.value = part.t;
      input.addEventListener("input", () => {
        if (!selectedId || !richState[index]) return;
        if (!input.value || input.value.includes("\u0000")) {
          if (richFeedback)
            richFeedback.textContent =
              "To preserve text-node positions, segments cannot be completely empty.";
          return;
        }
        const next = richState.map((entry, i) => ({
          p: entry.p,
          t: i === index ? input.value : entry.t,
        }));
        if (next.reduce((sum, entry) => sum + entry.t.length, 0) > 30000) {
          if (richFeedback)
            richFeedback.textContent =
              "The text segment limit has been reached.";
          return;
        }
        richState = next;
        if (richFeedback)
          richFeedback.textContent = "Markup and other segments are preserved.";
        send("condixia/editor/apply", {
          id: selectedId,
          property: "richText",
          value: JSON.stringify(richState),
        });
      });
      wrapper.append(caption, input);
      richFields.append(wrapper);
    });
  };

  // Step 15: manual WordPress navigation assignment (never accepts iframe HTML).
  const navRoot = document.querySelector("[data-cdx-manual-navigation]");
  const navInspect = document.querySelector("[data-cdx-nav-inspect]");
  const navDetails = document.querySelector("[data-cdx-nav-details]");
  const navSummary = document.querySelector("[data-cdx-nav-summary]");
  const navOptions = document.querySelector("[data-cdx-nav-options]");
  const navCreate = document.querySelector("[data-cdx-nav-create]");
  const navConnect = document.querySelector("[data-cdx-nav-connect]");
  const navDetach = document.querySelector("[data-cdx-nav-detach]");
  const navManage = document.querySelector("[data-cdx-nav-manage]");
  const navFeedback = document.querySelector("[data-cdx-nav-feedback]");
  const navButtons = [navInspect, navCreate, navConnect, navDetach].filter(
    Boolean,
  );

  let navContext = {
    siteId: Number(config.siteId || 0),
    artifactId: Number(config.artifactId || 0),
  };
  let navBound = false;
  let navShared = 1;
  let navBusy = false;
  let navCaptureBusy = false;
  const navCaptureRequests = new Map();
  let navCaptureNumber = 0;

  const capturePreviewMenu = (id) => {
    // The WordPress editor and its preview normally share an origin.
    // Direct capture avoids losing the response due to message timing.
    try {
      const runtime = frame.contentWindow?.CondixiaRuntime;

      if (typeof runtime?.captureMenu === "function") {
        return Promise.resolve(runtime.captureMenu(id));
      }
    } catch (_) {
      // Cross-origin previews use the existing postMessage bridge.
    }

    return new Promise((resolve) => {
      const requestId = `menu-${++navCaptureNumber}`;

      const timeout = window.setTimeout(() => {
        navCaptureRequests.delete(requestId);

        resolve({
          error:
            "The preview did not respond to menu capture. " +
            "Confirm editor-runtime.js loads inside the iframe, " +
            "then reload the editor.",
        });
      }, 2500);

      navCaptureRequests.set(requestId, (result) => {
        window.clearTimeout(timeout);
        resolve(result);
      });

      send("condixia/editor/runtime-menu-capture", {
        id,
        requestId,
      });
    });
  };

  const updateLayerNavigationBadge = (id, bound) => {
    if (bound) navigatorExtraBadges.navigation.add(id);
    else navigatorExtraBadges.navigation.delete(id);
    const entry = layerRecords.get(id);
    const choose = layerItems.get(id)?.querySelector(".cdx-stw-layer-select");
    if (!entry || !choose) return;
    entry.badge = bound ? "Navigation" : "";
    let badge = choose.querySelector(".cdx-stw-layer-badge");
    if (!bound) {
      badge?.remove();
      return;
    }
    if (!badge) {
      badge = document.createElement("span");
      badge.className = "cdx-stw-layer-badge";
      choose.append(badge);
    }
    badge.textContent = "Navigation";
  };

  const showNavResult = (data, elementId) => {
    if (!data || !navDetails || !navOptions) return;
    const bound = Boolean(data.bound);
    navBound = bound;
    navShared = Math.max(1, Number(data.shared || 1));
    navDetails.hidden = false;

    // Once connected, show management controls, not conversion again.
    if (navInspect) {
      navInspect.hidden = bound;
      navInspect.textContent = bound
        ? "WordPress Navigation connected"
        : "Use as Navigation";
    }

    if (navCreate) {
      navCreate.hidden = bound;
    }
    navSummary.textContent =
      `${Number(data.links || 0)} links found · ` +
      `${Number(data.nested || 0)} nested items` +
      (navShared > 1 ? ` · Shared across ${navShared} source documents` : "") +
      (bound ? " · Connected to WordPress" : "");

    navOptions.replaceChildren(new Option("Select a menu", ""));
    if (Array.isArray(data.menus)) {
      data.menus.slice(0, 200).forEach((menu) => {
        if (
          !["classic", "block"].includes(menu.provider) ||
          !Number.isSafeInteger(menu.id) ||
          menu.id < 1
        )
          return;
        navOptions.add(
          new Option(
            `${menu.label} (${menu.provider})`,
            `${menu.provider}:${menu.id}`,
          ),
        );
      });
    }
    navOptions.value = bound
      ? `${data.provider}:${Number(data.object_id || 0)}`
      : "";
    navConnect.textContent = bound ? "Change Menu" : "Connect Menu";
    navConnect.disabled = !navOptions.value;
    navDetach.hidden = !bound;

    let validManageUrl = "";
    try {
      const url = new URL(String(data.manage_url || ""), window.location.href);
      if (
        data.manage_url &&
        url.origin === window.location.origin &&
        url.pathname.includes("/wp-admin/")
      )
        validManageUrl = url.href;
    } catch (error) {
      /* No manage link. */
    }
    navManage.hidden = !bound || !validManageUrl;
    if (validManageUrl) navManage.href = validManageUrl;
    else navManage.removeAttribute("href");
    updateLayerNavigationBadge(elementId, bound);
  };

  const requestNavigation = async (operation) => {
    if (
      navBusy ||
      navCaptureBusy ||
      !selectedId ||
      !navContext.siteId ||
      !navContext.artifactId ||
      !config.actions?.manualNavigation ||
      !navFeedback
    )
      return;

    const selectionId = selectedId;
    navCaptureBusy = true;
    let captured;
    try {
      captured = await capturePreviewMenu(selectionId);
    } finally {
      navCaptureBusy = false;
    }
    if (selectedId !== selectionId) return;

    // Never send a source-only request after a failed runtime capture.
    if (
      !captured ||
      captured.error ||
      !/^cdx-stw-[a-f0-9]{24}$/.test(String(captured.id || "")) ||
      !Array.isArray(captured.items) ||
      captured.items.length < 2
    ) {
      navFeedback.textContent =
        captured?.error ||
        "Cannot read this menu from the preview. " +
          "Reload the editor and select its navigation container.";

      if (navInspect) {
        navInspect.hidden = false;
        navInspect.disabled = false;
        navInspect.textContent = "Retry navigation inspection";
      }

      return;
    }

    const menu = captured;
    const elementId = menu.id;

    const body = new URLSearchParams({
      action: config.actions.manualNavigation,
      _ajax_nonce: config.nonce,
      site_id: String(navContext.siteId),
      artifact_id: String(navContext.artifactId),
      element_id: elementId,
      operation,
    });
    if (menu.runtime === true) {
      body.set("runtime_menu", JSON.stringify(menu));
    }

    if (operation === "connect") {
      const value = navOptions?.value || "";
      const match = /^(classic|block):(\d+)$/.exec(value);
      if (!match) return;
      body.set("provider", match[1]);
      body.set("object_id", match[2]);
    }

    if (operation !== "inspect" && navShared > 1) {
      const confirmed = window.confirm(
        `This navigation is shared across ${navShared} source documents. ` +
          "Changing it affects every page using this imported navigation. Continue?",
      );
      if (!confirmed) return;
      body.set("confirm_shared", "1");
    }
    if (
      operation === "detach" &&
      !window.confirm(
        "Detach this WordPress menu? Imported navigation markup will remain intact.",
      )
    )
      return;

    navBusy = true;
    navButtons.forEach((button) => {
      button.disabled = true;
    });
    navFeedback.textContent = "Updating navigation…";
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body,
      });
      const json = await response.json();
      if (!response.ok || !json?.success || !json.data) {
        throw new Error(
          json?.data?.message || "Navigation could not be updated.",
        );
      }
      if (selectedId !== selectionId) return; // Selection changed during AJAX.
      showNavResult(json.data, elementId);
      navFeedback.textContent =
        operation === "inspect"
          ? "Navigation structure verified against the imported source."
          : operation === "detach"
            ? "Navigation detached. The imported markup is preserved."
            : "WordPress menu connected. Manage its links and order in WordPress.";
      if (["create", "connect", "detach"].includes(operation)) {
        if (
          window.confirm(
            "Menu settings saved. Refresh the preview to show the WordPress menu? Unsaved visual edits will be lost.",
          )
        ) {
          frame.src = frame.getAttribute("src");
        }
      }
    } catch (error) {
      if (selectedId === selectionId) {
        navFeedback.textContent =
          error?.message || "Navigation request failed.";

        if (navInspect) {
          navInspect.hidden = false;
          navInspect.textContent = "Retry navigation inspection";
        }
      }
    } finally {
      navBusy = false;
      navButtons.forEach((button) => {
        button.disabled = false;
      });
      if (navConnect) navConnect.disabled = !navOptions?.value;
    }
  };

  const reloadNavigationBadges = async () => {
    if (
      !navContext.siteId ||
      !navContext.artifactId ||
      !config.actions?.manualNavigation
    )
      return;
    const body = new URLSearchParams({
      action: config.actions.manualNavigation,
      _ajax_nonce: config.nonce,
      site_id: String(navContext.siteId),
      artifact_id: String(navContext.artifactId),
      operation: "badges",
    });
    try {
      const result = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body,
      });
      const json = await result.json();
      if (json?.success && Array.isArray(json.data?.ids)) {
        json.data.ids.slice(0, 1800).forEach((id) => {
          if (typeof id === "string") updateLayerNavigationBadge(id, true);
        });

        const selectedTag = layerRecords.get(selectedId)?.tag || "";

        if (
          selectedTag !== "nav" &&
          navigatorExtraBadges.navigation.has(selectedId) &&
          navDetails?.hidden &&
          !navBusy &&
          !navCaptureBusy
        ) {
          syncNavigationSelection({ tag: selectedTag });
        }
      }
    } catch (error) {
      /* Do not interrupt visual editing if badges fail. */
    }
  };

  let navSelectionVersion = 0;

  const syncNavigationSelection = (payload) => {
    if (!navRoot) return;

    const tag = String(payload.tag || "").toLowerCase();
    const supported =
      ["nav", "div", "ul", "ol", "li", "a", "span"].includes(tag) &&
      navContext.siteId > 0 &&
      navContext.artifactId > 0;

    navRoot.hidden = !supported;
    if (navDetails) navDetails.hidden = true;
    if (navCreate) navCreate.hidden = false;
    if (navFeedback) navFeedback.textContent = "";

    navBound = false;
    navShared = 1;

    const version = ++navSelectionVersion;
    const id = selectedId;

    if (navInspect) {
      navInspect.hidden = false;
      navInspect.disabled = false;
      navInspect.textContent = "Use as Navigation";
    }

    if (!supported) return;

    const knownBinding = navigatorExtraBadges.navigation.has(id);

    // A NAV or previously connected UL/container should restore its
    // saved menu automatically instead of offering conversion again.
    if (tag !== "nav" && !knownBinding) return;

    if (navInspect) {
      navInspect.textContent = "Checking navigation…";
      navInspect.disabled = true;
    }

    if (navFeedback) {
      navFeedback.textContent = "Loading the saved menu connection…";
    }

    const inspectWhenReady = () => {
      if (version !== navSelectionVersion || selectedId !== id) return;

      if (navBusy || navCaptureBusy) {
        window.setTimeout(inspectWhenReady, 150);
        return;
      }

      void requestNavigation("inspect");
    };

    window.setTimeout(inspectWhenReady, 40);
  };

  navInspect?.addEventListener("click", () => requestNavigation("inspect"));
  navCreate?.addEventListener("click", () => requestNavigation("create"));
  navConnect?.addEventListener("click", () => requestNavigation("connect"));
  navDetach?.addEventListener("click", () => requestNavigation("detach"));
  navOptions?.addEventListener("change", () => {
    if (navConnect) navConnect.disabled = !navOptions.value || navBusy;
  });

  // Step 16: server-validated Site Part assignment and source-region mapping.
  const partRoot = document.querySelector("[data-cdx-manual-sitepart]");
  const partInspect = document.querySelector("[data-cdx-part-inspect]");
  const partDetails = document.querySelector("[data-cdx-part-details]");
  const partTitle = document.querySelector("[data-cdx-part-title]");
  const partRole = document.querySelector("[data-cdx-part-role]");
  const partScope = document.querySelector("[data-cdx-part-scope]");
  const partOptions = document.querySelector("[data-cdx-part-options]");
  const partMapLabel = document.querySelector("[data-cdx-part-map-label]");
  const partAssign = document.querySelector("[data-cdx-part-assign]");
  const partMap = document.querySelector("[data-cdx-part-map]");
  const partDetach = document.querySelector("[data-cdx-part-detach]");
  const partNotice = document.querySelector("[data-cdx-part-notice]");
  const partFeedback = document.querySelector("[data-cdx-part-feedback]");

  const partButtons = [partInspect, partAssign, partMap, partDetach].filter(
    Boolean,
  );

  let partShared = 1;
  let partBusy = false;
  let partMapped = false;

  const updateLayerSitePartBadge = (id, role) => {
    if (role)
      navigatorExtraBadges.sitepart.set(
        id,
        role === "header" ? "Header" : "Footer",
      );
    else navigatorExtraBadges.sitepart.delete(id);
    const choose = layerItems.get(id)?.querySelector(".cdx-stw-layer-select");

    if (!choose) {
      return;
    }

    let badge = choose.querySelector(".cdx-stw-sitepart-badge");

    if (!role) {
      badge?.remove();
      return;
    }

    if (!badge) {
      badge = document.createElement("span");
      badge.className = "cdx-stw-sitepart-badge";
      choose.append(badge);
    }

    badge.textContent = role === "header" ? "Header" : "Footer";
  };

  const showSitePartResult = (data, id) => {
    if (!partDetails || !partOptions) {
      return;
    }

    partDetails.hidden = false;
    partMapped = Boolean(data.mapped);
    partShared = Math.max(1, Number(data.shared || 1));

    partTitle.value = String(data.title || "").slice(0, 100) || "Site Header";

    partRole.value = data.role === "footer" ? "footer" : "header";

    partScope.value = [
      "entire_site",
      "front_page",
      "all_pages",
      "object",
    ].includes(data.scope)
      ? data.scope
      : "entire_site";

    const pageOption = partScope.querySelector('[value="object"]');

    if (pageOption) {
      pageOption.disabled = !(Number(data.page_id || 0) > 0);
    }

    if (partScope.value === "object" && pageOption?.disabled) {
      partScope.value = "entire_site";
    }

    partOptions.replaceChildren(new Option("Choose a Site Part", ""));

    if (Array.isArray(data.options)) {
      data.options.slice(0, 100).forEach((option) => {
        if (!Number.isSafeInteger(option.id) || option.id < 1) {
          return;
        }

        partOptions.add(
          new Option(`${option.title} (${option.role})`, String(option.id)),
        );
      });
    }

    partDetach.hidden = !partMapped;
    partMapLabel.hidden = partMapped;
    partMap.hidden = partMapped;
    partMap.disabled = true;

    partNotice.textContent = String(data.notice || "");

    updateLayerSitePartBadge(id, data.bound ? data.role : "");
  };

  const sitePartRequest = async (operation) => {
    if (
      partBusy ||
      !selectedId ||
      !navContext.siteId ||
      !navContext.artifactId ||
      !config.actions?.manualSitePart ||
      !partFeedback
    ) {
      return;
    }

    const id = selectedId;

    const body = new URLSearchParams({
      action: config.actions.manualSitePart,
      _ajax_nonce: config.nonce,
      site_id: String(navContext.siteId),
      artifact_id: String(navContext.artifactId),
      element_id: id,
      operation,
    });

    if (operation === "assign") {
      body.set("role", partRole.value);
      body.set("scope", partScope.value);
      body.set("title", partTitle.value.trim());
    }

    if (operation === "map") {
      if (!partOptions.value) {
        return;
      }

      body.set("target_artifact_id", partOptions.value);
    }

    if (["assign", "detach"].includes(operation) && partShared > 1) {
      const confirmed = window.confirm(
        `This Site Part is shared by ${partShared} imported documents. ` +
          "Changing its assignment can affect every mapped page. Continue?",
      );

      if (!confirmed) {
        return;
      }

      body.set("confirm_shared", "1");
    }

    if (
      operation === "detach" &&
      !window.confirm(
        "Detach this Site Part? Original imported markup will remain unchanged.",
      )
    ) {
      return;
    }

    partBusy = true;

    partButtons.forEach((button) => {
      button.disabled = true;
    });

    partFeedback.textContent = "Updating Site Part…";

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body,
      });

      const json = await response.json();

      if (!response.ok || !json?.success || !json.data) {
        throw new Error(json?.data?.message || "Site Part update failed.");
      }

      if (selectedId !== id) {
        return;
      }

      showSitePartResult(json.data, id);

      partFeedback.textContent =
        operation === "inspect"
          ? "Imported region verified."
          : operation === "detach"
            ? "Site Part detached; source markup preserved."
            : operation === "map"
              ? "Region mapped to the existing Site Part."
              : "Site Part assignment saved.";
    } catch (error) {
      if (selectedId === id) {
        partFeedback.textContent =
          error?.message || "Site Part request failed.";
      }
    } finally {
      partBusy = false;

      partButtons.forEach((button) => {
        button.disabled = false;
      });

      if (partMap) {
        partMap.disabled = !partOptions?.value || partMapped;
      }
    }
  };

  const reloadSitePartBadges = async () => {
    if (
      !navContext.siteId ||
      !navContext.artifactId ||
      !config.actions?.manualSitePart
    ) {
      return;
    }

    const body = new URLSearchParams({
      action: config.actions.manualSitePart,
      _ajax_nonce: config.nonce,
      site_id: String(navContext.siteId),
      artifact_id: String(navContext.artifactId),
      operation: "badges",
    });

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body,
      });

      const json = await response.json();

      if (json?.success && Array.isArray(json.data?.items)) {
        json.data.items.slice(0, 1800).forEach((item) => {
          if (
            typeof item?.id === "string" &&
            ["header", "footer"].includes(item.role)
          ) {
            updateLayerSitePartBadge(item.id, item.role);
          }
        });
      }
    } catch (error) {
      // Metadata refresh failure must not interrupt visual editing.
    }
  };

  const syncSitePartSelection = (payload) => {
    if (!partRoot) {
      return;
    }

    const tag = String(payload.tag || "").toLowerCase();

    partRoot.hidden =
      !["header", "footer", "section", "aside", "div"].includes(tag) ||
      !navContext.siteId ||
      !navContext.artifactId;

    if (partDetails) {
      partDetails.hidden = true;
    }

    partShared = 1;
    partMapped = false;

    if (partFeedback) {
      partFeedback.textContent = "";
    }
  };

  partInspect?.addEventListener("click", () => sitePartRequest("inspect"));

  partAssign?.addEventListener("click", () => sitePartRequest("assign"));

  partMap?.addEventListener("click", () => sitePartRequest("map"));

  partDetach?.addEventListener("click", () => sitePartRequest("detach"));

  partOptions?.addEventListener("change", () => {
    if (partMap) {
      partMap.disabled = !partOptions.value || partBusy;
    }
  });

  // Step 17: convert repeated imported markup into a configured WordPress query.
  const gridRoot = document.querySelector("[data-cdx-grid-root]");

  // Navigation and Dynamic Grid belong in the same Inspector section.
  // Moving the existing node preserves its buttons, fields and event handlers.
  if (gridRoot && navRoot) {
    gridRoot.before(navRoot);
  }

  const gridInspect = document.querySelector("[data-cdx-grid-inspect]");
  const gridDetails = document.querySelector("[data-cdx-grid-details]");
  const gridSummary = document.querySelector("[data-cdx-grid-summary]");
  const gridPostType = document.querySelector("[data-cdx-grid-posttype]");
  const gridSample = document.querySelector("[data-cdx-grid-sample]");
  const gridCount = document.querySelector("[data-cdx-grid-count]");
  const gridOrderBy = document.querySelector("[data-cdx-grid-orderby]");
  const gridOrder = document.querySelector("[data-cdx-grid-order]");
  const gridTaxonomy = document.querySelector("[data-cdx-grid-taxonomy]");
  const gridTerms = document.querySelector("[data-cdx-grid-terms]");
  const gridPaginate = document.querySelector("[data-cdx-grid-paginate]");
  const gridMainQuery = document.querySelector("[data-cdx-grid-main-query]");
  const gridSave = document.querySelector("[data-cdx-grid-save]");
  const gridRevert = document.querySelector("[data-cdx-grid-revert]");
  const gridFeedback = document.querySelector("[data-cdx-grid-feedback]");
  const gridMaps = Array.from(document.querySelectorAll("[data-cdx-grid-map]"));
  const gridExtraRows = document.querySelector("[data-cdx-grid-extra-rows]");
  const gridAddField = document.querySelector("[data-cdx-grid-add-field]");
  const gridFieldHint = document.querySelector("[data-cdx-grid-field-hint]");

  let gridFieldCatalog = {};
  let gridFieldTargets = {};
  let gridCatalogRequest = 0;
  let gridCatalogLoading = false;
  let gridBusy = false;
  let gridActive = false;
  let gridTaxonomies = {};

  const gridBadge = (id, enabled) => {
    if (enabled) navigatorExtraBadges.grid.add(id);
    else navigatorExtraBadges.grid.delete(id);
    const choose = layerItems.get(id)?.querySelector(".cdx-stw-layer-select");
    if (!choose) return;
    let badge = choose.querySelector(".cdx-stw-grid-badge");
    if (!enabled) {
      badge?.remove();
      return;
    }
    if (!badge) {
      badge = document.createElement("span");
      badge.className = "cdx-stw-grid-badge";
      choose.append(badge);
    }
    badge.textContent = "Dynamic Grid";
  };

  const gridExtraMappingValues = () =>
    Array.from(
      gridExtraRows?.querySelectorAll("[data-cdx-grid-extra-row]") || [],
    )
      .map((row) => ({
        field_id: row.querySelector("[data-cdx-grid-extra-field]")?.value || "",
        element_id:
          row.querySelector("[data-cdx-grid-extra-target]")?.value || "",
      }))
      .filter((row) => row.field_id && row.element_id);

  const gridAddMappingRow = (fieldId = "", elementId = "") => {
    if (!gridExtraRows || gridExtraRows.children.length >= 25) {
      return;
    }

    const row = document.createElement("div");
    row.className = "cdx-stw-grid-extra-row";
    row.dataset.cdxGridExtraRow = "";

    const fieldLabel = document.createElement("label");
    fieldLabel.textContent = "WordPress / custom field";

    const fieldSelect = document.createElement("select");
    fieldSelect.dataset.cdxGridExtraField = "";

    fieldSelect.add(new Option("Select a field", ""));

    Object.values(gridFieldCatalog).forEach((entry) => {
      if (!entry || !["text", "html", "url", "media"].includes(entry.kind)) {
        return;
      }

      fieldSelect.add(
        new Option(String(entry.label || entry.id), String(entry.id)),
      );
    });

    fieldLabel.append(fieldSelect);

    const targetLabel = document.createElement("label");
    targetLabel.textContent = "Imported card element";

    const targetSelect = document.createElement("select");
    targetSelect.dataset.cdxGridExtraTarget = "";

    targetLabel.append(targetSelect);

    const remove = document.createElement("button");
    remove.type = "button";
    remove.className = "button cdx-stw-grid-remove";
    remove.textContent = "Remove mapping";

    const updateTargets = (wanted = "") => {
      const kind = gridFieldCatalog[fieldSelect.value]?.kind || "";

      const targets = Array.isArray(gridFieldTargets[kind])
        ? gridFieldTargets[kind]
        : [];

      targetSelect.replaceChildren(new Option("Select card element", ""));

      targets.forEach((entry) => {
        if (typeof entry?.id === "string") {
          targetSelect.add(
            new Option(String(entry.label || entry.id), entry.id),
          );
        }
      });

      targetSelect.value = targets.some((entry) => entry.id === wanted)
        ? wanted
        : "";
    };

    if (Object.hasOwn(gridFieldCatalog, fieldId)) {
      fieldSelect.value = fieldId;
    }

    updateTargets(elementId);

    fieldSelect.addEventListener("change", () => {
      updateTargets();
    });

    remove.addEventListener("click", () => {
      row.remove();
    });

    row.append(fieldLabel, targetLabel, remove);
    gridExtraRows.append(row);
  };

  const gridSetFieldCatalog = (catalog, mappings = []) => {
    gridFieldCatalog =
      catalog && typeof catalog === "object" && !Array.isArray(catalog)
        ? catalog
        : {};

    gridExtraRows?.replaceChildren();

    mappings.slice(0, 25).forEach((mapping) => {
      if (mapping && Object.hasOwn(gridFieldCatalog, mapping.field_id)) {
        gridAddMappingRow(mapping.field_id, mapping.element_id);
      }
    });

    if (gridFieldHint) {
      gridFieldHint.textContent =
        `${Object.keys(gridFieldCatalog).length} eligible fields ` +
        "for the selected post type. Only public registered " +
        "fields and explicitly REST-enabled ACF groups are offered.";
    }
  };

  const gridLoadCatalog = async () => {
    if (
      !gridPostType ||
      !config.actions?.manualDynamicGrid ||
      !navContext.siteId ||
      !navContext.artifactId ||
      !selectedId
    ) {
      return;
    }

    const version = ++gridCatalogRequest;
    const postType = gridPostType.value;
    const elementId = selectedId;

    gridCatalogLoading = true;

    if (gridSave) {
      gridSave.disabled = true;
    }

    const body = new URLSearchParams({
      action: config.actions.manualDynamicGrid,
      _ajax_nonce: config.nonce,
      site_id: String(navContext.siteId),
      artifact_id: String(navContext.artifactId),
      element_id: elementId,
      operation: "catalog",
      post_type: postType,
    });

    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body,
      });

      const json = await response.json();

      if (!response.ok || !json?.success || json.data?.post_type !== postType) {
        throw new Error(
          json?.data?.message || "Could not load post type fields.",
        );
      }

      if (
        version === gridCatalogRequest &&
        gridPostType.value === postType &&
        selectedId === elementId
      ) {
        gridSetFieldCatalog(json.data.field_catalog);
      }
    } catch (error) {
      if (version === gridCatalogRequest) {
        gridSetFieldCatalog({});

        if (gridFieldHint) {
          gridFieldHint.textContent =
            error?.message || "Could not load custom fields.";
        }
      }
    } finally {
      if (version === gridCatalogRequest) {
        gridCatalogLoading = false;

        if (gridSave) {
          gridSave.disabled = gridBusy;
        }
      }
    }
  };

  gridAddField?.addEventListener("click", () => {
    if (!gridCatalogLoading && Object.keys(gridFieldCatalog).length) {
      gridAddMappingRow();
    }
  });

  const gridTaxonomyOptions = (wanted = "") => {
    if (!gridTaxonomy || !gridPostType) return;
    const types = gridTaxonomies[gridPostType.value] || {};
    gridTaxonomy.replaceChildren(new Option("All terms", ""));
    Object.entries(types).forEach(([key, name]) => {
      gridTaxonomy.add(new Option(String(name), key));
    });
    gridTaxonomy.value = Object.hasOwn(types, wanted) ? wanted : "";
  };

  const showGrid = (data, id) => {
    if (!gridDetails || !data) return;

    gridCatalogRequest += 1;
    gridCatalogLoading = false;
    gridActive = Boolean(data.active);
    gridDetails.hidden = false;
    gridSummary.textContent =
      `${Number(data.count || 0)} imported cards · ` +
      (gridActive ? "Dynamic query connected" : "Currently static");
    gridInspect.textContent = gridActive
      ? "Configure Dynamic Grid"
      : "Convert to Dynamic Grid";
    gridRevert.hidden = !gridActive;
    const replaceOptions = (select, list, selectedValue) => {
      select.replaceChildren();
      list.forEach(([label, value]) => select.add(new Option(label, value)));
      select.value = selectedValue;
    };
    replaceOptions(
      gridPostType,
      Object.entries(data.post_types || {}).map(([key, label]) => [
        String(label),
        key,
      ]),
      String(data.query?.post_type || ""),
    );
    replaceOptions(
      gridSample,
      (data.samples || [])
        .slice(0, 150)
        .map((item, index) => [`Card ${index + 1}: ${item.label}`, item.id]),
      String(data.sample_id || ""),
    );
    gridTaxonomies = data.taxonomies_by_type || {};
    gridTaxonomyOptions(String(data.query?.taxonomy || ""));
    gridCount.value = String(data.query?.posts_per_page || data.count || 6);
    gridOrderBy.value = String(data.query?.orderby || "date");
    gridOrder.value = String(data.query?.order || "DESC");
    gridTerms.value = String(data.query?.terms || "");
    gridPaginate.checked = Boolean(data.query?.paginate);
    gridMainQuery.checked = Boolean(data.query?.use_main_query);
    gridMainQuery.disabled = gridPostType.value === "product";
    gridMaps
      .filter((item) => item.dataset.cdxGridMap === "price")
      .forEach((item) => {
        const label = item.closest("label");
        if (label)
          label.hidden =
            gridPostType.value !== "product" || !data.price_supported;
      });
    gridMaps.forEach((select) => {
      const key = select.dataset.cdxGridMap;
      const options = Array.isArray(data.fields?.[key]) ? data.fields[key] : [];
      replaceOptions(
        select,
        [
          ["Keep imported content", ""],
          ...options.map((item) => [
            `${item.label}${item.suggested ? " ★" : ""}`,
            item.id,
          ]),
        ],
        String(data.mappings?.[key] || ""),
      );
    });
    gridFieldTargets = data.field_targets || {};

    gridSetFieldCatalog(
      data.field_catalog,
      Array.isArray(data.extra_mappings) ? data.extra_mappings : [],
    );

    if (gridSave) {
      gridSave.disabled = gridBusy;
    }

    gridBadge(id, gridActive);
  };

  const requestGrid = async (operation) => {
    if (
      gridBusy ||
      !selectedId ||
      !navContext.siteId ||
      !navContext.artifactId ||
      !config.actions?.manualDynamicGrid ||
      !gridFeedback
    )
      return;
    const id = selectedId;
    const body = new URLSearchParams({
      action: config.actions.manualDynamicGrid,
      _ajax_nonce: config.nonce,
      site_id: String(navContext.siteId),
      artifact_id: String(navContext.artifactId),
      element_id: id,
      operation,
    });
    if (gridSample?.value) body.set("sample_id", gridSample.value);
    if (operation === "save") {
      body.set("post_type", gridPostType.value);
      body.set("count", gridCount.value);
      body.set("orderby", gridOrderBy.value);
      body.set("order", gridOrder.value);
      body.set("taxonomy", gridTaxonomy.value);
      body.set("terms", gridTerms.value);
      if (gridPaginate.checked) body.set("paginate", "1");
      if (gridMainQuery.checked && gridPostType.value !== "product")
        body.set("use_main_query", "1");
      if (gridCatalogLoading) {
        gridFeedback.textContent =
          "Wait for the post type fields to finish loading.";
        return;
      }

      gridMaps.forEach((select) => {
        body.set(`map_${select.dataset.cdxGridMap}`, select.value);
      });

      const extra = gridExtraMappingValues();

      if (extra.length !== (gridExtraRows?.children.length || 0)) {
        gridFeedback.textContent =
          "Complete or remove each additional mapping.";
        return;
      }

      body.set("extra_mappings", JSON.stringify(extra));
    }
    if (
      operation === "revert" &&
      !window.confirm(
        "Restore this listing to its original imported static cards?",
      )
    )
      return;
    gridBusy = true;
    [gridInspect, gridSave, gridRevert, gridSample].forEach((item) => {
      if (item) item.disabled = true;
    });
    gridFeedback.textContent = "Checking imported grid…";
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body,
      });
      const json = await response.json();
      if (!response.ok || !json?.success || !json.data) {
        throw new Error(
          json?.data?.message || "Dynamic Grid could not be updated.",
        );
      }
      if (selectedId !== id) return;
      showGrid(json.data, id);
      gridFeedback.textContent =
        operation === "inspect"
          ? "Choose a sample card, map its fields and configure the query."
          : operation === "revert"
            ? "Static listing restored. Imported source markup is unchanged."
            : "Dynamic Grid saved. Refreshing the preview…";
      if (operation === "save" || operation === "revert") {
        // Never silently discard unsaved visual overrides in the iframe.
        if (
          window.confirm(
            "Grid settings were saved. Refresh the preview now? " +
              "Any unsaved visual edits will be lost.",
          )
        ) {
          frame.src = frame.getAttribute("src");
        } else {
          gridFeedback.textContent =
            "Grid settings saved. Save any visual edits before refreshing.";
        }
      }
    } catch (error) {
      if (selectedId === id)
        gridFeedback.textContent = error?.message || "Grid request failed.";
    } finally {
      gridBusy = false;
      [gridInspect, gridSave, gridRevert, gridSample].forEach((item) => {
        if (item) item.disabled = false;
      });
    }
  };

  const reloadGridBadges = async () => {
    if (
      !config.actions?.manualDynamicGrid ||
      !navContext.siteId ||
      !navContext.artifactId
    )
      return;
    const body = new URLSearchParams({
      action: config.actions.manualDynamicGrid,
      _ajax_nonce: config.nonce,
      site_id: String(navContext.siteId),
      artifact_id: String(navContext.artifactId),
      operation: "badges",
    });
    try {
      const response = await fetch(config.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body,
      });
      const json = await response.json();
      if (json?.success && Array.isArray(json.data?.items)) {
        json.data.items.slice(0, 1800).forEach((item) => {
          if (typeof item?.id === "string")
            gridBadge(item.id, Boolean(item.enabled));
        });
      }
    } catch (error) {
      /* Optional Navigator decoration. */
    }
  };

  const syncGridSelection = (payload) => {
    if (!gridRoot) return;

    gridCatalogRequest += 1;
    gridCatalogLoading = false;

    const tag = String(payload.tag || "").toLowerCase();
    gridRoot.hidden =
      !["div", "section", "ul", "ol", "main", "article", "tbody"].includes(
        tag,
      ) ||
      !navContext.siteId ||
      !navContext.artifactId;
    if (gridDetails) gridDetails.hidden = true;
    if (gridFeedback) gridFeedback.textContent = "";
    gridActive = false;
  };

  gridInspect?.addEventListener("click", () => requestGrid("inspect"));
  gridSave?.addEventListener("click", () => requestGrid("save"));
  gridRevert?.addEventListener("click", () => requestGrid("revert"));
  gridSample?.addEventListener("change", () => requestGrid("inspect"));
  gridPostType?.addEventListener("change", () => {
    gridTaxonomyOptions();
    gridLoadCatalog();
    gridMainQuery.disabled = gridPostType.value === "product";
    gridMaps
      .filter((item) => item.dataset.cdxGridMap === "price")
      .forEach((item) => {
        const label = item.closest("label");
        if (label)
          label.hidden =
            gridPostType.value !== "product" || item.options.length < 2;
        if (gridPostType.value !== "product") item.value = "";
      });
    if (
      gridOrderBy &&
      gridPostType.value === "product" &&
      ["menu_order", "comment_count"].includes(gridOrderBy.value)
    )
      gridOrderBy.value = "date";
  });

  // Step 19: structure controls share the Step 11-18 editor history flow.
  const structureRoot = document.querySelector("[data-cdx-structure]");
  const structureHint = document.querySelector("[data-cdx-structure-hint]");
  const structureMoves = document.querySelector("[data-cdx-structure-moves]");
  const structureUp = document.querySelector("[data-cdx-structure-up]");
  const structureDown = document.querySelector("[data-cdx-structure-down]");
  const structureRestore = document.querySelector(
    "[data-cdx-structure-restore-position]",
  );
  const structureHide = document.querySelector("[data-cdx-structure-hide]");
  const structureShow = document.querySelector("[data-cdx-structure-show]");
  const structureRestoreVisibility = document.querySelector(
    "[data-cdx-structure-restore-visibility]",
  );
  const visibilityControls = Array.from(
    document.querySelectorAll("[data-cdx-visible-on]"),
  );
  const structureWarned = new Set();
  let currentStructure = null;

  const confirmStructureMove = (parentId) => {
    if (!parentId || structureWarned.has(parentId)) return Boolean(parentId);
    const explicitRisk = currentStructure?.positionalRisk;
    const confirmed = window.confirm(
      explicitRisk
        ? "This page contains positional CSS/JavaScript selectors. Moving sections may change the layout or behavior. Move this section anyway?"
        : "Reordering imported sections can affect CSS/JavaScript that depends on sibling position. Continue?",
    );
    if (confirmed) structureWarned.add(parentId);
    return confirmed;
  };

  const requestStructuralMove = (id, targetId, position) => {
    const entry = layerRecords.get(id);
    const other = targetId ? layerRecords.get(targetId) : null;
    if (
      !entry?.movable ||
      (other && (!other.movable || entry.parent !== other.parent))
    )
      return;
    if (!confirmStructureMove(entry.parent)) return;
    send("condixia/editor/structure-move", { id, targetId, position });
  };

  const syncStructureSelection = (payload) => {
    if (!structureRoot) return;
    currentStructure = payload?.structure || null;
    const canHide = Boolean(currentStructure?.canHide);
    structureRoot.hidden = !canHide;
    if (!canHide) return;
    const canMove = Boolean(currentStructure.canMove);
    if (structureMoves) structureMoves.hidden = !canMove;
    if (structureUp) structureUp.disabled = !currentStructure.canUp;
    if (structureDown) structureDown.disabled = !currentStructure.canDown;
    if (structureRestore) {
      structureRestore.disabled =
        !canMove ||
        (Array.isArray(currentStructure.order) &&
          Array.isArray(currentStructure.original) &&
          currentStructure.order.every(
            (id, index) => id === currentStructure.original[index],
          ));
    }
    if (structureHint)
      structureHint.textContent = canMove
        ? "Drag the grip beside sibling sections in Navigator; Alt+Up/Down works from the handle. Changes are reversible."
        : "Visibility is editable, but this section cannot be reordered safely.";
    const mask = /^[01]{3}$/.test(currentStructure.visibility || "")
      ? currentStructure.visibility
      : "111";
    const positions = { desktop: 0, tablet: 1, mobile: 2 };
    visibilityControls.forEach((control) => {
      const index = positions[control.dataset.cdxVisibleOn];
      if (index !== undefined) control.checked = mask[index] === "1";
    });
  };

  const setVisibility = (mask) => {
    if (!selectedId || !currentStructure?.canHide || !/^[01]{3}$/.test(mask))
      return;
    send("condixia/editor/apply", {
      id: selectedId,
      property: "visibility",
      value: mask,
    });
  };

  structureUp?.addEventListener("click", () =>
    requestStructuralMove(selectedId, "", "up"),
  );
  structureDown?.addEventListener("click", () =>
    requestStructuralMove(selectedId, "", "down"),
  );
  structureRestore?.addEventListener("click", () =>
    requestStructuralMove(selectedId, "", "restore"),
  );
  structureHide?.addEventListener("click", () => setVisibility("000"));
  structureShow?.addEventListener("click", () => setVisibility("111"));
  structureRestoreVisibility?.addEventListener("click", () =>
    setVisibility("111"),
  );
  visibilityControls.forEach((control) => {
    control.addEventListener("change", () => {
      const controls = Object.fromEntries(
        visibilityControls.map((input) => [
          input.dataset.cdxVisibleOn,
          input.checked ? "1" : "0",
        ]),
      );
      setVisibility(
        [controls.desktop, controls.tablet, controls.mobile].join(""),
      );
    });
  });

  let activeInspectorTab = "";
  let inspectorAvailable = new Set();
  let inspectorContentKinds = new Set();

  const showInspectorTab = (requested) => {
    const tabGroups = {
      content: ["content"],
      design: ["layout", "style"],
      advanced: ["advanced", "dynamic"],
    };

    const tab = inspectorAvailable.has(requested)
      ? requested
      : inspectorAvailable.has("content")
        ? "content"
        : "design";

    activeInspectorTab = tab;

    inspectorTabs.forEach((button) => {
      const name = button.dataset.cdxInspectorTab;
      const available = inspectorAvailable.has(name);

      button.disabled = !available;
      button.setAttribute("aria-pressed", String(available && name === tab));
    });

    inspectorPanels.forEach((panel) => {
      const kind = panel.dataset.cdxInspectorWhen;

      const belongsToTab = tabGroups[tab].includes(
        panel.dataset.cdxInspectorPanel,
      );

      const noDynamicControls =
        navRoot?.hidden !== false && gridRoot?.hidden !== false;

      panel.hidden =
        !belongsToTab ||
        (Boolean(kind) && !inspectorContentKinds.has(kind)) ||
        (panel.dataset.cdxInspectorPanel === "dynamic" && noDynamicControls);
    });
  };

  inspectorTabs.forEach((button) => {
    button.addEventListener("click", () => {
      showInspectorTab(button.dataset.cdxInspectorTab);
    });
  });

  const updateInspector = (payload) => {
    const tag = String(payload.tag || "div").toLowerCase();
    const caps = selectedCapabilities;
    const hasText = Boolean(caps.text || caps.nestedText || caps.link);
    const hasContent = hasText || Boolean(caps.image);
    const hasBackground = [
      "div",
      "section",
      "main",
      "article",
      "aside",
      "header",
      "footer",
      "nav",
      "figure",
      "ul",
      "ol",
      "li",
      "button",
      "a",
      "form",
    ].includes(tag);

    inspectorContentKinds = new Set();
    if (hasText) inspectorContentKinds.add("text");
    if (caps.image) inspectorContentKinds.add("image");

    inspectorAvailable = new Set(["design", "advanced"]);
    if (hasContent) inspectorAvailable.add("content");

    // Containers can inherit text colors; show the control and diagnose child overrides.
    setFieldVisibility("color", true);
    setFieldVisibility("backgroundColor", true);
    ["fontSize", "fontWeight", "textAlign"].forEach((property) => {
      setFieldVisibility(property, hasText);
    });

    const bgCard = document.querySelector('[data-cdx-media-card="background"]');
    if (bgCard) bgCard.hidden = !hasBackground;
    if (nestedTextWarning)
      nestedTextWarning.hidden = !caps.nestedText || Boolean(caps.richText);

    if (inspectorName) {
      const layer = layerRecords.get(payload.id);
      inspectorName.textContent = layer?.label || tag.toUpperCase();
    }
    if (inspectorHint) {
      inspectorHint.textContent = hasContent
        ? "Edit the selected element's content and appearance."
        : "Adjust this element's spacing and appearance.";
    }
    if (inspectorTag) inspectorTag.textContent = tag;
    if (inspectorReference) inspectorReference.textContent = payload.id;

    // Selecting a Navigation region opens its WordPress menu controls.
    activeInspectorTab =
      tag === "nav" ? "advanced" : hasContent ? "content" : "design";

    showInspectorTab(activeInspectorTab);
  };

  const select = (payload) => {
    if (
      !payload ||
      typeof payload !== "object" ||
      typeof payload.id !== "string"
    ) {
      return;
    }

    selectedId = payload.id;
    revealLayer(selectedId);

    if (navigatorElement) {
      const tag = typeof payload.tag === "string" ? payload.tag : "element";
      navigatorElement.textContent = `${layerRecords.get(selectedId)?.label || tag.toLowerCase()} · ${selectedId}`;
    }

    selectedCapabilities =
      payload.capabilities && typeof payload.capabilities === "object"
        ? payload.capabilities
        : {};

    empty.hidden = true;
    controls.hidden = false;

    setFieldVisibility("text", Boolean(selectedCapabilities.text));

    setFieldVisibility("linkUrl", Boolean(selectedCapabilities.link));

    setFieldVisibility("linkTarget", Boolean(selectedCapabilities.link));

    setFieldVisibility("imageAlt", Boolean(selectedCapabilities.image));

    const imageCard = document.querySelector('[data-cdx-media-card="image"]');

    if (imageCard) {
      imageCard.hidden = !selectedCapabilities.image;
    }

    updateMediaThumbnail("image", payload.src || "");
    updateMediaThumbnail("background", payload.backgroundImage || "");

    setValue("text", payload.text || "");

    setValue("linkUrl", payload.href || "");

    setValue("linkTarget", payload.target || "_self");

    setValue("imageAlt", payload.alt || "");

    setValue("color", payload.styles?.color || "");

    setValue("backgroundColor", payload.styles?.backgroundColor || "");

    setValue("fontSize", payload.styles?.fontSize || "");

    setValue("fontWeight", payload.styles?.fontWeight || "400");

    setValue("textAlign", payload.styles?.textAlign || "left");

    setValue("padding", payload.styles?.padding || "");

    setValue("margin", payload.styles?.margin || "");

    setValue("border", payload.styles?.border || "");

    setValue("borderRadius", payload.styles?.borderRadius || "");
    syncColorScope(payload);

    updateInspector(payload);
    showRichTextFields(payload);
    syncNavigationSelection(payload);
    syncSitePartSelection(payload);
    syncStructureSelection(payload);
    syncGridSelection(payload);

    // Navigation and Grid availability depends on the selected element.
    showInspectorTab(activeInspectorTab);

    setStatus(`${payload.tag || "element"} · ${payload.id}`);
  };

  window.addEventListener("message", (event) => {
    if (event.source !== frame.contentWindow) {
      return;
    }

    if (event.origin !== config.previewMessageOrigin) {
      return;
    }

    const message = event.data;

    if (
      !message ||
      typeof message !== "object" ||
      message.channel !== "condixia-ai-stw-editor" ||
      typeof message.type !== "string"
    ) {
      return;
    }

    if (message.type === "condixia/editor/runtime-menu-captured") {
      const requestId = String(message.payload?.requestId || "");
      const callback = navCaptureRequests.get(requestId);
      if (callback) {
        navCaptureRequests.delete(requestId);
        callback(message.payload?.result || null);
      }
      return;
    }

    if (message.type === "condixia/editor/layers") {
      renderLayers(message.payload);
      reloadNavigationBadges();
      reloadSitePartBadges();
      reloadGridBadges();
      return;
    }

    if (message.type === "condixia/editor/ready") {
      navContext = {
        siteId: Number(message.payload?.siteId || config.siteId || 0),
        artifactId: Number(
          message.payload?.artifactId || config.artifactId || 0,
        ),
      };

      if (layerSnapshot) {
        reloadNavigationBadges();
        reloadSitePartBadges();
        reloadGridBadges();
      }

      setStatus(config.readyText || "Preview ready");

      return;
    }

    if (message.type === "condixia/editor/selected") {
      select(message.payload);

      return;
    }

    if (message.type === "condixia/editor/selection-error") {
      setStatus(
        "The selected layer is no longer in the preview. Refresh the preview and try again.",
      );
      return;
    }

    if (message.type === "condixia/editor/style-scope-result") {
      if (styleScopeHint)
        styleScopeHint.textContent =
          message.payload?.error ||
          `Updated ${Number(message.payload?.count || 0)} matching elements. Save to publish.`;
      return;
    }

    if (message.type === "condixia/editor/changed") {
      if (
        message.payload?.property === "richText" &&
        message.payload?.record === false &&
        message.payload?.id === selectedId
      ) {
        updateRichTextFields(message.payload.after);
      }
      setStatus(config.changedText || "Preview changed — not saved");
    }
  });

  hexInputs.forEach((hex) => {
    const property = hex.dataset.cdxColorHex;
    const picker = document.querySelector(`[data-cdx-property="${property}"]`);

    if (!picker || picker.type !== "color") {
      return;
    }

    picker.addEventListener("input", () => {
      hex.value = picker.value.toUpperCase();

      const swatch = picker.closest(".cdx-stw-editor-color-swatch");

      if (swatch) {
        swatch.style.backgroundColor = picker.value;
      }
    });

    hex.addEventListener("change", () => {
      const value = hex.value.trim();

      const normalized = /^#?[0-9a-f]{3}$/i.test(value)
        ? `#${value
            .replace("#", "")
            .split("")
            .map((part) => part + part)
            .join("")}`
        : /^#?[0-9a-f]{6}$/i.test(value)
          ? `#${value.replace("#", "")}`
          : "";

      if (!normalized) {
        hex.value = picker.value.toUpperCase();
        return;
      }

      picker.value = normalized;
      picker.dispatchEvent(new Event("input", { bubbles: false }));
    });
  });

  const styleScope = document.querySelector("[data-cdx-style-scope]");
  const styleClass = document.querySelector("[data-cdx-style-class]");
  const styleClassLabel = document.querySelector(
    "[data-cdx-style-class-label]",
  );
  const styleScopeHint = document.querySelector("[data-cdx-style-scope-hint]");
  const solidBackground = document.querySelector("[data-cdx-solid-background]");
  let colorScopeDetails = null;

  const describeColorScope = () => {
    if (!styleScope || !styleScopeHint) return;
    const mode = styleScope.value;
    const classes = colorScopeDetails?.classes || [];
    if (styleClassLabel) styleClassLabel.hidden = mode !== "class";
    const count =
      mode === "class"
        ? Number(
            classes.find((item) => item.name === styleClass?.value)?.count || 0,
          )
        : Number(colorScopeDetails?.tagCount || 0);
    const notes = [];
    if (mode === "element")
      notes.push("Only this selected element will change.");
    else
      notes.push(
        `${count} source-backed elements match. Maximum per operation: 80.`,
      );
    if (colorScopeDetails?.nestedText)
      notes.push(
        "Nested text has its own color: select its text layer to recolor it.",
      );
    if (colorScopeDetails?.backgroundHasLayer && !solidBackground?.checked)
      notes.push(
        "An image/gradient may cover this background color. Use the solid option to replace it.",
      );
    styleScopeHint.textContent = notes.join(" ");
  };

  const syncColorScope = (payload) => {
    colorScopeDetails = {
      ...(payload.styleScope || {}),
      backgroundHasLayer: Boolean(payload.backgroundHasLayer),
    };
    if (styleClass) {
      const original = styleClass.value;
      styleClass.replaceChildren(new Option("Choose a class", ""));
      (colorScopeDetails.classes || []).forEach((option) => {
        if (!option || typeof option.name !== "string") return;
        styleClass.add(
          new Option(`.${option.name} (${option.count})`, option.name),
        );
      });
      styleClass.value =
        original &&
        Array.from(styleClass.options).some((opt) => opt.value === original)
          ? original
          : styleClass.options[1]?.value || "";
    }
    if (styleScope) {
      const classOption = styleScope.querySelector('[value="class"]');
      if (classOption) classOption.disabled = !styleClass?.value;
      if (styleScope.value === "class" && !styleClass?.value)
        styleScope.value = "element";
    }
    if (solidBackground)
      solidBackground.checked = Boolean(payload.backgroundSolid);
    describeColorScope();
  };

  styleScope?.addEventListener("change", describeColorScope);
  styleClass?.addEventListener("change", describeColorScope);
  solidBackground?.addEventListener("change", () => {
    const picker = document.querySelector(
      '[data-cdx-property="backgroundColor"]',
    );
    if (selectedId && picker) {
      const value = `${solidBackground.checked ? "solid:" : ""}${picker.value}`;
      const mode = styleScope?.value || "element";
      if (mode === "element")
        send("condixia/editor/apply", {
          id: selectedId,
          property: "backgroundColor",
          value,
        });
      else
        send("condixia/editor/apply-matching", {
          id: selectedId,
          property: "backgroundColor",
          value,
          mode,
          token: styleClass?.value || "",
        });
    }
    describeColorScope();
  });

  propertyControls.forEach((control) => {
    const apply = () => {
      if (!selectedId) {
        return;
      }

      const property = control.dataset.cdxProperty;
      if (["color", "backgroundColor"].includes(property)) {
        const mode = styleScope?.value || "element";
        const value =
          property === "backgroundColor" && solidBackground?.checked
            ? `solid:${control.value}`
            : control.value;
        if (mode !== "element") {
          send("condixia/editor/apply-matching", {
            id: selectedId,
            property,
            value,
            mode,
            token: styleClass?.value || "",
          });
          return;
        }
        send("condixia/editor/apply", { id: selectedId, property, value });
        return;
      }
      send("condixia/editor/apply", {
        id: selectedId,
        property,
        value: control.value,
      });
    };

    control.addEventListener("input", apply);

    control.addEventListener("change", apply);
  });

  const setPanelExpanded = (panel, expanded) => {
    if (!["navigator", "inspector"].includes(panel)) {
      return;
    }

    shell.classList.toggle(`is-${panel}-collapsed`, !expanded);

    panelButtons.forEach((button) => {
      if (button.dataset.cdxPanelToggle === panel) {
        button.setAttribute("aria-expanded", String(expanded));
      }
    });
  };

  panelButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const panel = button.dataset.cdxPanelToggle;

      setPanelExpanded(panel, button.getAttribute("aria-expanded") !== "true");
    });
  });

  // Floating Navigator: drag only from its header, not from layer rows.
  const navigatorPanel = document.querySelector(".cdx-stw-editor-navigator");

  const navigatorHandle = document.querySelector(
    "[data-cdx-navigator-drag-handle]",
  );

  const navigatorClose = document.querySelector("[data-cdx-navigator-close]");

  const navigatorToolbarButton = document.querySelector(
    '[data-cdx-panel-toggle="navigator"]',
  );

  const placeNavigator = (left, top) => {
    if (!navigatorPanel) return;

    const maxLeft = Math.max(
      8,
      window.innerWidth - navigatorPanel.offsetWidth - 8,
    );

    const maxTop = Math.max(8, window.innerHeight - 70);

    navigatorPanel.style.left = `${Math.max(8, Math.min(maxLeft, left))}px`;

    navigatorPanel.style.top = `${Math.max(8, Math.min(maxTop, top))}px`;
  };

  navigatorClose?.addEventListener("click", () => {
    setPanelExpanded("navigator", false);
    navigatorToolbarButton?.focus();
  });

  navigatorHandle?.addEventListener("keydown", (event) => {
    if (
      !event.altKey ||
      !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)
    ) {
      return;
    }

    event.preventDefault();

    const rect = navigatorPanel.getBoundingClientRect();

    const dx =
      event.key === "ArrowLeft" ? -20 : event.key === "ArrowRight" ? 20 : 0;

    const dy =
      event.key === "ArrowUp" ? -20 : event.key === "ArrowDown" ? 20 : 0;

    placeNavigator(rect.left + dx, rect.top + dy);
  });

  let navigatorPointer = null;

  navigatorHandle?.addEventListener("pointerdown", (event) => {
    if (event.button !== 0 || event.target.closest("button")) {
      return;
    }

    const rect = navigatorPanel.getBoundingClientRect();

    navigatorPointer = {
      id: event.pointerId,
      x: event.clientX,
      y: event.clientY,
      left: rect.left,
      top: rect.top,
    };

    navigatorHandle.setPointerCapture(event.pointerId);
    navigatorPanel.classList.add("is-dragging");
    event.preventDefault();
  });

  navigatorHandle?.addEventListener("pointermove", (event) => {
    if (!navigatorPointer || navigatorPointer.id !== event.pointerId) {
      return;
    }

    placeNavigator(
      navigatorPointer.left + event.clientX - navigatorPointer.x,
      navigatorPointer.top + event.clientY - navigatorPointer.y,
    );
  });

  const stopNavigatorDrag = () => {
    navigatorPointer = null;
    navigatorPanel?.classList.remove("is-dragging");
  };

  navigatorHandle?.addEventListener("pointerup", stopNavigatorDrag);

  navigatorHandle?.addEventListener("pointercancel", stopNavigatorDrag);

  navigatorHandle?.addEventListener("lostpointercapture", stopNavigatorDrag);

  navigatorPanel?.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      setPanelExpanded("navigator", false);
      navigatorToolbarButton?.focus();
    }
  });

  window.addEventListener("resize", () => {
    if (navigatorPanel && !shell.classList.contains("is-navigator-collapsed")) {
      const rect = navigatorPanel.getBoundingClientRect();
      placeNavigator(rect.left, rect.top);
    }
  });

  if (window.matchMedia("(max-width: 980px)").matches) {
    setPanelExpanded("navigator", false);
    setPanelExpanded("inspector", false);
  }
  const deviceWidths = {
    desktop: 1440,
    tablet: 768,
    mobile: 390,
  };

  let zoom = 1;
  let fitMode = true;

  const clampZoom = (value) => Math.min(2, Math.max(0.2, value));

  const renderCanvas = () => {
    const width = deviceWidths[stage.dataset.viewport] || 1440;
    const height = 900;

    frameWrap.style.width = `${width}px`;

    // Use layout-aware zoom instead of scaling the rendered iframe.
    // The iframe retains its desktop/tablet/mobile logical viewport.
    frameWrap.style.transform = "none";
    frameWrap.style.zoom = String(zoom);

    frame.style.height = `${height}px`;

    canvas.style.width = `${Math.ceil((width + 2) * zoom)}px`;
    canvas.style.height = `${Math.ceil((height + 2) * zoom)}px`;

    zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
  };

  const fitToWidth = () => {
    const width = deviceWidths[stage.dataset.viewport] || 1440;
    const computed = window.getComputedStyle(stage);

    const padding =
      parseFloat(computed.paddingLeft) + parseFloat(computed.paddingRight);

    const available = Math.max(100, stage.clientWidth - padding - 24);

    zoom =
      Math.round(clampZoom(Math.min(1, available / (width + 2))) * 100) / 100;

    renderCanvas();
  };

  viewportButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const viewport = button.dataset.cdxViewport;

      if (!Object.hasOwn(deviceWidths, viewport)) {
        return;
      }

      stage.dataset.viewport = viewport;

      viewportButtons.forEach((item) => {
        item.classList.toggle("is-active", item === button);
        item.setAttribute("aria-pressed", String(item === button));
      });

      fitMode = true;
      fitToWidth();
    });
  });

  zoomButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const action = button.dataset.cdxZoom;

      if (action === "fit") {
        fitMode = true;
        fitToWidth();
        return;
      }

      if (action === "actual") {
        zoom = 1;
      } else if (action === "in") {
        zoom = clampZoom(zoom + 0.1);
      } else if (action === "out") {
        zoom = clampZoom(zoom - 0.1);
      } else {
        return;
      }

      fitMode = false;
      zoom = Math.round(zoom * 100) / 100;

      renderCanvas();
    });
  });

  if (typeof ResizeObserver !== "undefined") {
    new ResizeObserver(() => {
      if (fitMode) {
        fitToWidth();
      }
    }).observe(stage);
  }

  window.addEventListener("resize", () => {
    if (fitMode) {
      fitToWidth();
    }
  });

  fitToWidth();

  const openMedia = (mode) => {
    if (!selectedId || !window.wp || !wp.media) {
      return;
    }

    mediaMode = mode;

    if (!mediaFrame) {
      mediaFrame = wp.media({
        title: config.mediaTitle || "Choose an image",
        button: {
          text: config.mediaButton || "Use this image",
        },
        library: {
          type: "image",
        },
        multiple: false,
      });

      mediaFrame.on("select", () => {
        const selection = mediaFrame.state().get("selection").first();

        if (!selection) {
          return;
        }

        const attachment = selection.toJSON();

        if (!attachment || typeof attachment.url !== "string") {
          return;
        }

        send("condixia/editor/apply", {
          id: selectedId,
          property: mediaMode === "background" ? "backgroundImage" : "imageSrc",
          value: attachment.url,
          attachmentId: Number(attachment.id || 0),
        });

        updateMediaThumbnail(mediaMode, attachment.url);

        if (mediaMode === "image" && typeof attachment.alt === "string") {
          setValue("imageAlt", attachment.alt);

          send("condixia/editor/apply", {
            id: selectedId,
            property: "imageAlt",
            value: attachment.alt,
            attachmentId: Number(attachment.id || 0),
          });
        }
      });
    }

    mediaFrame.open();
  };

  mediaButtons.forEach((button) => {
    button.addEventListener("click", () => {
      const mode = button.dataset.cdxMedia;

      if (mode === "image" && !selectedCapabilities.image) {
        return;
      }

      if (mode !== "image" && mode !== "background") {
        return;
      }

      openMedia(mode);
    });
  });

  frame.addEventListener("load", () => {
    selectedId = "";
    selectedCapabilities = {};
    richState = [];
    if (richFields) richFields.replaceChildren();
    if (richRoot) richRoot.hidden = true;
    activeInspectorTab = "";
    inspectorAvailable.clear();
    inspectorContentKinds.clear();
    if (navRoot) navRoot.hidden = true;
    if (navDetails) navDetails.hidden = true;
    if (partRoot) partRoot.hidden = true;
    if (structureRoot) structureRoot.hidden = true;
    currentStructure = null;
    if (gridRoot) gridRoot.hidden = true;
    if (gridDetails) gridDetails.hidden = true;
    if (partDetails) partDetails.hidden = true;
    if (navFeedback) navFeedback.textContent = "";
    navBound = false;
    navShared = 1;

    if (layerTree) {
      layerTree.replaceChildren();
    }

    clearNavigatorSortables();
    layerSnapshot = null;
    layerProjection = null;
    projectedSelectedId = "";
    expandedLayers.clear();
    navigatorExtraBadges.navigation.clear();
    navigatorExtraBadges.sitepart.clear();
    navigatorExtraBadges.grid.clear();
    layerRecords.clear();
    layerItems.clear();
    layerGroups.clear();
    layerToggles.clear();

    activeLayerId = "";

    if (layerCrumbs) {
      layerCrumbs.replaceChildren();
    }

    if (layerSearch) {
      layerSearch.value = "";
    }

    if (layerSummary) {
      layerSummary.textContent = "Loading layers…";
    }

    send("condixia/editor/layers-request");

    if (navigatorElement) {
      navigatorElement.textContent = "Nothing selected";
    }

    updateMediaThumbnail("image", "");
    updateMediaThumbnail("background", "");
    controls.hidden = true;
    empty.hidden = false;

    setStatus(config.selectText || "Select an element in the preview.");
  });
})();
