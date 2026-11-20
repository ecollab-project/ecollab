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
    <p class="desc" id="ecLayoutHelp">Drag a widget or use its move buttons to rearrange the workspace. The preview uses sample content and updates as you edit. Save when you’re ready.</p>
    <form id="ecWidgetForm">
      <fieldset class="ec-widget-fieldset" aria-label="Chat workspace preview" aria-describedby="ecLayoutHelp">
        <div class="ec-workspace-preview">
          <div class="ec-widget-columns">
            <section class="ec-widget-column ec-preview-left" aria-labelledby="ecWidgetsLeft">
              <div class="ec-preview-bar" aria-hidden="true">
                <span class="ec-preview-tile">✦</span><strong>Campus workspace</strong><span class="ec-preview-end">▾</span>
              </div>
              <div class="ec-preview-sidebar-content">
                <div class="ec-preview-search" aria-hidden="true"><span>⌕ Search</span><kbd>⌘K</kbd></div>
                <h3 id="ecWidgetsLeft">Left sidebar</h3>
                <ul id="ecWidgetList-left" data-widget-drop="left"></ul>
              </div>
            </section>
            <div class="ec-preview-chat" aria-hidden="true">
              <div class="ec-preview-bar ec-preview-channel">
                <span class="ec-preview-tile">#</span><strong>general</strong>
                <div class="ec-preview-tools"><span>⌕</span><span>⚑</span><span class="ec-preview-pill">Collab</span><span>♟</span></div>
              </div>
              <div class="ec-preview-thread">
                <div class="ec-preview-message">
                  <span class="ec-preview-avatar">M</span>
                  <div><b>Mika</b> <span class="ec-preview-pill">Student</span><p>Who’s free for a review session?</p></div>
                </div>
                <div class="ec-preview-date">Today</div>
                <div class="ec-preview-message">
                  <span class="ec-preview-avatar ec-preview-avatar-alt">A</span>
                  <div><b>Alex</b> <span class="ec-preview-pill">Personal</span><p>I’m in. Here are my notes.</p><div class="ec-preview-file"><span>▤</span> Review notes.pdf</div></div>
                </div>
              </div>
              <div class="ec-preview-composer">
                <span>⊕</span><span class="ec-preview-placeholder">Message #general</span><span class="ec-preview-pill">AI Assist</span><b>Send</b>
              </div>
            </div>
            <section class="ec-widget-column ec-preview-right" aria-labelledby="ecWidgetsRight">
              <div class="ec-preview-bar"><h3 id="ecWidgetsRight">Right sidebar</h3></div>
              <div class="ec-preview-sidebar-content">
                <ul id="ecWidgetList-right" data-widget-drop="right"></ul>
              </div>
            </section>
          </div>
          <div class="ec-preview-dock" aria-hidden="true">
            <div class="ec-preview-account">
              <span class="ec-preview-avatar ec-preview-avatar-alt">Y</span>
              <span><b>Your profile</b><small>Online · Student</small></span>
              <span class="ec-preview-account-tools"><span>♩</span><span>◖</span></span>
            </div>
            <div class="ec-preview-switcher">
              <span class="ec-preview-tile ec-preview-current">✦</span>
              <span class="ec-preview-tile">▥</span>
              <span class="ec-preview-tile">⌘</span>
              <span class="ec-preview-tile">+</span>
            </div>
            <span class="ec-preview-dock-label">Workspace dock</span>
          </div>
        </div>
        <?php
        $workspacePreviewSamples = [
          'shortcuts' => [['⌂', 'Home'], ['@', 'Mentions'], ['▮', 'Bookmarks'], ['◫', 'Discussions'], ['▤', 'Drafts'], ['✦', 'Collabs'], ['‹›', 'Code Lab'], ['▥', 'Library']],
          'channels' => [['#', 'announcements'], ['#', 'general'], ['#', 'study-room']],
          'voice' => [['◖', 'general-voice']],
          'direct' => [['J', 'Jarred'], ['M', 'Mika']],
          'groups' => [['G', 'Study group']],
          'activity' => [['M', 'Mika', 'Active']],
          'matches' => [['J', 'Jules', '92% match'], ['S', 'Sam', '86% match']],
          'members' => [['M', 'Mika', 'Student'], ['A', 'Alex', 'Personal'], ['R', 'Riley', 'Professor']],
        ];
        foreach ($workspacePreviewSamples as $widgetId => $sampleRows):
        ?>
          <template data-widget-sample="<?= htmlspecialchars($widgetId) ?>">
            <div class="ec-preview-samples" aria-hidden="true">
              <?php foreach ($sampleRows as $sampleRow):
                [$icon, $text] = $sampleRow;
                $detail = $sampleRow[2] ?? null; ?>
                <div class="ec-preview-sample<?= in_array($text, ['Home', 'general'], true) ? ' ec-preview-selected' : '' ?>">
                  <span><?= htmlspecialchars($icon) ?></span><span><?= htmlspecialchars($text) ?></span>
                  <?php if ($detail !== null): ?><small><?= htmlspecialchars($detail) ?></small><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </template>
        <?php endforeach; ?>
        <section class="ec-widget-tray" aria-labelledby="ecWidgetsHidden">
          <h3 id="ecWidgetsHidden">Hidden widgets</h3>
          <ul id="ecWidgetList-hidden" data-widget-drop="hidden"></ul>
        </section>
        <div class="actions">
          <button type="button" class="btn secondary" id="ecWidgetUndo">Discard edits</button>
          <button type="button" class="btn secondary" id="ecWidgetReset">Restore default layout</button>
          <button type="submit" class="btn primary">Save workspace layout</button>
        </div>
      </fieldset>
      <p id="ecWidgetStatus" role="status" aria-live="polite"></p>
    </form>
  </div>
</div>
