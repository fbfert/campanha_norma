## ADDED Requirements

### Requirement: AI Provider Abstraction
The system SHALL access artificial intelligence through a vendor independent contract, with provider, base URL, credential, model and timeouts resolved from configuration and environment, never hardcoded in services or jobs.

#### Scenario: Resolving the configured provider
- **WHEN** the interpretation pipeline requests a completion
- **THEN** the provider SHALL be resolved from configuration
- **AND** no service SHALL reference a vendor specific class directly.

#### Scenario: Credential handling
- **WHEN** the provider is built
- **THEN** the credential SHALL come from an environment secret
- **AND** the credential SHALL NOT appear in any persisted record, log entry or exception message.

#### Scenario: Missing configuration
- **WHEN** no provider credential is configured
- **THEN** the system SHALL use an inert provider
- **AND** interpretation SHALL fail in a controlled way without attempting a network call.

### Requirement: Structured Output Contract
Every AI call SHALL request a structured JSON response bound to a named schema and version, and the response SHALL be validated server side before any persistence.

#### Scenario: Valid structured response
- **WHEN** the provider returns JSON matching the active schema
- **THEN** the result SHALL be persisted as structured data
- **AND** the run SHALL be recorded as succeeded.

#### Scenario: Unparseable or non conforming response
- **WHEN** the provider returns text that is not valid JSON, is missing a required field, has a wrong type, contains an unknown field or has a value outside the allowed set
- **THEN** the run SHALL be recorded with invalid output status
- **AND** no classification or insight SHALL be created
- **AND** the item SHALL be flagged for human review.

#### Scenario: Confidence bounds
- **WHEN** a response reports a confidence outside the range from zero to one
- **THEN** the response SHALL be rejected as invalid output.

### Requirement: Provider Resilience
Provider calls SHALL apply timeout, limited retries with backoff, a simple circuit breaker and sanitized error reporting.

#### Scenario: Timeout
- **WHEN** the provider does not answer within the configured timeout
- **THEN** the call SHALL fail with a timeout error code
- **AND** the failure SHALL be recorded on the run without message content.

#### Scenario: Rate limiting
- **WHEN** the provider answers with a rate limit status
- **THEN** the call SHALL be retried with backoff up to the configured attempt limit
- **AND** the attempt number SHALL be recorded.

#### Scenario: Circuit opening
- **WHEN** consecutive provider failures reach the configured threshold
- **THEN** the circuit SHALL open for the configured period
- **AND** further calls SHALL fail immediately without a network request
- **AND** a successful call after the period SHALL reset the failure counter.

### Requirement: Auditable AI Runs
The system SHALL record every AI execution attempt with conversation, source message, purpose, provider, model, prompt version, schema version, status, request hash, structured result, token usage when available, latency, optional estimated cost, confidence, sanitized error, attempt number and start and completion timestamps.

#### Scenario: Recording an execution
- **WHEN** an AI call is attempted
- **THEN** a run record SHALL be created before the call and completed after it
- **AND** the record SHALL contain latency and outcome.

#### Scenario: No secrets stored
- **WHEN** a run is persisted
- **THEN** the record SHALL NOT contain credentials, authorization headers or unnecessary payloads.

#### Scenario: Cost is optional
- **WHEN** the provider does not report usage or cost
- **THEN** the run SHALL still be recorded
- **AND** no feature SHALL depend on cost being present.

#### Scenario: Failed attempts remain visible
- **WHEN** an execution fails and is retried
- **THEN** each attempt SHALL remain recorded
- **AND** the retry SHALL NOT overwrite the previous attempt.

### Requirement: Extended Message Classification
The system SHALL classify open messages into `permission_yes`, `permission_no`, `opt_out`, `question_answer`, `asks_for_clarification`, `asks_about_norma`, `off_topic`, `human_requested`, `complaint`, `sensitive_report`, `insult_or_abuse`, `media_or_unsupported` or `ambiguous`.

#### Scenario: Deterministic precedence
- **WHEN** the deterministic classifier of the conversational flow resolves opt-out or a clear short answer
- **THEN** that result SHALL be recorded with deterministic origin and full confidence
- **AND** no AI call SHALL be made for that message.

#### Scenario: AI classification when rules do not conclude
- **WHEN** the deterministic classifier returns an ambiguous result or the message is an open answer
- **THEN** the AI classifier MAY be invoked
- **AND** the resulting category SHALL be one of the supported categories.

#### Scenario: Unsupported message type
- **WHEN** the incoming message carries media or an unsupported type
- **THEN** the classification SHALL be `media_or_unsupported`
- **AND** no extraction SHALL be attempted.

#### Scenario: Single classification per version and purpose
- **WHEN** the same message is classified again with the same purpose, prompt version and schema version
- **THEN** no duplicate classification SHALL be created.

### Requirement: Structured Insight Extraction
The system SHALL extract a searchable insight from answers to the survey question, containing conversation, contact, source message, flow, question, question snapshot, summary, main topic, secondary topics, identified problem, suggested action, desired result, affected group, declared locality text, normalized locality when unambiguous, region, urgency, descriptive sentiment, keywords, confidence, human review flag and extraction version.

#### Scenario: Extracting from an answer
- **WHEN** a message is classified as `question_answer`
- **THEN** an insight SHALL be extracted and persisted
- **AND** the insight SHALL reference the original message without modifying it.

#### Scenario: Idempotent extraction
- **WHEN** the extraction runs again for the same source message and extraction version
- **THEN** the existing insight SHALL be updated in place
- **AND** no duplicate insight SHALL be created.

#### Scenario: No inference of sensitive attributes
- **WHEN** an insight is produced
- **THEN** it SHALL NOT contain inferred income, religion, race, health, political orientation, voting intention or any characteristic not declared by the person
- **AND** the schema SHALL NOT provide a field for such attributes.

#### Scenario: No guessed locality
- **WHEN** the person does not state a locality
- **THEN** the locality fields SHALL remain empty
- **AND** the system SHALL NOT infer a city from area code, name or any other signal.

#### Scenario: Descriptive sentiment only
- **WHEN** sentiment is recorded
- **THEN** it SHALL be descriptive of the expressed content
- **AND** it SHALL NOT be used to rank, target or persuade an individual.

### Requirement: Administrative Topic Taxonomy
The system SHALL provide administration of topics and subtopics with name, slug, optional parent, synonyms, active state, display order, interface colour and a mandatory fallback topic.

#### Scenario: Managing topics
- **WHEN** an authorized user creates, edits or reorders a topic
- **THEN** the change SHALL be persisted and audited.

#### Scenario: Deleting a used topic
- **WHEN** a user attempts to delete a topic already referenced by an insight
- **THEN** the deletion SHALL be refused
- **AND** the user SHALL be informed that the topic is in use.

#### Scenario: Fallback topic is protected
- **WHEN** a user attempts to delete or deactivate the fallback topic
- **THEN** the operation SHALL be refused.

#### Scenario: Mapping free model output
- **WHEN** the model returns a topic string
- **THEN** the string SHALL be matched deterministically against slug, name and synonyms of registered topics
- **AND** an unmatched string SHALL be mapped to the fallback topic
- **AND** the original string SHALL be preserved for audit.

#### Scenario: Model never creates taxonomy
- **WHEN** the model returns an unknown topic
- **THEN** no topic SHALL be created automatically.

### Requirement: Interpretation Pipeline
The system SHALL process incoming messages through an asynchronous pipeline that applies the automation guard, runs the deterministic classifier, optionally runs AI classification, validates the response, extracts an insight for answers, persists the result, updates the flow stage and flags human review, without sending any generated reply.

#### Scenario: Message persisted before analysis
- **WHEN** an incoming message arrives
- **THEN** it SHALL be persisted first
- **AND** no AI call SHALL occur inside the transaction that registers the message.

#### Scenario: Dedicated queue
- **WHEN** interpretation is dispatched
- **THEN** it SHALL run on its own queue
- **AND** it SHALL NOT block the incoming message queue or the conversational automation queue.

#### Scenario: No generated reply
- **WHEN** the pipeline completes for any classification
- **THEN** no automatic message text produced by AI SHALL be created or sent.

#### Scenario: Guard blocks interpretation
- **WHEN** automation or interpretation is disabled, the conversation is paused, the contact is inactive or marked as do not contact
- **THEN** no AI call SHALL be made
- **AND** the reason SHALL be recorded.

#### Scenario: Reprocessing is safe under concurrency
- **WHEN** the same message is dispatched to two workers
- **THEN** a lock SHALL serialize the work
- **AND** the persisted result SHALL be identical to a single execution.

### Requirement: Minimal Model Context
The prompt SHALL contain only the selected question, the current message truncated to the configured limit, at most the configured number of immediately preceding messages from the same conversation, the registered taxonomy and the instructions.

#### Scenario: No third party data
- **WHEN** the prompt is assembled
- **THEN** it SHALL NOT contain the contact base, messages from other conversations, contact name, phone number, tags or campaign history.

#### Scenario: Input truncation
- **WHEN** the message exceeds the configured input limit
- **THEN** it SHALL be truncated before being sent
- **AND** the stored original message SHALL remain complete.

### Requirement: Versioned Prompts
System prompts SHALL be stored as versioned files, separated by purpose, with exactly one active version per purpose and support for reprocessing with a different version.

#### Scenario: Active version
- **WHEN** the pipeline builds a request
- **THEN** it SHALL use the active version configured for that purpose
- **AND** the version SHALL be recorded on the run and on the result.

#### Scenario: Prompt safety instructions
- **WHEN** a prompt is authored
- **THEN** it SHALL instruct the model not to produce political opinion, not to persuade, not to infer voting intention and to answer strictly with the structured schema.

#### Scenario: Reprocessing with a new version
- **WHEN** an item is reprocessed with a different prompt version
- **THEN** a new result SHALL be produced for that version
- **AND** the previous version result SHALL remain available as history.

### Requirement: Confidence and Human Review
The system SHALL apply configurable confidence thresholds and deterministic routing rules that send an item to human review, always recording a readable reason.

#### Scenario: Low confidence
- **WHEN** the reported confidence is below the configured threshold for that purpose
- **THEN** the item SHALL be flagged for human review with a low confidence reason
- **AND** the conversation SHALL be marked as waiting for human handling.

#### Scenario: Sensitive situations
- **WHEN** the original text matches configured expressions for report of wrongdoing, threat, personal request, named accusation, sensitive legal content, promise request, individual urgency or risk
- **THEN** the item SHALL be flagged for human review
- **AND** the detection SHALL run independently of the AI result, including when the AI call failed.

#### Scenario: Review reason is visible
- **WHEN** an item awaits review
- **THEN** the interface SHALL display the reason for the review.

### Requirement: Audited Human Correction
An operator SHALL be able to correct classification, topic and insight fields, and every correction SHALL record the original value, the corrected value, the responsible user, an optional reason and the date.

#### Scenario: Correcting a result
- **WHEN** an authorized operator corrects a field
- **THEN** the current insight SHALL be updated
- **AND** a correction record SHALL preserve the original value.

#### Scenario: No automatic learning
- **WHEN** corrections accumulate
- **THEN** they SHALL NOT be used to train, tune or seed prompts automatically
- **AND** promoting corrections SHALL require an explicit versioned prompt change.

#### Scenario: Permission required
- **WHEN** a user without the correction permission attempts to correct a result
- **THEN** the request SHALL be denied.

### Requirement: Interpretation Privacy Controls
The system SHALL separate operational identification from analytical content, mask phone numbers on analytical screens for users without the specific permission, offer anonymization for reports, apply a configurable retention to AI runs and keep personal data out of technical logs.

#### Scenario: Masked phone on analytical screens
- **WHEN** a user without the sensitive contact data permission opens an analytical screen
- **THEN** the phone number SHALL be masked.

#### Scenario: Retention of runs
- **WHEN** the retention command runs
- **THEN** AI runs older than the configured retention SHALL be removed
- **AND** the persisted insights and original messages SHALL remain intact.

#### Scenario: Logs without personal data
- **WHEN** an interpretation succeeds or fails
- **THEN** the technical log SHALL contain identifiers and codes only
- **AND** it SHALL NOT contain credentials, full phone numbers or message bodies.

### Requirement: Interpretation Interface
The system SHALL provide a panel on the conversation showing classification, confidence, summary and topics with an explicit indication of AI generated content, a review queue, manual correction, authorized reprocessing and version history, each protected by its own permission.

#### Scenario: AI content is labelled
- **WHEN** interpretation results are displayed
- **THEN** they SHALL be visually identified as generated by artificial intelligence.

#### Scenario: Review queue
- **WHEN** an operator opens the review queue
- **THEN** items awaiting review SHALL be listed with their reason
- **AND** the list SHALL be filterable.

#### Scenario: Authorized reprocessing
- **WHEN** a user with the reprocessing permission requests reprocessing of an item
- **THEN** a new run SHALL be dispatched
- **AND** the action SHALL be audited.

#### Scenario: Version history
- **WHEN** an item has results from more than one version
- **THEN** the interface SHALL list the versions and allow reading each result.

### Requirement: Interpretation Monitoring
The system SHALL expose monitoring of run volume, success and failure counts, latency, low confidence volume, items awaiting review, failures by provider and stuck jobs.

#### Scenario: Viewing metrics
- **WHEN** an authorized user opens interpretation monitoring
- **THEN** the metrics SHALL be presented for a selectable period.

#### Scenario: Stuck executions
- **WHEN** a run stays in a running state beyond the configured limit
- **THEN** it SHALL be listed as stuck.

### Requirement: Safe Reprocessing Commands
The system SHALL provide commands to reprocess interpretation by identifier or period and to apply run retention, requiring at least one filter and explicit confirmation for large ranges.

#### Scenario: Reprocessing by filter
- **WHEN** the reprocessing command is invoked with a message, conversation or date range
- **THEN** only the matching items SHALL be dispatched.

#### Scenario: Refusing an unbounded run
- **WHEN** the reprocessing command is invoked with no filter
- **THEN** it SHALL refuse to run.

#### Scenario: Confirmation for large ranges
- **WHEN** the matched volume exceeds the configured confirmation threshold
- **THEN** the command SHALL require explicit confirmation before dispatching.
