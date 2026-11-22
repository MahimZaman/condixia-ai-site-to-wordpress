(() => {
  "use strict";

  const script = document.currentScript;

  const parentOrigin = script?.dataset?.parentOrigin || "";

  const siteId = Number(script?.dataset?.siteId || 0);

  const artifactId = Number(script?.dataset?.artifactId || 0);

  if (!parentOrigin || window.parent === window) {
    return;
  }

  let selected = null;

  const send = (type, payload = {}) => {
    window.parent.postMessage(
      {
        channel: "condixia-ai-stw-editor",
        type,
        payload,
      },
      parentOrigin,
    );
  };

  const textTags = new Set([
    "A",
    "BUTTON",
    "EM",
    "H1",
    "H2",
    "H3",
    "H4",
    "H5",
    "H6",
    "LABEL",
    "LI",
    "P",
    "SMALL",
    "SPAN",
    "STRONG",
    "TD",
    "TH",
  ]);

  const rgbToHex = (value) => {
    const match = String(value || "").match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)/i);

    if (!match) {
      return "";
    }

    return `#${[match[1], match[2], match[3]]
      .map((channel) => Number(channel).toString(16).padStart(2, "0"))
      .join("")}`;
  };

  const backgroundImageUrl = (value) => {
    const match = String(value || "").match(/^url\(["']?(.*?)["']?\)$/i);

    return match ? match[1] : "";
  };

  // Step 18: model *text nodes*, not innerHTML; preserve inline elements and attributes.
  const richInlineTags = new Set([
    "SPAN",
    "STRONG",
    "EM",
    "B",
    "I",
    "MARK",
    "SMALL",
    "A",
    "BR",
    "CODE",
    "SUB",
    "SUP",
    "U",
    "S",
    "DEL",
    "INS",
    "ABBR",
    "TIME",
    "WBR",
  ]);
  const richPathPattern = /^(?:0|[1-9]\d{0,2})(?:\.(?:0|[1-9]\d{0,2})){0,11}$/;

  const richTextModel = (element) => {
    if (!textTags.has(element.tagName) || element.children.length === 0)
      return null;

    const segments = [];
    const nodes = [];
    let valid = true;
    let total = 0;

    const visit = (node, path, depth) => {
      if (!valid || depth > 12 || !richPathPattern.test(path)) {
        valid = false;
        return;
      }
      if (node.nodeType === Node.TEXT_NODE) {
        const t = String(node.nodeValue || "");
        total += t.length;
        if (segments.length >= 100 || t.length > 4000 || total > 30000) {
          valid = false;
          return;
        }
        segments.push({ p: path, t });
        nodes.push(node);
        return;
      }
      if (
        node.nodeType !== Node.ELEMENT_NODE ||
        !richInlineTags.has(node.tagName) ||
        node.hasAttribute("contenteditable")
      ) {
        valid = false;
        return;
      }
      Array.from(node.childNodes).forEach((child, index) => {
        visit(child, `${path}.${index}`, depth + 1);
      });
    };

    Array.from(element.childNodes).forEach((child, index) => {
      visit(child, String(index), 1);
    });

    return valid && segments.length > 0 ? { segments, nodes } : null;
  };

  const applyRichText = (element, value) => {
    const current = richTextModel(element);
    if (!current || typeof value !== "string" || value.length > 60000)
      return false;
    let changes;
    try {
      changes = JSON.parse(value);
    } catch (error) {
      return false;
    }
    if (!Array.isArray(changes) || changes.length !== current.segments.length)
      return false;
    let total = 0;
    const valid = changes.every((part, index) => {
      if (
        !part ||
        typeof part !== "object" ||
        part.p !== current.segments[index].p ||
        typeof part.t !== "string" ||
        !part.t.length ||
        part.t.length > 4000 ||
        part.t.includes("\u0000")
      )
        return false;
      total += part.t.length;
      return total <= 30000;
    });
    if (!valid) return false;
    changes.forEach((part, index) => {
      current.nodes[index].nodeValue = part.t;
    });
    return true;
  };

  // Step 19 — safe sibling movement and breakpoint visibility.
  const structuralTags = new Set(["SECTION", "ARTICLE", "ASIDE", "DIV"]);
  const structuralParents = new Set(["MAIN", "SECTION", "ARTICLE", "DIV"]);
  const structuralId = /^cdx-stw-[a-f0-9]{24}$/;
  const structuralForbidden = "header,footer,nav,form,table,ul,ol";
  const structuralListClass =
    /(?:^|[\s_-])(grid|products|cards|list|slider|carousel|swiper)(?:$|[\s_-])/i;
  const breakpointCss =
    '@media(min-width:1025px){[data-cdx-visibility^="0"]{display:none!important}}' +
    "@media(min-width:768px) and (max-width:1024px){" +
    '[data-cdx-visibility="000"],[data-cdx-visibility="001"],' +
    '[data-cdx-visibility="100"],[data-cdx-visibility="101"]{display:none!important}}' +
    '@media(max-width:767px){[data-cdx-visibility$="0"]{display:none!important}}';

  const ensureBreakpointCss = () => {
    if (document.querySelector("[data-cdx-visibility-rules]")) return;
    const style = document.createElement("style");
    style.dataset.cdxVisibilityRules = "1";
    style.textContent = breakpointCss;
    (document.head || document.body).appendChild(style);
  };

  const structureGroup = (parent) => {
    if (
      !parent ||
      !structuralParents.has(parent.tagName) ||
      !structuralId.test(parent.getAttribute("data-cdx-id") || "") ||
      parent.closest(structuralForbidden) ||
      parent.hasAttribute("data-cdx-grid-managed") ||
      parent.querySelector("main") ||
      structuralListClass.test(parent.className || "")
    )
      return null;
    // Non-whitespace text and comments/scripts between siblings are position-sensitive.
    if (
      Array.from(parent.childNodes).some(
        (node) =>
          (node.nodeType === Node.TEXT_NODE &&
            (node.nodeValue || "").trim() !== "") ||
          (node.nodeType !== Node.TEXT_NODE &&
            node.nodeType !== Node.ELEMENT_NODE),
      )
    )
      return null;
    const items = Array.from(parent.children);
    if (
      items.length < 2 ||
      items.length > 80 ||
      items.some(
        (item) =>
          !structuralTags.has(item.tagName) ||
          !structuralId.test(item.getAttribute("data-cdx-id") || "") ||
          item.querySelector("main"),
      )
    )
      return null;
    const current = items.map((item) => item.getAttribute("data-cdx-id"));
    const stored = parent.getAttribute("data-cdx-order-original");
    const original = stored ? stored.split(",") : current.slice();
    if (
      original.length !== current.length ||
      new Set(original).size !== original.length ||
      current.some((id) => !original.includes(id))
    )
      return null;
    return { items, current, original };
  };

  const positionalRisk = () => {
    const pattern =
      /:nth-(?:child|of-type)|:(?:first|last)-(?:child|of-type)|(?:previous|next)ElementSibling|\.children\s*\[|querySelectorAll\s*\(/i;
    // External styles/scripts may be inaccessible: the shell always confirms the first move.
    return Array.from(
      document.querySelectorAll("style,script:not([src])"),
    ).some((item) => pattern.test(item.textContent || ""));
  };

  const structureInfo = (element) => {
    const eligible =
      structuralTags.has(element.tagName) &&
      !element.closest(structuralForbidden) &&
      !element.querySelector("main") &&
      structuralId.test(element.getAttribute("data-cdx-id") || "");
    const group = eligible ? structureGroup(element.parentElement) : null;
    const index = group ? group.items.indexOf(element) : -1;
    return {
      canHide: eligible,
      canMove: index >= 0,
      canUp: index > 0,
      canDown: index >= 0 && index < group.items.length - 1,
      parentId: group ? element.parentElement.getAttribute("data-cdx-id") : "",
      original: group ? group.original : [],
      order: group ? group.current : [],
      visibility: /^[01]{3}$/.test(
        element.getAttribute("data-cdx-visibility") || "",
      )
        ? element.getAttribute("data-cdx-visibility")
        : "111",
      positionalRisk: positionalRisk(),
    };
  };

  const childOrderValue = (parent) => {
    const group = structureGroup(parent);
    return group
      ? JSON.stringify({ original: group.original, order: group.current })
      : "";
  };

  const applyChildOrder = (parent, value) => {
    const group = structureGroup(parent);
    if (!group || typeof value !== "string" || value.length > 12000)
      return false;
    let order;
    try {
      order = JSON.parse(value);
    } catch (error) {
      return false;
    }
    if (
      !order ||
      !Array.isArray(order.original) ||
      !Array.isArray(order.order) ||
      order.original.length !== group.original.length ||
      order.original.some((id, index) => id !== group.original[index]) ||
      order.order.length !== group.current.length ||
      new Set(order.order).size !== order.order.length ||
      order.order.some((id) => !group.current.includes(id))
    )
      return false;
    const byId = new Map(
      group.items.map((item) => [item.getAttribute("data-cdx-id"), item]),
    );
    parent.setAttribute("data-cdx-order-original", group.original.join(","));
    order.order.forEach((id) => parent.appendChild(byId.get(id)));
    return true;
  };

  const moveStructure = (id, targetId, position) => {
    const source = layerElements.get(id);
    const target = targetId ? layerElements.get(targetId) : null;
    const group = source ? structureGroup(source.parentElement) : null;
    if (!group || !group.items.includes(source)) return;
    if (
      targetId &&
      (!target || !group.items.includes(target) || target === source)
    )
      return;
    const order = group.current.slice();
    const from = order.indexOf(id);
    let to = target
      ? order.indexOf(targetId)
      : position === "up"
        ? from - 1
        : from + 1;
    if (position === "restore") {
      to = from;
      order.splice(0, order.length, ...group.original);
    } else {
      if (to < 0 || to >= order.length) return;
      order.splice(from, 1);
      if (target) {
        to = order.indexOf(targetId) + (position === "after" ? 1 : 0);
      }
      order.splice(to, 0, id);
    }
    if (order.every((entry, index) => entry === group.current[index])) return;
    const parent = source.parentElement;
    select(parent);
    apply("childOrder", JSON.stringify({ original: group.original, order }));
    select(source);
  };

  // Matching is limited to stable source-backed elements. Query-generated cards
  // intentionally do not get visual-editing overrides per result.
  const editableColorNodes = () =>
    Array.from(document.querySelectorAll("[data-cdx-id]")).filter(
      (node) =>
        /^cdx-stw-[a-f0-9]{24}$/.test(node.getAttribute("data-cdx-id") || "") &&
        !node.closest("[data-cdx-grid-managed]"),
    );

  const styleMatches = (element, mode, token) => {
    const all = editableColorNodes();
    if (mode === "class") {
      if (
        !/^[a-zA-Z_][a-zA-Z0-9_-]{0,80}$/.test(token) ||
        !element.classList.contains(token)
      )
        return [];
      return all.filter((node) => node.classList.contains(token));
    }
    if (mode === "tag") {
      const region =
        element.parentElement?.closest("section,main") || document.body;
      return all.filter(
        (node) => region.contains(node) && node.tagName === element.tagName,
      );
    }
    return [];
  };

  const colorScopeInfo = (element) => {
    const classes = Array.from(element.classList)
      .filter(
        (value) =>
          /^[a-zA-Z_][a-zA-Z0-9_-]{0,80}$/.test(value) &&
          !/^(?:cdx-stw-|container$|row$|col(?:-|$)|btn$)/.test(value),
      )
      .slice(0, 12)
      .map((name) => ({
        name,
        count: styleMatches(element, "class", name).length,
      }));
    const nestedText = Array.from(
      element.querySelectorAll("span,strong,em,a,p,h1,h2,h3,h4,h5,h6"),
    ).some(
      (node) =>
        node.textContent.trim() &&
        getComputedStyle(node).color !== getComputedStyle(element).color,
    );
    return {
      classes,
      tagCount: styleMatches(element, "tag", "").length,
      tag: element.tagName.toLowerCase(),
      nestedText,
    };
  };

  const payload = (element) => {
    const style = window.getComputedStyle(element);

    const tag = element.tagName;
    const nestedText = textTags.has(tag) && element.children.length > 0;
    const rich = nestedText ? richTextModel(element) : null;

    return {
      id: element.getAttribute("data-cdx-id") || "",
      tag: tag.toLowerCase(),
      text: textTags.has(tag) ? element.textContent.trim() : "",
      richText: rich ? JSON.stringify(rich.segments) : "",
      structure: structureInfo(element),
      richLabels: rich
        ? rich.nodes.map((node) => {
            const parent = node.parentElement;
            return parent
              ? parent.tagName.toLowerCase() +
                  (parent.classList.length ? `.${parent.classList[0]}` : "")
              : "text";
          })
        : [],
      href: tag === "A" ? element.getAttribute("href") || "" : "",
      target: tag === "A" ? element.getAttribute("target") || "_self" : "",
      src: tag === "IMG" ? element.getAttribute("src") || "" : "",
      alt: tag === "IMG" ? element.getAttribute("alt") || "" : "",
      backgroundImage: backgroundImageUrl(style.backgroundImage),
      backgroundHasLayer: style.backgroundImage !== "none",
      backgroundSolid: element.hasAttribute("data-cdx-solid-background"),
      styleScope: colorScopeInfo(element),
      capabilities: {
        text: textTags.has(tag) && !nestedText,
        nestedText,
        richText: Boolean(rich),
        link: tag === "A",
        image: tag === "IMG",
        style: true,
        background: true,
      },
      styles: {
        color: rgbToHex(style.color),
        backgroundColor: rgbToHex(style.backgroundColor),
        fontSize: style.fontSize,
        fontWeight: style.fontWeight,
        textAlign: style.textAlign,
        padding: style.padding,
        margin: style.margin,
        border: style.border,
        borderRadius: style.borderRadius,
      },
    };
  };

  // Read-only snapshot of the editable preview structure.
  const layerElements = new Map();
  const MAX_LAYERS = 4000;

  const describeLayer = (element) => {
    const tag = element.tagName.toLowerCase();
    const has = (name) => element.classList.contains(name);

    let name = tag;
    let badge = "";

    if (
      tag === "button" ||
      element.getAttribute("role") === "button" ||
      element.classList.contains("btn") ||
      element.classList.contains("button")
    ) {
      name = "Button";
    } else if (tag === "header" || element.getAttribute("role") === "banner") {
      name = "Header";
      badge = "Header";
    } else if (
      tag === "footer" ||
      element.getAttribute("role") === "contentinfo"
    ) {
      name = "Footer";
      badge = "Footer";
    } else if (tag === "nav" || element.getAttribute("role") === "navigation") {
      name = "Navigation";
      badge = "Nav";
    } else if (tag === "main") {
      name = "Main Content";
    } else if (has("hero") || has("hero-section") || has("hero-banner")) {
      name =
        tag === "section" ? "Section" : tag === "div" ? "Container" : "Hero";
      badge = "Hero";
    } else if (
      has("product-grid") ||
      has("products-grid") ||
      has("product-list")
    ) {
      name =
        tag === "section"
          ? "Section"
          : tag === "div"
            ? "Container"
            : "Product Grid";
      badge = "Grid";
    } else if (has("product-card") || has("product-item")) {
      name = "Product Card";
      badge = "Card";
    } else if (has("card")) {
      name = "Card";
    } else {
      name =
        {
          section: "Section",
          article: "Article",
          div: "Container",
          img: "Image",
          a: "Link",
          button: "Button",
          ul: "List",
          ol: "List",
          li: "List Item",
          p: "Text",
          span: "Text",
          small: "Text",
          strong: "Text",
          em: "Text",
          b: "Text",
          i: "Text",
          label: "Text",
          time: "Text",
          blockquote: "Text",
          h1: "Heading",
          h2: "Heading",
          h3: "Heading",
          h4: "Heading",
          h5: "Heading",
          h6: "Heading",
        }[tag] || tag.toUpperCase();
    }

    const directText =
      Array.from(element.childNodes)
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent.trim())
        .find(Boolean) || "";

    const hint = (
      element.getAttribute("aria-label") ||
      (tag === "img" ? element.getAttribute("alt") : "") ||
      ([
        "h1",
        "h2",
        "h3",
        "h4",
        "h5",
        "h6",
        "p",
        "a",
        "button",
        "span",
        "small",
        "strong",
        "em",
        "label",
        "li",
      ].includes(tag)
        ? directText
        : "") ||
      ""
    )
      .replace(/\s+/g, " ")
      .trim()
      .slice(0, 45);

    const classes = Array.from(element.classList)
      .filter(
        (item) =>
          /^[a-zA-Z_][a-zA-Z0-9_-]{0,80}$/.test(item) &&
          !item.startsWith("cdx-stw-"),
      )
      .slice(0, 12);
    const domId = String(element.getAttribute("id") || "").slice(0, 90);
    const meaningfulClass =
      classes.find((item) =>
        /(?:hero|banner|slider|product|portfolio|gallery|testimonial|review|feature|service|pricing|contact|about|team|faq|footer|header|benefit|newsletter|category|brand|grid|card)/i.test(
          item,
        ),
      ) || "";
    const meaningful = Boolean(
      meaningfulClass ||
      domId ||
      badge ||
      element.hasAttribute("aria-label") ||
      element.hasAttribute("data-cdx-grid-managed"),
    );
    const humanize = (text) =>
      text
        .replace(/[_-]+/g, " ")
        .replace(/\b\w/g, (char) => char.toUpperCase())
        .slice(0, 70);
    // Keep the semantic element type visible; classes and DOM IDs are hints,
    // never substitutes for the real selectable source element.
    // A repeated card remains an individual selectable element.
    // Derive its label from its own heading only.
    const heading = /^(?:Card|Product Card)$/.test(name)
      ? element.querySelector("h1,h2,h3,h4,h5,h6,.card-title,.product-title")
      : null;
    const cardHint = heading
      ? (heading.textContent || "").replace(/\s+/g, " ").trim().slice(0, 45)
      : "";
    return {
      tag,
      label:
        hint || cardHint || meaningfulClass || domId
          ? `${name} · ${hint || cardHint || humanize(meaningfulClass || domId)}`
          : name,
      badge,
      domId,
      classes: classes.join(" "),
      meaningful,
    };
  };

  const snapshotLayers = () => {
    layerElements.clear();

    const layers = [];
    const groupCache = new Map();
    let truncated = false;

    if (!document.body) {
      return { layers, truncated };
    }

    const walker = document.createTreeWalker(
      document.body,
      NodeFilter.SHOW_ELEMENT,
    );

    let element;

    while ((element = walker.nextNode())) {
      const id = element.getAttribute("data-cdx-id") || "";

      if (!id || id.length > 120 || layerElements.has(id)) {
        continue;
      }

      if (layers.length >= MAX_LAYERS) {
        truncated = true;
        break;
      }

      let ancestor = element.parentElement;

      while (
        ancestor &&
        !layerElements.has(ancestor.getAttribute("data-cdx-id"))
      ) {
        ancestor = ancestor.parentElement;
      }

      const parent = ancestor ? ancestor.getAttribute("data-cdx-id") || "" : "";

      layerElements.set(id, element);

      const itemParent = element.parentElement;
      if (!groupCache.has(itemParent))
        groupCache.set(itemParent, structureGroup(itemParent));
      const group = groupCache.get(itemParent);
      layers.push({
        id,
        parent,
        movable: Boolean(group && group.items.includes(element)),
        ...describeLayer(element),
      });
    }

    return { layers, truncated };
  };

  // Visible above imported styles and overflow-clipped element outlines.
  // This overlay has no data-cdx-id and is never saved as page content.
  const selectionOverlay = document.createElement("div");
  selectionOverlay.className = "cdx-stw-selection-overlay";
  selectionOverlay.setAttribute("aria-hidden", "true");
  selectionOverlay.hidden = true;
  document.body.appendChild(selectionOverlay);

  let selectionPaintQueued = false;

  const paintSelection = () => {
    selectionPaintQueued = false;

    if (!selected || !selected.isConnected) {
      selectionOverlay.hidden = true;
      return;
    }

    const rect = selected.getBoundingClientRect();

    const visible =
      rect.width > 0 &&
      rect.height > 0 &&
      rect.bottom > 0 &&
      rect.right > 0 &&
      rect.top < window.innerHeight &&
      rect.left < window.innerWidth;

    selectionOverlay.hidden = !visible;
    if (!visible) return;

    const x = Math.max(0, rect.left);
    const y = Math.max(0, rect.top);

    selectionOverlay.style.left = `${x}px`;
    selectionOverlay.style.top = `${y}px`;

    selectionOverlay.style.width = `${Math.max(0, Math.min(window.innerWidth, rect.right) - x)}px`;

    selectionOverlay.style.height = `${Math.max(0, Math.min(window.innerHeight, rect.bottom) - y)}px`;
  };

  const updateSelectionOverlay = () => {
    if (selectionPaintQueued) return;

    selectionPaintQueued = true;
    window.requestAnimationFrame(paintSelection);
  };

  window.addEventListener("scroll", updateSelectionOverlay, true);
  window.addEventListener("resize", updateSelectionOverlay);

  window.visualViewport?.addEventListener("resize", updateSelectionOverlay);

  const select = (element) => {
    if (selected) {
      selected.classList.remove("cdx-stw-editor-selected");
    }

    selected = element;
    selected.classList.add("cdx-stw-editor-selected");

    updateSelectionOverlay();

    send("condixia/editor/selected", payload(selected));
  };

  const cleanCss = (value) => {
    const text = String(value || "").trim();

    if (text.length > 120 || /[;{}<>]/.test(text)) {
      return "";
    }

    return text;
  };

  const safeLink = (value) => {
    const text = String(value || "").trim();

    if (text === "") {
      return "";
    }

    if (
      text.startsWith("#") ||
      text.startsWith("/") ||
      text.startsWith("./") ||
      text.startsWith("../")
    ) {
      return text;
    }

    try {
      const url = new URL(text, window.location.href);

      if (["http:", "https:", "mailto:", "tel:"].includes(url.protocol)) {
        return text;
      }
    } catch (error) {
      return "";
    }

    return "";
  };

  const safeImageUrl = (value) => {
    try {
      const url = new URL(String(value || ""), window.location.href);

      if (["http:", "https:"].includes(url.protocol)) {
        return url.href;
      }
    } catch (error) {
      return "";
    }

    return "";
  };

  const replaceText = (element, value) => {
    const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);

    const nodes = [];

    let node = walker.nextNode();

    while (node) {
      if (String(node.nodeValue || "").trim() !== "") {
        nodes.push(node);
      }

      node = walker.nextNode();
    }

    if (nodes.length === 0) {
      element.appendChild(document.createTextNode(String(value || "")));

      return;
    }

    nodes[0].nodeValue = String(value || "");

    for (let index = 1; index < nodes.length; index += 1) {
      nodes[index].nodeValue = "";
    }
  };

  const originalBackgroundImages = new WeakMap();

  const applyStyle = (property, value) => {
    const cleaned = cleanCss(value);

    if (cleaned === "") {
      selected.style.removeProperty(property);

      return;
    }

    selected.style.setProperty(property, cleaned);
  };

  const valueForProperty = (element, property) => {
    const current = payload(element);

    return (
      {
        text: current.text,
        richText: current.richText,
        linkUrl: current.href,
        linkTarget: current.target,
        imageSrc: current.src,
        imageAlt: current.alt,
        backgroundImage: current.backgroundImage,
        color: current.styles.color,
        backgroundColor: current.backgroundSolid
          ? `solid:${current.styles.backgroundColor}`
          : current.styles.backgroundColor,
        fontSize: current.styles.fontSize,
        fontWeight: current.styles.fontWeight,
        textAlign: current.styles.textAlign,
        padding: current.styles.padding,
        margin: current.styles.margin,
        border: current.styles.border,
        borderRadius: current.styles.borderRadius,
        visibility: current.structure.visibility,
        childOrder: childOrderValue(element),
      }[property] ?? ""
    );
  };

  const apply = (
    property,
    value,
    record = true,
    attachmentId = 0,
    batchId = "",
  ) => {
    if (!selected) {
      return;
    }

    const before = valueForProperty(selected, property);

    switch (property) {
      case "text":
        if (!textTags.has(selected.tagName) || selected.children.length !== 0)
          return;
        replaceText(selected, value);
        break;

      case "richText":
        if (!applyRichText(selected, value)) return;
        break;

      case "visibility":
        if (
          !structuralTags.has(selected.tagName) ||
          selected.closest(structuralForbidden) ||
          selected.querySelector("main") ||
          !/^[01]{3}$/.test(value)
        )
          return;
        ensureBreakpointCss();
        selected.setAttribute("data-cdx-visibility", value);
        break;

      case "childOrder":
        if (!applyChildOrder(selected, value)) return;
        break;

      case "linkUrl":
        if (selected.tagName === "A") {
          const href = safeLink(value);

          if (href !== "") {
            selected.setAttribute("href", href);
          }
        }
        break;

      case "linkTarget":
        if (selected.tagName === "A") {
          if (value === "_blank") {
            selected.setAttribute("target", "_blank");

            selected.setAttribute("rel", "noopener noreferrer");
          } else {
            selected.removeAttribute("target");
          }
        }
        break;

      case "imageSrc":
        if (selected.tagName === "IMG") {
          const src = safeImageUrl(value);

          if (src !== "") {
            selected.setAttribute("src", src);

            selected.removeAttribute("srcset");

            selected.removeAttribute("sizes");
          }
        }
        break;

      case "imageAlt":
        if (selected.tagName === "IMG") {
          selected.setAttribute("alt", String(value || ""));
        }
        break;

      case "backgroundImage": {
        const src = safeImageUrl(value);

        if (src !== "") {
          selected.style.backgroundImage = `url("${src.replace(
            /["\\\r\n]/g,
            "",
          )}")`;
        }

        break;
      }

      case "color":
        if (/^#[0-9a-f]{6}$/i.test(value)) {
          selected.style.setProperty("color", value, "important");
        }
        break;

      case "backgroundColor": {
        const solid = typeof value === "string" && value.startsWith("solid:");
        const color = solid ? value.slice(6) : value;
        if (!/^#[0-9a-f]{6}$/i.test(color)) break;
        selected.style.setProperty("background-color", color, "important");
        if (solid) {
          if (
            !originalBackgroundImages.has(selected) &&
            !selected.hasAttribute("data-cdx-solid-background")
          ) {
            originalBackgroundImages.set(selected, {
              value: selected.style.getPropertyValue("background-image"),
              priority: selected.style.getPropertyPriority("background-image"),
            });
          }
          selected.style.setProperty("background-image", "none", "important");
          selected.setAttribute("data-cdx-solid-background", "1");
        } else if (selected.hasAttribute("data-cdx-solid-background")) {
          // An existing persisted override is restored precisely on next Save/reload.
          const previous = originalBackgroundImages.get(selected);
          if (previous?.value) {
            selected.style.setProperty(
              "background-image",
              previous.value,
              previous.priority,
            );
          } else {
            selected.style.removeProperty("background-image");
          }
          selected.removeAttribute("data-cdx-solid-background");
        }
        break;
      }

      case "fontSize":
        applyStyle("font-size", value);
        break;

      case "fontWeight":
        if (/^(?:[1-9]00|normal|bold)$/i.test(String(value || ""))) {
          selected.style.fontWeight = String(value);
        }
        break;

      case "textAlign":
        if (["left", "center", "right", "justify"].includes(value)) {
          selected.style.textAlign = value;
        }
        break;

      case "padding":
        applyStyle("padding", value);
        break;

      case "margin":
        applyStyle("margin", value);
        break;

      case "border":
        applyStyle("border", value);
        break;

      case "borderRadius":
        applyStyle("border-radius", value);
        break;

      default:
        return;
    }

    const after = valueForProperty(selected, property);
    updateSelectionOverlay();

    if (selected.hasAttribute("data-cdx-runtime")) {
      window.CondixiaRuntime?.setLive(
        selected.getAttribute("data-cdx-id") || "",
        property,
        after,
      );
    }

    send("condixia/editor/changed", {
      id: selected.getAttribute("data-cdx-id") || "",
      property,
      before,
      after,
      record: record !== false,
      attachmentId: Number(attachmentId || 0),
      batchId,
    });
    if (property === "childOrder") {
      send("condixia/editor/layers", snapshotLayers());
      send("condixia/editor/selected", payload(selected));
    } else if (property === "visibility") {
      send("condixia/editor/selected", payload(selected));
    }
  };

  document.addEventListener(
    "click",
    (event) => {
      const target =
        event.target instanceof Element
          ? event.target.closest("[data-cdx-id]")
          : null;

      if (!target) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      select(target);
    },
    true,
  );

  document.addEventListener(
    "submit",
    (event) => {
      event.preventDefault();
      event.stopPropagation();
    },
    true,
  );

  window.addEventListener("message", (event) => {
    if (event.source !== window.parent || event.origin !== parentOrigin) {
      return;
    }

    const message = event.data;

    if (
      !message ||
      typeof message !== "object" ||
      message.channel !== "condixia-ai-stw-editor"
    ) {
      return;
    }

    if (message.type === "condixia/editor/layers-request") {
      send("condixia/editor/layers", snapshotLayers());
      return;
    }

    if (message.type === "condixia/editor/structure-move") {
      const request = message.payload || {};
      if (
        typeof request.id === "string" &&
        ["up", "down", "restore", "before", "after"].includes(request.position)
      ) {
        moveStructure(request.id, request.targetId || "", request.position);
      }
      return;
    }

    if (message.type === "condixia/editor/apply-matching") {
      const request = message.payload || {};
      const element = layerElements.get(request.id);
      if (
        !element ||
        !element.isConnected ||
        !["color", "backgroundColor"].includes(request.property) ||
        !["class", "tag"].includes(request.mode) ||
        typeof request.value !== "string" ||
        !/^(?:solid:)?#[0-9a-fA-F]{6}$/.test(request.value) ||
        (request.property === "color" && request.value.startsWith("solid:"))
      )
        return;
      const targets = styleMatches(element, request.mode, request.token || "");
      if (targets.length < 2 || targets.length > 80) {
        send("condixia/editor/style-scope-result", {
          error:
            targets.length > 80
              ? "Too many matches (maximum 80). Narrow the selected class."
              : "No other editable source elements match this choice.",
        });
        return;
      }
      const beforeSelection = selected;
      const batchId = `color-${Date.now()}-${Math.random().toString(36).slice(2)}`;
      targets.forEach((node) => {
        selected = node;
        apply(request.property, request.value, true, 0, batchId);
      });
      selected = beforeSelection;
      send("condixia/editor/style-scope-result", { count: targets.length });
      if (selected) send("condixia/editor/selected", payload(selected));
      return;
    }

    if (message.type === "condixia/editor/select-layer") {
      const id = message.payload?.id;

      const element = typeof id === "string" ? layerElements.get(id) : null;

      if (
        element &&
        element.isConnected &&
        element.getAttribute("data-cdx-id") === id
      ) {
        select(element);

        element.scrollIntoView({
          block: "center",
          inline: "nearest",
        });
      } else {
        send("condixia/editor/selection-error", { id });
      }

      return;
    }

    if (
      message.type !== "condixia/editor/apply" ||
      !message.payload ||
      typeof message.payload !== "object" ||
      typeof message.payload.property !== "string"
    ) {
      return;
    }

    // History commands may target a previously selected element.
    if (
      !selected ||
      message.payload.id !== selected.getAttribute("data-cdx-id")
    ) {
      if (message.payload.record !== false) return;
      const previous = layerElements.get(message.payload.id);
      if (!previous || !previous.isConnected) return;
      select(previous);
    }

    apply(
      message.payload.property,
      message.payload.value,
      message.payload.record !== false,
      Number(message.payload.attachmentId || 0),
    );
  });

  window.addEventListener("cdx-stw-runtime-updated", () => {
    const wanted = selected?.getAttribute("data-cdx-id") || "";

    send("condixia/editor/layers", snapshotLayers());

    const replacement = wanted ? layerElements.get(wanted) : null;

    if (replacement && replacement !== selected && replacement.isConnected) {
      select(replacement);
    }
  });

  send("condixia/editor/ready", {
    siteId,
    artifactId,
  });
})();
