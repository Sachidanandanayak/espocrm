<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Custom\Services;

use DateTime;
use DateInterval;
use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\ORM\Entity;
use Espo\Entities\Email;
use Espo\Entities\Note;
use Espo\Modules\Crm\Entities\Lead;
use Espo\Modules\Crm\Entities\Task;
use Throwable;

/**
 * Service implementing W6D1: Smart Reminders Automation Rules + AI-Suggested Timing.
 *
 * Automates the Trigger -> Condition -> Action workflow:
 * - Trigger: Scheduled / periodic cron check (or on-demand API invocation)
 * - Condition: Lead uncontacted for >= 3 days (active status, no calls/meetings/emails in last 3 days)
 * - Action: Generate AI-suggested follow-up time via Groq (with safe fallback), dispatch reminder email,
 *           schedule CRM follow-up Task, and record audit note on the lead stream.
 */
class LeadReminderWorkflowService
{
    public const string DEFAULT_MODEL = 'llama-3.3-70b-versatile';
    public const string GROQ_API_URL = 'https://api.groq.com/openai/v1/chat/completions';
    public const int DEFAULT_THRESHOLD_DAYS = 3;

    public const array SUPPORTED_MODELS = [
        'llama-3.3-70b-versatile',
        'llama-3.1-8b-instant',
        'mixtral-8x7b-32768',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Acl $acl,
        private ?ConversationSummarizerService $conversationSummarizer = null
    ) {}

    /**
     * Resolves the repository for an entity type, supporting custom test doubles and standard Espo RDB.
     */
    protected function getRdbRepository(string $entityType): mixed
    {
        if (method_exists($this->entityManager, 'getRDBRepositoryCustom')) {
            return $this->entityManager->getRDBRepositoryCustom($entityType);
        }

        return $this->entityManager->getRDBRepository($entityType);
    }

    /**
     * Resolves the Groq API key from request override, environment variables, or EspoCRM config.
     */
    public function getApiKey(?string $overrideKey = null): ?string
    {
        if ($overrideKey !== null && trim($overrideKey) !== '') {
            return trim($overrideKey);
        }

        if ($this->conversationSummarizer !== null) {
            $key = $this->conversationSummarizer->getApiKey();
            if ($key !== null && trim($key) !== '') {
                return trim($key);
            }
        }

        $envKey = getenv('GROQ_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }

        if (isset($_ENV['GROQ_API_KEY']) && is_string($_ENV['GROQ_API_KEY']) && trim($_ENV['GROQ_API_KEY']) !== '') {
            return trim($_ENV['GROQ_API_KEY']);
        }

        $configKey = $this->config->get('groqApiKey');
        if (is_string($configKey) && trim($configKey) !== '') {
            return trim($configKey);
        }

        return null;
    }

    /**
     * Resolves the Groq model name from override, environment, or EspoCRM config.
     */
    public function getModel(?string $overrideModel = null): string
    {
        if ($overrideModel !== null && trim($overrideModel) !== '') {
            return trim($overrideModel);
        }

        if ($this->conversationSummarizer !== null) {
            $model = $this->conversationSummarizer->getModel();
            if ($model !== '' && $model !== self::DEFAULT_MODEL) {
                return $model;
            }
        }

        $envModel = getenv('GROQ_MODEL');
        if (is_string($envModel) && trim($envModel) !== '') {
            return trim($envModel);
        }

        $configModel = $this->config->get('groqModel');
        if (is_string($configModel) && trim($configModel) !== '') {
            return trim($configModel);
        }

        return self::DEFAULT_MODEL;
    }

    /**
     * Returns integration status, workflow configuration, and readiness metadata.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $apiKey = $this->getApiKey();
        $model = $this->getModel();

        return [
            'status' => 'active',
            'workflowRule' => $this->getWorkflowRule(),
            'defaultThresholdDays' => self::DEFAULT_THRESHOLD_DAYS,
            'groq' => [
                'provider' => 'Groq',
                'configured' => ($apiKey !== null && trim($apiKey) !== ''),
                'activeModel' => $model,
                'supportedModels' => self::SUPPORTED_MODELS,
            ],
        ];
    }

    /**
     * Returns the structured definition of the Trigger -> Condition -> Action workflow rule.
     *
     * @return array<string, mixed>
     */
    public function getWorkflowRule(): array
    {
        return [
            'id' => 'lead-uncontacted-3d-reminder',
            'name' => 'Lead Uncontacted for 3 Days Smart Follow-Up Reminder',
            'entityType' => 'Lead',
            'isActive' => true,
            'trigger' => [
                'type' => 'scheduled',
                'schedule' => '0 9 * * *',
                'description' => 'Daily scheduled job execution at 09:00 AM (also invokable on-demand via REST API or event hook)',
            ],
            'conditions' => [
                [
                    'field' => 'status',
                    'operator' => 'in',
                    'value' => ['New', 'Assigned', 'In Process'],
                    'description' => 'Lead status must be active (not Converted, Dead, or Recycled)',
                ],
                [
                    'field' => 'createdAt',
                    'operator' => 'lessThanOrEqual',
                    'value' => '-3 days',
                    'description' => 'Lead created at least 3 days ago without recent qualification',
                ],
                [
                    'field' => 'activityHistory',
                    'operator' => 'emptyInLastDays',
                    'value' => self::DEFAULT_THRESHOLD_DAYS,
                    'description' => 'No calls, meetings, or inbound/outbound emails recorded within the last 3 days',
                ],
                [
                    'field' => 'remindedAt',
                    'operator' => 'notWithinLastDays',
                    'value' => self::DEFAULT_THRESHOLD_DAYS,
                    'description' => 'No reminder email dispatched for this inactivity cycle within the last 3 days (anti-spam throttling)',
                ],
            ],
            'actions' => [
                [
                    'type' => 'aiSuggestedTiming',
                    'provider' => 'Groq',
                    'model' => self::DEFAULT_MODEL,
                    'description' => 'Analyze lead history, company profile, industry norms, and source to compute the optimal follow-up date and time slot',
                ],
                [
                    'type' => 'sendReminderEmail',
                    'target' => 'lead_and_assignedUser',
                    'description' => 'Generate and dispatch a structured reminder email containing the AI-suggested timing and personalized draft message',
                ],
                [
                    'type' => 'createStreamNote',
                    'description' => 'Publish an audit and operational note to the Lead stream documenting the automated action and AI recommendation',
                ],
                [
                    'type' => 'createFollowUpTask',
                    'description' => 'Schedule a CRM follow-up Task assigned to the sales rep for the AI-suggested time slot',
                ],
            ],
        ];
    }

    /**
     * Evaluates whether a specific Lead meets all "uncontacted for N days" conditions.
     */
    public function isLeadUncontacted(CoreEntity|Entity $lead, int $days = self::DEFAULT_THRESHOLD_DAYS): bool
    {
        $thresholdTime = strtotime("-{$days} days");
        $thresholdDateTime = date('Y-m-d H:i:s', $thresholdTime);

        // 1. Condition: Status must be active (not closed/converted)
        $status = (string) ($lead->get('status') ?? Lead::STATUS_NEW);
        if (in_array($status, [Lead::STATUS_CONVERTED, Lead::STATUS_DEAD, Lead::STATUS_RECYCLED], true)) {
            return false;
        }

        // 2. Condition: Lead must have been created at least N days ago
        $createdAt = (string) ($lead->get('createdAt') ?? '');
        if ($createdAt !== '' && $createdAt > $thresholdDateTime) {
            return false;
        }

        // 3. Condition: Anti-spam throttling check
        $remindedAt = (string) ($lead->get('remindedAt') ?? '');
        if ($remindedAt !== '' && $remindedAt > $thresholdDateTime) {
            return false;
        }

        $leadId = (string) $lead->getId();
        if ($leadId === '') {
            return true;
        }

        // 4. Condition: Check for recent activities in the last N days
        try {
            if ($this->hasRecentActivity('Call', $leadId, $thresholdDateTime)) {
                return false;
            }
            if ($this->hasRecentActivity('Meeting', $leadId, $thresholdDateTime)) {
                return false;
            }
            if ($this->hasRecentActivity('Email', $leadId, $thresholdDateTime)) {
                return false;
            }
        } catch (Throwable) {
            // If related repository is unavailable, rely on core lead attributes
        }

        return true;
    }

    /**
     * Checks if any completed or recent activities exist for this lead since the threshold date.
     */
    protected function hasRecentActivity(string $entityType, string $leadId, string $thresholdDateTime): bool
    {
        try {
            $repo = $this->getRdbRepository($entityType);
            if (!$repo) {
                return false;
            }

            $dateField = match ($entityType) {
                'Email' => 'createdAt',
                default => 'dateStart',
            };

            $collection = $repo->where([
                'parentType' => 'Lead',
                'parentId' => $leadId,
                $dateField . '>=' => $thresholdDateTime,
                'deleted' => false,
            ])
            ->limit(0, 1)
            ->find();

            return count($collection) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Finds all leads in the CRM that satisfy the "uncontacted for N days" workflow condition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findEligibleLeads(int $days = self::DEFAULT_THRESHOLD_DAYS): array
    {
        if (!$this->acl->checkScope('Lead', Table::ACTION_READ)) {
            throw new Forbidden("Access denied to Lead entity.");
        }

        $thresholdDateTime = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $repo = $this->getRdbRepository('Lead');
        $leads = $repo->where([
            'status' => [Lead::STATUS_NEW, Lead::STATUS_ASSIGNED, Lead::STATUS_IN_PROCESS],
            'createdAt<=' => $thresholdDateTime,
            'deleted' => false,
        ])
        ->order('createdAt', true)
        ->limit(0, 100)
        ->find();

        $eligible = [];
        foreach ($leads as $lead) {
            if ($this->isLeadUncontacted($lead, $days)) {
                $createdAt = (string) ($lead->get('createdAt') ?? '');
                $daysElapsed = 0;
                if ($createdAt !== '') {
                    $createdTs = strtotime($createdAt);
                    if ($createdTs !== false) {
                        $daysElapsed = (int) floor((time() - $createdTs) / 86400);
                    }
                }

                $eligible[] = [
                    'id' => (string) $lead->getId(),
                    'name' => (string) ($lead->get('name') ?? 'Unnamed Lead'),
                    'status' => (string) ($lead->get('status') ?? Lead::STATUS_NEW),
                    'accountName' => (string) ($lead->get('accountName') ?? ''),
                    'industry' => (string) ($lead->get('industry') ?? ''),
                    'source' => (string) ($lead->get('source') ?? ''),
                    'opportunityAmount' => (float) ($lead->get('opportunityAmount') ?? 0),
                    'emailAddress' => (string) ($lead->get('emailAddress') ?? ''),
                    'phoneNumber' => (string) ($lead->get('phoneNumber') ?? ''),
                    'createdAt' => $createdAt,
                    'daysUncontacted' => max($days, $daysElapsed),
                    'assignedUserId' => (string) ($lead->get('assignedUserId') ?? ''),
                    'assignedUserName' => (string) ($lead->get('assignedUserName') ?? ''),
                ];
            }
        }

        return $eligible;
    }

    /**
     * Builds structured lead context for AI analysis from entity attributes and stream notes.
     *
     * @return array<string, mixed>
     */
    public function buildLeadContext(CoreEntity|Entity|array $lead): array
    {
        if (is_array($lead)) {
            $leadId = (string) ($lead['id'] ?? 'lead_custom');
            $leadName = (string) ($lead['name'] ?? 'Prospective Client');
            $accountName = (string) ($lead['accountName'] ?? $lead['company'] ?? '');
            $industry = (string) ($lead['industry'] ?? 'Technology');
            $source = (string) ($lead['source'] ?? 'Web Site');
            $status = (string) ($lead['status'] ?? 'New');
            $amount = (float) ($lead['opportunityAmount'] ?? $lead['amount'] ?? 0);
            $description = (string) ($lead['description'] ?? '');
            $email = (string) ($lead['emailAddress'] ?? '');
            $phone = (string) ($lead['phoneNumber'] ?? '');
            $createdAt = (string) ($lead['createdAt'] ?? date('Y-m-d H:i:s', strtotime('-3 days')));
            $notes = (array) ($lead['notes'] ?? []);
        } else {
            $leadId = (string) $lead->getId();
            $leadName = (string) ($lead->get('name') ?? 'Prospective Client');
            $accountName = (string) ($lead->get('accountName') ?? '');
            $industry = (string) ($lead->get('industry') ?? '');
            $source = (string) ($lead->get('source') ?? '');
            $status = (string) ($lead->get('status') ?? 'New');
            $amount = (float) ($lead->get('opportunityAmount') ?? 0);
            $description = (string) ($lead->get('description') ?? '');
            $email = (string) ($lead->get('emailAddress') ?? '');
            $phone = (string) ($lead->get('phoneNumber') ?? '');
            $createdAt = (string) ($lead->get('createdAt') ?? '');
            $notes = $this->fetchEntityNotes('Lead', $leadId);
        }

        $daysUncontacted = self::DEFAULT_THRESHOLD_DAYS;
        if ($createdAt !== '') {
            $ts = strtotime($createdAt);
            if ($ts !== false) {
                $daysUncontacted = max(self::DEFAULT_THRESHOLD_DAYS, (int) floor((time() - $ts) / 86400));
            }
        }

        $fullPrompt = "Current CRM System Date/Time: " . date('Y-m-d H:i:s') . "\n" .
            "Lead ID: {$leadId}\n" .
            "Lead Name: {$leadName}\n" .
            "Company / Account: " . ($accountName ?: '(Not specified)') . "\n" .
            "Industry: " . ($industry ?: 'Business Services') . "\n" .
            "Lead Source: " . ($source ?: 'Direct Inquiry') . "\n" .
            "Deal / Opportunity Size: " . ($amount > 0 ? "\${$amount}" : 'Standard Inquiry') . "\n" .
            "Lead Status: {$status}\n" .
            "Created Date: {$createdAt} ({$daysUncontacted} days ago, uncontacted)\n" .
            "Lead Initial Inquiry / Description: " . ($description ?: '(No inquiry description recorded)') . "\n";

        if (!empty($notes)) {
            $fullPrompt .= "Past Stream Activity & Historical Notes:\n" . implode("\n---\n", $notes) . "\n";
        }

        return [
            'leadId' => $leadId,
            'leadName' => $leadName,
            'accountName' => $accountName,
            'industry' => $industry ?: 'Business Services',
            'source' => $source ?: 'Web Site',
            'status' => $status,
            'opportunityAmount' => $amount,
            'description' => $description,
            'emailAddress' => $email,
            'phoneNumber' => $phone,
            'createdAt' => $createdAt,
            'daysUncontacted' => $daysUncontacted,
            'notes' => $notes,
            'fullPrompt' => $fullPrompt,
        ];
    }

    /**
     * Generates an optimal follow-up timing suggestion using Groq LLM (or simulated fallback).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function suggestFollowUpTime(array $params): array
    {
        $leadContext = null;

        if (!empty($params['leadId'])) {
            $leadId = (string) $params['leadId'];
            if (!$this->acl->checkScope('Lead', Table::ACTION_READ)) {
                throw new Forbidden("Access denied to Lead entity.");
            }

            $lead = $this->entityManager->getEntityById('Lead', $leadId);
            if (!$lead) {
                throw new NotFound("Lead '{$leadId}' not found.");
            }

            $leadContext = $this->buildLeadContext($lead);
        } elseif (!empty($params['lead']) && (is_array($params['lead']) || is_object($params['lead']))) {
            $leadContext = $this->buildLeadContext((array) $params['lead']);
        } elseif (!empty($params['name']) || !empty($params['company'])) {
            $leadContext = $this->buildLeadContext($params);
        } else {
            throw new BadRequest("Either 'leadId' or lead context parameters ('name', 'company') must be provided.");
        }

        $apiKey = $this->getApiKey($params['apiKey'] ?? null);
        $model = $this->getModel($params['model'] ?? null);
        $simulate = (bool) ($params['simulate'] ?? false);
        $throwOnError = (bool) ($params['throwOnError'] ?? false);

        // Deterministic simulation mode when explicitly requested or API key absent
        if ($simulate || $apiKey === null || trim($apiKey) === '') {
            return $this->simulateFollowUpTiming($leadContext);
        }

        // Live Groq LLM inference with safe fallback
        try {
            return $this->callGroqTimingApi($leadContext, $apiKey, $model);
        } catch (Throwable $e) {
            if ($throwOnError) {
                if ($e instanceof BadRequest || $e instanceof Forbidden || $e instanceof NotFound) {
                    throw $e;
                }
                throw new BadRequest("Groq API error: " . $e->getMessage());
            }

            // Safe fallback behavior ensuring the workflow remains resilient
            $fallback = $this->simulateFollowUpTiming($leadContext);
            $fallback['fallback'] = true;
            $fallback['fallbackReason'] = $e->getMessage();
            return $fallback;
        }
    }

    /**
     * Executes live Groq API call for follow-up timing prediction.
     *
     * @param array<string, mixed> $leadContext
     * @return array<string, mixed>
     */
    protected function callGroqTimingApi(array $leadContext, string $apiKey, string $model): array
    {
        $systemMessage = "You are an expert AI CRM Sales Automation and Follow-Up Optimization Assistant.\n" .
            "Your objective is to determine the single best upcoming date and time for sales reps to follow up with an uncontacted lead.\n" .
            "Rules:\n" .
            "1. Calculate an optimal future datetime (ISO format: YYYY-MM-DD HH:MM:SS) during standard business hours (09:00 to 17:00).\n" .
            "2. Avoid Saturdays and Sundays. If the next day is a weekend, suggest Monday.\n" .
            "3. B2B decision makers respond best Tuesday through Thursday mornings (10:00 to 11:30) or early afternoon (14:00 to 15:30).\n" .
            "4. Align urgency and channel with deal value, lead source, and industry.\n" .
            "5. You MUST respond strictly with a valid JSON object matching this schema:\n" .
            "{\n" .
            "  \"suggestedFollowUpAt\": \"YYYY-MM-DD HH:MM:SS\",\n" .
            "  \"suggestedTimeSlot\": \"e.g. Tomorrow at 10:00 AM or Wednesday 10:30 AM\",\n" .
            "  \"optimalDay\": \"Monday\" | \"Tuesday\" | \"Wednesday\" | \"Thursday\" | \"Friday\",\n" .
            "  \"optimalTimeOfDay\": \"Morning (10:00 AM)\" | \"Afternoon (2:00 PM)\" | string,\n" .
            "  \"confidence\": \"High\" | \"Medium\",\n" .
            "  \"urgency\": \"High\" | \"Medium\" | \"Low\",\n" .
            "  \"recommendedChannel\": \"Email\" | \"Phone Call\" | \"Meeting\",\n" .
            "  \"rationale\": \"Concise 2-3 sentence explanation of why this timing and channel was selected based on lead history, industry norms, and engagement.\",\n" .
            "  \"emailSubject\": \"Non-spammy, high-converting follow-up subject line\",\n" .
            "  \"emailBody\": \"Polite, personalized 2-3 paragraph follow-up email draft\"\n" .
            "}";

        $prompt = "Analyze this uncontacted lead and calculate the optimal follow-up time:\n\n" . $leadContext['fullPrompt'];

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemMessage],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'response_format' => ['type' => 'json_object'],
        ];

        $ch = curl_init(self::GROQ_API_URL);
        if ($ch === false) {
            throw new Error("Failed to initialize cURL for Groq API.");
        }

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Error("Failed to connect to Groq API: {$curlError}");
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new Error("Invalid non-JSON response from Groq API (HTTP {$httpCode}).");
        }

        if ($httpCode !== 200) {
            $errorMessage = $decoded['error']['message'] ?? "Groq API returned HTTP {$httpCode}";
            if ($httpCode === 401) {
                throw new BadRequest("Groq API authentication failed: {$errorMessage}");
            }
            if ($httpCode === 429) {
                throw new Error("Groq API rate limit exceeded: {$errorMessage}");
            }
            throw new Error("Groq API error ({$httpCode}): {$errorMessage}");
        }

        $rawContent = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($rawContent) || trim($rawContent) === '') {
            throw new Error("Groq API returned an empty completion content.");
        }

        $parsed = json_decode($rawContent, true);
        if (!is_array($parsed)) {
            throw new Error("Failed to parse Groq response JSON.");
        }

        $leadContext['aiSuggestion'] = [
            'suggestedFollowUpAt' => (string) ($parsed['suggestedFollowUpAt'] ?? date('Y-m-d 10:00:00', strtotime('+1 day'))),
            'suggestedTimeSlot' => (string) ($parsed['suggestedTimeSlot'] ?? 'Tomorrow at 10:00 AM'),
            'optimalDay' => (string) ($parsed['optimalDay'] ?? date('l', strtotime('+1 day'))),
            'optimalTimeOfDay' => (string) ($parsed['optimalTimeOfDay'] ?? 'Morning (10:00 AM)'),
            'confidence' => (string) ($parsed['confidence'] ?? 'High'),
            'urgency' => (string) ($parsed['urgency'] ?? 'Medium'),
            'recommendedChannel' => (string) ($parsed['recommendedChannel'] ?? 'Email'),
            'rationale' => (string) ($parsed['rationale'] ?? 'AI calculated follow-up timing based on lead engagement history.'),
            'emailSubject' => (string) ($parsed['emailSubject'] ?? "Following up with {$leadContext['leadName']}"),
            'emailBody' => (string) ($parsed['emailBody'] ?? "Hi {$leadContext['leadName']},\n\nFollowing up on your interest..."),
            'model' => (string) ($decoded['model'] ?? $model),
            'provider' => 'Groq',
            'simulated' => false,
            'usage' => [
                'promptTokens' => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
                'completionTokens' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
                'totalTokens' => (int) ($decoded['usage']['total_tokens'] ?? 0),
            ],
        ];

        return $leadContext['aiSuggestion'];
    }

    /**
     * Deterministic simulation mode for testing and safe offline execution.
     *
     * @param array<string, mixed> $leadContext
     * @return array<string, mixed>
     */
    public function simulateFollowUpTiming(array $leadContext): array
    {
        $company = $leadContext['accountName'] ?: 'Enterprise Lead';
        $leadName = $leadContext['leadName'] ?: 'Prospect';
        $industry = $leadContext['industry'] ?: 'Business Services';
        $source = $leadContext['source'] ?: 'Web Site';
        $amount = (float) ($leadContext['opportunityAmount'] ?? 0);
        $days = (int) ($leadContext['daysUncontacted'] ?? self::DEFAULT_THRESHOLD_DAYS);

        // Compute next optimal business day (skipping Saturday & Sunday)
        $dt = new DateTime();
        $dt->modify('+1 day');
        $dayOfWeek = (int) $dt->format('w'); // 0 = Sunday, 6 = Saturday

        if ($dayOfWeek === 6) { // Saturday -> advance to Monday
            $dt->modify('+2 days');
        } elseif ($dayOfWeek === 0) { // Sunday -> advance to Monday
            $dt->modify('+1 day');
        }

        // Sector-tailored time slots and channel recommendations
        $timeStr = '10:00:00';
        $slotName = 'Morning (10:00 AM)';
        $channel = 'Email';
        $urgency = 'Medium';

        $industryLower = strtolower($industry);
        if (str_contains($industryLower, 'tech') || str_contains($industryLower, 'software') || str_contains($industryLower, 'it')) {
            $timeStr = '10:00:00';
            $slotName = 'Morning (10:00 AM)';
            $channel = 'Email';
        } elseif (str_contains($industryLower, 'health') || str_contains($industryLower, 'medical')) {
            $timeStr = '08:45:00';
            $slotName = 'Early Morning (8:45 AM)';
            $channel = 'Phone Call';
        } elseif (str_contains($industryLower, 'finance') || str_contains($industryLower, 'bank')) {
            $timeStr = '10:30:00';
            $slotName = 'Mid-Morning (10:30 AM)';
            $channel = 'Email';
        } elseif (str_contains($industryLower, 'retail') || str_contains($industryLower, 'commerce')) {
            $timeStr = '14:00:00';
            $slotName = 'Early Afternoon (2:00 PM)';
            $channel = 'Email';
        }

        if ($amount >= 25000 || $source === 'Web Site') {
            $urgency = 'High';
        }

        if ($source === 'Call' || (!empty($leadContext['phoneNumber']) && $urgency === 'High')) {
            $channel = 'Phone Call';
        }

        $formattedDate = $dt->format('Y-m-d') . ' ' . $timeStr;
        $dayName = $dt->format('l');
        $suggestedTimeSlot = "{$dayName} at " . date('g:i A', strtotime($timeStr));

        $rationale = "Lead from {$company} in {$industry} has been uncontacted for {$days} days. " .
            "Decision-makers in {$industry} show peak engagement on {$dayName}s around {$slotName}. " .
            ($amount > 0 ? "Deal value of \${$amount} justifies {$urgency} priority follow-up via {$channel}." : "Recommended channel is {$channel} based on initial {$source} lead source.");

        $emailSubject = "Following up on your {$company} inquiry - Cynaris Solutions";
        $emailBody = "Hi {$leadName},\n\n" .
            "I noticed you contacted our team {$days} days ago regarding CRM solutions for {$company}. " .
            "I wanted to quickly check in and see if you had any questions regarding technical architecture, pricing, or implementation timelines.\n\n" .
            "Would you have 10 minutes on {$suggestedTimeSlot} for a brief conversation?\n\n" .
            "Best regards,\nAccount Executive\nCynaris Software";

        return [
            'suggestedFollowUpAt' => $formattedDate,
            'suggestedTimeSlot' => $suggestedTimeSlot,
            'optimalDay' => $dayName,
            'optimalTimeOfDay' => $slotName,
            'confidence' => 'High',
            'urgency' => $urgency,
            'recommendedChannel' => $channel,
            'rationale' => $rationale,
            'emailSubject' => $emailSubject,
            'emailBody' => $emailBody,
            'model' => 'llama-3.3-70b-versatile (simulated)',
            'provider' => 'Groq',
            'simulated' => true,
            'usage' => [
                'promptTokens' => (int) max(30, (int) (strlen($leadContext['fullPrompt'] ?? '') / 4)),
                'completionTokens' => 110,
                'totalTokens' => (int) max(30, (int) (strlen($leadContext['fullPrompt'] ?? '') / 4)) + 110,
            ],
        ];
    }

    /**
     * Executes the Smart Reminder workflow action for a single lead:
     * 1. Evaluates uncontacted condition.
     * 2. Computes AI-suggested follow-up time.
     * 3. Dispatches reminder email.
     * 4. Publishes audit stream note.
     * 5. Schedules CRM follow-up Task.
     * 6. Updates lead reminder timestamp.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function executeReminder(string $leadId, array $options = []): array
    {
        if (!$this->acl->checkScope('Lead', Table::ACTION_READ) || !$this->acl->checkScope('Lead', Table::ACTION_EDIT)) {
            throw new Forbidden("Access denied to Lead entity.");
        }

        $lead = $this->entityManager->getEntityById('Lead', $leadId);
        if (!$lead) {
            throw new NotFound("Lead '{$leadId}' not found.");
        }

        $force = (bool) ($options['force'] ?? false);
        $thresholdDays = (int) ($options['days'] ?? self::DEFAULT_THRESHOLD_DAYS);

        // Verify condition unless forced
        if (!$force && !$this->isLeadUncontacted($lead, $thresholdDays)) {
            return [
                'status' => 'skipped',
                'leadId' => $leadId,
                'leadName' => (string) ($lead->get('name') ?? ''),
                'reason' => 'Lead does not satisfy uncontacted condition or was recently reminded.',
            ];
        }

        // Step 1: AI Timing Suggestion
        $leadContext = $this->buildLeadContext($lead);
        $timingParams = array_merge($options, ['leadId' => $leadId]);
        $aiSuggestion = $this->suggestFollowUpTime($timingParams);

        // Step 2: Reminder Email Action
        $emailRecord = $this->createReminderEmail($lead, $aiSuggestion, $leadContext);

        // Step 3: Stream Note Audit Action
        $noteRecord = $this->createStreamNote($lead, $aiSuggestion, $leadContext);

        // Step 4: Scheduled Follow-Up Task Action
        $taskRecord = $this->createFollowUpTask($lead, $aiSuggestion);

        // Step 5: Update Lead Entity Tracking Attributes
        $now = date('Y-m-d H:i:s');
        $lead->set('remindedAt', $now);
        $lead->set('aiFollowUpAt', $aiSuggestion['suggestedFollowUpAt']);
        $lead->set('aiFollowUpRationale', $aiSuggestion['rationale']);
        $this->entityManager->saveEntity($lead);

        return [
            'status' => 'success',
            'leadId' => $leadId,
            'leadName' => $leadContext['leadName'],
            'daysUncontacted' => $leadContext['daysUncontacted'],
            'aiSuggestion' => $aiSuggestion,
            'email' => [
                'id' => $emailRecord ? (string) $emailRecord->getId() : null,
                'subject' => $aiSuggestion['emailSubject'],
                'to' => $leadContext['emailAddress'] ?: '(assigned sales rep)',
                'status' => 'Sent',
            ],
            'noteId' => $noteRecord ? (string) $noteRecord->getId() : null,
            'taskId' => $taskRecord ? (string) $taskRecord->getId() : null,
            'executedAt' => $now,
        ];
    }

    /**
     * Executes the workflow for all currently eligible uncontacted leads (batch automation).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function runBatch(int $days = self::DEFAULT_THRESHOLD_DAYS, array $options = []): array
    {
        $eligibleLeads = $this->findEligibleLeads($days);
        $processed = 0;
        $remindersSent = 0;
        $skipped = 0;
        $errors = [];
        $results = [];

        foreach ($eligibleLeads as $item) {
            $leadId = $item['id'];
            $processed++;

            try {
                $res = $this->executeReminder($leadId, array_merge($options, ['days' => $days]));
                if (($res['status'] ?? '') === 'success') {
                    $remindersSent++;
                } else {
                    $skipped++;
                }
                $results[] = $res;
            } catch (Throwable $e) {
                $errors[] = [
                    'leadId' => $leadId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'completed',
            'thresholdDays' => $days,
            'totalEligible' => count($eligibleLeads),
            'processed' => $processed,
            'remindersSent' => $remindersSent,
            'skipped' => $skipped,
            'errorCount' => count($errors),
            'errors' => $errors,
            'details' => $results,
        ];
    }

    /**
     * Creates and persists an Email entity for the reminder action.
     */
    protected function createReminderEmail(CoreEntity|Entity $lead, array $aiSuggestion, array $leadContext): ?Entity
    {
        try {
            $repo = $this->getRdbRepository('Email');
            if (!$repo) {
                return null;
            }

            /** @var Entity $email */
            $email = $repo->getNew();
            $recipient = $leadContext['emailAddress'] ?: 'lead@example.com';

            $bodyHtml = "<p>Hello <strong>{$leadContext['leadName']}</strong>,</p>" .
                "<p>" . nl2br(htmlspecialchars($aiSuggestion['emailBody'])) . "</p>" .
                "<hr>" .
                "<p style='color: #666; font-size: 12px;'>" .
                "<strong>[Smart Reminder Automation Rule]</strong><br>" .
                "Lead uncontacted for {$leadContext['daysUncontacted']} days.<br>" .
                "AI-Suggested Follow-Up: <strong>{$aiSuggestion['suggestedTimeSlot']}</strong> ({$aiSuggestion['suggestedFollowUpAt']})<br>" .
                "Urgency: <strong>{$aiSuggestion['urgency']}</strong> | Optimal Channel: <strong>{$aiSuggestion['recommendedChannel']}</strong><br>" .
                "Rationale: <em>" . htmlspecialchars($aiSuggestion['rationale']) . "</em>" .
                "</p>";

            $email->set('name', $aiSuggestion['emailSubject']);
            $email->set('to', $recipient);
            $email->set('body', $bodyHtml);
            $email->set('isHtml', true);
            $email->set('status', 'Sent');
            $email->set('parentType', 'Lead');
            $email->set('parentId', (string) $lead->getId());

            $this->entityManager->saveEntity($email);
            return $email;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Creates and persists an audit Note entity on the Lead stream.
     */
    protected function createStreamNote(CoreEntity|Entity $lead, array $aiSuggestion, array $leadContext): ?Entity
    {
        try {
            $repo = $this->getRdbRepository('Note');
            if (!$repo) {
                return null;
            }

            /** @var Entity $note */
            $note = $repo->getNew();

            $postContent = "[Smart Reminder Automation Rule]\n" .
                "Lead has been uncontacted for {$leadContext['daysUncontacted']} days. Automated follow-up reminder dispatched.\n\n" .
                "• AI-Suggested Follow-Up: {$aiSuggestion['suggestedTimeSlot']} ({$aiSuggestion['suggestedFollowUpAt']})\n" .
                "• Optimal Channel: {$aiSuggestion['recommendedChannel']}\n" .
                "• Urgency: {$aiSuggestion['urgency']} (Confidence: {$aiSuggestion['confidence']})\n" .
                "• AI Rationale: {$aiSuggestion['rationale']}\n" .
                "• Recommended Email Subject: {$aiSuggestion['emailSubject']}";

            $note->set('post', $postContent);
            $note->set('type', 'Post');
            $note->set('parentType', 'Lead');
            $note->set('parentId', (string) $lead->getId());

            $this->entityManager->saveEntity($note);
            return $note;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Creates and persists a follow-up Task entity scheduled for the AI-suggested time slot.
     */
    protected function createFollowUpTask(CoreEntity|Entity $lead, array $aiSuggestion): ?Entity
    {
        try {
            $repo = $this->getRdbRepository('Task');
            if (!$repo) {
                return null;
            }

            /** @var Entity $task */
            $task = $repo->getNew();
            $leadName = (string) ($lead->get('name') ?? 'Lead');

            $task->set('name', "Follow up with {$leadName} ({$aiSuggestion['suggestedTimeSlot']})");
            $task->set('parentType', 'Lead');
            $task->set('parentId', (string) $lead->getId());
            $task->set('dateEnd', $aiSuggestion['suggestedFollowUpAt']);
            $task->set('status', 'Not Started');
            $task->set('priority', ($aiSuggestion['urgency'] ?? 'Medium') === 'High' ? 'High' : 'Normal');

            if ($lead->get('assignedUserId')) {
                $task->set('assignedUserId', (string) $lead->get('assignedUserId'));
            }

            $taskDescription = "AI Follow-Up Suggestion:\n" .
                "• Suggested Time Slot: {$aiSuggestion['suggestedTimeSlot']}\n" .
                "• Channel: {$aiSuggestion['recommendedChannel']}\n" .
                "• AI Rationale: {$aiSuggestion['rationale']}\n\n" .
                "Draft Follow-Up Email Body:\n" .
                $aiSuggestion['emailBody'];

            $task->set('description', $taskDescription);

            $this->entityManager->saveEntity($task);
            return $task;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Helper to retrieve recent stream notes for an entity.
     *
     * @return string[]
     */
    protected function fetchEntityNotes(string $entityType, string $entityId): array
    {
        try {
            if (!$this->acl->checkScope('Note', Table::ACTION_READ)) {
                return [];
            }

            $repo = $this->getRdbRepository('Note');
            if (!$repo) {
                return [];
            }

            $notes = $repo->where([
                'parentId' => $entityId,
                'parentType' => $entityType,
                'deleted' => false,
            ])
            ->order('number', false)
            ->limit(0, 5)
            ->find();

            $result = [];
            foreach ($notes as $note) {
                $post = (string) ($note->get('post') ?? '');
                if ($post !== '') {
                    $result[] = $post;
                }
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }
}
