<?php
/************************************************************************
 * Verification script for LeadReminderWorkflow Controller & REST API (W6D1)
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

use Espo\Custom\Controllers\LeadReminderWorkflow as LeadReminderWorkflowController;
use Espo\Custom\Services\LeadReminderWorkflowService;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity as OrmEntity;

echo "=== W6D1: Smart Reminders REST API Verification ===\n\n";

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
// Mock Infrastructure
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

class MockRequest implements Request {
    public function __construct(
        private array $body = [],
        private string $rawBody = '',
        private array $queryParams = [],
        private string $method = 'POST'
    ) {}

    public function getParsedBody(): \stdClass {
        return (object) $this->body;
    }

    public function getBodyContents(): ?string {
        return $this->rawBody ?: json_encode($this->body);
    }

    public function hasQueryParam(string $name): bool {
        return isset($this->queryParams[$name]);
    }

    public function getQueryParam(string $name): ?string {
        return isset($this->queryParams[$name]) ? (string) $this->queryParams[$name] : null;
    }

    public function getQueryParams(): array {
        return $this->queryParams;
    }

    public function getMethod(): string {
        return $this->method;
    }

    public function hasRouteParam(string $name): bool { return false; }
    public function getRouteParam(string $name): ?string { return null; }
    public function getRouteParams(): array { return []; }
    public function getHeader(string $name): ?string { return null; }
    public function hasHeader(string $name): bool { return false; }
    public function getHeaderAsArray(string $name): array { return []; }
    public function getHeaders(): array { return []; }
    public function getResourcePath(): string { return ''; }
    public function getCookieParam(string $name): ?string { return null; }
    public function getCookieParams(): array { return []; }
    public function getServerParam(string $name): mixed { return null; }
    public function getServerParams(): array { return []; }
    public function getContentType(): ?string { return 'application/json'; }
    public function isJson(): bool { return true; }
    public function isXml(): bool { return false; }
    public function isForm(): bool { return false; }
    public function isMultipart(): bool { return false; }
    public function isXhr(): bool { return true; }
    public function getTarget(): ?string { return null; }
    public function getUri(): \Psr\Http\Message\UriInterface { throw new \BadMethodCallException(); }
    public function toPsr7(): \Psr\Http\Message\ServerRequestInterface { throw new \BadMethodCallException(); }
    public function getAttribute(string $name, $default = null): mixed { return null; }
    public function getAttributes(): array { return []; }
}

// ---------------------------------------------------------
// Test Suite 1: Controller Initialization & Defaults
// ---------------------------------------------------------
echo "Test Suite 1: Controller Initialization & Defaults\n";

$fourDaysAgo = date('Y-m-d H:i:s', strtotime('-4 days'));

$testLead = new MockEntity('Lead', [
    'id' => 'lead_api_001',
    'name' => 'Alexander Stone',
    'status' => 'New',
    'createdAt' => $fourDaysAgo,
    'accountName' => 'Apex Dynamics',
    'industry' => 'Technology',
    'opportunityAmount' => 45000,
    'emailAddress' => 'astone@apexdynamics.com',
    'assignedUserId' => 'user_rep_1',
]);

$em = new MockEntityManager([
    'Lead:lead_api_001' => $testLead,
], [
    'Lead' => new MockRepository('Lead', [$testLead]),
    'Email' => new MockRepository('Email', []),
    'Note' => new MockRepository('Note', []),
    'Task' => new MockRepository('Task', []),
]);

$config = new MockConfig([]);
$acl = new MockAcl();
$service = new LeadReminderWorkflowService($em, $config, $acl);
$controller = new LeadReminderWorkflowController($service, $acl);

assertCondition("Controller instantiated successfully", $controller instanceof LeadReminderWorkflowController);
assertCondition("Default action is status", LeadReminderWorkflowController::$defaultAction === 'status');

// ---------------------------------------------------------
// Test Suite 2: GET /LeadReminderWorkflow/status
// ---------------------------------------------------------
echo "\nTest Suite 2: GET /LeadReminderWorkflow/status Endpoint\n";

$statusReq = new MockRequest([], '', [], 'GET');
$statusRes = $controller->getActionStatus($statusReq);

assertCondition("Status endpoint returns array", is_array($statusRes));
assertCondition("Status is active", $statusRes['status'] === 'active');
assertCondition("Workflow rule definition included", isset($statusRes['workflowRule']['id']));
assertCondition("Groq provider details present", $statusRes['groq']['provider'] === 'Groq');
assertCondition("Default threshold days is 3", $statusRes['defaultThresholdDays'] === 3);

$aliasStatusRes = $controller->actionStatus($statusReq);
assertCondition("Action alias actionStatus matches getActionStatus", $aliasStatusRes['status'] === $statusRes['status']);

// ---------------------------------------------------------
// Test Suite 3: GET /LeadReminderWorkflow/eligibleLeads
// ---------------------------------------------------------
echo "\nTest Suite 3: GET /LeadReminderWorkflow/eligibleLeads Endpoint\n";

$eligibleReq = new MockRequest([], '', ['days' => 3], 'GET');
$eligibleRes = $controller->getActionEligibleLeads($eligibleReq);

assertCondition("eligibleLeads returns success", $eligibleRes['status'] === 'success');
assertCondition("eligibleLeads count is 1", $eligibleRes['count'] === 1);
assertCondition("eligible lead id is lead_api_001", $eligibleRes['list'][0]['id'] === 'lead_api_001');
assertCondition("eligible lead company is Apex Dynamics", $eligibleRes['list'][0]['accountName'] === 'Apex Dynamics');

// ---------------------------------------------------------
// Test Suite 4: POST /LeadReminderWorkflow/suggestTiming
// ---------------------------------------------------------
echo "\nTest Suite 4: POST /LeadReminderWorkflow/suggestTiming Endpoint\n";

$timingReq = new MockRequest([
    'leadId' => 'lead_api_001',
    'simulate' => true,
], '', [], 'POST');

$timingRes = $controller->postActionSuggestTiming($timingReq);

assertCondition("suggestTiming returns success", $timingRes['status'] === 'success');
assertCondition("Timing data contains suggestedFollowUpAt", !empty($timingRes['data']['suggestedFollowUpAt']));
assertCondition("Timing data contains suggestedTimeSlot", !empty($timingRes['data']['suggestedTimeSlot']));
assertCondition("Timing data contains optimalDay", !empty($timingRes['data']['optimalDay']));
assertCondition("Timing data contains rationale", !empty($timingRes['data']['rationale']));
assertCondition("Timing data contains emailSubject", !empty($timingRes['data']['emailSubject']));
assertCondition("Timing data contains emailBody", !empty($timingRes['data']['emailBody']));

$aliasTimingRes = $controller->actionSuggestTiming($timingReq);
assertCondition("Action alias actionSuggestTiming matches postActionSuggestTiming", $aliasTimingRes['status'] === 'success');

// ---------------------------------------------------------
// Test Suite 5: POST /LeadReminderWorkflow/execute
// ---------------------------------------------------------
echo "\nTest Suite 5: POST /LeadReminderWorkflow/execute Endpoint\n";

$execReq = new MockRequest([
    'leadId' => 'lead_api_001',
    'simulate' => true,
], '', [], 'POST');

$execRes = $controller->postActionExecute($execReq);

assertCondition("execute endpoint returns success", $execRes['status'] === 'success');
assertCondition("execute returns leadId", $execRes['leadId'] === 'lead_api_001');
assertCondition("execute creates email", !empty($execRes['email']['id']));
assertCondition("execute creates stream note", !empty($execRes['noteId']));
assertCondition("execute creates follow-up task", !empty($execRes['taskId']));

// ---------------------------------------------------------
// Test Suite 6: POST /LeadReminderWorkflow/runBatch
// ---------------------------------------------------------
echo "\nTest Suite 6: POST /LeadReminderWorkflow/runBatch Endpoint\n";

$batchReq = new MockRequest([
    'days' => 3,
    'simulate' => true,
], '', [], 'POST');

$batchRes = $controller->postActionRunBatch($batchReq);

assertCondition("runBatch returns completed status", $batchRes['status'] === 'completed');
assertCondition("runBatch reports totalEligible >= 0", isset($batchRes['totalEligible']));
assertCondition("runBatch reports processed >= 0", isset($batchRes['processed']));

// ---------------------------------------------------------
// Test Suite 7: API Error Validation & HTTP Exceptions
// ---------------------------------------------------------
echo "\nTest Suite 7: API Error Validation & HTTP Exceptions\n";

// 1. Empty suggestTiming request
try {
    $emptyReq = new MockRequest([], '', [], 'POST');
    $controller->postActionSuggestTiming($emptyReq);
    assertCondition("Empty suggestTiming request throws BadRequest", false);
} catch (BadRequest $e) {
    assertCondition("Empty suggestTiming request throws BadRequest", true);
}

// 2. Missing leadId in execute request
try {
    $missingIdReq = new MockRequest(['force' => true], '', [], 'POST');
    $controller->postActionExecute($missingIdReq);
    assertCondition("Missing leadId in execute throws BadRequest", false);
} catch (BadRequest $e) {
    assertCondition("Missing leadId in execute throws BadRequest", true);
}

// 3. ACL forbidden check
$aclForbidden = new MockAcl(['Lead' => [Table::ACTION_READ => false]]);
$serviceForbidden = new LeadReminderWorkflowService($em, $config, $aclForbidden);
$controllerForbidden = new LeadReminderWorkflowController($serviceForbidden, $aclForbidden);

try {
    $controllerForbidden->postActionSuggestTiming(new MockRequest(['leadId' => 'lead_api_001']));
    assertCondition("Forbidden exception thrown on unauthorized access", false);
} catch (Forbidden $e) {
    assertCondition("Forbidden exception thrown on unauthorized access", true);
}

// ---------------------------------------------------------
// Test Suite 8: routes.json Registration & Backward Compatibility
// ---------------------------------------------------------
echo "\nTest Suite 8: routes.json Registration & Backward Compatibility\n";

$routesPath = __DIR__ . '/../../custom/Espo/Custom/Resources/routes.json';
assertCondition("routes.json exists", file_exists($routesPath));

$routes = json_decode(file_get_contents($routesPath), true);
assertCondition("routes.json is valid array", is_array($routes));

$registeredRoutes = [];
foreach ($routes as $r) {
    $registeredRoutes[] = strtoupper($r['method']) . ' ' . $r['route'];
}

assertCondition("routes.json registers GET /LeadReminderWorkflow/status", in_array('GET /LeadReminderWorkflow/status', $registeredRoutes, true));
assertCondition("routes.json registers GET /LeadReminderWorkflow/eligibleLeads", in_array('GET /LeadReminderWorkflow/eligibleLeads', $registeredRoutes, true));
assertCondition("routes.json registers POST /LeadReminderWorkflow/suggestTiming", in_array('POST /LeadReminderWorkflow/suggestTiming', $registeredRoutes, true));
assertCondition("routes.json registers POST /LeadReminderWorkflow/execute", in_array('POST /LeadReminderWorkflow/execute', $registeredRoutes, true));
assertCondition("routes.json registers POST /LeadReminderWorkflow/runBatch", in_array('POST /LeadReminderWorkflow/runBatch', $registeredRoutes, true));

// Regression check: preserve W5D4 and W5D5
assertCondition("routes.json preserves W5D4 GET /ActivitySummary (zero regressions)", in_array('GET /ActivitySummary', $registeredRoutes, true));
assertCondition("routes.json preserves W5D5 POST /ConversationSummarizer/summarize", in_array('POST /ConversationSummarizer/summarize', $registeredRoutes, true));
assertCondition("routes.json preserves W5D5 GET /ConversationSummarizer/status", in_array('GET /ConversationSummarizer/status', $registeredRoutes, true));

// Global.json check
$globalJson = json_decode(file_get_contents(__DIR__ . '/../../custom/Espo/Custom/Resources/i18n/en_US/Global.json'), true);
assertCondition("Global.json defines SmartReminders label", isset($globalJson['labels']['SmartReminders']));
assertCondition("Global.json defines LeadReminderWorkflow label", isset($globalJson['labels']['LeadReminderWorkflow']));
assertCondition("Global.json preserves W5D4 ActivitySummary dashlet", isset($globalJson['dashlets']['ActivitySummary']));
assertCondition("Global.json preserves W5D5 ConversationSummarizer dashlet", isset($globalJson['dashlets']['ConversationSummarizer']));

echo "\n==================================================\n";
echo "Total Assertions: " . ($passCount + $failCount) . "\n";
echo "Passed: " . $passCount . "\n";
echo "Failed: " . $failCount . "\n";
echo "==================================================\n";

if ($failCount > 0) {
    exit(1);
}
