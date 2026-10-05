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

namespace Espo\Custom\Controllers;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Custom\Services\LeadReminderWorkflowService;

/**
 * Controller exposing REST API endpoints for Lead Uncontacted Smart Reminders & AI Timing.
 */
class LeadReminderWorkflow
{
    public static string $defaultAction = 'status';

    public function __construct(
        private LeadReminderWorkflowService $service,
        private Acl $acl
    ) {}

    /**
     * GET /api/v1/LeadReminderWorkflow/status
     * Returns workflow configuration, trigger schedule, conditions, and Groq readiness.
     *
     * @return array<string, mixed>
     */
    public function getActionStatus(Request $request): array
    {
        return $this->service->getStatus();
    }

    /**
     * Fallback alias for status action.
     *
     * @return array<string, mixed>
     */
    public function actionStatus(Request $request): array
    {
        return $this->getActionStatus($request);
    }

    /**
     * GET /api/v1/LeadReminderWorkflow/eligibleLeads
     * Returns list of leads that have been uncontacted for >= threshold days.
     *
     * @return array<string, mixed>
     * @throws Forbidden
     */
    public function getActionEligibleLeads(Request $request): array
    {
        $params = $this->extractParams($request);
        $days = isset($params['days']) ? (int) $params['days'] : LeadReminderWorkflowService::DEFAULT_THRESHOLD_DAYS;

        $leads = $this->service->findEligibleLeads($days);

        return [
            'status' => 'success',
            'thresholdDays' => $days,
            'count' => count($leads),
            'list' => $leads,
        ];
    }

    /**
     * Fallback alias for eligibleLeads action.
     *
     * @return array<string, mixed>
     * @throws Forbidden
     */
    public function actionEligibleLeads(Request $request): array
    {
        return $this->getActionEligibleLeads($request);
    }

    /**
     * POST /api/v1/LeadReminderWorkflow/suggestTiming
     * Uses Groq LLM (or simulation fallback) to predict optimal follow-up time for a lead.
     *
     * @return array<string, mixed>
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionSuggestTiming(Request $request): array
    {
        $params = $this->extractParams($request);
        if (empty($params)) {
            throw new BadRequest("Empty request payload. Provide 'leadId' or lead profile.");
        }

        $suggestion = $this->service->suggestFollowUpTime($params);

        return [
            'status' => 'success',
            'data' => $suggestion,
        ];
    }

    /**
     * Fallback alias for suggestTiming action.
     *
     * @return array<string, mixed>
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function actionSuggestTiming(Request $request): array
    {
        return $this->postActionSuggestTiming($request);
    }

    /**
     * POST /api/v1/LeadReminderWorkflow/execute
     * Executes the reminder workflow for a specific lead:
     * Condition check -> AI timing -> Email dispatch -> Task creation -> Stream note.
     *
     * @return array<string, mixed>
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionExecute(Request $request): array
    {
        $params = $this->extractParams($request);
        $leadId = (string) ($params['leadId'] ?? '');

        if ($leadId === '') {
            throw new BadRequest("Parameter 'leadId' is required.");
        }

        return $this->service->executeReminder($leadId, $params);
    }

    /**
     * Fallback alias for execute action.
     *
     * @return array<string, mixed>
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function actionExecute(Request $request): array
    {
        return $this->postActionExecute($request);
    }

    /**
     * POST /api/v1/LeadReminderWorkflow/runBatch
     * Triggers batch workflow execution across all eligible uncontacted leads.
     *
     * @return array<string, mixed>
     * @throws Forbidden
     */
    public function postActionRunBatch(Request $request): array
    {
        $params = $this->extractParams($request);
        $days = isset($params['days']) ? (int) $params['days'] : LeadReminderWorkflowService::DEFAULT_THRESHOLD_DAYS;

        return $this->service->runBatch($days, $params);
    }

    /**
     * Fallback alias for runBatch action.
     *
     * @return array<string, mixed>
     * @throws Forbidden
     */
    public function actionRunBatch(Request $request): array
    {
        return $this->postActionRunBatch($request);
    }

    /**
     * Helper to extract request parameters from body and query string.
     *
     * @return array<string, mixed>
     */
    private function extractParams(Request $request): array
    {
        $body = $request->getParsedBody();
        $params = is_object($body) ? (array) $body : (is_array($body) ? $body : []);

        if (empty($params)) {
            $rawContent = $request->getBodyContents();
            if ($rawContent) {
                $decoded = json_decode($rawContent, true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }
        }

        $queryParams = $request->getQueryParams();
        if (is_array($queryParams)) {
            $params = array_merge($queryParams, $params);
        }

        return $params;
    }
}
