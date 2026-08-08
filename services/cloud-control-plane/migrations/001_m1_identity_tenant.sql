CREATE TABLE IF NOT EXISTS cloud_users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(320) NOT NULL,
  email_canonical VARCHAR(320) NOT NULL,
  display_name VARCHAR(100) NULL,
  password_hash VARCHAR(255) NULL,
  status VARCHAR(32) NOT NULL,
  email_verified_at DATETIME(6) NULL,
  totp_secret_ciphertext VARBINARY(512) NULL,
  totp_secret_nonce VARBINARY(32) NULL,
  totp_enabled_at DATETIME(6) NULL,
  failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME(6) NULL,
  last_login_at DATETIME(6) NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  UNIQUE KEY uq_cloud_users_email (email_canonical)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_user_identities (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  provider VARCHAR(16) NOT NULL,
  issuer VARCHAR(191) NOT NULL,
  subject VARCHAR(191) NOT NULL,
  display_name VARCHAR(100) NOT NULL,
  avatar_url VARCHAR(2048) NULL,
  bound_at DATETIME(6) NOT NULL,
  last_login_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_external_identity (provider, issuer, subject),
  UNIQUE KEY uq_user_provider (user_id, provider, issuer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_email_verifications (
  id CHAR(36) PRIMARY KEY,
  email_canonical VARCHAR(320) NOT NULL,
  purpose VARCHAR(32) NOT NULL,
  delivery_status VARCHAR(32) NOT NULL,
  code_digest CHAR(64) NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME(6) NOT NULL,
  consumed_at DATETIME(6) NULL,
  requested_ip VARCHAR(45) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  KEY idx_email_verification_lookup (email_canonical, purpose, delivery_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_tenants (
  id CHAR(36) PRIMARY KEY,
  type VARCHAR(16) NOT NULL,
  name VARCHAR(150) NOT NULL,
  status VARCHAR(16) NOT NULL,
  created_by_user_id CHAR(36) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_tenant_creator FOREIGN KEY (created_by_user_id) REFERENCES cloud_users(id),
  KEY idx_tenant_type_status (type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_tenant_members (
  id CHAR(36) PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  user_id CHAR(36) NOT NULL,
  role VARCHAR(32) NOT NULL,
  status VARCHAR(16) NOT NULL,
  joined_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_member_tenant FOREIGN KEY (tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_tenant_member (tenant_id, user_id),
  KEY idx_member_user_status (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_tenant_relations (
  id CHAR(36) PRIMARY KEY,
  agent_tenant_id CHAR(36) NOT NULL,
  customer_tenant_id CHAR(36) NOT NULL,
  status VARCHAR(16) NOT NULL,
  effective_from DATETIME(6) NOT NULL,
  effective_until DATETIME(6) NULL,
  created_by_user_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  active_customer_tenant_id CHAR(36)
    GENERATED ALWAYS AS (CASE WHEN status = 'ACTIVE' THEN customer_tenant_id ELSE NULL END) STORED,
  CONSTRAINT fk_relation_agent FOREIGN KEY (agent_tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_relation_customer FOREIGN KEY (customer_tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_relation_creator FOREIGN KEY (created_by_user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_active_customer_agent (active_customer_tenant_id),
  KEY idx_relation_agent_status (agent_tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_agent_profiles (
  tenant_id CHAR(36) PRIMARY KEY,
  status VARCHAR(16) NOT NULL,
  level_code VARCHAR(32) NULL,
  credit_status VARCHAR(16) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_agent_profile_tenant FOREIGN KEY (tenant_id) REFERENCES cloud_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_sessions (
  id CHAR(36) PRIMARY KEY,
  token_digest CHAR(64) NOT NULL,
  user_id CHAR(36) NOT NULL,
  audience VARCHAR(16) NOT NULL,
  current_tenant_id CHAR(36) NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(512) NOT NULL,
  last_activity_at DATETIME(6) NOT NULL,
  idle_expires_at DATETIME(6) NOT NULL,
  absolute_expires_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  revoke_reason VARCHAR(100) NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES cloud_users(id),
  CONSTRAINT fk_session_tenant FOREIGN KEY (current_tenant_id) REFERENCES cloud_tenants(id),
  UNIQUE KEY uq_session_token_digest (token_digest),
  KEY idx_session_user_active (user_id, revoked_at, absolute_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_invitations (
  id CHAR(36) PRIMARY KEY,
  tenant_id CHAR(36) NOT NULL,
  email_canonical VARCHAR(320) NOT NULL,
  role VARCHAR(32) NOT NULL,
  token_digest CHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  accepted_at DATETIME(6) NULL,
  invited_by_user_id CHAR(36) NOT NULL,
  created_at DATETIME(6) NOT NULL,
  CONSTRAINT fk_invitation_tenant FOREIGN KEY (tenant_id) REFERENCES cloud_tenants(id),
  CONSTRAINT fk_invitation_actor FOREIGN KEY (invited_by_user_id) REFERENCES cloud_users(id),
  UNIQUE KEY uq_invitation_token_digest (token_digest),
  KEY idx_invitation_email_status (email_canonical, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cloud_audit_events (
  id CHAR(36) PRIMARY KEY,
  occurred_at DATETIME(6) NOT NULL,
  actor_user_id CHAR(36) NULL,
  actor_tenant_id CHAR(36) NULL,
  event_type VARCHAR(64) NOT NULL,
  target_type VARCHAR(64) NOT NULL,
  target_id CHAR(36) NULL,
  reason VARCHAR(500) NULL,
  request_id VARCHAR(64) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  metadata_json JSON NOT NULL,
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES cloud_users(id),
  CONSTRAINT fk_audit_tenant FOREIGN KEY (actor_tenant_id) REFERENCES cloud_tenants(id),
  KEY idx_audit_time (occurred_at),
  KEY idx_audit_actor_time (actor_user_id, occurred_at),
  KEY idx_audit_target (target_type, target_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
