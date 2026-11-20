<dialog id="createResourceDialog" class="resource-create-dialog" aria-labelledby="createResourceTitle">
  <form id="createResourceForm">
    <h2 id="createResourceTitle">New whiteboard</h2>
    <label>
      <span>Title</span>
      <input class="form-input" name="title" maxlength="200" required autofocus>
    </label>
    <label id="resourceFormat">
      <span>File type</span>
      <select class="form-select" name="type">
        <option value="docx">Word</option>
        <option value="xlsx">Excel</option>
        <option value="pptx">PowerPoint</option>
      </select>
    </label>
    <label>
      <span>Coworkspace</span>
      <select class="form-select" name="workspace_id" required></select>
    </label>
    <label>
      <span>Who can view</span>
      <select class="form-select" name="visibility">
        <option value="public">Server members</option>
        <option value="private">Only people you give access to</option>
      </select>
    </label>
    <details id="resourceDescription">
      <summary>Add a description (optional)</summary>
      <label><span>Description</span><textarea class="form-textarea" name="description" rows="3" maxlength="500"></textarea></label>
    </details>
    <p id="resourceCreateError" role="alert" hidden></p>
    <div class="resource-create-actions">
      <button type="button" class="btn" id="resourceCreateCancel">Cancel</button>
      <button type="submit" class="btn primary" id="resourceCreateSubmit">Create and open</button>
    </div>
  </form>
</dialog>
