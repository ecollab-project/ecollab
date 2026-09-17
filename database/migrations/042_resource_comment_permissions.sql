ALTER TABLE collab_resource_permissions MODIFY COLUMN permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';
ALTER TABLE collab_resource_invites MODIFY COLUMN permission ENUM('view','comment','edit') NOT NULL DEFAULT 'view';
