## ADDED Requirements

### Requirement: Knowledge Provider Abstractions
The system SHALL access knowledge storage, embeddings, retrieval and grounding validation through vendor independent contracts, with the active implementation resolved from configuration and an inert implementation available for environments without credentials.

#### Scenario: Resolving the provider from configuration
- **WHEN** the knowledge subsystem is used
- **THEN** the knowledge base provider, the embedding provider and the retrieval strategy SHALL be resolved from configuration
- **AND** no provider name, endpoint, credential, model or limit SHALL be hardcoded in application logic.

#### Scenario: Inert provider without credentials
- **WHEN** no knowledge provider is configured
- **THEN** indexing SHALL fail with a sanitized error and the document SHALL NOT become retrievable
- **AND** retrieval SHALL return an empty set without raising an unhandled error.

#### Scenario: External identifiers are persisted
- **WHEN** a provider assigns an external store, file or chunk identifier
- **THEN** that identifier SHALL be persisted alongside the base, document or chunk.

#### Scenario: Provider swap without changing callers
- **WHEN** the configured knowledge provider changes
- **THEN** ingestion, retrieval and generation SHALL continue to work through the same contracts
- **AND** no caller SHALL depend on the storage implementation.

#### Scenario: Tests never call a real provider
- **WHEN** the automated test suite runs
- **THEN** every knowledge provider and embedding provider interaction SHALL use a fake or a faked HTTP layer
- **AND** no real external request SHALL be issued.

### Requirement: Knowledge Base Administration
The system SHALL provide administration of knowledge bases with name, description, purpose, status, version, provider, external store identifier, usage policy, approving user and timestamps.

#### Scenario: Creating a base
- **WHEN** an authorized user creates a knowledge base
- **THEN** the base SHALL be stored with its provider, purpose, version and usage policy
- **AND** the base SHALL NOT be usable for retrieval until it is active.

#### Scenario: Logical isolation between bases
- **WHEN** retrieval runs for a given base
- **THEN** only chunks belonging to that base SHALL be considered
- **AND** content from another base SHALL NOT be returned.

#### Scenario: Associating a base with a flow
- **WHEN** a knowledge base is associated with a conversational flow
- **THEN** retrieval for conversations of that flow SHALL consider that base
- **AND** a flow without any associated active base SHALL produce no retrieval.

#### Scenario: Deleting a base synchronizes the provider
- **WHEN** an authorized user deletes a knowledge base
- **THEN** the corresponding external store SHALL be removed through the provider
- **AND** retrieval logs and citations already recorded SHALL remain readable.

### Requirement: Knowledge Document Administration
The system SHALL store documents with title, type, source, document date, private file reference, content hash, status, version, metadata, author, approver, provider file identifier, sanitized error message and timestamps.

#### Scenario: Document lifecycle states
- **WHEN** a document exists
- **THEN** its status SHALL be one of draft, processing, ready, approved, rejected, obsolete or failed.

#### Scenario: Allowed content types
- **WHEN** a document is registered
- **THEN** its type SHALL be one of approved biography, public history, institutional competence, approved proposal, official published position, authorized public agenda, frequently asked questions or contact channels.

#### Scenario: Forbidden content is not admissible
- **WHEN** content would consist of rumour, unapproved material, private personal data, confidential electoral strategy, another contact's conversation, vote inference, opponent information used for attack or an unformalized promise
- **THEN** the system SHALL NOT provide a document type for it
- **AND** such content SHALL NOT be retrievable.

#### Scenario: Sanitized error message
- **WHEN** ingestion or indexing fails
- **THEN** the stored error SHALL identify the failure by an operational code
- **AND** it SHALL NOT contain credentials, secret headers or full file contents.

### Requirement: Document Ingestion
The system SHALL ingest documents through private upload with MIME and size validation, hashing, deduplication, antivirus verification, text extraction, configurable chunking, page and section metadata when available, indexing in a dedicated queue, reprocessing, synchronized deletion and mandatory human approval before availability.

#### Scenario: Private storage outside the public directory
- **WHEN** a file is uploaded
- **THEN** it SHALL be stored on a private disk outside the publicly served directory
- **AND** the stored filename SHALL be normalized so that the original name cannot traverse directories.

#### Scenario: Invalid MIME type
- **WHEN** a file whose type is not accepted is uploaded
- **THEN** the upload SHALL be rejected with a validation error
- **AND** no document record SHALL be created.

#### Scenario: Size limit
- **WHEN** a file larger than the configured maximum size is uploaded
- **THEN** the upload SHALL be rejected.

#### Scenario: Duplicate document
- **WHEN** a file whose content hash already exists in the same base is uploaded
- **THEN** the upload SHALL be refused as a duplicate
- **AND** the existing document SHALL be identified to the user.

#### Scenario: Antivirus verification
- **WHEN** a file is uploaded and antivirus verification is required
- **THEN** the file SHALL be scanned before extraction
- **AND** an infected file SHALL be rejected and removed
- **AND** an unavailable scanner SHALL refuse the upload while verification is required.

#### Scenario: Indexing runs in its own queue
- **WHEN** a document is accepted
- **THEN** extraction, chunking and indexing SHALL run asynchronously in a queue separate from message intake, interpretation and reply generation.

#### Scenario: Indexing failure
- **WHEN** extraction or indexing fails
- **THEN** the document SHALL move to the failed status with a sanitized error
- **AND** it SHALL NOT become retrievable
- **AND** reprocessing SHALL be available.

#### Scenario: Unavailable extractor
- **WHEN** the configured extractor for a document format is not available in the environment
- **THEN** the document SHALL fail with an explicit operational code
- **AND** the system SHALL NOT attempt a partial or improvised extraction.

#### Scenario: Chunking metadata
- **WHEN** a document is chunked
- **THEN** the chunk size and overlap SHALL come from configuration
- **AND** page and section metadata SHALL be recorded when the format provides them.

#### Scenario: Approval is required before availability
- **WHEN** a document finishes indexing successfully
- **THEN** it SHALL reach the ready status and SHALL NOT be retrievable
- **AND** only an explicit human approval SHALL make it retrievable.

#### Scenario: Reprocessing
- **WHEN** an authorized user reprocesses a document
- **THEN** its chunks SHALL be rebuilt
- **AND** the document SHALL require approval again before becoming retrievable.

#### Scenario: Synchronized deletion
- **WHEN** a document is deleted
- **THEN** its chunks and its private file SHALL be removed and the deletion SHALL be propagated to the provider
- **AND** previously recorded retrieval snapshots and citations SHALL remain readable.

### Requirement: Prompt Injection Defence In Documents
Retrieved document content SHALL be treated as data and never as instructions, and embedded instructions SHALL be neutralized at ingestion and recorded.

#### Scenario: Instruction embedded in a document
- **WHEN** ingested text matches a configured instruction injection pattern
- **THEN** the matching content SHALL be neutralized or isolated before chunking
- **AND** the document SHALL be flagged and the detection SHALL be recorded.

#### Scenario: Reviewer sees the detection
- **WHEN** a flagged document is reviewed for approval
- **THEN** the detection SHALL be displayed to the reviewer.

#### Scenario: Retrieved content is delimited as reference material
- **WHEN** retrieved chunks are placed in the prompt
- **THEN** they SHALL be enclosed in a delimited block declared as reference data
- **AND** the prompt SHALL state that instructions inside that block are not to be obeyed.

#### Scenario: The system prompt prevails
- **WHEN** document content conflicts with the system prompt
- **THEN** the system prompt SHALL prevail
- **AND** document content SHALL NOT change tools, secrets, policies, thresholds or configuration.

### Requirement: Knowledge Retrieval
Retrieval SHALL query only the bases associated with the conversation flow, filtered by approved status and current version, with configurable top count and threshold, a context limit, deduplication and a log of retrieved chunks.

#### Scenario: Only approved content participates
- **WHEN** retrieval runs
- **THEN** only chunks of approved documents SHALL be considered
- **AND** draft, processing, ready, rejected, obsolete and failed documents SHALL be excluded.

#### Scenario: Obsolete document is not retrieved
- **WHEN** a document is marked obsolete
- **THEN** it SHALL stop being retrieved immediately
- **AND** its previous citations SHALL remain readable.

#### Scenario: Threshold and top count
- **WHEN** retrieval runs
- **THEN** the number of returned chunks SHALL respect the configured top count
- **AND** chunks scoring below the configured threshold SHALL be discarded.

#### Scenario: Context limit and deduplication
- **WHEN** retrieved chunks exceed the configured context limit or repeat the same content
- **THEN** the excess SHALL be dropped and duplicates SHALL be removed before the prompt is assembled.

#### Scenario: Empty retrieval
- **WHEN** no chunk reaches the threshold
- **THEN** the retrieval SHALL return an empty set
- **AND** no factual answer SHALL be produced from it.

#### Scenario: Retrieval log
- **WHEN** retrieval runs
- **THEN** the query, strategy, parameters, candidate count, returned count and scores SHALL be recorded
- **AND** each returned chunk SHALL be recorded with its document, version, page, section, score and a content snapshot.

#### Scenario: Official context is separated from conversation context
- **WHEN** the prompt is assembled
- **THEN** the official retrieved content and the conversation content SHALL appear in distinct labelled blocks.

#### Scenario: No third party conversation as a source
- **WHEN** retrieval runs
- **THEN** it SHALL query only the knowledge chunk store
- **AND** it SHALL NOT read conversations, messages, contacts or citizen opinion records.

#### Scenario: Citizen opinions are never a source for answers
- **WHEN** a factual answer is produced
- **THEN** its evidence SHALL come exclusively from approved knowledge documents
- **AND** the population opinion database SHALL NOT be used as a source.

#### Scenario: Retrieval failure does not interrupt message intake
- **WHEN** the retrieval or embedding provider is unavailable
- **THEN** incoming message persistence, the deterministic flow and the interpretation subphase SHALL be unaffected
- **AND** the behaviour SHALL fall back to human handling or the configured institutional text.

### Requirement: Vector Storage Limits
When embeddings are stored in the relational database, the system SHALL persist the vector dimension, bound the candidate set by an explicit configurable limit and refuse to degrade silently beyond it.

#### Scenario: Dimension mismatch
- **WHEN** a stored embedding has a dimension different from the configured one
- **THEN** it SHALL be ignored by vector search
- **AND** the inconsistency SHALL be reported by the diagnostic command.

#### Scenario: Candidate limit exceeded
- **WHEN** the candidate set for vector search exceeds the configured maximum
- **THEN** vector search SHALL be refused with a recorded reason
- **AND** retrieval SHALL fall back to the lexical strategy.

#### Scenario: Measured limits
- **WHEN** the test suite runs
- **THEN** a test SHALL exercise vector search against a synthetic corpus
- **AND** it SHALL fail if the documented limits are not respected.

### Requirement: Grounded Answer Generation
When an active approved base is associated with the flow, reply generation SHALL receive the contact question, the conversation context and the retrieved official excerpts, SHALL be instructed to answer only from those excerpts, and SHALL produce structured output containing a grounding flag and citations.

#### Scenario: Grounded structured output
- **WHEN** a grounded reply is generated
- **THEN** the structured output SHALL contain the action, reply text, a grounding flag, citations with document identifier and chunk reference, optional page and section, confidence and a human review flag.

#### Scenario: Dedicated versioned prompt and schema
- **WHEN** grounded generation runs
- **THEN** it SHALL use the prompt version and schema version configured for grounded generation
- **AND** the prompt and schema of the previous subphase SHALL remain unchanged.

#### Scenario: No gap filling from model knowledge
- **WHEN** the retrieved excerpts do not contain the answer
- **THEN** the model SHALL NOT complete the gap with general knowledge
- **AND** the system SHALL route to human handling or answer with the configured institutional text.

#### Scenario: Opinion is not fact
- **WHEN** the conversation context contains what the person stated
- **THEN** that content SHALL NOT be presented as an official position or as a fact about the world.

#### Scenario: Knowledge disabled
- **WHEN** knowledge retrieval is disabled
- **THEN** generation SHALL behave exactly as in the previous subphase
- **AND** the previous subphases SHALL remain fully functional.

### Requirement: Grounding Validation
Generated answers SHALL be validated deterministically after the model answers: factual statements SHALL require at least one piece of evidence, cited identifiers SHALL belong to the retrieved set, and numeric terms, dates, commitments and proposals SHALL have explicit support in the cited excerpts.

#### Scenario: Factual statement without evidence
- **WHEN** the generated text contains a factual statement and no citation
- **THEN** the answer SHALL be refused
- **AND** the conversation SHALL be routed to human handling.

#### Scenario: Citation outside the retrieved set
- **WHEN** a cited identifier does not belong to the set returned by retrieval
- **THEN** the answer SHALL be refused with a recorded reason.

#### Scenario: Citation of a non retrievable document
- **WHEN** a cited identifier points to a document that is not approved or is obsolete
- **THEN** the answer SHALL be refused.

#### Scenario: Unsupported number or date
- **WHEN** the generated text contains a number, value or date that does not appear in the cited excerpts
- **THEN** the answer SHALL be refused with a recorded reason.

#### Scenario: Unsupported commitment
- **WHEN** the generated text states a commitment, promise or proposal without explicit support in the cited excerpts
- **THEN** the answer SHALL be refused.

#### Scenario: Grounding flag without citations
- **WHEN** the model declares the answer grounded and provides no valid citation
- **THEN** the answer SHALL be refused.

#### Scenario: Refused answer is never sent
- **WHEN** grounding validation refuses an answer
- **THEN** no text SHALL be sent, neither automatically nor through human approval
- **AND** the refusal reason SHALL be recorded and displayed.

#### Scenario: Citations are internal by default
- **WHEN** a grounded reply is sent to the contact
- **THEN** citations SHALL NOT be appended to the message unless explicitly configured otherwise
- **AND** the administrative interface SHALL always display the sources used.

### Requirement: Document Versioning And Traceability
A new document version SHALL NOT overwrite previous traceability, a previous version SHALL be markable as obsolete, past answers SHALL preserve their references and inconsistent or unindexed documents SHALL be reportable.

#### Scenario: Superseding a document
- **WHEN** a new version of a document is approved
- **THEN** the previous version SHALL be marked obsolete and linked from the new one
- **AND** the previous version SHALL remain readable.

#### Scenario: Old answers keep their references
- **WHEN** a document is superseded or deleted
- **THEN** citations recorded in earlier suggestions SHALL remain readable with their content snapshot, document version, page and section.

#### Scenario: Inconsistency report
- **WHEN** the diagnostic command runs
- **THEN** it SHALL report approved documents without chunks, chunks without embeddings while vector search is active, documents stuck in processing, dimension mismatches and provider identifiers missing on either side.

### Requirement: Knowledge Administration Interface
The system SHALL provide administrative screens for bases, documents, status, approval, reprocessing, text and chunk preview, retrieval testing, answer testing without sending, visualization of the sources used in a suggestion, base to flow association and dedicated permissions.

#### Scenario: Previewing extracted text
- **WHEN** a reviewer opens a document
- **THEN** the extracted text and its chunks SHALL be viewable before approval.

#### Scenario: Testing retrieval
- **WHEN** an authorized user submits a test query
- **THEN** the matching excerpts, their documents and their scores SHALL be displayed
- **AND** nothing SHALL be sent to any contact.

#### Scenario: Testing an answer without sending
- **WHEN** an authorized user tests answer generation
- **THEN** the generated text, the grounding verdict and the citations SHALL be displayed
- **AND** no message SHALL be created or sent.

#### Scenario: Sources of a suggestion
- **WHEN** an operator opens a grounded suggestion
- **THEN** each excerpt used SHALL be displayed with its document, version, page and section.

#### Scenario: Authorized download only
- **WHEN** a user without the download permission requests a document file
- **THEN** the request SHALL be denied
- **AND** the file SHALL NOT be reachable through a public URL.

#### Scenario: Dedicated permissions
- **WHEN** knowledge screens and actions are used
- **THEN** viewing, managing bases, uploading, approving, deleting, downloading, testing retrieval and managing settings SHALL each require their own permission.

### Requirement: Knowledge Operations
The system SHALL provide synchronization and diagnostic commands, provider health, ingestion metrics, usage information when available, queue visibility, stuck document detection, cleanup, metadata backup, a provider swap procedure and a rollback to operating without retrieval.

#### Scenario: Synchronization command
- **WHEN** the synchronization command runs
- **THEN** it SHALL reconcile stored documents and chunks with the provider
- **AND** it SHALL report differences without deleting data unless explicitly instructed.

#### Scenario: Diagnostic command
- **WHEN** the diagnostic command runs
- **THEN** it SHALL report provider health, ingestion metrics, queue state, stuck documents and inconsistencies.

#### Scenario: Rollback without retrieval
- **WHEN** knowledge retrieval is disabled
- **THEN** the deterministic flow, the interpretation subphase and the reply generation subphase SHALL continue to operate unchanged.

#### Scenario: Auditing knowledge actions
- **WHEN** a base or document is created, updated, approved, rejected, reprocessed, obsoleted, downloaded or deleted
- **THEN** the action SHALL be recorded in the audit log with the responsible user
- **AND** no secret SHALL be written to logs.
