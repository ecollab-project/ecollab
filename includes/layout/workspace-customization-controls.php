<div class="card ec-custom-palette-card">
  <div class="head">Custom palette</div>
  <div class="body">
    <form id="ecCustomPalette" aria-describedby="ecPaletteHelp">
      <p class="desc" id="ecPaletteHelp">Use a color picker or enter a hex code. Preview the combination before saving it.</p>
      <div class="ec-palette-fields">
        <?php foreach (WorkspaceCustomization::COLORS as $role => $field): ?>
          <div class="ec-color-field">
            <label for="ecColor-<?= $role ?>"><?= $field['label'] ?></label>
            <small id="ecColorHint-<?= $role ?>"><?= $field['hint'] ?></small>
            <div class="ec-color-inputs">
              <input type="color" name="<?= $role ?>" id="ecColor-<?= $role ?>" aria-describedby="ecColorHint-<?= $role ?>">
              <input type="text" name="<?= $role ?>Hex" aria-label="<?= $field['label'] ?> hex code" pattern="#[0-9A-Fa-f]{6}" maxlength="7" required spellcheck="false" autocomplete="off">
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="ec-palette-preview" id="ecPalettePreview" aria-label="Palette preview">
        <div><div><strong>Highlighted channel</strong><br>Sample message</div><span>Send</span></div>
      </div>
      <p id="ecPaletteContrast" class="desc" aria-live="polite" hidden>Text may be hard to read against the background or panels.</p>
      <div class="actions">
        <button class="btn secondary ec-theme-recovery" type="button" id="ecRestoreOriginal">Restore eCollab Original</button>
        <button class="btn secondary" type="button" id="ecResetColorDraft">Undo edits</button>
        <button class="btn secondary" type="button" id="ecEditSavedPalette">Load saved colors</button>
        <button class="btn primary" type="submit">Save custom palette</button>
      </div>
    </form>
    <p id="ecPaletteStatus" role="status" aria-live="polite"></p>
  </div>
</div>
<div class="card" id="workspace-layout">
  <div class="head">Workspace layout</div>
  <div class="body">
    <p class="desc">Choose what appears in your Chat sidebars. Move widgets between sides or reorder them within a side.</p>
    <form id="ecWidgetForm">
      <fieldset class="ec-widget-fieldset" aria-label="Chat widgets">
        <div class="ec-widget-columns">
          <section class="ec-widget-column" aria-labelledby="ecWidgetsLeft">
            <h3 id="ecWidgetsLeft">Left sidebar</h3>
            <ul id="ecWidgetList-left"></ul>
          </section>
          <section class="ec-widget-column" aria-labelledby="ecWidgetsRight">
            <h3 id="ecWidgetsRight">Right sidebar</h3>
            <ul id="ecWidgetList-right"></ul>
          </section>
        </div>
        <div class="actions">
          <button type="button" class="btn secondary" id="ecWidgetReset">Restore default layout</button>
          <button type="submit" class="btn primary">Save workspace layout</button>
        </div>
      </fieldset>
      <p id="ecWidgetStatus" role="status" aria-live="polite"></p>
    </form>
  </div>
</div>
