(() => {
  "use strict";

  const config = window.CondixiaEditorConfig;

  if (!config || typeof config !== "object") {
    return;
  }

  const frame = document.querySelector("[data-cdx-editor-frame]");

  const status = document.querySelector("[data-cdx-editor-status]");

  const button = (action) =>
    document.querySelector(`[data-cdx-editor-action="${action}"]`);

  const saveButton = button("save");
  const undoButton = button("undo");
  const redoButton = button("redo");
  const restoreElementButton = button("restore-element");
  const restorePageButton = button("restore-page");
  const restoreOriginalButton = button("restore-original");
  const restoreRevisionButton = button("restore-revision");

  const revisionsSelect = document.querySelector("[data-cdx-editor-revisions]");

  if (
    !frame ||
    !status ||
    !saveButton ||
    !undoButton ||
    !redoButton ||
    !restoreElementButton ||
    !restorePageButton ||
    !restoreOriginalButton ||
    !restoreRevisionButton ||
    !revisionsSelect
  ) {
    return;
  }

  let siteId = 0;
  let artifactId = 0;
  let selectedId = "";
  let busy = false;

  let history = [];
  let redo = [];

  const baselines = new Map();
  const pending = new Map();

  const key = (elementId, property) => `${elementId}\u0000${property}`;

  const setStatus = (text) => {
    status.textContent = text;
  };

  const valuesFromSelection = (payload) => ({
    text: payload.text || "",
    richText: payload.richText || "",
    linkUrl: payload.href || "",
    linkTarget: payload.target || "_self",
    imageSrc: payload.src || "",
    imageAlt: payload.alt || "",
    backgroundImage: payload.backgroundImage || "",
    color: payload.styles?.color || "",
    backgroundColor: payload.backgroundSolid
      ? `solid:${payload.styles?.backgroundColor || '#000000'}`
      : payload.styles?.backgroundColor || "",
    fontSize: payload.styles?.fontSize || "",
    fontWeight: payload.styles?.fontWeight || "400",
    textAlign: payload.styles?.textAlign || "left",
    padding: payload.styles?.padding || "",
    margin: payload.styles?.margin || "",
    border: payload.styles?.border || "",
    borderRadius: payload.styles?.borderRadius || "",
  });

  const dirty = () => pending.size > 0;

  const updateButtons = () => {
    const hasContext = siteId > 0 && artifactId > 0;

    saveButton.disabled = busy || !hasContext || !dirty();

    undoButton.disabled = busy || history.length === 0;

    redoButton.disabled = busy || redo.length === 0;

    restoreElementButton.disabled = busy || !hasContext || selectedId === "";

    restorePageButton.disabled = busy || !dirty();

    restoreOriginalButton.disabled = busy || !hasContext;

    restoreRevisionButton.disabled =
      busy || !hasContext || Number(revisionsSelect.value || 0) < 1;
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

  const setControlValue = (property, value) => {
    const control = document.querySelector(`[data-cdx-property="${property}"]`);

    if (!control) {
      return;
    }

    if (control.type === "color") {
      const color = String(value || "").replace(/^solid:/, "");
      control.value = /^#[0-9a-f]{6}$/i.test(color) ? color : "#000000";
      const hex = document.querySelector(`[data-cdx-color-hex="${property}"]`);
      if (hex) hex.value = control.value.toUpperCase();
      const swatch = control.closest(".cdx-stw-editor-color-swatch");
      if (swatch) swatch.style.backgroundColor = control.value;
      if (property === "backgroundColor") {
        const solid = document.querySelector('[data-cdx-solid-background]');
        if (solid) solid.checked = String(value || "").startsWith("solid:");
      }
      return;
    }

    control.value = typeof value === "string" ? value : "";
  };

  const resetSession = () => {
    selectedId = "";
    history = [];
    redo = [];

    baselines.clear();
    pending.clear();

    updateButtons();
  };

  const reloadPreview = () => {
    frame.src = config.previewUrl;
  };

  const post = async (action, fields = {}) => {
    const body = new FormData();

    body.append("action", action);
    body.append("_ajax_nonce", config.nonce);

    body.append("site_id", String(siteId));

    body.append("artifact_id", String(artifactId));

    Object.entries(fields).forEach(([name, value]) => {
      body.append(name, String(value));
    });

    const response = await fetch(config.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body,
    });

    const json = await response.json();

    if (!response.ok || !json || json.success !== true) {
      throw new Error(
        json?.data?.message || config.saveErrorText || "Request failed.",
      );
    }

    return json.data || {};
  };

  const populateRevisions = (revisions) => {
    revisionsSelect.replaceChildren();

    const first = document.createElement("option");

    first.value = "";
    first.textContent = "Saved revisions";

    revisionsSelect.appendChild(first);

    if (Array.isArray(revisions)) {
      revisions.forEach((revision) => {
        const id = Number(revision?.id || 0);

        if (id < 1) {
          return;
        }

        const option = document.createElement("option");

        option.value = String(id);

        option.textContent =
          `#${id} · ` +
          `${revision.revision_type || "save"} · ` +
          `${revision.created_at || ""}`;

        revisionsSelect.appendChild(option);
      });
    }

    updateButtons();
  };

  const loadRevisions = async () => {
    if (siteId < 1 || artifactId < 1) {
      populateRevisions([]);

      return;
    }

    try {
      const data = await post(config.actions?.revisions || "");

      populateRevisions(data.revisions || []);
    } catch (error) {
      populateRevisions([]);
    }
  };

  const recordChange = (payload) => {
    if (
      !payload ||
      typeof payload !== "object" ||
      typeof payload.id !== "string" ||
      typeof payload.property !== "string"
    ) {
      return;
    }

    const elementId = payload.id;

    const property = payload.property;

    const before = typeof payload.before === "string" ? payload.before : "";

    const after = typeof payload.after === "string" ? payload.after : "";

    const attachmentId = Number(payload.attachmentId || 0);

    if (!baselines.has(elementId)) {
      baselines.set(elementId, {});
    }

    const baseline = baselines.get(elementId);

    if (!Object.prototype.hasOwnProperty.call(baseline, property)) {
      baseline[property] = before;
    }

    const changeKey = key(elementId, property);

    if (after === baseline[property]) {
      pending.delete(changeKey);
    } else {
      pending.set(changeKey, {
        element_id: elementId,
        property_key: property,
        value: after,
        attachment_id: attachmentId,
      });
    }

    if (payload.record !== false && before !== after) {
      const batchId = typeof payload.batchId === 'string'
        ? payload.batchId.slice(0, 100) : '';
      if (batchId) {
        const latest = history[history.length - 1];
        const change = { id: elementId, property, before, after, attachmentId };
        if (latest?.batchId === batchId) latest.changes.push(change);
        else history.push({ batchId, changes: [change], id: selectedId });
        redo = [];
        setStatus(config.changedText || 'Preview changed — not saved');
        updateButtons();
        return;
      }
      const now = Date.now();

      const last = history[history.length - 1];

      if (
        last &&
        last.id === elementId &&
        last.property === property &&
        now - last.time < 600
      ) {
        last.after = after;
        last.attachmentId = attachmentId;

        last.time = now;
      } else {
        history.push({
          id: elementId,
          property,
          before,
          after,
          attachmentId,
          time: now,
        });
      }

      redo = [];
    }

    setStatus(config.changedText || "Preview changed — not saved");

    updateButtons();
  };

  window.addEventListener("message", (event) => {
    if (
      event.source !== frame.contentWindow ||
      event.origin !== config.previewMessageOrigin
    ) {
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

    if (message.type === "condixia/editor/ready") {
      siteId = Number(message.payload?.siteId || 0);

      artifactId = Number(message.payload?.artifactId || 0);

      resetSession();
      loadRevisions();

      return;
    }

    if (message.type === "condixia/editor/selected") {
      const payload = message.payload;

      if (!payload || typeof payload.id !== "string") {
        return;
      }

      selectedId = payload.id;

      if (!baselines.has(selectedId)) {
        baselines.set(selectedId, valuesFromSelection(payload));
      }

      updateButtons();

      return;
    }

    if (message.type === "condixia/editor/changed") {
      recordChange(message.payload);
    }
  });

  saveButton.addEventListener("click", async () => {
    if (busy || !dirty() || siteId < 1 || artifactId < 1) {
      return;
    }

    busy = true;

    updateButtons();

    setStatus(config.savingText || "Saving changes…");

    try {
      const data = await post(config.actions?.save || "", {
        changes: JSON.stringify(Array.from(pending.values())),
      });

      populateRevisions(data.revisions || []);

      resetSession();

      setStatus(config.savedText || "Changes saved.");

      reloadPreview();
    } catch (error) {
      setStatus(
        error.message ||
          config.saveErrorText ||
          "The changes could not be saved.",
      );
    } finally {
      busy = false;

      updateButtons();
    }
  });

  undoButton.addEventListener("click", () => {
    if (busy || history.length === 0) {
      return;
    }

    const command = history.pop();

    redo.push(command);

    selectedId = command.id;

    const changes = command.changes || [command];
    [...changes].reverse().forEach((change) => {
      send('condixia/editor/apply', {
        id: change.id, property: change.property, value: change.before,
        attachmentId: 0, record: false,
      });
    });
    if (command.changes) send('condixia/editor/select-layer', { id: command.id });
    else setControlValue(command.property, command.before);

    updateButtons();
  });

  redoButton.addEventListener("click", () => {
    if (busy || redo.length === 0) {
      return;
    }

    const command = redo.pop();

    history.push(command);

    selectedId = command.id;

    const changes = command.changes || [command];
    changes.forEach((change) => {
      send('condixia/editor/apply', {
        id: change.id, property: change.property, value: change.after,
        attachmentId: change.attachmentId || 0, record: false,
      });
    });
    if (command.changes) send('condixia/editor/select-layer', { id: command.id });
    else setControlValue(command.property, command.after);

    updateButtons();
  });

  restoreElementButton.addEventListener("click", async () => {
    if (
      busy ||
      selectedId === "" ||
      !window.confirm(
        config.restoreElementConfirmText || "Restore this element?",
      )
    ) {
      return;
    }

    busy = true;

    updateButtons();

    try {
      await post(config.actions?.restoreElement || "", {
        element_id: selectedId,
      });

      resetSession();
      reloadPreview();
    } catch (error) {
      setStatus(error.message || config.saveErrorText);
    } finally {
      busy = false;

      updateButtons();
    }
  });

  restorePageButton.addEventListener("click", () => {
    if (
      !dirty() ||
      !window.confirm(config.discardConfirmText || "Discard unsaved changes?")
    ) {
      return;
    }

    resetSession();
    reloadPreview();
  });

  restoreOriginalButton.addEventListener("click", async () => {
    if (
      busy ||
      !window.confirm(
        config.restoreOriginalConfirmText || "Restore original state?",
      )
    ) {
      return;
    }

    busy = true;

    updateButtons();

    try {
      await post(config.actions?.restoreOriginal || "");

      resetSession();
      reloadPreview();
    } catch (error) {
      setStatus(error.message || config.saveErrorText);
    } finally {
      busy = false;

      updateButtons();
    }
  });

  revisionsSelect.addEventListener("change", updateButtons);

  restoreRevisionButton.addEventListener("click", async () => {
    const revisionId = Number(revisionsSelect.value || 0);

    if (
      busy ||
      revisionId < 1 ||
      !window.confirm(
        config.restoreRevisionConfirmText || "Restore this revision?",
      )
    ) {
      return;
    }

    busy = true;

    updateButtons();

    try {
      await post(config.actions?.restoreRevision || "", {
        revision_id: revisionId,
      });

      resetSession();
      reloadPreview();
    } catch (error) {
      setStatus(error.message || config.saveErrorText);
    } finally {
      busy = false;

      updateButtons();
    }
  });

  window.addEventListener("keydown", (event) => {
    if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== "z") {
      return;
    }

    event.preventDefault();

    if (event.shiftKey) {
      redoButton.click();
    } else {
      undoButton.click();
    }
  });

  window.addEventListener("beforeunload", (event) => {
    if (!dirty()) {
      return;
    }

    event.preventDefault();
    event.returnValue = "";
  });

  updateButtons();
})();
