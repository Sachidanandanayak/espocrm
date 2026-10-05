<?php
/************************************************************************
 * Verification script for LeadReminderWorkflowService (W6D1 Unit Tests)
 * Smart Reminders — Automation Rules + AI-Suggested Timing
 ************************************************************************/

// Autoloader for Espo and Espo\Custom
spl_autoload_register(function (string $class): void {
    $classPath = str_replace('\\', '/', $class);

    if (str_starts_with($classPath, 'Espo/Custom/')) {
        $file = __DIR__ . '/../../custom/Espo/Custom/' . substr($classPath, 12) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    if (str_starts_with($classPath, 'Espo/')) {
        $file = __DIR__ . '/../../application/Espo/' . substr($classPath, 5) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

use Espo\Custom\Services\LeadReminderWorkflowService;
use Espo\Custom\Jobs\LeadReminderWorkflow;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Job\JobDataLess;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity as OrmEntity;
use Espo\Modules\Crm\Entities\Lead;

echo "=== W6D1: Smart Reminders Automation & AI Timing Verification ===\n\n";

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

// ---------------------------------------------------------
// Mock Helpers
// ---------------------------------------------------------

class MockEntity extends CoreEntity {
    private array $customAttributes = [];

    public function __construct(string $entityType, array $attributes = []) {
        parent::__construct($entityType, []);
        $this->customAttributes = $attributes;
    }

    public function get(string $name): mixed {
        return $this->customAttributes[$name] ?? parent::get($name);
    }

    public function set($attribute, $value = null): static {
        if (is_string($attribute)) {
            $this->customAttributes[$attribute] = $value;
        }
        return $this;
    }

    public function has(string $name): bool {
        return array_key_exists($name, $this->customAttributes) || parent::has($name);
    }

    public function getId(): string {
        return (string) ($this->customAttributes['id'] ?? '');
    }
}

class MockConfig extends Config {
    public function __construct(private array $values = []) {}

    public function get(string $key, mixed $default = null): mixed {
        return $this->values[$key] ?? $default;
    }

    public function set($name, $value = null, bool $dontMarkDirty = false): void {
        if (is_string($name)) {
            $this->values[$name] = $value;
        }
    }
}

class MockAcl extends Acl {
    private array $scopePermissions = [];

    public function __construct(array $scopePermissions = []) {
        $this->scopePermissions = $scopePermissions;
    }

    public function checkScope(string $scope, ?string $action = null): bool {
        if ($action !== null && isset($this->scopePermissions[$scope][$action])) {
            return (bool) $this->scopePermissions[$scope][$action];
        }
        return true;
    }
}

class MockRepository {
    private array $records = [];
    private string $entityType;

    public function __construct(string $entityType, array $records = []) {
        $this->entityType = $entityType;
        $this->records = $records;
    }

    public function where(array $where): static {
        $filtered = [];
        foreach ($this->records as $rec) {
            $match = true;
            foreach ($where as $field => $val) {
                if (str_ends_with($field, '<=')) {
                    $prop = substr($field, 0, -2);
                    if (($rec->get($prop) ?? '') > $val) {
                        $match = false;
                        break;
                    }
                } elseif (str_ends_with($field, '>=')) {
                    $prop = substr($field, 0, -2);
                    if (($rec->get($prop) ?? '') < $val) {
                        $match = false;
                        break;
                    }
                } elseif (is_array($val)) {
                    if (!in_array($rec->get($field), $val, true)) {
                        $match = false;
                        break;
                    }
                } else {
                    if ($rec->get($field) != $val) {
                        $match = false;
                        break;
                    }
                }
            }
            if ($match) {
                $filtered[] = $rec;
            }
        }
        return new self($this->entityType, $filtered);
    }

    public function order(string $field, bool $asc = true): static {
        return $this;
    }

    public function limit(int $offset, int $limit = 0): static {
        if ($limit > 0) {
            $sliced = array_slice($this->records, $offset, $limit);
            return new self($this->entityType, $sliced);
        }
        return $this;
    }

    public function find(): array {
        return $this->records;
    }

    public function count(): int {
        return count($this->records);
    }

    public function getNew(): MockEntity {
        $id = 'new_' . uniqid();
        return new MockEntity($this->entityType, ['id' => $id]);
    }
}

class MockEntityManager extends EntityManager {
    private array $entities = [];
    public array $savedEntities = [];
    public array $repositories = [];

    public function __construct(array $entities = [], array $repositories = []) {
        $this->entities = $entities;
        $this->repositories = $repositories;
    }

    public function getEntity(string $entityType, ?string $id = null): ?OrmEntity {
        if ($id === null) {
            return null;
        }
        return $this->entities["{$entityType}:{$id}"] ?? null;
    }

    public function getEntityById(string $entityType, string $id): ?OrmEntity {
        return $this->entities["{$entityType}:{$id}"] ?? null;
    }

    public function getRDBRepositoryCustom(string $entityType): MockRepository {
        return $this->repositories[$entityType] ?? new MockRepository($entityType, []);
    }

    public function saveEntity(OrmEntity $entity, array $options = []): void {
        $this->savedEntities[] = $entity;
        $type = method_exists($entity, 'getEntityType') ? $entity->getEntityType() : 'Unknown';
        $id = method_exists($entity, 'getId') ? $entity->getId() : '';
        if ($type !== '' && $id !== '') {
            $this->entities["{$type}:{$id}"] = $entity;
        }
    }
}

// ---------------------------------------------------------
// Test Suite 1: Service Architecture & Configuration Defaults
// ---------------------------------------------------------
echo "Test Suite 1: Service Architecture & Configuration Defaults\n";

$config = new MockConfig([]);
$acl = new MockAcl();
$em = new MockEntityManager();
$service = new LeadReminderWorkflowService($em, $config, $acl);

assertCondition("Service instance created successfully", $service instanceof LeadReminderWorkflowService);
assertCondition("Default model is llama-3.3-70b-versatile", LeadReminderWorkflowService::DEFAULT_MODEL === 'llama-3.3-70b-versatile');
assertCondition("Default threshold days is 3", LeadReminderWorkflowService::DEFAULT_THRESHOLD_DAYS === 3);
assertCondition("Groq API URL points to chat/completions endpoint", LeadReminderWorkflowService::GROQ_API_URL === 'https://api.groq.com/openai/v1/chat/completions');
assertCondition("Supported models include llama-3.3-70b-versatile", in_array('llama-3.3-70b-versatile', LeadReminderWorkflowService::SUPPORTED_MODELS, true));

// ---------------------------------------------------------
// Test Suite 2: API Key & Model Resolution Hierarchy
// ---------------------------------------------------------
echo "\nTest Suite 2: API Key & Model Resolution\n";

assertCondition("API key is null when not configured", $service->getApiKey() === null);
assertCondition("Override key takes highest precedence", $service->getApiKey('override_lead_key_123') === 'override_lead_key_123');

$configWithKey = new MockConfig(['groqApiKey' => 'config_groq_key_reminders']);
$serviceWithConfigKey = new LeadReminderWorkflowService($em, $configWithKey, $acl);
assertCondition("Config key resolved when present", $serviceWithConfigKey->getApiKey() === 'config_groq_key_reminders');
assertCondition("Override still beats config key", $serviceWithConfigKey->getApiKey('override_beats_config') === 'override_beats_config');

assertCondition("Default model used when none configured", $service->getModel() === 'llama-3.3-70b-versatile');
assertCondition("Model override applied when specified", $service->getModel('llama-3.1-8b-instant') === 'llama-3.1-8b-instant');

$configWithModel = new MockConfig(['groqModel' => 'mixtral-8x7b-32768']);
$serviceWithConfigModel = new LeadReminderWorkflowService($em, $configWithModel, $acl);
assertCondition("Config model resolved properly", $serviceWithConfigModel->getModel() === 'mixtral-8x7b-32768');

// ---------------------------------------------------------
// Test Suite 3: Workflow Trigger -> Condition -> Action Metadata
// ---------------------------------------------------------
echo "\nTest Suite 3: Workflow Trigger -> Condition -> Action Architecture Metadata\n";

$status = $service->getStatus();
assertCondition("Status reports active status", $status['status'] === 'active');
assertCondition("Status exposes workflow rule definition", isset($status['workflowRule']['id']));
assertCondition("Workflow rule entityType is Lead", $status['workflowRule']['entityType'] === 'Lead');
assertCondition("Trigger type is scheduled", $status['workflowRule']['trigger']['type'] === 'scheduled');
assertCondition("Trigger schedule is 0 9 * * *", $status['workflowRule']['trigger']['schedule'] === '0 9 * * *');

$conditions = $status['workflowRule']['conditions'];
assertCondition("Workflow rule defines conditions", is_array($conditions) && count($conditions) >= 4);

$hasStatusCond = false;
$hasCreatedAtCond = false;
$hasActivityCond = false;
$hasThrottleCond = false;

foreach ($conditions as $c) {
    if ($c['field'] === 'status') $hasStatusCond = true;
    if ($c['field'] === 'createdAt') $hasCreatedAtCond = true;
    if ($c['field'] === 'activityHistory') $hasActivityCond = true;
    if ($c['field'] === 'remindedAt') $hasThrottleCond = true;
}

assertCondition("Condition verifies lead status is active", $hasStatusCond);
assertCondition("Condition verifies lead created at least 3 days ago", $hasCreatedAtCond);
assertCondition("Condition verifies no activities in last 3 days", $hasActivityCond);
assertCondition("Condition verifies reminder throttling (remindedAt)", $hasThrottleCond);

$actions = $status['workflowRule']['actions'];
assertCondition("Workflow rule defines actions", is_array($actions) && count($actions) >= 4);

$hasAiAction = false;
$hasEmailAction = false;
$hasNoteAction = false;
$hasTaskAction = false;

foreach ($actions as $a) {
    if ($a['type'] === 'aiSuggestedTiming') $hasAiAction = true;
    if ($a['type'] === 'sendReminderEmail') $hasEmailAction = true;
    if ($a['type'] === 'createStreamNote') $hasNoteAction = true;
    if ($a['type'] === 'createFollowUpTask') $hasTaskAction = true;
}

assertCondition("Action includes AI timing suggestion via Groq", $hasAiAction);
assertCondition("Action includes reminder email dispatch", $hasEmailAction);
assertCondition("Action includes stream note audit log", $hasNoteAction);
assertCondition("Action includes CRM follow-up Task scheduling", $hasTaskAction);

// ---------------------------------------------------------
// Test Suite 4: Uncontacted Condition Evaluator (isLeadUncontacted)
// ---------------------------------------------------------
echo "\nTest Suite 4: Uncontacted Condition Evaluator (isLeadUncontacted)\n";

$fourDaysAgo = date('Y-m-d H:i:s', strtotime('-4 days'));
$twoDaysAgo = date('Y-m-d H:i:s', strtotime('-2 days'));
$oneDayAgo = date('Y-m-d H:i:s', strtotime('-1 day'));

// 1. Lead created 4 days ago with no activities -> Uncontacted!
$eligibleLead = new MockEntity('Lead', [
    'id' => 'lead_uncontacted_1',
    'name' => 'Sarah Connor',
    'status' => 'New',
    'createdAt' => $fourDaysAgo,
    'accountName' => 'Cyberdyne Systems',
    'industry' => 'Technology',
    'opportunityAmount' => 50000,
    'emailAddress' => 'sconnor@cyberdyne.com',
]);

assertCondition("Lead created 4 days ago with status 'New' passes uncontacted check", $service->isLeadUncontacted($eligibleLead, 3));

// 2. Lead created 2 days ago -> Fails (too recent)
$recentLead = new MockEntity('Lead', [
    'id' => 'lead_recent',
    'name' => 'John Connor',
    'status' => 'New',
    'createdAt' => $twoDaysAgo,
]);
assertCondition("Lead created 2 days ago fails 3-day uncontacted check", !$service->isLeadUncontacted($recentLead, 3));

// 3. Converted lead -> Fails (closed)
$convertedLead = new MockEntity('Lead', [
    'id' => 'lead_converted',
    'name' => 'Miles Dyson',
    'status' => 'Converted',
    'createdAt' => $fourDaysAgo,
]);
assertCondition("Converted lead fails uncontacted check", !$service->isLeadUncontacted($convertedLead, 3));

// 4. Dead lead -> Fails (closed)
$deadLead = new MockEntity('Lead', [
    'id' => 'lead_dead',
    'name' => 'Kyle Reese',
    'status' => 'Dead',
    'createdAt' => $fourDaysAgo,
]);
assertCondition("Dead lead fails uncontacted check", !$service->isLeadUncontacted($deadLead, 3));

// 5. Lead with recent Call (1 day ago) -> Fails
$recentCall = new MockEntity('Call', [
    'id' => 'call_1',
    'parentType' => 'Lead',
    'parentId' => 'lead_with_call',
    'dateStart' => $oneDayAgo,
    'deleted' => false,
]);
$leadWithCall = new MockEntity('Lead', [
    'id' => 'lead_with_call',
    'name' => 'Marcus Wright',
    'status' => 'New',
    'createdAt' => $fourDaysAgo,
]);

$emWithCall = new MockEntityManager([], [
    'Call' => new MockRepository('Call', [$recentCall]),
]);
$serviceWithCall = new LeadReminderWorkflowService($emWithCall, $config, $acl);
assertCondition("Lead with Call in last 3 days fails uncontacted check", !$serviceWithCall->isLeadUncontacted($leadWithCall, 3));

// 6. Lead with recent Meeting (1 day ago) -> Fails
$recentMeeting = new MockEntity('Meeting', [
    'id' => 'meet_1',
    'parentType' => 'Lead',
    'parentId' => 'lead_with_meet',
    'dateStart' => $oneDayAgo,
    'deleted' => false,
]);
$leadWithMeet = new MockEntity('Lead', [
    'id' => 'lead_with_meet',
    'name' => 'Kate Brewster',
    'status' => 'Assigned',
    'createdAt' => $fourDaysAgo,
]);

$emWithMeet = new MockEntityManager([], [
    'Meeting' => new MockRepository('Meeting', [$recentMeeting]),
]);
$serviceWithMeet = new LeadReminderWorkflowService($emWithMeet, $config, $acl);
assertCondition("Lead with Meeting in last 3 days fails uncontacted check", !$serviceWithMeet->isLeadUncontacted($leadWithMeet, 3));

// 7. Lead already reminded 1 day ago -> Fails (throttled)
$leadAlreadyReminded = new MockEntity('Lead', [
    'id' => 'lead_throttled',
    'name' => 'Grace Harper',
    'status' => 'In Process',
    'createdAt' => $fourDaysAgo,
    'remindedAt' => $oneDayAgo,
]);
assertCondition("Lead reminded 1 day ago fails uncontacted check (anti-spam throttling)", !$service->isLeadUncontacted($leadAlreadyReminded, 3));

// ---------------------------------------------------------
// Test Suite 5: AI Groq Follow-Up Timing Prediction & Safe Fallback
// ---------------------------------------------------------
echo "\nTest Suite 5: AI Groq Follow-Up Timing Prediction & Safe Fallback\n";

$leadContextTech = [
    'leadId' => 'lead_tech_001',
    'leadName' => 'Sarah Connor',
    'accountName' => 'Cyberdyne Systems',
    'industry' => 'Technology',
    'source' => 'Web Site',
    'status' => 'New',
    'opportunityAmount' => 50000,
    'daysUncontacted' => 4,
    'fullPrompt' => 'Tech lead from Cyberdyne Systems inquiry.',
];

$aiSimTech = $service->simulateFollowUpTiming($leadContextTech);

assertCondition("Simulation returns suggestedFollowUpAt string", !empty($aiSimTech['suggestedFollowUpAt']));
assertCondition("Simulation returns suggestedTimeSlot string", !empty($aiSimTech['suggestedTimeSlot']));

$simDate = new DateTime($aiSimTech['suggestedFollowUpAt']);
$dayOfWeek = (int) $simDate->format('w');
assertCondition("Suggested follow-up date never falls on Saturday or Sunday", $dayOfWeek !== 0 && $dayOfWeek !== 6);

assertCondition("Technology sector suggests Morning (10:00 AM) slot", $aiSimTech['optimalTimeOfDay'] === 'Morning (10:00 AM)');
assertCondition("High-value enterprise lead ($50,000) has High urgency", $aiSimTech['urgency'] === 'High');
assertCondition("Website inbound lead recommends Email channel", $aiSimTech['recommendedChannel'] === 'Email');
assertCondition("Rationale references company name", str_contains($aiSimTech['rationale'], 'Cyberdyne Systems'));
assertCondition("Rationale references industry", str_contains($aiSimTech['rationale'], 'Technology'));
assertCondition("Email subject line contains company reference", str_contains($aiSimTech['emailSubject'], 'Cyberdyne Systems'));
assertCondition("Email body draft contains lead greeting", str_contains($aiSimTech['emailBody'], 'Sarah Connor'));
assertCondition("Simulated flag is true", $aiSimTech['simulated'] === true);
assertCondition("Token usage metrics are present", isset($aiSimTech['usage']['totalTokens']) && $aiSimTech['usage']['totalTokens'] > 0);

// Healthcare lead testing
$leadContextHealth = [
    'leadId' => 'lead_health_001',
    'leadName' => 'Dr. Peter Silberman',
    'accountName' => 'County Hospital',
    'industry' => 'Healthcare',
    'source' => 'Call',
    'status' => 'New',
    'opportunityAmount' => 15000,
    'phoneNumber' => '+1-555-0199',
    'daysUncontacted' => 3,
    'fullPrompt' => 'Healthcare lead from County Hospital.',
];
$aiSimHealth = $service->simulateFollowUpTiming($leadContextHealth);
assertCondition("Healthcare sector suggests Early Morning (8:45 AM) slot", $aiSimHealth['optimalTimeOfDay'] === 'Early Morning (8:45 AM)');
assertCondition("Inbound Call source recommends Phone Call channel", $aiSimHealth['recommendedChannel'] === 'Phone Call');

// Safe fallback when Groq API key is unconfigured
$suggestResultUnconfigured = $service->suggestFollowUpTime([
    'lead' => $leadContextTech,
]);
assertCondition("Safe fallback when unconfigured: returns valid timing suggestion without throwing", !empty($suggestResultUnconfigured['suggestedFollowUpAt']));
assertCondition("Safe fallback when unconfigured: simulated flag is true", $suggestResultUnconfigured['simulated'] === true);

// Safe fallback on simulated network error with throwOnError=false
$serviceMockFail = new class($em, new MockConfig(['groqApiKey' => 'test_key']), $acl) extends LeadReminderWorkflowService {
    protected function callGroqTimingApi(array $leadContext, string $apiKey, string $model): array {
        throw new \RuntimeException("Connection timeout to api.groq.com:443");
    }
};

$safeFallbackResult = $serviceMockFail->suggestFollowUpTime([
    'lead' => $leadContextTech,
    'throwOnError' => false,
]);
assertCondition("Safe fallback on Groq network failure: gracefully catches error", isset($safeFallbackResult['fallback']) && $safeFallbackResult['fallback'] === true);
assertCondition("Safe fallback records fallbackReason", str_contains($safeFallbackResult['fallbackReason'], 'Connection timeout'));
assertCondition("Safe fallback still yields valid follow-up date", !empty($safeFallbackResult['suggestedFollowUpAt']));

// Strict key error handling with throwOnError=true
try {
    $serviceMockFail->suggestFollowUpTime([
        'lead' => $leadContextTech,
        'throwOnError' => true,
    ]);
    assertCondition("Strict error validation throws BadRequest on network/key failure", false);
} catch (BadRequest $e) {
    assertCondition("Strict error validation throws BadRequest on network/key failure", true);
}

// ---------------------------------------------------------
// Test Suite 6: Full Workflow Execution & Side Effects
// ---------------------------------------------------------
echo "\nTest Suite 6: Full Workflow Execution & Side Effects (executeReminder)\n";

$targetLead = new MockEntity('Lead', [
    'id' => 'lead_exec_001',
    'name' => 'Sarah Connor',
    'status' => 'New',
    'createdAt' => $fourDaysAgo,
    'accountName' => 'Cyberdyne Systems',
    'industry' => 'Technology',
    'opportunityAmount' => 50000,
    'emailAddress' => 'sconnor@cyberdyne.com',
    'assignedUserId' => 'user_sales_1',
]);

$emailRepo = new MockRepository('Email', []);
$noteRepo = new MockRepository('Note', []);
$taskRepo = new MockRepository('Task', []);
$leadRepo = new MockRepository('Lead', [$targetLead]);

$emExec = new MockEntityManager([
    'Lead:lead_exec_001' => $targetLead,
], [
    'Email' => $emailRepo,
    'Note' => $noteRepo,
    'Task' => $taskRepo,
    'Lead' => $leadRepo,
]);

$serviceExec = new LeadReminderWorkflowService($emExec, $config, $acl);

$execResult = $serviceExec->executeReminder('lead_exec_001');

assertCondition("executeReminder returns success status", $execResult['status'] === 'success');
assertCondition("executeReminder returns leadId", $execResult['leadId'] === 'lead_exec_001');
assertCondition("executeReminder dispatches reminder email", !empty($execResult['email']['id']));
assertCondition("executeReminder email recipient matches lead", $execResult['email']['to'] === 'sconnor@cyberdyne.com');
assertCondition("executeReminder creates stream note", !empty($execResult['noteId']));
assertCondition("executeReminder creates follow-up task", !empty($execResult['taskId']));
assertCondition("Lead entity remindedAt was updated", !empty($targetLead->get('remindedAt')));
assertCondition("Lead entity aiFollowUpAt was recorded", !empty($targetLead->get('aiFollowUpAt')));
assertCondition("Lead entity aiFollowUpRationale was recorded", !empty($targetLead->get('aiFollowUpRationale')));

// Execution on already reminded lead without force returns skipped
$skippedResult = $serviceExec->executeReminder('lead_exec_001', ['force' => false]);
assertCondition("Second execution without force returns skipped status", $skippedResult['status'] === 'skipped');

// Execution with force=true bypasses condition
$forcedResult = $serviceExec->executeReminder('lead_exec_001', ['force' => true]);
assertCondition("Execution with force=true succeeds even if recently reminded", $forcedResult['status'] === 'success');

// ---------------------------------------------------------
// Test Suite 7: Batch Workflow Execution (runBatch)
// ---------------------------------------------------------
echo "\nTest Suite 7: Batch Workflow Execution (runBatch)\n";

$leadBatch1 = new MockEntity('Lead', [
    'id' => 'lead_b1',
    'name' => 'Lead One',
    'status' => 'New',
    'createdAt' => $fourDaysAgo,
    'accountName' => 'Alpha Corp',
    'industry' => 'Technology',
    'emailAddress' => 'lead1@alpha.com',
]);

$leadBatch2 = new MockEntity('Lead', [
    'id' => 'lead_b2',
    'name' => 'Lead Two',
    'status' => 'Assigned',
    'createdAt' => $fourDaysAgo,
    'accountName' => 'Beta Health',
    'industry' => 'Healthcare',
    'emailAddress' => 'lead2@beta.com',
]);

$emBatch = new MockEntityManager([
    'Lead:lead_b1' => $leadBatch1,
    'Lead:lead_b2' => $leadBatch2,
], [
    'Lead' => new MockRepository('Lead', [$leadBatch1, $leadBatch2]),
    'Email' => new MockRepository('Email', []),
    'Note' => new MockRepository('Note', []),
    'Task' => new MockRepository('Task', []),
]);

$serviceBatch = new LeadReminderWorkflowService($emBatch, $config, $acl);

$batchResult = $serviceBatch->runBatch(3);

assertCondition("runBatch returns completed status", $batchResult['status'] === 'completed');
assertCondition("runBatch reports totalEligible = 2", $batchResult['totalEligible'] === 2);
assertCondition("runBatch reports processed = 2", $batchResult['processed'] === 2);
assertCondition("runBatch reports remindersSent = 2", $batchResult['remindersSent'] === 2);
assertCondition("runBatch reports errorCount = 0", $batchResult['errorCount'] === 0);

// ---------------------------------------------------------
// Test Suite 8: ScheduledJob Class Verification
// ---------------------------------------------------------
echo "\nTest Suite 8: ScheduledJob Class Verification\n";

assertCondition("LeadReminderWorkflow ScheduledJob class exists", class_exists(LeadReminderWorkflow::class));
$jobReflection = new \ReflectionClass(LeadReminderWorkflow::class);
assertCondition("LeadReminderWorkflow implements JobDataLess", $jobReflection->implementsInterface(JobDataLess::class));

// Verify metadata registration in scheduledJobs.json
$scheduledJobsMeta = json_decode(file_get_contents(__DIR__ . '/../../custom/Espo/Custom/Resources/metadata/app/scheduledJobs.json'), true);
assertCondition("scheduledJobs.json registers LeadReminderWorkflow", isset($scheduledJobsMeta['LeadReminderWorkflow']));
assertCondition("scheduledJobs.json assigns jobClassName", $scheduledJobsMeta['LeadReminderWorkflow']['jobClassName'] === 'Espo\\Custom\\Jobs\\LeadReminderWorkflow');
assertCondition("scheduledJobs.json assigns cron schedule", $scheduledJobsMeta['LeadReminderWorkflow']['scheduling'] === '0 9 * * *');

echo "\n==================================================\n";
echo "Total Assertions: " . ($passCount + $failCount) . "\n";
echo "Passed: " . $passCount . "\n";
echo "Failed: " . $failCount . "\n";
echo "==================================================\n";

if ($failCount > 0) {
    exit(1);
}
