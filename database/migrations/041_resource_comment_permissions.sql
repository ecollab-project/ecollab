-- Add comment as a distinct resource permission.
ALTER TABLE collab_resource_permissions
  MODIFY COLUMN permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';

ALTER TABLE collab_resource_invites
  MODIFY COLUMN permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';

ALTER TABLE collab_documents
  ADD COLUMN IF NOT EXISTS public_permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';

ALTER TABLE collab_whiteboards
  ADD COLUMN IF NOT EXISTS public_permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';
