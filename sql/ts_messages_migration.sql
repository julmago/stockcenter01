ALTER TABLE ts_messages
  ADD COLUMN assigned_to_user_id INT UNSIGNED NULL AFTER created_by,
  ADD KEY idx_ts_messages_assigned (assigned_to_user_id, created_at),
  ADD CONSTRAINT fk_ts_messages_assigned_to_user
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id)
    ON DELETE RESTRICT;

ALTER TABLE users
  ADD COLUMN last_tasks_seen_at DATETIME NULL AFTER created_at;
