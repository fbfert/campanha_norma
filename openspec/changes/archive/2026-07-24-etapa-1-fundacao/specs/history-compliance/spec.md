## ADDED Requirements

### Requirement: Foundation Audit Events
The system SHALL audit foundation-stage security and administrative events without recording passwords, tokens, raw sessions, or secrets.

#### Scenario: Authentication audit
- **WHEN** a user logs in, logs out, or a relevant login failure occurs
- **THEN** the system SHALL record an audit event with user when available, action, IP address, user agent, and safe description.

#### Scenario: Administrative user audit
- **WHEN** a user is created, edited, blocked, unblocked, assigned roles, soft-deleted, or has a password reset by an administrator
- **THEN** the system SHALL record an audit event with safe old and new values.

#### Scenario: Password audit safety
- **WHEN** a password is changed or reset
- **THEN** the audit log SHALL record the action
- **AND** SHALL NOT store the password or password hash.
