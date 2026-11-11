-- Sessions now record their owner, so "sign out everywhere" can delete that
-- person's rows immediately instead of waiting for garbage collection.

ALTER TABLE sessions
  ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER id,
  ADD KEY sessions_user (user_id),
  ADD CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE;
