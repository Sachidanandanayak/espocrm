# CHANGELOG — Cynaris EspoCRM Sprint

All notable changes across the Cynaris Internship EspoCRM modules and debug sprints are documented in this file.

---

## [W6D3] - 2026-10-07 / 2026-10-08

### 🚀 What Was Built
* **Database Performance Benchmarking Suite:**
  - Evaluated and benchmarked 3 slow/relevant EspoCRM database queries from custom internship modules (`ActivitySummaryService`, `LeadReminderWorkflowService`, `ConversationSummarizerService`).
  - Implemented empirical EXPLAIN and MariaDB session profiling harness with realistic 3,000+ row entity datasets to capture authentic query plans and timings.
* **Justified Composite Indexes Added:**
  - `meeting (parent_type, deleted, parent_id, date_start)` registered as `activitySummary` in `custom/Espo/Custom/Resources/metadata/entityDefs/Meeting.json` and active in MariaDB as `idx_meeting_activity_summary`.
  - `lead (deleted, created_at, status)` registered as `deletedCreatedAtStatus` in `custom/Espo/Custom/Resources/metadata/entityDefs/Lead.json` and active in MariaDB as `idx_lead_deleted_created_status`.
  - `note (parent_id, parent_type, deleted, number)` registered as `parentDeletedNumber` in `custom/Espo/Custom/Resources/metadata/entityDefs/Note.json` and active in MariaDB as `idx_note_parent_deleted_number`.
* **W6D3 Automated Test Suite (`tests/unit/verify_w6d3_performance_indexes.php`):**
  - Added 34 automated unit assertions validating metadata index definitions, live MariaDB column existence, live database index verification, query plan optimization, and route preservation.
* **W6D3 Performance Investigation Documentation (`W6D3_PERFORMANCE_INVESTIGATION.md`):**
  - Comprehensive report recording root cause analyses, before/after EXPLAIN query plans, MariaDB session profiling timings, index justifications, and PR review limitations.

### 🐛 What Was Fixed
* **Fixed Issue 1: Database Column Desynchronization on Custom Lead Fields:**
  - **Problem:** Direct SQL and strict ORM operations selecting or updating `reminded_at`, `ai_follow_up_at`, or `ai_follow_up_rationale` failed with MariaDB error `1054 (42S22): Unknown column 'reminded_at' in 'field list'` because metadata declarations in `Lead.json` had not been physically synchronized to table `lead` in MariaDB.
  - **Resolution:** Executed physical schema synchronization in MariaDB creating `reminded_at datetime`, `ai_follow_up_at datetime`, and `ai_follow_up_rationale mediumtext` columns with appropriate nullability and defaults.
* **Fixed Issue 2: Temporary Table and Filesort Query Degradation on Activity Summary Aggregation:**
  - **Problem:** `ActivitySummaryService::getMeetingCounts` query triggered a full table scan (`type: ALL`, 3,002 rows scanned) and required an internal temporary table and filesort (`Using temporary; Using filesort`) due to lack of a composite index supporting both the `date_start` filter and `GROUP BY parent_id`.
  - **Resolution:** Introduced composite index `idx_meeting_activity_summary (parent_type, deleted, parent_id, date_start)`. Converted the plan to a covering index range scan (`type: range`), completely eliminating temporary table materialization and filesort, reducing latency from 4.12 ms to 2.89 ms (-30%).
* **Fixed Issue 3: Inefficient Inactivity Filter and Table Lookups on Uncontacted Lead Scans:**
  - **Problem:** `LeadReminderWorkflowService::findEligibleLeads` scanned `created_at` but was unable to filter `status IN (...)` at the index level, reading hundreds of rows from table storage.
  - **Resolution:** Introduced composite index `idx_lead_deleted_created_status (deleted, created_at, status)`. Enabled direct index-level evaluation of `status` while preserving reverse `created_at` ordering, reducing latency from 4.22 ms to 0.94 ms (-78%, >4.5x faster).
* **Fixed Issue 4: Full Index Traversal and Low Selectivity on Conversation Stream Notes:**
  - **Problem:** `ConversationSummarizerService::fetchEntityNotes` performed reverse scans on `UNIQ_NUMBER` reading 141 rows with only 3.55% selectivity to find 5 matching notes for an entity.
  - **Resolution:** Introduced composite index `idx_note_parent_deleted_number (parent_id, parent_type, deleted, number)`. Reduced rows read to exactly 5 (100% selectivity) with `type: ref`, reducing latency from 2.50 ms to 1.19 ms (-52%, >2.1x faster).

### ⚠️ What Remains as Known Issues
* **Upstream PR Contributor Access Restriction:** Upstream repository `espocrm/espocrm` pull request creation is restricted for external contributor accounts; verified via GitHub API that no upstream PRs exist for the account, and all commits remain local to branch `feat/w6d3-3m-sachidananda`.
* **Docker Container Volume Cache Invalidation:** When deploying new entity definition metadata to pre-existing Docker volume containers without restarting, `php rebuild.php` or explicit schema migration scripts must be executed to ensure ORM metadata caches match live MariaDB indexes.
* **High-Volume Production Online DDL Considerations:** Adding compound indexes on tables exceeding 10M rows in production should utilize `ALGORITHM=INPLACE, LOCK=NONE` to prevent table locks during peak transaction windows.

---

## [W6D2] - 2026-10-06

### 🚀 What Was Built
* **Smart Reminders Frontend Dashlet (`client/custom/src/views/dashlets/smart-reminders.js`):** Interactive dashlet for monitoring eligible leads, running AI timing suggestions, and triggering batch reminder dispatches.
* **User Preferences Extension:** Added user-level follow-up customization fields (`smartReminderEnabled`, `smartReminderThresholdDays`, `smartReminderPreferredChannel`, `smartReminderWorkingHoursOnly`, `smartReminderAutoExecute`, `smartReminderGroqModel`) in `custom/Espo/Custom/Resources/metadata/entityDefs/Preferences.json`.
* **REST API Preferences Endpoints:** Registered `GET` and `POST` `/api/v1/LeadReminderWorkflow/preferences`.
* **W6D2 Automated Test Suite:** 68 passing assertions in `tests/unit/verify_smart_reminders_frontend_preferences.php`.

---

## [W6D1] - 2026-10-05

### 🚀 What Was Built
* **Smart Reminders Automated Workflow Service (`LeadReminderWorkflowService.php`):** Daily scheduled rule evaluating uncontacted leads (3-day inactivity threshold).
* **Groq LLM Timing Engine with Resilient Simulation Fallback:** Dynamic follow-up scheduling avoiding weekends with industry-specific business hours.
* **Scheduled Job Class (`LeadReminderWorkflow.php` in Jobs):** CRM cron worker.
* **W6D1 Automated Test Suites:** 75 service assertions and 45 API controller assertions.

---

## [W5D5] - 2026-10-02

### 🚀 What Was Built
* **AI Conversation Summarizer Service (`ConversationSummarizerService.php`):** Context extraction from Meetings, Calls, Opportunities, Leads, Accounts, and Stream Notes with Groq LLM summarization.
* **Conversation Summarizer Dashlet (`conversation-summarizer.js`):** Frontend interface with quick sentiment indicators.
* **W5D5 Automated Test Suites:** 76 total unit and API assertions.

---

## [W5D4] - 2026-10-01

### 🚀 What Was Built
* **Activity Summary Service (`ActivitySummaryService.php`):** Multi-activity reporting engine grouping Meetings, Calls, Tasks, and Emails per Account.
* **Activity Summary Dashlet (`activity-summary.js`):** Graphical visualization of CRM engagement.
* **W5D4 Automated Test Suites:** 38 total unit and API assertions.
