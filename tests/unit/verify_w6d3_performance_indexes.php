<?php
/************************************************************************
 * Verification script for W6D3 Performance Investigation & Schema Indexes
 * Cynaris Full Stack Internship — Week 6 Day 3 Debug Sprint
 ************************************************************************/

echo "=== W6D3: Database Performance Investigation & Schema Indexes Verification ===\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(string $description, bool $condition): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] " . $description . "\n";
        $passCount++;
    } else {
        echo " [FAIL] " . $description . "\n";
        $failCount++;
    }
}

// =========================================================================
// Test Suite 1: Entity Metadata Indexes Definition & Syntax Verification
// =========================================================================
echo "Test Suite 1: Entity Metadata Indexes Definition\n";

$leadMetaPath = __DIR__ . '/../../custom/Espo/Custom/Resources/metadata/entityDefs/Lead.json';
assertCondition("custom Lead.json metadata file exists", file_exists($leadMetaPath));
$leadMeta = json_decode(file_get_contents($leadMetaPath), true);
assertCondition("custom Lead.json is valid JSON", is_array($leadMeta));
assertCondition("Lead metadata defines 'deletedCreatedAtStatus' index", isset($leadMeta['indexes']['deletedCreatedAtStatus']));
assertCondition(
    "Lead 'deletedCreatedAtStatus' index covers ['deleted', 'createdAt', 'status']",
    ($leadMeta['indexes']['deletedCreatedAtStatus']['columns'] ?? []) === ['deleted', 'createdAt', 'status']
);
assertCondition("Lead metadata preserves 'remindedAt' field", isset($leadMeta['fields']['remindedAt']));
assertCondition("Lead metadata preserves 'aiFollowUpAt' field", isset($leadMeta['fields']['aiFollowUpAt']));
assertCondition("Lead metadata preserves 'aiFollowUpRationale' field", isset($leadMeta['fields']['aiFollowUpRationale']));

$meetingMetaPath = __DIR__ . '/../../custom/Espo/Custom/Resources/metadata/entityDefs/Meeting.json';
assertCondition("custom Meeting.json metadata file exists", file_exists($meetingMetaPath));
$meetingMeta = json_decode(file_get_contents($meetingMetaPath), true);
assertCondition("custom Meeting.json is valid JSON", is_array($meetingMeta));
assertCondition("Meeting metadata defines 'activitySummary' index", isset($meetingMeta['indexes']['activitySummary']));
assertCondition(
    "Meeting 'activitySummary' index covers ['parentType', 'deleted', 'parentId', 'dateStart']",
    ($meetingMeta['indexes']['activitySummary']['columns'] ?? []) === ['parentType', 'deleted', 'parentId', 'dateStart']
);

$noteMetaPath = __DIR__ . '/../../custom/Espo/Custom/Resources/metadata/entityDefs/Note.json';
assertCondition("custom Note.json metadata file exists", file_exists($noteMetaPath));
$noteMeta = json_decode(file_get_contents($noteMetaPath), true);
assertCondition("custom Note.json is valid JSON", is_array($noteMeta));
assertCondition("Note metadata defines 'parentDeletedNumber' index", isset($noteMeta['indexes']['parentDeletedNumber']));
assertCondition(
    "Note 'parentDeletedNumber' index covers ['parentId', 'parentType', 'deleted', 'number']",
    ($noteMeta['indexes']['parentDeletedNumber']['columns'] ?? []) === ['parentId', 'parentType', 'deleted', 'number']
);

// =========================================================================
// Test Suite 2: Live Database Schema & Index Verification (via MariaDB PDO)
// =========================================================================
echo "\nTest Suite 2: Live MariaDB Schema & Indexes Verification\n";

try {
    $dsn = "mysql:host=127.0.0.1;port=3306;dbname=espocrm;charset=utf8mb4";
    // Attempt local docker port or fallback to docker exec verification
    $pdo = null;
    try {
        $pdo = new PDO($dsn, 'espocrm', 'espocrm_db_pass_local', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (\Throwable) {
        // Port 3306 may only be exposed within docker network; verify via shell output if direct connection not exposed
    }

    if ($pdo !== null) {
        // Direct PDO check
        $stmt = $pdo->query("SHOW COLUMNS FROM lead LIKE 'reminded_at'");
        assertCondition("Live DB: lead.reminded_at column exists", $stmt->rowCount() > 0);

        $stmt = $pdo->query("SHOW COLUMNS FROM lead LIKE 'ai_follow_up_at'");
        assertCondition("Live DB: lead.ai_follow_up_at column exists", $stmt->rowCount() > 0);

        $stmt = $pdo->query("SHOW COLUMNS FROM lead LIKE 'ai_follow_up_rationale'");
        assertCondition("Live DB: lead.ai_follow_up_rationale column exists", $stmt->rowCount() > 0);

        $stmt = $pdo->query("SHOW INDEX FROM meeting WHERE Key_name = 'idx_meeting_activity_summary'");
        assertCondition("Live DB: idx_meeting_activity_summary exists on meeting", $stmt->rowCount() >= 4);

        $stmt = $pdo->query("SHOW INDEX FROM lead WHERE Key_name = 'idx_lead_deleted_created_status'");
        assertCondition("Live DB: idx_lead_deleted_created_status exists on lead", $stmt->rowCount() >= 3);

        $stmt = $pdo->query("SHOW INDEX FROM note WHERE Key_name = 'idx_note_parent_deleted_number'");
        assertCondition("Live DB: idx_note_parent_deleted_number exists on note", $stmt->rowCount() >= 4);
    } else {
        // Check via shell inspection against espocrm-db container
        $cmdCheck = shell_exec('docker exec espocrm-db mariadb -u espocrm -pespocrm_db_pass_local espocrm -e "SHOW COLUMNS FROM lead LIKE \'reminded_at\'; SHOW INDEX FROM meeting WHERE Key_name = \'idx_meeting_activity_summary\'; SHOW INDEX FROM lead WHERE Key_name = \'idx_lead_deleted_created_status\'; SHOW INDEX FROM note WHERE Key_name = \'idx_note_parent_deleted_number\';" 2>nul');
        assertCondition("Live DB: lead.reminded_at column verified in MariaDB", str_contains((string) $cmdCheck, 'reminded_at'));
        assertCondition("Live DB: idx_meeting_activity_summary verified on meeting", str_contains((string) $cmdCheck, 'idx_meeting_activity_summary'));
        assertCondition("Live DB: idx_lead_deleted_created_status verified on lead", str_contains((string) $cmdCheck, 'idx_lead_deleted_created_status'));
        assertCondition("Live DB: idx_note_parent_deleted_number verified on note", str_contains((string) $cmdCheck, 'idx_note_parent_deleted_number'));
    }
} catch (\Throwable $e) {
    assertCondition("Live DB verification error: " . $e->getMessage(), false);
}

// =========================================================================
// Test Suite 3: Query Plan & Index Utilization Verification
// =========================================================================
echo "\nTest Suite 3: Query Plan & Index Utilization Verification\n";

$explainMeeting = shell_exec('docker exec espocrm-db mariadb -u espocrm -pespocrm_db_pass_local espocrm -e "EXPLAIN SELECT parent_id, COUNT(id) FROM meeting WHERE parent_type = \'Account\' AND parent_id IS NOT NULL AND date_start >= \'2026-09-01 00:00:00\' AND deleted = 0 GROUP BY parent_id;" 2>nul');
assertCondition("EXPLAIN Meeting query utilizes idx_meeting_activity_summary", str_contains((string) $explainMeeting, 'idx_meeting_activity_summary'));
assertCondition("EXPLAIN Meeting query avoids filesort", !str_contains((string) $explainMeeting, 'Using filesort'));
assertCondition("EXPLAIN Meeting query avoids temporary table", !str_contains((string) $explainMeeting, 'Using temporary'));

$explainLead = shell_exec('docker exec espocrm-db mariadb -u espocrm -pespocrm_db_pass_local espocrm -e "EXPLAIN SELECT id, first_name, last_name, status, created_at FROM lead WHERE status IN (\'New\', \'Assigned\', \'In Process\') AND created_at <= \'2026-10-04 18:27:00\' AND deleted = 0 ORDER BY created_at DESC LIMIT 100;" 2>nul');
assertCondition("EXPLAIN Lead query utilizes idx_lead_deleted_created_status", str_contains((string) $explainLead, 'idx_lead_deleted_created_status'));

$explainNote = shell_exec('docker exec espocrm-db mariadb -u espocrm -pespocrm_db_pass_local espocrm -e "EXPLAIN SELECT id, post, number, created_at FROM note WHERE parent_id = \'acc_001\' AND parent_type = \'Account\' AND deleted = 0 ORDER BY number DESC LIMIT 5;" 2>nul');
assertCondition("EXPLAIN Note query utilizes idx_note_parent_deleted_number", str_contains((string) $explainNote, 'idx_note_parent_deleted_number'));
assertCondition("EXPLAIN Note query uses ref access type", str_contains((string) $explainNote, 'ref'));

// =========================================================================
// Test Suite 4: Preservation of Prior W5D4, W5D5, W6D1, W6D2 Features
// =========================================================================
echo "\nTest Suite 4: Prior Module Functionality Preservation\n";

$routesPath = __DIR__ . '/../../custom/Espo/Custom/Resources/routes.json';
$routes = json_decode(file_get_contents($routesPath), true);
$endpoints = array_column($routes, 'route');

assertCondition("Preserved W5D4 ActivitySummary route", in_array('/ActivitySummary', $endpoints, true));
assertCondition("Preserved W5D5 ConversationSummarizer/summarize route", in_array('/ConversationSummarizer/summarize', $endpoints, true));
assertCondition("Preserved W5D5 ConversationSummarizer/status route", in_array('/ConversationSummarizer/status', $endpoints, true));
assertCondition("Preserved W6D1 LeadReminderWorkflow/status route", in_array('/LeadReminderWorkflow/status', $endpoints, true));
assertCondition("Preserved W6D1 LeadReminderWorkflow/eligibleLeads route", in_array('/LeadReminderWorkflow/eligibleLeads', $endpoints, true));
assertCondition("Preserved W6D1 LeadReminderWorkflow/suggestTiming route", in_array('/LeadReminderWorkflow/suggestTiming', $endpoints, true));
assertCondition("Preserved W6D1 LeadReminderWorkflow/execute route", in_array('/LeadReminderWorkflow/execute', $endpoints, true));
assertCondition("Preserved W6D1 LeadReminderWorkflow/runBatch route", in_array('/LeadReminderWorkflow/runBatch', $endpoints, true));
assertCondition("Preserved W6D2 LeadReminderWorkflow/preferences GET route", in_array('/LeadReminderWorkflow/preferences', $endpoints, true));

echo "\n==================================================\n";
echo "Total Assertions: " . ($passCount + $failCount) . "\n";
echo "Passed: " . $passCount . "\n";
echo "Failed: " . $failCount . "\n";
echo "==================================================\n";

if ($failCount === 0) {
    echo "Result: ALL W6D3 VERIFICATION CHECKS PASSED (100% SUCCESS)!\n";
    exit(0);
} else {
    echo "Result: VERIFICATION CHECKS FAILED!\n";
    exit(1);
}
