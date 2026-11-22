(() => {
  "use strict";

  // Runtime identities are paths through the rendered DOM, never changes to
  // the original imported HTML. The same path is computed on editor/public.
  const dataNode = document.getElementById("cdx-stw-runtime-data");
  if (!dataNode || !document.body) return;

  let data;
  try {
    data = JSON.parse(dataNode.textContent || "{}");
  } catch (_) {
    return;
  }

  const preview = data.preview === true;
  const allowed = new Set([
    "text",
    "imagealt",
    "linkurl",
    "linktarget",
    "imagesrc",
    "backgroundimage",
    "color",
    "backgroundcolor",
    "fontsize",
    "fontweight",
    "textalign",
    "padding",
    "margin",
    "border",
    "borderradius",
  ]);

  const overrides = new Map();
  const live = new Map();

  for (const row of Array.isArray(data.overrides)
    ? data.overrides.slice(0, 500)
    : []) {
    if (
      !row ||
      !/^cdx-stw-[a-f0-9]{24}$/.test(row.element_id) ||
      !allowed.has(row.property_key) ||
      typeof row.value !== "string"
    )
      continue;

    if (!overrides.has(row.element_id)) {
      overrides.set(row.element_id, new Map());
    }

    overrides.get(row.element_id).set(row.property_key, row.value);
  }

  const hex = (value, seed) => {
    let h = seed >>> 0;

    for (let i = 0; i < value.length; i += 1) {
      h = Math.imul((h ^ value.charCodeAt(i)) >>> 0, 16777619) >>> 0;
    }

    return h.toString(16).padStart(8, "0");
  };

  const stableId = (path) =>
    `cdx-stw-${
      hex(path, 2166136261) +
      hex(path, 2166136261 ^ 0x9e3779b9) +
      hex(path, 2166136261 ^ 0x85ebca6b)
    }`;

  const pathFor = (element, uniqueIds) => {
    const sections = [];

    for (
      let node = element;
      node && node !== document.body && sections.length < 40;
      node = node.parentElement
    ) {
      if (node.id && uniqueIds.has(node.id)) {
        sections.unshift(`id:${node.id}`);
        return sections.join("/");
      }

      let position = 1;

      for (
        let sibling = node.previousElementSibling;
        sibling;
        sibling = sibling.previousElementSibling
      ) {
        if (sibling.tagName === node.tagName) {
          position += 1;
        }
      }

      sections.unshift(`${node.tagName.toLowerCase()}[${position}]`);
    }

    return `body/${sections.join("/")}`;
  };

  const applyValue = (element, property, value) => {
    if (property === "text") {
      if (element.children.length || element.textContent === value) {
        return;
      }

      element.textContent = value;
      return;
    }

    if (property === "imagealt" && element.tagName === "IMG") {
      if (element.alt !== value) element.alt = value;
      return;
    }

    if (property === "linkurl" && element.tagName === "A") {
      if (element.getAttribute("href") !== value) {
        element.setAttribute("href", value);
      }
      return;
    }

    if (property === "linktarget" && element.tagName === "A") {
      if (value === "_blank") {
        element.target = "_blank";
        element.rel = "noopener noreferrer";
      } else if (element.hasAttribute("target")) {
        element.removeAttribute("target");
      }
      return;
    }

    if (property === "imagesrc" && element.tagName === "IMG") {
      if (element.getAttribute("src") !== value) {
        element.setAttribute("src", value);
      }

      element.removeAttribute("srcset");
      element.removeAttribute("sizes");
      return;
    }

    if (property === "backgroundimage") {
      if (!/^https?:\/\//i.test(value)) return;

      const escaped = value.replace(/["\\\r\n]/g, "");
      const css = `url("${escaped}")`;

      if (element.style.backgroundImage !== css) {
        element.style.setProperty("background-image", css, "important");
      }

      return;
    }

    const propertyName = {
      color: "color",
      backgroundcolor: "background-color",
      fontsize: "font-size",
      fontweight: "font-weight",
      textalign: "text-align",
      padding: "padding",
      margin: "margin",
      border: "border",
      borderradius: "border-radius",
    }[property];

    if (
      propertyName &&
      (element.style.getPropertyValue(propertyName) !== value ||
        element.style.getPropertyPriority(propertyName) !== "important")
    ) {
      element.style.setProperty(propertyName, value, "important");
    }
  };

  // WordPress controls menu data; the imported page retains its own menu CSS.
  const navigation = new Map();
  for (const entry of Array.isArray(data.navigation)
    ? data.navigation.slice(0, 20)
    : []) {
    if (entry && typeof entry.path === "string" && Array.isArray(entry.items)) {
      navigation.set(entry.path, entry.items);
    }
  }
  const menuTemplates = new WeakMap();

  const safeMenuUrl = (value) => {
    try {
      const url = new URL(String(value || ""), window.location.href);
      return ["http:", "https:", "mailto:", "tel:"].includes(url.protocol)
        ? String(value)
        : "";
    } catch (_) {
      return "";
    }
  };

  const cleanClonedEditorIds = (element) => {
    [element, ...element.querySelectorAll("[data-cdx-id]")].forEach((node) => {
      node.removeAttribute("data-cdx-id");
      node.removeAttribute("data-cdx-runtime");
      node.classList.remove("cdx-stw-editor-selected");
    });
  };

  const setMenuLink = (anchor, entry) => {
    const url = safeMenuUrl(entry.url);
    if (!url) return false;
    anchor.setAttribute("href", url);
    const locationTarget = new URL(url, window.location.href);
    const active =
      locationTarget.origin === window.location.origin &&
      locationTarget.pathname === window.location.pathname &&
      locationTarget.search === window.location.search;
    anchor.classList.toggle("active", active);
    if (active) anchor.setAttribute("aria-current", "page");
    else anchor.removeAttribute("aria-current");
    if (entry.blank) {
      anchor.setAttribute("target", "_blank");
      anchor.setAttribute("rel", "noopener noreferrer");
    } else {
      anchor.removeAttribute("target");
      anchor.removeAttribute("rel");
    }
    const label = String(entry.label || "").slice(0, 150);
    const walker = document.createTreeWalker(anchor, NodeFilter.SHOW_TEXT);
    const textNodes = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      if ((node.nodeValue || "").trim()) textNodes.push(node);
    }
    if (textNodes.length) {
      textNodes[0].nodeValue = label;
      textNodes.slice(1).forEach((node) => {
        node.nodeValue = "";
      });
    } else {
      anchor.append(document.createTextNode(label));
    }
    return true;
  };

  const flattenedMenu = (items, output = []) => {
    for (const item of items) {
      if (!item || typeof item !== "object") continue;
      output.push(item);
      flattenedMenu(Array.isArray(item.children) ? item.children : [], output);
      if (output.length > 80) break;
    }
    return output;
  };

  const renderRuntimeMenu = (root, items) => {
    if (!Array.isArray(items) || items.length > 80) return;
    const expected = flattenedMenu(items);
    const anchors = Array.from(root.querySelectorAll("a[href]"));
    if (
      anchors.length === expected.length &&
      anchors.every(
        (anchor, index) =>
          anchor.textContent.trim().replace(/\s+/g, " ") ===
            String(expected[index].label || "")
              .trim()
              .replace(/\s+/g, " ") &&
          anchor.getAttribute("href") === String(expected[index].url || ""),
      )
    )
      return;

    // Update existing links in place when the count matches, preserving
    // imported event listeners, per-link classes, and existing DOM identity.
    const direct = Array.from(root.children).filter(
      (child) => child.tagName === "A",
    );
    if (
      direct.length === items.length &&
      items.every(
        (item) => !Array.isArray(item.children) || item.children.length === 0,
      )
    ) {
      direct.forEach((anchor, index) => setMenuLink(anchor, items[index]));
      return;
    }

    let template = menuTemplates.get(root);
    if (!template) {
      const list = root.matches("ul,ol") ? root : root.querySelector("ul,ol");
      const listItems = list
        ? Array.from(list.children).filter((child) => child.tagName === "LI")
        : [];
      if (listItems.length >= 2 && listItems[0].querySelector("a[href]")) {
        template = {
          type: "list",
          parent: list,
          sample: listItems[0].cloneNode(true),
        };
      } else {
        const direct = Array.from(root.children).filter(
          (child) => child.tagName === "A",
        );
        if (direct.length < 2) return; // Never overwrite a mixed header.
        template = {
          type: "links",
          parent: root,
          sample: direct[0].cloneNode(true),
        };
      }
      menuTemplates.set(root, template);
    }

    const { parent, sample, type } = template;
    if (!parent.isConnected) return;
    const native = items.filter((item) => safeMenuUrl(item?.url || ""));

    const output = document.createDocumentFragment();
    const makeListItem = (entry, depth = 0) => {
      if (depth > 5) return null;
      const node = sample.cloneNode(true);
      cleanClonedEditorIds(node);
      node.querySelectorAll("ul,ol").forEach((list) => list.remove());
      const anchor = node.querySelector("a[href]");
      if (!anchor || !setMenuLink(anchor, entry)) return null;
      if (Array.isArray(entry.children) && entry.children.length && depth < 5) {
        const list = document.createElement("ul");
        list.className = "sub-menu";
        entry.children.forEach((child) => {
          const item = makeListItem(child, depth + 1);
          if (item) list.append(item);
        });
        if (list.children.length) node.append(list);
      }
      return node;
    };
    native.forEach((entry) => {
      const node = sample.cloneNode(true);
      cleanClonedEditorIds(node);
      if (type === "links") {
        if (setMenuLink(node, entry)) output.append(node);
      } else {
        const item = makeListItem(entry);
        if (item) output.append(item);
      }
    });

    const current = Array.from(parent.children).filter((child) =>
      type === "links" ? child.tagName === "A" : child.tagName === "LI",
    );
    if (current.length === 0) return;
    parent.insertBefore(output, current[0]);
    current.forEach((child) => child.remove());
  };

  const captureRuntimeMenu = (wantedId) => {
    // Only capture the menu region. Never replace a logo or shopping controls.
    const picked = Array.from(document.querySelectorAll("[data-cdx-id]")).find(
      (node) => node.getAttribute("data-cdx-id") === wantedId,
    );
    if (!picked)
      return { error: "The selected element is no longer in the preview." };
    let root = picked.closest("nav,[role='navigation']");

    if (!root) {
      // A menu can be a standalone list without a surrounding <nav>.
      root = picked.closest("ul,ol");
    }

    if (!root) {
      const matches = picked.querySelectorAll("nav,[role='navigation'],ul,ol");

      if (matches.length === 1) root = matches[0];
    }

    if (!root) {
      return {
        error: "Select a navigation link, menu list, or Navigation container.",
      };
    }
    // A nav can legitimately contain a search or dropdown button. In that
    // case bind the menu-only list, not the entire mixed-content nav.
    if (root.querySelector("form,button,input,select,textarea")) {
      const list = picked.closest("ul,ol") || root.querySelector("ul,ol");
      if (!list || list.querySelector("form,button,input,select,textarea")) {
        return {
          error: "Select the menu-only list, not search/cart controls.",
        };
      }
      root = list;
    }

    const readAnchor = (anchor, children = []) => ({
      label: (anchor.textContent || "")
        .replace(/\s+/g, " ")
        .trim()
        .slice(0, 150),
      url: (anchor.getAttribute("href") || "").slice(0, 2048),
      blank: anchor.getAttribute("target") === "_blank",
      rel: (anchor.getAttribute("rel") || "").slice(0, 150),
      children,
    });
    const readList = (list, depth = 0) => {
      if (depth > 5) return [];
      return Array.from(list.children)
        .filter((child) => child.tagName === "LI")
        .slice(0, 80)
        .flatMap((li) => {
          const anchor =
            Array.from(li.children).find((child) => child.tagName === "A") ||
            li.querySelector("a[href]");
          if (!anchor || !anchor.hasAttribute("href")) return [];
          const nested = Array.from(li.children).find((child) =>
            child.matches("ul,ol"),
          );
          return [
            readAnchor(anchor, nested ? readList(nested, depth + 1) : []),
          ];
        });
    };
    const list = root.matches("ul,ol") ? root : root.querySelector("ul,ol");
    const items =
      list &&
      Array.from(list.children).filter((li) => li.tagName === "LI").length >= 2
        ? readList(list)
        : Array.from(root.children)
            .filter((child) => child.tagName === "A")
            .slice(0, 80)
            .map((anchor) => readAnchor(anchor));
    if (items.length < 2) {
      return { error: "Select navigation with at least two links." };
    }
    const ids = new Map();
    for (const element of Array.from(document.body.querySelectorAll("[id]"))) {
      ids.set(element.id, (ids.get(element.id) || 0) + 1);
    }
    const unique = new Set(
      [...ids].filter(([, count]) => count === 1).map(([id]) => id),
    );
    const id = root.getAttribute("data-cdx-id") || "";
    if (!/^cdx-stw-[a-f0-9]{24}$/.test(id)) {
      return {
        error:
          "The menu needs a stable Navigator identity. Reload the preview.",
      };
    }
    return {
      id,
      tag: root.tagName.toLowerCase(),
      path: pathFor(root, unique),
      items,
      runtime:
        root.hasAttribute("data-cdx-runtime") ||
        !!root.querySelector("[data-cdx-runtime]"),
    };
  };

  if (preview && typeof data.parent_origin === "string" && data.parent_origin) {
    window.addEventListener("message", (event) => {
      if (event.source !== window.parent || event.origin !== data.parent_origin)
        return;
      const message = event.data;
      if (
        message?.channel !== "condixia-ai-stw-editor" ||
        message.type !== "condixia/editor/runtime-menu-capture"
      )
        return;
      const selectedId = String(message.payload?.id || "");
      const requestId = String(message.payload?.requestId || "");
      const result = captureRuntimeMenu(selectedId);
      window.parent.postMessage(
        {
          channel: "condixia-ai-stw-editor",
          type: "condixia/editor/runtime-menu-captured",
          payload: { requestId, result },
        },
        data.parent_origin,
      );
    });
  }

  let scheduled = false;

  const scan = () => {
    scheduled = false;

    const candidates = Array.from(document.body.querySelectorAll("*")).slice(
      0,
      4000,
    );

    const counts = new Map();

    for (const element of candidates) {
      if (element.id) {
        counts.set(element.id, (counts.get(element.id) || 0) + 1);
      }
    }

    const uniqueIds = new Set(
      [...counts].filter(([, count]) => count === 1).map(([id]) => id),
    );

    let added = 0;

    for (const element of candidates) {
      if (
        element.closest(
          "svg,template,script,style,noscript,[data-cdx-runtime-ignore]",
        )
      )
        continue;

      if (element.classList.contains("cdx-stw-selection-overlay")) {
        continue;
      }

      const path = pathFor(element, uniqueIds);
      if (navigation.has(path)) {
        renderRuntimeMenu(element, navigation.get(path));
      }
      const runtimeId = stableId(path);

      const sourceId = element.getAttribute("data-cdx-id") || "";

      if (preview && !sourceId) {
        element.setAttribute("data-cdx-id", runtimeId);
        element.setAttribute("data-cdx-runtime", "1");
        added += 1;
      }

      // Source-backed elements must keep their original IDs.
      if (sourceId && sourceId !== runtimeId) continue;

      for (const bindings of [overrides.get(runtimeId), live.get(runtimeId)]) {
        if (!bindings) continue;

        for (const [property, value] of bindings) {
          applyValue(element, property, value);
        }
      }
    }

    if (preview && added) {
      window.dispatchEvent(new CustomEvent("cdx-stw-runtime-updated"));
    }
  };

  const schedule = () => {
    if (scheduled) return;

    scheduled = true;
    window.requestAnimationFrame(scan);
  };

  if (preview) {
    window.CondixiaRuntime = {
      // Allow the same-origin WordPress editor to capture the rendered
      // menu directly. The postMessage bridge remains available.
      captureMenu: captureRuntimeMenu,

      setLive(id, property, value) {
        const key = String(property).toLowerCase();

        if (
          !/^cdx-stw-[a-f0-9]{24}$/.test(id) ||
          !allowed.has(key) ||
          typeof value !== "string"
        )
          return;

        if (!live.has(id)) {
          live.set(id, new Map());
        }

        live.get(id).set(key, value);
      },
    };
  }

  // The site may create an element and immediately receive a click
  // before the next animation frame. Index first, then let the editor
  // select the clicked element.
  if (preview) {
    document.addEventListener(
      "click",
      (event) => {
        const target = event.target;

        if (!(target instanceof Element)) return;
        if (target.closest("[data-cdx-runtime-ignore]")) return;

        if (!target.hasAttribute("data-cdx-id")) {
          scan();
        }
      },
      true,
    );
  }

  const observer = new MutationObserver((mutations) => {
    if (
      mutations.some(
        (record) =>
          record.type === "childList" &&
          (record.addedNodes.length || record.removedNodes.length),
      )
    ) {
      schedule();
    }
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  });

  schedule();

  document.addEventListener("DOMContentLoaded", schedule, { once: true });

  window.addEventListener("load", schedule, { once: true });
})();
