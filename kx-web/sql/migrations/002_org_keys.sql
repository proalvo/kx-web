-- Organization provisioning keys: allow kx-server to create competitions
ALTER TABLE organization
  ADD COLUMN org_key_hash VARCHAR(255) NOT NULL DEFAULT '' AFTER status,
  ADD COLUMN org_key_hint CHAR(4)      NOT NULL DEFAULT '' AFTER org_key_hash;
INSERT IGNORE INTO schema_migration (version) VALUES (2);
