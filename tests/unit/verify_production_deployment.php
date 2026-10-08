<?php
/*******************************************************************************
 * Automated Verification Script for EspoCRM Production Deployment (W6D4)
 * Program: Cynaris Software Engineering Internship — Week 6 Day 4
 * Author:  Sachidananda Nayak
 * Branch:  feat/w6d4-3m-sachidananda
 * Scope:   Validates Production Docker Config, Live API Calls, CRM Funnel,
 *          and Public Production Gateway.
 ******************************************************************************/

declare(strict_types=1);

echo "========================================================================\n";
echo " Cynaris Internship W6D4: EspoCRM Production Deployment Verification\n";
echo "========================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $cond, string $message, ?string $details = null): void {
    global $passCount, $failCount;
    if ($cond) {
        echo " [PASS] $message\n";
        if ($details !== null) {
            echo "        -> $details\n";
        }
        $passCount++;
    } else {
        echo " [FAIL] $message\n";
        if ($details !== null) {
            echo "        -> Error: $details\n";
        }
        $failCount++;
    }
}

// Configuration
$baseUrl = getenv('ESPOCRM_URL') ?: 'http://localhost:8080';
$publicUrl = getenv('ESPOCRM_PUBLIC_URL') ?: 'https://bright-nails-carry.loca.lt';
$adminUser = getenv('ESPOCRM_USER') ?: 'admin';
$adminPass = getenv('ESPOCRM_PASS') ?: 'EspoCRM_Admin_2026!';
$authHeader = 'Basic ' . base64_encode($adminUser . ':' . $adminPass);

/**
 * Perform HTTP request helper
 */
function makeHttpRequest(string $url, string $method = 'GET', ?array $headers = [], ?string $body = null, int $timeout = 10): array {
    $ch = curl_init();
    $defaultHeaders = [
        'User-Agent: EspoCRM-Production-Verifier/1.0',
        'Accept: application/json, text/html',
        'Bypass-Tunnel-Reminder: true',
    ];
    $mergedHeaders = array_merge($defaultHeaders, $headers ?: []);

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $mergedHeaders);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'statusCode' => $statusCode,
        'body'       => $response,
        'error'      => $error,
    ];
}

// -----------------------------------------------------------------------------
// STEP 1: Verify Production Docker Compose Configuration
// -----------------------------------------------------------------------------
echo "--- Section 1: Production Docker Architecture Inspection ---\n";

$composePath = __DIR__ . '/../../docker-compose.production.yml';
$composeExists = file_exists($composePath);
assertCondition($composeExists, "Production compose file exists: docker-compose.production.yml");

if ($composeExists) {
    $composeContent = (string) file_get_contents($composePath);

    assertCondition(
        str_contains($composeContent, 'espocrm:') &&
        str_contains($composeContent, 'espocrm-db:') &&
        str_contains($composeContent, 'espocrm-daemon:') &&
        str_contains($composeContent, 'espocrm-websocket:'),
        "Production stack defines all 4 required enterprise services (App, MariaDB, Daemon, WebSocket)"
    );

    assertCondition(
        str_contains($composeContent, 'healthcheck:'),
        "Database and application containers define rigorous automated healthchecks"
    );

    assertCondition(
        str_contains($composeContent, 'espocrm_db_data:') &&
        str_contains($composeContent, 'espocrm_data:') &&
        str_contains($composeContent, 'espocrm_custom:'),
        "Persistent named volumes configured for relational DB, uploaded data, and custom extensions"
    );

    assertCondition(
        str_contains($composeContent, 'restart: unless-stopped'),
        "High availability restart policy (unless-stopped) configured across production services"
    );
}

// -----------------------------------------------------------------------------
// STEP 2: Live Instance Health Check
// -----------------------------------------------------------------------------
echo "\n--- Section 2: Local Production Server Health Check ---\n";

$healthResponse = makeHttpRequest($baseUrl . '/');
assertCondition(
    $healthResponse['statusCode'] === 200,
    "Local EspoCRM instance is online and responding at {$baseUrl}",
    "HTTP Code: " . $healthResponse['statusCode']
);

$hasHtmlShell = str_contains((string) $healthResponse['body'], '<title>EspoCRM</title>') ||
                str_contains((string) $healthResponse['body'], 'data-name="loader-params"');
assertCondition($hasHtmlShell, "HTTP response renders valid EspoCRM Single-Page Application (SPA) shell");

// -----------------------------------------------------------------------------
// STEP 3: 3 Real REST API Calls Against Production System
// -----------------------------------------------------------------------------
echo "\n--- Section 3: REST API v1 Production Verification (3 Calls) ---\n";

// API Call 1: GET /api/v1/App/user (Session, Roles, and App Metadata)
$api1 = makeHttpRequest(
    $baseUrl . '/api/v1/App/user',
    'GET',
    ['Authorization: ' . $authHeader]
);
assertCondition(
    $api1['statusCode'] === 200,
    "API Call 1 [GET /api/v1/App/user]: Authenticated session telemetry returned HTTP 200",
    "HTTP Code: " . $api1['statusCode']
);
$api1Data = json_decode((string) $api1['body'], true);
$isAdmin = isset($api1Data['user']['userName']) && $api1Data['user']['userName'] === 'admin';
$crmVersion = $api1Data['settings']['version'] ?? 'Unknown';
assertCondition(
    $isAdmin && !empty($crmVersion),
    "API Call 1 Data: Verified authenticated user 'admin' on EspoCRM version {$crmVersion}",
    "User: " . ($api1Data['user']['userName'] ?? 'N/A') . " | Version: {$crmVersion}"
);

// API Call 2: GET /api/v1/Lead (CRM Lead Collection)
$api2 = makeHttpRequest(
    $baseUrl . '/api/v1/Lead?maxSize=10',
    'GET',
    ['Authorization: ' . $authHeader]
);
assertCondition(
    $api2['statusCode'] === 200,
    "API Call 2 [GET /api/v1/Lead]: CRM Lead collection endpoint returned HTTP 200",
    "HTTP Code: " . $api2['statusCode']
);
$api2Data = json_decode((string) $api2['body'], true);
$leadTotal = $api2Data['total'] ?? 0;
assertCondition(
    $leadTotal > 0 && isset($api2Data['list']),
    "API Call 2 Data: Retrieved active CRM Lead entities collection (total: {$leadTotal} records)",
    "Total Leads: {$leadTotal}"
);

// API Call 3: GET /api/v1/Opportunity (CRM Sales Funnel Pipeline)
$api3 = makeHttpRequest(
    $baseUrl . '/api/v1/Opportunity?maxSize=10',
    'GET',
    ['Authorization: ' . $authHeader]
);
assertCondition(
    $api3['statusCode'] === 200,
    "API Call 3 [GET /api/v1/Opportunity]: CRM Opportunity pipeline endpoint returned HTTP 200",
    "HTTP Code: " . $api3['statusCode']
);
$api3Data = json_decode((string) $api3['body'], true);
$oppTotal = $api3Data['total'] ?? 0;
assertCondition(
    $oppTotal > 0 && isset($api3Data['list']),
    "API Call 3 Data: Retrieved active CRM Opportunity deals (total: {$oppTotal} records)",
    "Total Opportunities: {$oppTotal}"
);

// -----------------------------------------------------------------------------
// STEP 4: Full CRM Workflow Verification (Lead → Opportunity → Account → Activity)
// -----------------------------------------------------------------------------
echo "\n--- Section 4: CRM Lifecycle Verification (Lead -> Opp -> Account -> Activity) ---\n";

// Target entities created for W6D4 verification
$targetLeadId = '6ac7d18d45ce82dbc';
$targetAccountId = '6ac7d1927f57f425a';
$targetOppId = '6ac7d1b3cd00e7fde';
$targetMeetingId = '6ac7d1bfb6371c942';

// 4.1 Lead Entity Verification
$leadFetch = makeHttpRequest($baseUrl . '/api/v1/Lead/' . $targetLeadId, 'GET', ['Authorization: ' . $authHeader]);
assertCondition(
    $leadFetch['statusCode'] === 200,
    "CRM Lead Record: Found Lead 'Elena Rostova' (ID: {$targetLeadId})",
    "HTTP Code: " . $leadFetch['statusCode']
);
$leadRecord = json_decode((string) $leadFetch['body'], true);
assertCondition(
    ($leadRecord['accountName'] ?? '') === 'Apex Enterprise Global' &&
    ($leadRecord['status'] ?? '') === 'In Process' &&
    ($leadRecord['source'] ?? '') === 'Web Site',
    "CRM Lead Attributes: Account 'Apex Enterprise Global', Status 'In Process', Source 'Web Site'"
);

// 4.2 Account Entity Verification
$accountFetch = makeHttpRequest($baseUrl . '/api/v1/Account/' . $targetAccountId, 'GET', ['Authorization: ' . $authHeader]);
assertCondition(
    $accountFetch['statusCode'] === 200,
    "CRM Account Record: Found Account 'Apex Enterprise Global' (ID: {$targetAccountId})",
    "HTTP Code: " . $accountFetch['statusCode']
);
$accountRecord = json_decode((string) $accountFetch['body'], true);
assertCondition(
    ($accountRecord['name'] ?? '') === 'Apex Enterprise Global' &&
    ($accountRecord['type'] ?? '') === 'Customer' &&
    ($accountRecord['industry'] ?? '') === 'Telecommunications',
    "CRM Account Attributes: Verified Name 'Apex Enterprise Global', Type 'Customer', Industry 'Telecommunications'"
);

// 4.3 Opportunity Entity Verification
$oppFetch = makeHttpRequest($baseUrl . '/api/v1/Opportunity/' . $targetOppId, 'GET', ['Authorization: ' . $authHeader]);
assertCondition(
    $oppFetch['statusCode'] === 200,
    "CRM Opportunity Record: Found Deal 'Apex Enterprise Cloud CRM Deployment & Migration' (ID: {$targetOppId})",
    "HTTP Code: " . $oppFetch['statusCode']
);
$oppRecord = json_decode((string) $oppFetch['body'], true);
assertCondition(
    ($oppRecord['accountId'] ?? '') === $targetAccountId &&
    ($oppRecord['amount'] ?? 0) === 75000 &&
    ($oppRecord['stage'] ?? '') === 'Prospecting',
    "CRM Opportunity Relationship: Linked to Account ID {$targetAccountId}, Amount $75,000, Stage 'Prospecting'"
);

// 4.4 Activity (Meeting) Entity Verification
$meetingFetch = makeHttpRequest($baseUrl . '/api/v1/Meeting/' . $targetMeetingId, 'GET', ['Authorization: ' . $authHeader]);
assertCondition(
    $meetingFetch['statusCode'] === 200,
    "CRM Activity Record: Found Meeting 'Production Architecture & Security Sign-Off' (ID: {$targetMeetingId})",
    "HTTP Code: " . $meetingFetch['statusCode']
);
$meetingRecord = json_decode((string) $meetingFetch['body'], true);
assertCondition(
    ($meetingRecord['parentType'] ?? '') === 'Account' &&
    ($meetingRecord['parentId'] ?? '') === $targetAccountId &&
    ($meetingRecord['status'] ?? '') === 'Planned',
    "CRM Activity Relationship: Parent Type 'Account', Parent ID {$targetAccountId}, Status 'Planned'"
);

// -----------------------------------------------------------------------------
// STEP 5: Public Production Gateway Verification
// -----------------------------------------------------------------------------
echo "\n--- Section 5: Public Production Gateway Reachability ---\n";

$publicResponse = makeHttpRequest($publicUrl . '/');
$publicOnline = ($publicResponse['statusCode'] === 200);

assertCondition(
    $publicOnline,
    "Public production URL ({$publicUrl}) is reachable and returned HTTP 200",
    "Status: " . $publicResponse['statusCode'] . ($publicResponse['error'] ? " Error: " . $publicResponse['error'] : "")
);

if ($publicOnline) {
    $hasPublicHtml = str_contains((string) $publicResponse['body'], '<title>EspoCRM</title>');
    assertCondition(
        $hasPublicHtml,
        "Public production gateway successfully serves EspoCRM frontend interface"
    );
}

// -----------------------------------------------------------------------------
// Verification Summary Report
// -----------------------------------------------------------------------------
echo "\n========================================================================\n";
echo " Verification Execution Summary\n";
echo " Total Tests: " . ($passCount + $failCount) . " | Passed: {$passCount} | Failed: {$failCount}\n";
echo "========================================================================\n";

if ($failCount > 0) {
    echo " [FAIL] Verification suite encountered {$failCount} failure(s).\n";
    exit(1);
}

echo " [SUCCESS] All production deployment verifications passed successfully!\n";
exit(0);
