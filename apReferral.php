<?php
/**
 * AP Student Support Referral API for ACS Academy
 * Handles student referral operations with Dataverse
 * 
 * Endpoint: parents.acsacademy.edu.sg/api/apReferral.php
 * 
 * Azure AD App Registration:
 * - Application (client) ID: 7d1340e2-9fa9-4a80-8b89-93e5bced6ebc
 * - Directory (tenant) ID: 6dff32de-1cd0-4ada-892b-2298e1f61698
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// ============================================================================
// SAFETY: ensure we return JSON even on fatal errors
// (Apache/PHP can otherwise return a blank 500 with no body)
// ============================================================================
register_shutdown_function(function () {
    $err = error_get_last();
    if (!$err) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) return;

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    // Avoid leaking secrets; just return location + message.
    echo json_encode([
        'error' => 'PHP fatal error',
        'message' => $err['message'] ?? 'Fatal error',
        'file' => $err['file'] ?? null,
        'line' => $err['line'] ?? null
    ]);
});

set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
});

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================================
// BASIC API KEY GATE (simple shared secret via query param)
// - Set AP_REFERRAL_API_KEY (or AP_API_KEY) on the server.
// - Clients must call: apReferral.php?...&key=YOUR_KEY
// NOTE: This is "basic gating" only; the key is visible in browser clients.
// ============================================================================
$API_KEY_REQUIRED = getenv('AP_REFERRAL_API_KEY') ?: getenv('AP_API_KEY') ?: null;
if (!empty($API_KEY_REQUIRED)) {
    $provided = $_GET['key'] ?? '';
    if (!$provided || !hash_equals((string)$API_KEY_REQUIRED, (string)$provided)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ============================================================================
// AZURE AD CONFIGURATION - Load from environment variables
// ============================================================================
$CLIENT_ID = getenv('AZURE_CLIENT_ID2');
$CLIENT_SECRET = getenv('AZURE_CLIENT_SECRET2');
$TENANT_ID = getenv('AZURE_TENANT_ID');
$DATAVERSE_URL = getenv('DATAVERSE_URL');

// ============================================================================
// DATAVERSE TABLE NAMES
// ============================================================================
// Classes and Students tables (same as attendance app)
$CLASSES_TABLE = 'crd88_classeses';
$CLASSES_TABLE_LOGICAL = 'crd88_classes';
$STUDENTS_TABLE = 'new_studentses';
$STUDENTS_TABLE_LOGICAL = 'new_students';

// AP Referral tables (updated hierarchy)
// ReferralToAP (grandparent) -> Action Plan (parent) -> Goal (child)
$REFERRAL_TABLE = getenv('DV_REFERRAL_TABLE') ?: 'crd88_referraltoaps';
$REFERRAL_TABLE_LOGICAL = getenv('DV_REFERRAL_TABLE_LOGICAL') ?: 'crd88_referraltoap';

$ACTIONPLAN_TABLE = getenv('DV_ACTIONPLAN_TABLE') ?: 'crd88_actionplans';
$ACTIONPLAN_TABLE_LOGICAL = getenv('DV_ACTIONPLAN_TABLE_LOGICAL') ?: 'crd88_actionplan';

$GOAL_TABLE = getenv('DV_GOAL_TABLE') ?: 'crd88_goals';
$GOAL_TABLE_LOGICAL = getenv('DV_GOAL_TABLE_LOGICAL') ?: 'crd88_goal';

// ============================================================================
// DATAVERSE FIELD NAMES
// ============================================================================
// Classes table fields
$CLASS_ID_FIELD = 'crd88_classesid';
$CLASS_NAME_FIELD = 'crd88_classid';

// Students table fields
$STUDENT_ID_FIELD = 'new_studentsid';
$STUDENT_NAME_FIELD = 'new_fullname';
$STUDENT_NUMBER_FIELD = 'crd88_indexnumber';
$STUDENT_CLASS_FIELD = 'crd88_class';

// ReferralToAP fields (defaults preserve existing column logical names where possible)
$REFERRAL_ID_FIELD = getenv('DV_REFERRAL_ID_FIELD') ?: 'crd88_referraltoapid';
$REFERRAL_REFID_FIELD = getenv('DV_REFERRAL_REFID_FIELD') ?: 'crd88_refid'; // Primary name/autonumber

// Student lookup on ReferralToAP
$REFERRAL_STUDENT_FIELD = getenv('DV_REFERRAL_STUDENT_FIELD') ?: 'crd88_student'; // lookup logical name
// IMPORTANT: Dataverse Web API uses the NAVIGATION PROPERTY for @odata.bind. Often it matches the lookup logical name,
// but it can differ. Override with DV_REFERRAL_STUDENT_NAV if needed.
$REFERRAL_STUDENT_NAV = getenv('DV_REFERRAL_STUDENT_NAV') ?: $REFERRAL_STUDENT_FIELD;

// Referral details
$REFERRAL_DOMAINS_ISSUES_FIELD = getenv('DV_REFERRAL_DOMAINS_ISSUES_FIELD') ?: 'crd88_domainsandconditions';
$REFERRAL_STRENGTHS_FIELD = getenv('DV_REFERRAL_STRENGTHS_FIELD') ?: 'crd88_strengths';
$REFERRAL_MEETING_NOTES_FIELD = getenv('DV_REFERRAL_MEETING_NOTES_FIELD') ?: 'crd88_meetingnotes';
$REFERRAL_FINAL_OUTCOME_FIELD = getenv('DV_REFERRAL_FINAL_OUTCOME_FIELD') ?: 'crd88_referralfinaloutcome';
$REFERRAL_INTERVENTION_COMPLETE_FIELD = getenv('DV_REFERRAL_INTERVENTION_COMPLETE_FIELD') ?: 'crd88_referralcompleted';

// For later: subjects fields on ReferralToAP (kept optional; only written if provided)
$REFERRAL_TRIGGER_SUBJECTS_FIELD = getenv('DV_REFERRAL_TRIGGER_SUBJECTS_FIELD') ?: 'crd88_triggersubjects';
$REFERRAL_PREFERRED_SUBJECTS_FIELD = getenv('DV_REFERRAL_PREFERRED_SUBJECTS_FIELD') ?: 'crd88_preferredsubjects';

// Action Plan fields
$ACTIONPLAN_ID_FIELD = getenv('DV_ACTIONPLAN_ID_FIELD') ?: 'crd88_actionplanid';
$ACTIONPLAN_ACTIONID_FIELD = getenv('DV_ACTIONPLAN_ACTIONID_FIELD') ?: 'crd88_actionid'; // Primary name/autonumber

// Lookup on Action Plan pointing to ReferralToAP
$ACTIONPLAN_REFERRAL_LOOKUP_FIELD = getenv('DV_ACTIONPLAN_REFERRAL_LOOKUP_FIELD') ?: 'crd88_referraltoap';
$ACTIONPLAN_REFERRAL_NAV = getenv('DV_ACTIONPLAN_REFERRAL_NAV') ?: $ACTIONPLAN_REFERRAL_LOOKUP_FIELD;

// These defaults mirror the prior single-table column names; override to match your Action Plan table columns.
$ACTIONPLAN_TEXT_FIELD = getenv('DV_ACTIONPLAN_TEXT_FIELD') ?: 'crd88_actionplandetails';
$ACTIONPLAN_ACTION_BY_FIELD = getenv('DV_ACTIONPLAN_ACTION_BY_FIELD') ?: 'crd88_actionby';
$ACTIONPLAN_REVIEW_DATE_FIELD = getenv('DV_ACTIONPLAN_REVIEW_DATE_FIELD') ?: 'crd88_reviewdate';
$ACTIONPLAN_MEETING_NOTES_FIELD = getenv('DV_ACTIONPLAN_MEETING_NOTES_FIELD') ?: 'crd88_actionplanmeetingnotes';
// Action Plan status (Option Set): In Progress=5, Completed=7 (configurable)
$ACTIONPLAN_STATUS_FIELD = getenv('DV_ACTIONPLAN_STATUS_FIELD') ?: 'crd88_actionplanstatus';

// Goal fields
$GOAL_ID_FIELD = getenv('DV_GOAL_ID_FIELD') ?: 'crd88_goalid';
$GOAL_NAME_FIELD = getenv('DV_GOAL_NAME_FIELD') ?: 'crd88_goalid1'; // Primary name/autonumber

// Lookup on Goal pointing to Action Plan
$GOAL_ACTIONPLAN_LOOKUP_FIELD = getenv('DV_GOAL_ACTIONPLAN_LOOKUP_FIELD') ?: 'crd88_actionplan';
$GOAL_ACTIONPLAN_NAV = getenv('DV_GOAL_ACTIONPLAN_NAV') ?: $GOAL_ACTIONPLAN_LOOKUP_FIELD;

// Text/description column on Goal (override as needed)
$GOAL_TEXT_FIELD = getenv('DV_GOAL_TEXT_FIELD') ?: 'crd88_goaldetails';

// Optional newer schema: Goal can link directly to ReferralToAP (Goal-first UX)
$GOAL_REFERRAL_LOOKUP_FIELD = getenv('DV_GOAL_REFERRAL_LOOKUP_FIELD') ?: 'crd88_referraltoap';
$GOAL_REFERRAL_NAV = getenv('DV_GOAL_REFERRAL_NAV') ?: $GOAL_REFERRAL_LOOKUP_FIELD;

// Optional newer schema: Action Plan can link to a Goal (per-goal action plans)
$ACTIONPLAN_GOAL_LOOKUP_FIELD = getenv('DV_ACTIONPLAN_GOAL_LOOKUP_FIELD') ?: 'crd88_goal';
$ACTIONPLAN_GOAL_NAV = getenv('DV_ACTIONPLAN_GOAL_NAV') ?: $ACTIONPLAN_GOAL_LOOKUP_FIELD;

/**
 * Helper: safely build odata bind path.
 */
function odataBind($entitySetName, $id) {
    $clean = trim((string)$id);
    return "/{$entitySetName}({$clean})";
}

/**
 * Helper: treat null/empty strings as empty.
 */
function isBlank($v) {
    return $v === null || (is_string($v) && trim($v) === '');
}

/**
 * Helper: detect Dataverse "undeclared property" payload errors for a specific property.
 */
function dataverseUndeclaredProperty($rawBody) {
    if (!$rawBody || !is_string($rawBody)) return null;
    // Example: "An undeclared property 'crd88_new_students' which only has property annotations..."
    if (preg_match("/An undeclared property '([^']+)'/i", $rawBody, $m)) {
        return $m[1] ?? null;
    }
    return null;
}

/**
 * Helper: detect Dataverse "invalid property" errors.
 */
function dataverseInvalidProperty($rawBody) {
    if (!$rawBody || !is_string($rawBody)) return null;
    // Example: "Invalid property 'crd88_domainsandissues' was found in entity ..."
    if (preg_match("/Invalid property '([^']+)'/i", $rawBody, $m)) {
        return $m[1] ?? null;
    }
    // Example: "Could not find a property named 'crd88_referraltoap' on type ..."
    if (preg_match("/property named '([^']+)'/i", $rawBody, $m)) {
        return $m[1] ?? null;
    }
    return null;
}

/**
 * Helper: discover Many-to-One relationship nav property + lookup attribute.
 * Used to reliably bind/look up related records without guessing schema.
 *
 * Returns array of ['nav' => <navigationPropertyName>, 'attr' => <referencingAttribute>, 'schema' => <schemaName>]
 */
function discoverManyToOneRelationshipToTarget($dataverseUrl, $accessToken, $entityLogicalName, $targetEntityLogicalName) {
    static $cache = [];
    $key = "{$entityLogicalName}=>{$targetEntityLogicalName}";
    if (isset($cache[$key])) return $cache[$key];

    $safeEntity = str_replace("'", "''", (string)$entityLogicalName);
    $url = "{$dataverseUrl}/api/data/v9.2/EntityDefinitions(LogicalName='{$safeEntity}')/ManyToOneRelationships"
        . "?\$select=ReferencingAttribute,ReferencingEntityNavigationPropertyName,ReferencedEntity,SchemaName";

    $res = makeDataverseRequest('GET', $url, $accessToken);
    if (($res['status'] ?? 0) !== 200) {
        $cache[$key] = [];
        return [];
    }

    $data = json_decode($res['body'] ?? '', true);
    $rels = $data['value'] ?? [];
    $out = [];
    foreach ($rels as $r) {
        $refEntity = $r['ReferencedEntity'] ?? null;
        if (!$refEntity) continue;
        if (strcasecmp((string)$refEntity, (string)$targetEntityLogicalName) !== 0) continue;

        $nav = $r['ReferencingEntityNavigationPropertyName'] ?? null;
        $attr = $r['ReferencingAttribute'] ?? null;
        $schema = $r['SchemaName'] ?? null;
        if ($nav || $attr) {
            $out[] = ['nav' => $nav, 'attr' => $attr, 'schema' => $schema];
        }
    }

    // Prefer relationships whose schema contains "goal" (when multiple exist)
    usort($out, function ($a, $b) {
        $as = strtolower((string)($a['schema'] ?? ''));
        $bs = strtolower((string)($b['schema'] ?? ''));
        $aw = (strpos($as, 'goal') !== false) ? 0 : 1;
        $bw = (strpos($bs, 'goal') !== false) ? 0 : 1;
        return $aw <=> $bw;
    });

    $cache[$key] = $out;
    return $out;
}

/**
 * Helper: create ReferralToAP with fallback nav-property names for student lookup.
 */
function createReferralWithStudentBindFallback(
    $dataverseUrl,
    $accessToken,
    $referralTable,
    $studentsTable,
    $studentId,
    $interventionCompleteField,
    $domainsAndIssuesField,
    $domainsAndIssues,
    $strengthsField,
    $strengths,
    $triggerSubjectsField,
    $triggerSubjects,
    $preferredSubjectsField,
    $preferredSubjects,
    $preferredStudentNav,
    $fallbackStudentNavs
) {
    $navCandidates = [];
    if (!empty($preferredStudentNav)) $navCandidates[] = $preferredStudentNav;
    foreach ($fallbackStudentNavs as $n) {
        if (!empty($n) && !in_array($n, $navCandidates, true)) $navCandidates[] = $n;
    }

    $attempts = [];
    $last = null;
    foreach ($navCandidates as $nav) {
        // Build payload with optional fields; if Dataverse rejects a field name,
        // we retry without that field (common during schema transitions).
        $referralData = [
            "{$nav}@odata.bind" => odataBind($studentsTable, $studentId),
            $interventionCompleteField => false,
        ];
        if (!empty($domainsAndIssuesField)) $referralData[$domainsAndIssuesField] = $domainsAndIssues;
        if (!empty($strengthsField)) $referralData[$strengthsField] = $strengths;
        if (!empty($triggerSubjectsField) && !isBlank($triggerSubjects)) $referralData[$triggerSubjectsField] = $triggerSubjects;
        if (!empty($preferredSubjectsField) && !isBlank($preferredSubjects)) $referralData[$preferredSubjectsField] = $preferredSubjects;

        $createUrl = "{$dataverseUrl}/api/data/v9.2/{$referralTable}";
        $removed = [];
        while (true) {
            $createResult = makeDataverseRequest('POST', $createUrl, $accessToken, $referralData);
            $last = $createResult;
            $attempts[] = [
                'studentNav' => $nav,
                'status' => $createResult['status'] ?? null,
                'undeclaredProperty' => dataverseUndeclaredProperty($createResult['body'] ?? ''),
                'invalidProperty' => dataverseInvalidProperty($createResult['body'] ?? ''),
                'removedOptionalFields' => array_keys($removed),
            ];

            if ($createResult['status'] === 201 || $createResult['status'] === 204) {
                $createResult['meta'] = ['usedStudentNav' => $nav, 'attempts' => $attempts];
                return $createResult;
            }

            // If this failed due to an undeclared student nav property, try next nav candidate
            $undeclared = dataverseUndeclaredProperty($createResult['body'] ?? '');
            if ($undeclared && strcasecmp($undeclared, $nav) === 0) {
                break; // break inner loop and continue outer loop
            }

            // If this failed due to an invalid optional field, remove and retry once per field
            $invalid = dataverseInvalidProperty($createResult['body'] ?? '');
            if ($invalid && empty($removed[$invalid]) && array_key_exists($invalid, $referralData)) {
                unset($referralData[$invalid]);
                $removed[$invalid] = true;
                continue;
            }

            // Otherwise stop (it's a different error)
            break;
        }
    }
    $ret = $last ?: ['status' => 500, 'body' => json_encode(['error' => 'Referral create failed'])];
    $ret['meta'] = ['attempts' => $attempts];
    return $ret;
}

/**
 * Helper: find referral id from an Action Plan record (best-effort across environments).
 */
function actionPlanGetReferralId($ap, $configuredLookupField) {
    if (!is_array($ap)) return null;
    $k1 = "_{$configuredLookupField}_value";
    if (isset($ap[$k1]) && $ap[$k1]) return $ap[$k1];
    // Common variants
    if (isset($ap['_crd88_referraltoap_value']) && $ap['_crd88_referraltoap_value']) return $ap['_crd88_referraltoap_value'];
    if (isset($ap['_crd88_referralid_value']) && $ap['_crd88_referralid_value']) return $ap['_crd88_referralid_value'];
    if (isset($ap['_crd88_referral_value']) && $ap['_crd88_referral_value']) return $ap['_crd88_referral_value'];
    return null;
}

/**
 * Helper: query latest action plan for a referral with fallback scanning.
 */
function fetchLatestActionPlanForReferral(
    $dataverseUrl,
    $accessToken,
    $actionPlanTable,
    $configuredReferralLookupField,
    $referralId,
    $topScan = 50
) {
    // First attempt: normal filtered query using configured lookup field
    $filter = urlencode("_{$configuredReferralLookupField}_value eq {$referralId}");
    $orderby = urlencode("createdon desc");
    $top = 1;
    $url = "{$dataverseUrl}/api/data/v9.2/{$actionPlanTable}?\$filter={$filter}&\$orderby={$orderby}&\$top={$top}";
    $res = makeDataverseRequest('GET', $url, $accessToken);
    if ($res['status'] === 200) {
        $data = json_decode($res['body'], true);
        $items = $data['value'] ?? [];
        return !empty($items) ? $items[0] : null;
    }

    // Fallback: scan recent action plans and match referral id from any lookup field present
    $orderby2 = urlencode("createdon desc");
    $top2 = (int)$topScan;
    $url2 = "{$dataverseUrl}/api/data/v9.2/{$actionPlanTable}?\$orderby={$orderby2}&\$top={$top2}";
    $res2 = makeDataverseRequest('GET', $url2, $accessToken);
    if ($res2['status'] !== 200) return null;
    $data2 = json_decode($res2['body'], true);
    $items2 = $data2['value'] ?? [];
    foreach ($items2 as $ap) {
        $rid = actionPlanGetReferralId($ap, $configuredReferralLookupField);
        if ($rid && strcasecmp($rid, $referralId) === 0) return $ap;
    }
    return null;
}

// Validate configuration
if (empty($CLIENT_ID) || empty($CLIENT_SECRET) || empty($TENANT_ID) || empty($DATAVERSE_URL)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Configuration missing. Please set AZURE_CLIENT_ID2, AZURE_CLIENT_SECRET2, AZURE_TENANT_ID, and DATAVERSE_URL environment variables on your server.'
    ]);
    exit;
}

/**
 * Get Azure AD access token using client credentials flow
 */
function getAccessToken($tenantId, $clientId, $clientSecret, $dataverseUrl) {
    $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
    
    $scope = rtrim($dataverseUrl, '/') . '/.default';
    
    $data = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scope' => $scope,
        'grant_type' => 'client_credentials'
    ];
    
    $ch = curl_init($tokenUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("Failed to get access token: HTTP {$httpCode} - {$response}");
    }
    
    $tokenData = json_decode($response, true);
    if (!isset($tokenData['access_token'])) {
        throw new Exception("Access token not found in response");
    }
    
    return $tokenData['access_token'];
}

/**
 * Make API request to Dataverse
 */
function makeDataverseRequest($method, $url, $accessToken, $data = null) {
    $ch = curl_init($url);
    
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'OData-MaxVersion: 4.0',
        'OData-Version: 4.0',
        'Accept: application/json',
        'Prefer: return=representation'
    ];
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    switch ($method) {
        case 'GET':
            break;
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case 'PUT':
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL error: {$error}");
    }
    
    return [
        'status' => $httpCode,
        'body' => $response
    ];
}

try {
    // Get access token
    $accessToken = getAccessToken($TENANT_ID, $CLIENT_ID, $CLIENT_SECRET, $DATAVERSE_URL);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $requestBody = json_decode(file_get_contents('php://input'), true);
    $queryParams = $_GET;
    $action = $queryParams['action'] ?? '';
    
    switch ($action) {
        case 'classes':
            // GET /api/apReferral.php?action=classes - Get all classes
            $select = urlencode("{$CLASS_ID_FIELD},{$CLASS_NAME_FIELD}");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$CLASSES_TABLE}?\$select={$select}";
            $result = makeDataverseRequest('GET', $url, $accessToken);
            
            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                echo json_encode([
                    'success' => true,
                    'classes' => $data['value'] ?? []
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch classes', 'details' => $result['body']]);
            }
            break;
            
        case 'students':
            // GET /api/apReferral.php?action=students&classId=xxx - Get students in a class
            $classId = $queryParams['classId'] ?? null;
            if (!$classId) {
                http_response_code(400);
                echo json_encode(['error' => 'classId parameter is required']);
                exit;
            }
            
            $filter = urlencode("_{$STUDENT_CLASS_FIELD}_value eq {$classId}");
            $select = urlencode("{$STUDENT_ID_FIELD},{$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD}");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$STUDENTS_TABLE}?\$filter={$filter}&\$select={$select}";
            $result = makeDataverseRequest('GET', $url, $accessToken);
            
            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                echo json_encode([
                    'success' => true,
                    'students' => $data['value'] ?? []
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch students', 'details' => $result['body']]);
            }
            break;
            
        case 'submitReferrals':
            // POST /api/apReferral.php?action=submitReferrals - Submit student referrals
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use POST.']);
                exit;
            }
            
            if (!$requestBody || !isset($requestBody['referrals'])) {
                http_response_code(400);
                echo json_encode(['error' => 'referrals array is required']);
                exit;
            }
            
            $referrals = $requestBody['referrals'];
            $results = [];
            $errors = [];
            $debug = (($queryParams['debug'] ?? '') === '1');
            
            foreach ($referrals as $referral) {
                $studentId = $referral['studentId'] ?? null;
                $domainsAndIssues = $referral['domainsAndIssues'] ?? '';
                $referredBy = $referral['referredBy'] ?? '';
                $referredByName = $referral['referredByName'] ?? '';
                $strengths = $referral['strengths'] ?? '';
                // Optional future fields (accept string or array; store as newline-delimited text)
                $triggerSubjects = $referral['triggerSubjects'] ?? null;
                if (is_array($triggerSubjects)) $triggerSubjects = implode("\n", array_filter(array_map('strval', $triggerSubjects)));
                $preferredSubjects = $referral['preferredSubjects'] ?? null;
                if (is_array($preferredSubjects)) $preferredSubjects = implode("\n", array_filter(array_map('strval', $preferredSubjects)));
                
                if (!$studentId) {
                    $errors[] = [
                        'referral' => $referral,
                        'error' => 'studentId is required'
                    ];
                    continue;
                }
                
                // Create the referral record, with fallback attempts for student lookup nav property
                $createResult = createReferralWithStudentBindFallback(
                    $DATAVERSE_URL,
                    $accessToken,
                    $REFERRAL_TABLE,
                    $STUDENTS_TABLE,
                    $studentId,
                    $REFERRAL_INTERVENTION_COMPLETE_FIELD,
                    $REFERRAL_DOMAINS_ISSUES_FIELD,
                    $domainsAndIssues,
                    $REFERRAL_STRENGTHS_FIELD,
                    $strengths,
                    $REFERRAL_TRIGGER_SUBJECTS_FIELD,
                    $triggerSubjects,
                    $REFERRAL_PREFERRED_SUBJECTS_FIELD,
                    $preferredSubjects,
                    $REFERRAL_STUDENT_NAV,
                    [
                        // Keep this tight: we know ReferralToAP uses crd88_student lookup.
                        // The correct navigation property is usually the lookup logical name
                        // (sometimes with different casing).
                        $REFERRAL_STUDENT_FIELD,
                        'crd88_student',
                        'crd88_Student',
                    ]
                );
                
                if ($createResult['status'] === 201 || $createResult['status'] === 204) {
                    $createdData = json_decode($createResult['body'], true);
                    $results[] = [
                        'studentId' => $studentId,
                        // Dataverse autonumber should populate crd88_refid; return it if present
                        'refId' => $createdData[$REFERRAL_REFID_FIELD] ?? null,
                        'action' => 'created',
                        'id' => $createdData[$REFERRAL_ID_FIELD] ?? 'new'
                    ];
                } else {
                    $err = [
                        'studentId' => $studentId,
                        'error' => $createResult['body']
                    ];
                    if ($debug) {
                        $err['debug'] = $createResult['meta'] ?? null;
                    }
                    $errors[] = $err;
                }
            }
            
            if (empty($errors)) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Referrals submitted successfully',
                    'results' => $results
                ]);
            } else {
                http_response_code(207); // Multi-Status
                echo json_encode([
                    'success' => count($results) > 0,
                    'message' => 'Some referrals failed',
                    'results' => $results,
                    'errors' => $errors
                ]);
            }
            break;
            
        case 'outcomes':
            // GET /api/apReferral.php?action=outcomes - Legacy helper: return referrals with their latest action plan & goals (best-effort)
            // NOTE: Prefer outcomesPending/outcomesCompleted for UI lists.
            $selectFields = [
                $REFERRAL_ID_FIELD,
                $REFERRAL_REFID_FIELD,
                $REFERRAL_FINAL_OUTCOME_FIELD,
                $REFERRAL_INTERVENTION_COMPLETE_FIELD,
                'createdon',
                "_{$REFERRAL_STUDENT_FIELD}_value",
            ];
            if (!empty($REFERRAL_DOMAINS_ISSUES_FIELD)) $selectFields[] = $REFERRAL_DOMAINS_ISSUES_FIELD;
            if (!empty($REFERRAL_STRENGTHS_FIELD)) $selectFields[] = $REFERRAL_STRENGTHS_FIELD;
            if (!empty($REFERRAL_MEETING_NOTES_FIELD)) $selectFields[] = $REFERRAL_MEETING_NOTES_FIELD;
            if (!empty($REFERRAL_TRIGGER_SUBJECTS_FIELD)) $selectFields[] = $REFERRAL_TRIGGER_SUBJECTS_FIELD;
            if (!empty($REFERRAL_PREFERRED_SUBJECTS_FIELD)) $selectFields[] = $REFERRAL_PREFERRED_SUBJECTS_FIELD;
            $select = urlencode(implode(',', $selectFields));
            $orderby = urlencode("createdon desc");
            $expand = urlencode("{$REFERRAL_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}?\$select={$select}&\$orderby={$orderby}&\$expand={$expand}";
            $result = makeDataverseRequest('GET', $url, $accessToken);

            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                $items = $data['value'] ?? [];
                // Best-effort enrichment: attach latestActionPlan + goals (can be slow for large datasets)
                foreach ($items as &$r) {
                    $rid = $r[$REFERRAL_ID_FIELD] ?? null;
                    if (!$rid) continue;
                    // latest action plan for this referral
                    $apSelect = urlencode("{$ACTIONPLAN_ID_FIELD},{$ACTIONPLAN_ACTIONID_FIELD},{$ACTIONPLAN_TEXT_FIELD},{$ACTIONPLAN_ACTION_BY_FIELD},{$ACTIONPLAN_REVIEW_DATE_FIELD},{$ACTIONPLAN_MEETING_NOTES_FIELD},createdon,_{$ACTIONPLAN_REFERRAL_LOOKUP_FIELD}_value");
                    $apFilter = urlencode("_{$ACTIONPLAN_REFERRAL_LOOKUP_FIELD}_value eq {$rid}");
                    $apOrder = urlencode("createdon desc");
                    $apUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}?\$select={$apSelect}&\$filter={$apFilter}&\$orderby={$apOrder}&\$top=1";
                    $apRes = makeDataverseRequest('GET', $apUrl, $accessToken);
                    if ($apRes['status'] === 200) {
                        $apData = json_decode($apRes['body'], true);
                        $apItems = $apData['value'] ?? [];
                        $latest = !empty($apItems) ? $apItems[0] : null;
                        $r['latestActionPlan'] = $latest;
                        if ($latest && isset($latest[$ACTIONPLAN_ID_FIELD])) {
                            $apId = $latest[$ACTIONPLAN_ID_FIELD];
                            $gSelect = urlencode("{$GOAL_ID_FIELD},{$GOAL_NAME_FIELD},{$GOAL_TEXT_FIELD},createdon,_{$GOAL_ACTIONPLAN_LOOKUP_FIELD}_value");
                            $gFilter = urlencode("_{$GOAL_ACTIONPLAN_LOOKUP_FIELD}_value eq {$apId}");
                            $gOrder = urlencode("createdon asc");
                            $gUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}?\$select={$gSelect}&\$filter={$gFilter}&\$orderby={$gOrder}";
                            $gRes = makeDataverseRequest('GET', $gUrl, $accessToken);
                            if ($gRes['status'] === 200) {
                                $gData = json_decode($gRes['body'], true);
                                $r['goals'] = $gData['value'] ?? [];
                            }
                        }
                    }
                }

                echo json_encode([
                    'success' => true,
                    'referrals' => $items
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch referrals', 'details' => $result['body']]);
            }
            break;

        case 'outcomesPending':
            // Pending = Referral final outcome blank/null
            // Include only Action Plans that have a review date set (Meeting -> Review Outcomes)
            // Strategy: query action plans with review date, then join back to referral.
            $apFilter = urlencode("({$ACTIONPLAN_REVIEW_DATE_FIELD} ne null)");
            // IMPORTANT: avoid $select here because referral lookup field name can differ by environment
            $apOrder = urlencode("{$ACTIONPLAN_REVIEW_DATE_FIELD} desc");
            $apUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}?\$filter={$apFilter}&\$orderby={$apOrder}";
            $apRes = makeDataverseRequest('GET', $apUrl, $accessToken);

            if ($apRes['status'] !== 200) {
                http_response_code($apRes['status']);
                echo json_encode(['error' => 'Failed to fetch action plans', 'details' => $apRes['body']]);
                break;
            }

            $apData = json_decode($apRes['body'], true);
            $plans = $apData['value'] ?? [];
            $cases = [];

            foreach ($plans as $ap) {
                // Try to discover referral id from any referral lookup present
                $rid = $ap["_{$ACTIONPLAN_REFERRAL_LOOKUP_FIELD}_value"] ?? null;
                if (!$rid) $rid = $ap['_crd88_referraltoap_value'] ?? null;
                if (!$rid) $rid = $ap['_crd88_referralid_value'] ?? null;
                if (!$rid) $rid = $ap['_crd88_referral_value'] ?? null;
                if (!$rid) continue;

                $rFields = [
                    $REFERRAL_ID_FIELD,
                    $REFERRAL_REFID_FIELD,
                    $REFERRAL_FINAL_OUTCOME_FIELD,
                    $REFERRAL_INTERVENTION_COMPLETE_FIELD,
                    'createdon',
                    "_{$REFERRAL_STUDENT_FIELD}_value",
                ];
                if (!empty($REFERRAL_DOMAINS_ISSUES_FIELD)) $rFields[] = $REFERRAL_DOMAINS_ISSUES_FIELD;
                if (!empty($REFERRAL_STRENGTHS_FIELD)) $rFields[] = $REFERRAL_STRENGTHS_FIELD;
                if (!empty($REFERRAL_MEETING_NOTES_FIELD)) $rFields[] = $REFERRAL_MEETING_NOTES_FIELD;
                if (!empty($REFERRAL_TRIGGER_SUBJECTS_FIELD)) $rFields[] = $REFERRAL_TRIGGER_SUBJECTS_FIELD;
                if (!empty($REFERRAL_PREFERRED_SUBJECTS_FIELD)) $rFields[] = $REFERRAL_PREFERRED_SUBJECTS_FIELD;
                $rSelect = urlencode(implode(',', $rFields));
                $rExpand = urlencode("{$REFERRAL_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");
                $rUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}({$rid})?\$select={$rSelect}&\$expand={$rExpand}";
                $rRes = makeDataverseRequest('GET', $rUrl, $accessToken);
                if ($rRes['status'] !== 200) continue;
                $ref = json_decode($rRes['body'], true);

                $finalOutcome = $ref[$REFERRAL_FINAL_OUTCOME_FIELD] ?? null;
                if (!isBlank($finalOutcome)) continue;

                // Attach plan fields (flatten for frontend convenience)
                $ref['actionPlan'] = $ap[$ACTIONPLAN_TEXT_FIELD] ?? '';
                $ref['actionBy'] = $ap[$ACTIONPLAN_ACTION_BY_FIELD] ?? '';
                $ref['reviewDate'] = $ap[$ACTIONPLAN_REVIEW_DATE_FIELD] ?? null;
                $ref['actionPlanId'] = $ap[$ACTIONPLAN_ID_FIELD] ?? null;

                $cases[] = $ref;
            }

            echo json_encode([
                'success' => true,
                'cases' => $cases
            ]);
            break;

        case 'outcomesCompleted':
            // Completed = Referral final outcome filled (not null/empty)
            // Include only Action Plans that have a review date set
            $apFilter = urlencode("({$ACTIONPLAN_REVIEW_DATE_FIELD} ne null)");
            // IMPORTANT: avoid $select here because referral lookup field name can differ by environment
            $apOrder = urlencode("{$ACTIONPLAN_REVIEW_DATE_FIELD} desc");
            $apUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}?\$filter={$apFilter}&\$orderby={$apOrder}";
            $apRes = makeDataverseRequest('GET', $apUrl, $accessToken);

            if ($apRes['status'] !== 200) {
                http_response_code($apRes['status']);
                echo json_encode(['error' => 'Failed to fetch action plans', 'details' => $apRes['body']]);
                break;
            }

            $apData = json_decode($apRes['body'], true);
            $plans = $apData['value'] ?? [];
            $cases = [];

            foreach ($plans as $ap) {
                $rid = $ap["_{$ACTIONPLAN_REFERRAL_LOOKUP_FIELD}_value"] ?? null;
                if (!$rid) $rid = $ap['_crd88_referraltoap_value'] ?? null;
                if (!$rid) $rid = $ap['_crd88_referralid_value'] ?? null;
                if (!$rid) $rid = $ap['_crd88_referral_value'] ?? null;
                if (!$rid) continue;

                $rFields = [
                    $REFERRAL_ID_FIELD,
                    $REFERRAL_REFID_FIELD,
                    $REFERRAL_FINAL_OUTCOME_FIELD,
                    $REFERRAL_INTERVENTION_COMPLETE_FIELD,
                    'createdon',
                    "_{$REFERRAL_STUDENT_FIELD}_value",
                ];
                if (!empty($REFERRAL_DOMAINS_ISSUES_FIELD)) $rFields[] = $REFERRAL_DOMAINS_ISSUES_FIELD;
                if (!empty($REFERRAL_STRENGTHS_FIELD)) $rFields[] = $REFERRAL_STRENGTHS_FIELD;
                if (!empty($REFERRAL_MEETING_NOTES_FIELD)) $rFields[] = $REFERRAL_MEETING_NOTES_FIELD;
                if (!empty($REFERRAL_TRIGGER_SUBJECTS_FIELD)) $rFields[] = $REFERRAL_TRIGGER_SUBJECTS_FIELD;
                if (!empty($REFERRAL_PREFERRED_SUBJECTS_FIELD)) $rFields[] = $REFERRAL_PREFERRED_SUBJECTS_FIELD;
                $rSelect = urlencode(implode(',', $rFields));
                $rExpand = urlencode("{$REFERRAL_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");
                $rUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}({$rid})?\$select={$rSelect}&\$expand={$rExpand}";
                $rRes = makeDataverseRequest('GET', $rUrl, $accessToken);
                if ($rRes['status'] !== 200) continue;
                $ref = json_decode($rRes['body'], true);

                $finalOutcome = $ref[$REFERRAL_FINAL_OUTCOME_FIELD] ?? null;
                if (isBlank($finalOutcome)) continue;

                $ref['actionPlan'] = $ap[$ACTIONPLAN_TEXT_FIELD] ?? '';
                $ref['actionBy'] = $ap[$ACTIONPLAN_ACTION_BY_FIELD] ?? '';
                $ref['reviewDate'] = $ap[$ACTIONPLAN_REVIEW_DATE_FIELD] ?? null;
                $ref['actionPlanId'] = $ap[$ACTIONPLAN_ID_FIELD] ?? null;

                $cases[] = $ref;
            }

            echo json_encode([
                'success' => true,
                'cases' => $cases
            ]);
            break;

        case 'latestCaseByStudent':
        case 'latestReferralByStudent':
            // GET /api/apReferral.php?action=latestReferralByStudent&studentId=xxx - Get latest ReferralToAP record for a student
            $studentId = $queryParams['studentId'] ?? null;
            if (!$studentId) {
                http_response_code(400);
                echo json_encode(['error' => 'studentId parameter is required']);
                exit;
            }

            $filter = urlencode("_{$REFERRAL_STUDENT_FIELD}_value eq {$studentId}");
            $selectFields = [
                $REFERRAL_ID_FIELD,
                $REFERRAL_REFID_FIELD,
                $REFERRAL_FINAL_OUTCOME_FIELD,
                $REFERRAL_INTERVENTION_COMPLETE_FIELD,
                'createdon',
                "_{$REFERRAL_STUDENT_FIELD}_value",
            ];
            if (!empty($REFERRAL_DOMAINS_ISSUES_FIELD)) $selectFields[] = $REFERRAL_DOMAINS_ISSUES_FIELD;
            if (!empty($REFERRAL_STRENGTHS_FIELD)) $selectFields[] = $REFERRAL_STRENGTHS_FIELD;
            if (!empty($REFERRAL_MEETING_NOTES_FIELD)) $selectFields[] = $REFERRAL_MEETING_NOTES_FIELD;
            if (!empty($REFERRAL_TRIGGER_SUBJECTS_FIELD)) $selectFields[] = $REFERRAL_TRIGGER_SUBJECTS_FIELD;
            if (!empty($REFERRAL_PREFERRED_SUBJECTS_FIELD)) $selectFields[] = $REFERRAL_PREFERRED_SUBJECTS_FIELD;
            $select = urlencode(implode(',', $selectFields));
            $orderby = urlencode("createdon desc");
            $top = 1;
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}?\$filter={$filter}&\$select={$select}&\$orderby={$orderby}&\$top={$top}";
            $result = makeDataverseRequest('GET', $url, $accessToken);

            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                $items = $data['value'] ?? [];
                echo json_encode([
                    'success' => true,
                    'referral' => !empty($items) ? $items[0] : null
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch latest case', 'details' => $result['body']]);
            }
            break;

        case 'latestActionPlanByReferral':
            // GET /api/apReferral.php?action=latestActionPlanByReferral&referralId=xxx
            $referralId = $queryParams['referralId'] ?? null;
            if (!$referralId) {
                http_response_code(400);
                echo json_encode(['error' => 'referralId parameter is required']);
                exit;
            }

            $ap = fetchLatestActionPlanForReferral($DATAVERSE_URL, $accessToken, $ACTIONPLAN_TABLE, $ACTIONPLAN_REFERRAL_LOOKUP_FIELD, $referralId, 75);
            echo json_encode([
                'success' => true,
                'actionPlan' => $ap,
                // Normalized shape so frontend doesn't need to know Dataverse column names
                'actionPlanNormalized' => $ap ? [
                    'actionPlan' => $ap[$ACTIONPLAN_TEXT_FIELD] ?? '',
                    'actionBy' => $ap[$ACTIONPLAN_ACTION_BY_FIELD] ?? '',
                    'meetingNotes' => $ap[$ACTIONPLAN_MEETING_NOTES_FIELD] ?? '',
                    'reviewDate' => $ap[$ACTIONPLAN_REVIEW_DATE_FIELD] ?? null,
                    'actionPlanId' => $ap[$ACTIONPLAN_ID_FIELD] ?? null,
                    'actionId' => $ap[$ACTIONPLAN_ACTIONID_FIELD] ?? null,
                ] : null
            ]);
            break;

        case 'upsertActionPlan':
            // POST /api/apReferral.php?action=upsertActionPlan
            // Body: { referralId, actionPlan?, actionBy?, meetingNotes?, reviewDate? }
            if ($method !== 'POST' && $method !== 'PATCH') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use POST.']);
                exit;
            }
            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }
            $referralId = $requestBody['referralId'] ?? null;
            if (!$referralId) {
                http_response_code(400);
                echo json_encode(['error' => 'referralId is required']);
                exit;
            }

            // Find existing latest plan (fallback scan if lookup differs)
            $existing = fetchLatestActionPlanForReferral($DATAVERSE_URL, $accessToken, $ACTIONPLAN_TABLE, $ACTIONPLAN_REFERRAL_LOOKUP_FIELD, $referralId, 75);

            $planData = [];
            if (isset($requestBody['actionPlan'])) $planData[$ACTIONPLAN_TEXT_FIELD] = $requestBody['actionPlan'];
            if (isset($requestBody['actionBy'])) $planData[$ACTIONPLAN_ACTION_BY_FIELD] = $requestBody['actionBy'];
            if (isset($requestBody['meetingNotes'])) $planData[$ACTIONPLAN_MEETING_NOTES_FIELD] = $requestBody['meetingNotes'];
            if (isset($requestBody['reviewDate'])) $planData[$ACTIONPLAN_REVIEW_DATE_FIELD] = $requestBody['reviewDate'];

            if ($existing && isset($existing[$ACTIONPLAN_ID_FIELD])) {
                $planId = $existing[$ACTIONPLAN_ID_FIELD];
                if (empty($planData)) {
                    echo json_encode(['success' => true, 'message' => 'No fields to update', 'actionPlanId' => $planId]);
                    break;
                }
                $updateUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}({$planId})";
                $updateRes = makeDataverseRequest('PATCH', $updateUrl, $accessToken, $planData);
                if ($updateRes['status'] === 204 || $updateRes['status'] === 200) {
                    echo json_encode(['success' => true, 'message' => 'Action plan updated', 'actionPlanId' => $planId]);
                } else {
                    http_response_code($updateRes['status']);
                    echo json_encode(['error' => 'Failed to update action plan', 'details' => $updateRes['body']]);
                }
                break;
            }

            // Create new action plan (best-effort) bound to referral; if bind fails, create unbound
            $createData = $planData;
            $bindCandidates = [
                $ACTIONPLAN_REFERRAL_NAV,
                $ACTIONPLAN_REFERRAL_LOOKUP_FIELD,
                'crd88_referraltoap',
                'crd88_referralid',
                'crd88_referral',
            ];

            $createRes = null;
            foreach ($bindCandidates as $nav) {
                if (!$nav) continue;
                $tryData = $createData;
                $tryData["{$nav}@odata.bind"] = odataBind($REFERRAL_TABLE, $referralId);
                $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}";
                $createRes = makeDataverseRequest('POST', $createUrl, $accessToken, $tryData);
                if ($createRes['status'] === 201 || $createRes['status'] === 204) break;
                $undeclared = dataverseUndeclaredProperty($createRes['body'] ?? '');
                if ($undeclared && strcasecmp($undeclared, $nav) === 0) continue;
                break;
            }

            // Final fallback: create without binding (will not show up in outcomes lists unless linked later)
            if (!$createRes || !($createRes['status'] === 201 || $createRes['status'] === 204)) {
                $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}";
                $createRes = makeDataverseRequest('POST', $createUrl, $accessToken, $createData);
            }

            if ($createRes['status'] === 201 || $createRes['status'] === 204) {
                $created = json_decode($createRes['body'], true);
                echo json_encode([
                    'success' => true,
                    'message' => 'Action plan created',
                    'actionPlanId' => $created[$ACTIONPLAN_ID_FIELD] ?? null
                ]);
            } else {
                http_response_code($createRes['status']);
                echo json_encode(['error' => 'Failed to create action plan', 'details' => $createRes['body']]);
            }
            break;

        case 'listGoalsByReferral':
            // GET /api/apReferral.php?action=listGoalsByReferral&referralId=xxx
            $referralId = $queryParams['referralId'] ?? null;
            if (!$referralId) {
                http_response_code(400);
                echo json_encode(['error' => 'referralId parameter is required']);
                exit;
            }
            $debug = (($queryParams['debug'] ?? '') === '1');

            // Support BOTH relationship models:
            // A) Goal -> Referral lookup exists (preferred; allows multiple goals per referral)
            // B) Referral -> Goal lookup exists (single goal on referral, e.g. "_crd88_goals_value")

            $attempts = [];
            $goalsById = []; // id => raw goal object

            // A) Try Goal -> Referral filter
            $goalToRefRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $GOAL_TABLE_LOGICAL, $REFERRAL_TABLE_LOGICAL);
            $goalToRefAttrs = [];
            foreach ($goalToRefRels as $r) {
                if (!empty($r['attr'])) $goalToRefAttrs[] = $r['attr'];
            }
            // Fallback guesses if metadata isn't available in this org
            $goalToRefAttrs[] = $GOAL_REFERRAL_LOOKUP_FIELD;
            $goalToRefAttrs[] = 'crd88_referraltoap';
            $goalToRefAttrs[] = 'crd88_referralid';
            $goalToRefAttrs[] = 'crd88_referral';

            foreach ($goalToRefAttrs as $lk) {
                if (isBlank($lk)) continue;
                $filter = urlencode("_{$lk}_value eq {$referralId}");
                $select = urlencode("{$GOAL_ID_FIELD},{$GOAL_NAME_FIELD},{$GOAL_TEXT_FIELD},createdon,_{$lk}_value");
                $orderby = urlencode("createdon asc");
                $url = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}?\$filter={$filter}&\$select={$select}&\$orderby={$orderby}";
                $try = makeDataverseRequest('GET', $url, $accessToken);
                if ($debug) {
                    $attempts[] = ['mode' => 'goal->referral', 'lookupAttr' => $lk, 'status' => $try['status'] ?? null];
                }
                if (($try['status'] ?? 0) !== 200) {
                    $invalid = dataverseInvalidProperty($try['body'] ?? '');
                    if ($invalid && (strcasecmp($invalid, $lk) === 0 || strcasecmp($invalid, "_{$lk}_value") === 0)) continue;
                    continue;
                }
                $data = json_decode($try['body'] ?? '', true);
                $items = $data['value'] ?? [];
                foreach ($items as $g) {
                    $gid = $g[$GOAL_ID_FIELD] ?? null;
                    if ($gid) $goalsById[$gid] = $g;
                }
                // If this lookup exists and returned 0..n goals, we can stop trying other attrs
                break;
            }

            // B) Try Referral -> Goal lookup (single)
            $refToGoalRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $REFERRAL_TABLE_LOGICAL, $GOAL_TABLE_LOGICAL);
            $refToGoalAttrs = [];
            foreach ($refToGoalRels as $r) {
                if (!empty($r['attr'])) $refToGoalAttrs[] = $r['attr'];
            }
            $refToGoalAttrs[] = 'crd88_goals';
            $refToGoalAttrs[] = 'crd88_goal';
            $refToGoalAttrs[] = 'crd88_goalid';

            $linkedGoalId = null;
            $usedReferralLookup = null;
            foreach ($refToGoalAttrs as $lk) {
                if (isBlank($lk)) continue;
                $select = urlencode("_{$lk}_value");
                $rUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}({$referralId})?\$select={$select}";
                $rRes = makeDataverseRequest('GET', $rUrl, $accessToken);
                if ($debug) {
                    $attempts[] = ['mode' => 'referral->goal', 'lookupAttr' => $lk, 'select' => "_{$lk}_value", 'status' => $rRes['status'] ?? null];
                }
                if (($rRes['status'] ?? 0) !== 200) {
                    $invalid = dataverseInvalidProperty($rRes['body'] ?? '');
                    if ($invalid && (strcasecmp($invalid, "_{$lk}_value") === 0 || strcasecmp($invalid, $lk) === 0)) continue;
                    continue;
                }
                $rData = json_decode($rRes['body'] ?? '', true);
                $linkedGoalId = $rData["_{$lk}_value"] ?? null;
                $usedReferralLookup = $lk;
                if (!isBlank($linkedGoalId)) break;
            }

            if (!isBlank($linkedGoalId) && empty($goalsById[$linkedGoalId])) {
                // Fetch the linked goal
                $gSelect = urlencode("{$GOAL_ID_FIELD},{$GOAL_NAME_FIELD},{$GOAL_TEXT_FIELD},createdon");
                $gUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$linkedGoalId})?\$select={$gSelect}";
                $gRes = makeDataverseRequest('GET', $gUrl, $accessToken);
                if (($gRes['status'] ?? 0) === 200) {
                    $g = json_decode($gRes['body'] ?? '', true);
                    $gid = $g[$GOAL_ID_FIELD] ?? $linkedGoalId;
                    $goalsById[$gid] = $g;
                } else if ($debug) {
                    $attempts[] = ['mode' => 'referral->goal fetch goal', 'linkedGoalId' => $linkedGoalId, 'status' => $gRes['status'] ?? null, 'body' => $gRes['body'] ?? null];
                }
            }

            $goals = array_values($goalsById);
            // Sort stable by createdon then text
            usort($goals, function ($a, $b) use ($GOAL_TEXT_FIELD) {
                $ca = $a['createdon'] ?? '';
                $cb = $b['createdon'] ?? '';
                if ($ca !== $cb) return strcmp($ca, $cb);
                $ta = (string)($a[$GOAL_TEXT_FIELD] ?? '');
                $tb = (string)($b[$GOAL_TEXT_FIELD] ?? '');
                return strcmp($ta, $tb);
            });

            $normalized = [];
            foreach ($goals as $g) {
                $normalized[] = [
                    'id' => $g[$GOAL_ID_FIELD] ?? null,
                    'name' => $g[$GOAL_NAME_FIELD] ?? null,
                    'text' => $g[$GOAL_TEXT_FIELD] ?? '',
                    'referralLookupField' => $usedReferralLookup,
                ];
            }

            $out = ['success' => true, 'goals' => $goals, 'goalsNormalized' => $normalized];
            if ($debug) $out['debug'] = ['attempts' => $attempts, 'usedReferralLookup' => $usedReferralLookup, 'linkedGoalId' => $linkedGoalId];
            // If only Referral->Goal exists, warn that it can only represent one goal link.
            if (!empty($linkedGoalId) && count($goals) <= 1 && empty($goalToRefRels)) {
                $out['warning'] = 'This environment appears to link ReferralToAP -> Goal (single lookup). Multiple goals per referral require a Goal -> Referral lookup.';
            }
            echo json_encode($out);
            break;

        case 'createGoal':
            // POST /api/apReferral.php?action=createGoal
            // Body: { referralId, text }
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use POST.']);
                exit;
            }
            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }
            $referralId = $requestBody['referralId'] ?? null;
            $text = $requestBody['text'] ?? '';
            if (!$referralId) {
                http_response_code(400);
                echo json_encode(['error' => 'referralId is required']);
                exit;
            }
            if (isBlank($text)) {
                http_response_code(400);
                echo json_encode(['error' => 'text is required']);
                exit;
            }

            $data = [];
            if (!isBlank($GOAL_TEXT_FIELD)) $data[$GOAL_TEXT_FIELD] = (string)$text;

            $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}";
            // Create goal first (not bound), then bind ReferralToAP -> Goal lookup
            $createRes = makeDataverseRequest('POST', $createUrl, $accessToken, $data);
            if (!($createRes['status'] === 201 || $createRes['status'] === 204)) {
                http_response_code($createRes['status']);
                echo json_encode(['error' => 'Failed to create goal', 'details' => $createRes['body']]);
                break;
            }

            if ($createRes['status'] === 201 || $createRes['status'] === 204) {
                $created = json_decode($createRes['body'], true);
                $goalIdCreated = $created[$GOAL_ID_FIELD] ?? null;

                $linked = false;
                $linkMethod = null;
                $linkAttempts = [];

                // Preferred: bind Goal -> Referral if that relationship exists (supports multiple goals)
                $goalToRefRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $GOAL_TABLE_LOGICAL, $REFERRAL_TABLE_LOGICAL);
                $goalNavCandidates = [];
                foreach ($goalToRefRels as $r) {
                    if (!empty($r['nav'])) $goalNavCandidates[] = $r['nav'];
                }
                $goalNavCandidates[] = $GOAL_REFERRAL_NAV;
                $goalNavCandidates[] = $GOAL_REFERRAL_LOOKUP_FIELD;

                if (!isBlank($goalIdCreated)) {
                    foreach ($goalNavCandidates as $nav) {
                        if (isBlank($nav)) continue;
                        $upd = ["{$nav}@odata.bind" => odataBind($REFERRAL_TABLE, $referralId)];
                        $gUpdUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$goalIdCreated})";
                        $gUpdRes = makeDataverseRequest('PATCH', $gUpdUrl, $accessToken, $upd);
                        if ($gUpdRes['status'] === 204 || $gUpdRes['status'] === 200) { $linked = true; $linkMethod = 'goal->referral'; break; }
                        $undeclared = dataverseUndeclaredProperty($gUpdRes['body'] ?? '');
                        $invalid = dataverseInvalidProperty($gUpdRes['body'] ?? '');
                        $linkAttempts[] = ['method' => 'goal->referral', 'nav' => $nav, 'status' => $gUpdRes['status'] ?? null, 'undeclared' => $undeclared, 'invalid' => $invalid];
                        if ($undeclared && strcasecmp($undeclared, $nav) === 0) continue;
                        if ($invalid && strcasecmp($invalid, $nav) === 0) continue;
                        break;
                    }
                }

                // Fallback: bind Referral -> Goal (single lookup, overwrites previous)
                if (!$linked && !isBlank($goalIdCreated)) {
                    $refMetaRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $REFERRAL_TABLE_LOGICAL, $GOAL_TABLE_LOGICAL);
                    $refBindCandidates = [];
                    foreach ($refMetaRels as $r) {
                        if (!empty($r['nav'])) $refBindCandidates[] = $r['nav'];
                    }
                    $refBindCandidates[] = 'crd88_goals';
                    $refBindCandidates[] = 'crd88_goal';
                    $refBindCandidates[] = 'crd88_goalid';

                    foreach ($refBindCandidates as $nav) {
                        if (isBlank($nav)) continue;
                        $upd = ["{$nav}@odata.bind" => odataBind($GOAL_TABLE, $goalIdCreated)];
                        $rUpdUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}({$referralId})";
                        $rUpdRes = makeDataverseRequest('PATCH', $rUpdUrl, $accessToken, $upd);
                        if ($rUpdRes['status'] === 204 || $rUpdRes['status'] === 200) { $linked = true; $linkMethod = 'referral->goal'; break; }
                        $undeclared = dataverseUndeclaredProperty($rUpdRes['body'] ?? '');
                        $invalid = dataverseInvalidProperty($rUpdRes['body'] ?? '');
                        $linkAttempts[] = ['method' => 'referral->goal', 'nav' => $nav, 'status' => $rUpdRes['status'] ?? null, 'undeclared' => $undeclared, 'invalid' => $invalid];
                        if ($undeclared && strcasecmp($undeclared, $nav) === 0) continue;
                        if ($invalid && strcasecmp($invalid, $nav) === 0) continue;
                        break;
                    }
                }

                echo json_encode([
                    'success' => true,
                    'goalId' => $goalIdCreated,
                    'linkedToReferral' => $linked,
                    'linkMethod' => $linkMethod,
                    'linkAttempts' => $linkAttempts
                ]);
            }
            break;

        case 'updateGoal':
            // POST /api/apReferral.php?action=updateGoal
            // Body: { goalId, text }
            if ($method !== 'POST' && $method !== 'PATCH') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use POST.']);
                exit;
            }
            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }
            $goalId = $requestBody['goalId'] ?? null;
            $text = $requestBody['text'] ?? null;
            if (!$goalId) {
                http_response_code(400);
                echo json_encode(['error' => 'goalId is required']);
                exit;
            }
            if ($text === null) {
                http_response_code(400);
                echo json_encode(['error' => 'text is required']);
                exit;
            }

            $payload = [];
            if (!isBlank($GOAL_TEXT_FIELD)) $payload[$GOAL_TEXT_FIELD] = (string)$text;
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$goalId})";
            $res = makeDataverseRequest('PATCH', $url, $accessToken, $payload);
            if ($res['status'] === 204 || $res['status'] === 200) {
                echo json_encode(['success' => true, 'message' => 'Goal updated']);
            } else {
                http_response_code($res['status']);
                echo json_encode(['error' => 'Failed to update goal', 'details' => $res['body']]);
            }
            break;

        case 'listActionPlansByGoal':
            // GET /api/apReferral.php?action=listActionPlansByGoal&goalId=xxx
            $goalId = $queryParams['goalId'] ?? null;
            if (!$goalId) {
                http_response_code(400);
                echo json_encode(['error' => 'goalId parameter is required']);
                exit;
            }

            // In this environment, Goal links to Action Plan via _crd88_actionplan_value (Goal -> ActionPlan),
            // so we fetch the goal's linked action plan id, then fetch that plan.
            $metaRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $GOAL_TABLE_LOGICAL, $ACTIONPLAN_TABLE_LOGICAL);
            $goalLookupCandidates = [];
            foreach ($metaRels as $r) {
                if (!empty($r['attr'])) $goalLookupCandidates[] = $r['attr'];
            }
            $goalLookupCandidates[] = $GOAL_ACTIONPLAN_LOOKUP_FIELD;
            $goalLookupCandidates[] = 'crd88_actionplan';
            $goalLookupCandidates[] = 'crd88_actionplanid';

            $linkedActionPlanId = null;
            foreach ($goalLookupCandidates as $lk) {
                if (isBlank($lk)) continue;
                $select = urlencode("_{$lk}_value");
                $gUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$goalId})?\$select={$select}";
                $gRes = makeDataverseRequest('GET', $gUrl, $accessToken);
                if (($gRes['status'] ?? 0) !== 200) {
                    $invalid = dataverseInvalidProperty($gRes['body'] ?? '');
                    if ($invalid && strcasecmp($invalid, "_{$lk}_value") === 0) continue;
                    continue;
                }
                $gData = json_decode($gRes['body'] ?? '', true);
                $linkedActionPlanId = $gData["_{$lk}_value"] ?? null;
                if (!isBlank($linkedActionPlanId)) break;
            }

            if (isBlank($linkedActionPlanId)) {
                echo json_encode(['success' => true, 'actionPlans' => [], 'actionPlansNormalized' => []]);
                break;
            }

            $apSelect = urlencode("{$ACTIONPLAN_ID_FIELD},{$ACTIONPLAN_ACTIONID_FIELD},{$ACTIONPLAN_TEXT_FIELD},{$ACTIONPLAN_ACTION_BY_FIELD},{$ACTIONPLAN_REVIEW_DATE_FIELD},{$ACTIONPLAN_STATUS_FIELD},createdon");
            $apUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}({$linkedActionPlanId})?\$select={$apSelect}";
            $apRes = makeDataverseRequest('GET', $apUrl, $accessToken);
            if (($apRes['status'] ?? 0) === 200) {
                $ap = json_decode($apRes['body'] ?? '', true);
                $normalized = [[
                    'id' => $ap[$ACTIONPLAN_ID_FIELD] ?? $linkedActionPlanId,
                    'actionId' => $ap[$ACTIONPLAN_ACTIONID_FIELD] ?? null,
                    'actionPlanDetails' => $ap[$ACTIONPLAN_TEXT_FIELD] ?? '',
                    'actionBy' => $ap[$ACTIONPLAN_ACTION_BY_FIELD] ?? '',
                    'reviewDate' => $ap[$ACTIONPLAN_REVIEW_DATE_FIELD] ?? null,
                    'status' => $ap[$ACTIONPLAN_STATUS_FIELD] ?? 5,
                ]];
                echo json_encode(['success' => true, 'actionPlans' => [$ap], 'actionPlansNormalized' => $normalized]);
            } else {
                http_response_code($apRes['status'] ?? 400);
                echo json_encode(['error' => 'Failed to fetch action plan', 'details' => $apRes['body'] ?? null]);
            }
            break;

        case 'upsertActionPlanForGoal':
            // POST /api/apReferral.php?action=upsertActionPlanForGoal
            // Body: { goalId, referralId?, actionPlanId?, actionPlanDetails?, actionBy?, reviewDate?, status? }
            if ($method !== 'POST' && $method !== 'PATCH') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use POST.']);
                exit;
            }
            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }
            $goalId = $requestBody['goalId'] ?? null;
            $referralId = $requestBody['referralId'] ?? null;
            if (!$goalId) {
                http_response_code(400);
                echo json_encode(['error' => 'goalId is required']);
                exit;
            }

            $apId = $requestBody['actionPlanId'] ?? null;
            $payload = [];
            if (isset($requestBody['actionPlanDetails'])) $payload[$ACTIONPLAN_TEXT_FIELD] = $requestBody['actionPlanDetails'];
            if (isset($requestBody['actionBy'])) $payload[$ACTIONPLAN_ACTION_BY_FIELD] = $requestBody['actionBy'];
            if (isset($requestBody['reviewDate'])) $payload[$ACTIONPLAN_REVIEW_DATE_FIELD] = $requestBody['reviewDate'];
            if (isset($requestBody['status'])) $payload[$ACTIONPLAN_STATUS_FIELD] = (int)$requestBody['status'];

            // Resolve existing Action Plan linked from the Goal (Goal -> Action Plan)
            $metaRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $GOAL_TABLE_LOGICAL, $ACTIONPLAN_TABLE_LOGICAL);
            $goalLookupCandidates = [];
            foreach ($metaRels as $r) {
                if (!empty($r['attr'])) $goalLookupCandidates[] = $r['attr'];
            }
            $goalLookupCandidates[] = $GOAL_ACTIONPLAN_LOOKUP_FIELD;
            $goalLookupCandidates[] = 'crd88_actionplan';
            $goalLookupCandidates[] = 'crd88_actionplanid';
            $linkedActionPlanId = null;
            foreach ($goalLookupCandidates as $lk) {
                if (isBlank($lk)) continue;
                $select = urlencode("_{$lk}_value");
                $gUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$goalId})?\$select={$select}";
                $gRes = makeDataverseRequest('GET', $gUrl, $accessToken);
                if (($gRes['status'] ?? 0) !== 200) continue;
                $gData = json_decode($gRes['body'] ?? '', true);
                $linkedActionPlanId = $gData["_{$lk}_value"] ?? null;
                if (!isBlank($linkedActionPlanId)) break;
            }

            // If caller didn't pass actionPlanId, but goal already has one, update it
            if (!$apId && !isBlank($linkedActionPlanId)) {
                $apId = $linkedActionPlanId;
            }

            if ($apId) {
                if (empty($payload)) {
                    echo json_encode(['success' => true, 'message' => 'No fields to update', 'actionPlanId' => $apId]);
                    break;
                }
                $url = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}({$apId})";
                $res = makeDataverseRequest('PATCH', $url, $accessToken, $payload);
                if ($res['status'] === 204 || $res['status'] === 200) {
                    echo json_encode(['success' => true, 'message' => 'Action plan updated', 'actionPlanId' => $apId]);
                } else {
                    http_response_code($res['status']);
                    echo json_encode(['error' => 'Failed to update action plan', 'details' => $res['body']]);
                }
                break;
            }

            // Create action plan (NOT bound to goal), then bind goal -> action plan
            $createData = $payload;
            $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}";
            $createRes = null;

            // Best-effort: bind referral if provided (this is optional)
            $refBindCandidates = [
                $ACTIONPLAN_REFERRAL_NAV,
                $ACTIONPLAN_REFERRAL_LOOKUP_FIELD,
                'crd88_referraltoap',
                'crd88_referralid',
                'crd88_referral',
            ];
            if (!isBlank($referralId)) {
                foreach ($refBindCandidates as $rNav) {
                    if (isBlank($rNav)) continue;
                    $tryData = $createData;
                    $tryData["{$rNav}@odata.bind"] = odataBind($REFERRAL_TABLE, $referralId);
                    $createRes = makeDataverseRequest('POST', $createUrl, $accessToken, $tryData);
                    if ($createRes['status'] === 201 || $createRes['status'] === 204) break;
                    $undeclared = dataverseUndeclaredProperty($createRes['body'] ?? '');
                    $invalid = dataverseInvalidProperty($createRes['body'] ?? '');
                    if ($undeclared && strcasecmp($undeclared, $rNav) === 0) continue;
                    if ($invalid && strcasecmp($invalid, $rNav) === 0) continue;
                    break;
                }
            }

            if (!$createRes || !($createRes['status'] === 201 || $createRes['status'] === 204)) {
                $createRes = makeDataverseRequest('POST', $createUrl, $accessToken, $createData);
            }

            if (!($createRes['status'] === 201 || $createRes['status'] === 204)) {
                http_response_code($createRes['status'] ?? 400);
                echo json_encode(['error' => 'Failed to create action plan', 'details' => $createRes['body'] ?? null]);
                break;
            }

            $created = json_decode($createRes['body'], true);
            $newApId = $created[$ACTIONPLAN_ID_FIELD] ?? null;
            if (isBlank($newApId)) {
                echo json_encode(['success' => true, 'message' => 'Action plan created (id unavailable)', 'actionPlanId' => null]);
                break;
            }

            // Bind Goal -> Action Plan (this is the actual relationship in your data)
            $goalBindNavCandidates = [];
            foreach ($metaRels as $r) {
                if (!empty($r['nav'])) $goalBindNavCandidates[] = $r['nav'];
            }
            $goalBindNavCandidates[] = $GOAL_ACTIONPLAN_NAV;
            $goalBindNavCandidates[] = $GOAL_ACTIONPLAN_LOOKUP_FIELD;
            $goalBindNavCandidates[] = 'crd88_actionplan';
            $bound = false;
            foreach ($goalBindNavCandidates as $gNav) {
                if (isBlank($gNav)) continue;
                $upd = ["{$gNav}@odata.bind" => odataBind($ACTIONPLAN_TABLE, $newApId)];
                $gUpdUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$goalId})";
                $gUpdRes = makeDataverseRequest('PATCH', $gUpdUrl, $accessToken, $upd);
                if ($gUpdRes['status'] === 204 || $gUpdRes['status'] === 200) { $bound = true; break; }
                $undeclared = dataverseUndeclaredProperty($gUpdRes['body'] ?? '');
                $invalid = dataverseInvalidProperty($gUpdRes['body'] ?? '');
                if ($undeclared && strcasecmp($undeclared, $gNav) === 0) continue;
                if ($invalid && strcasecmp($invalid, $gNav) === 0) continue;
                break;
            }

            echo json_encode([
                'success' => true,
                'message' => $bound ? 'Action plan created and linked to goal' : 'Action plan created (goal link failed)',
                'actionPlanId' => $newApId
            ]);
            break;

        case 'listGoalsByActionPlan':
            // GET /api/apReferral.php?action=listGoalsByActionPlan&actionPlanId=xxx
            $actionPlanId = $queryParams['actionPlanId'] ?? null;
            if (!$actionPlanId) {
                http_response_code(400);
                echo json_encode(['error' => 'actionPlanId parameter is required']);
                exit;
            }
            $filter = urlencode("_{$GOAL_ACTIONPLAN_LOOKUP_FIELD}_value eq {$actionPlanId}");
            $select = urlencode("{$GOAL_ID_FIELD},{$GOAL_NAME_FIELD},{$GOAL_TEXT_FIELD},createdon,_{$GOAL_ACTIONPLAN_LOOKUP_FIELD}_value");
            $orderby = urlencode("createdon asc");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}?\$filter={$filter}&\$select={$select}&\$orderby={$orderby}";
            $result = makeDataverseRequest('GET', $url, $accessToken);
            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                $goals = $data['value'] ?? [];
                // Attach normalized "text" for convenience
                $normalized = [];
                foreach ($goals as $g) {
                    $normalized[] = [
                        'id' => $g[$GOAL_ID_FIELD] ?? null,
                        'name' => $g[$GOAL_NAME_FIELD] ?? null,
                        'text' => $g[$GOAL_TEXT_FIELD] ?? '',
                    ];
                }
                echo json_encode(['success' => true, 'goals' => $goals, 'goalsNormalized' => $normalized]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch goals', 'details' => $result['body']]);
            }
            break;

        case 'replaceGoals':
            // POST /api/apReferral.php?action=replaceGoals
            // Body: { actionPlanId, goals: [ { text } ] }
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use POST.']);
                exit;
            }
            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }
            $actionPlanId = $requestBody['actionPlanId'] ?? null;
            if (!$actionPlanId) {
                http_response_code(400);
                echo json_encode(['error' => 'actionPlanId is required']);
                exit;
            }
            $goals = $requestBody['goals'] ?? [];
            if (!is_array($goals)) $goals = [];

            // Delete existing goals
            $filter = urlencode("_{$GOAL_ACTIONPLAN_LOOKUP_FIELD}_value eq {$actionPlanId}");
            $select = urlencode("{$GOAL_ID_FIELD}");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}?\$filter={$filter}&\$select={$select}";
            $existingRes = makeDataverseRequest('GET', $url, $accessToken);
            if ($existingRes['status'] === 200) {
                $existingData = json_decode($existingRes['body'], true);
                $existingItems = $existingData['value'] ?? [];
                foreach ($existingItems as $g) {
                    $gid = $g[$GOAL_ID_FIELD] ?? null;
                    if (!$gid) continue;
                    $delUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$gid})";
                    makeDataverseRequest('DELETE', $delUrl, $accessToken);
                }
            }

            // Create new goals
            $createdIds = [];
            foreach ($goals as $g) {
                $text = is_array($g) ? ($g['text'] ?? '') : '';
                if (isBlank($text)) continue;
                $data = [
                    "{$GOAL_ACTIONPLAN_NAV}@odata.bind" => odataBind($ACTIONPLAN_TABLE, $actionPlanId),
                ];
                if (!isBlank($GOAL_TEXT_FIELD)) {
                    $data[$GOAL_TEXT_FIELD] = $text;
                }
                $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}";
                $cr = makeDataverseRequest('POST', $createUrl, $accessToken, $data);
                if ($cr['status'] === 201 || $cr['status'] === 204) {
                    $cd = json_decode($cr['body'], true);
                    if (is_array($cd) && isset($cd[$GOAL_ID_FIELD])) $createdIds[] = $cd[$GOAL_ID_FIELD];
                }
            }

            echo json_encode(['success' => true, 'createdGoalIds' => $createdIds]);
            break;
            
        case 'updateCase':
        case 'updateReferral':
            // Legacy-friendly updater. Interprets "caseId" as "referralId" and updates ReferralToAP fields.
            // Use upsertActionPlan + replaceGoals for Action Plan/Goal data.
            if ($method !== 'PATCH' && $method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use PATCH or POST.']);
                exit;
            }

            $referralId = $queryParams['caseId'] ?? ($queryParams['referralId'] ?? null);
            if (!$referralId) {
                http_response_code(400);
                echo json_encode(['error' => 'referralId parameter is required']);
                exit;
            }

            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }

            // Build ReferralToAP update data from allowed fields
            $updateData = [];
            $allowedFields = [
                'meetingNotes' => $REFERRAL_MEETING_NOTES_FIELD,
                'finalOutcome' => $REFERRAL_FINAL_OUTCOME_FIELD,
                'interventionComplete' => $REFERRAL_INTERVENTION_COMPLETE_FIELD,
                'domainsAndIssues' => $REFERRAL_DOMAINS_ISSUES_FIELD,
                'strengths' => $REFERRAL_STRENGTHS_FIELD
                ,
                'triggerSubjects' => $REFERRAL_TRIGGER_SUBJECTS_FIELD,
                'preferredSubjects' => $REFERRAL_PREFERRED_SUBJECTS_FIELD
            ];

            foreach ($allowedFields as $inputKey => $dataverseField) {
                if (isset($requestBody[$inputKey])) {
                    $updateData[$dataverseField] = $requestBody[$inputKey];
                }
            }

            if (empty($updateData)) {
                echo json_encode(['success' => true, 'message' => 'No referral fields to update', 'referralId' => $referralId]);
                break;
            }

            $updateUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}({$referralId})";
            $updateResult = makeDataverseRequest('PATCH', $updateUrl, $accessToken, $updateData);

            if ($updateResult['status'] === 204 || $updateResult['status'] === 200) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Referral updated successfully',
                    'referralId' => $referralId
                ]);
            } else {
                http_response_code($updateResult['status']);
                echo json_encode(['error' => 'Failed to update referral', 'details' => $updateResult['body']]);
            }
            break;
            
        case 'getCase':
        case 'getReferral':
            // GET /api/apReferral.php?action=getReferral&referralId=xxx
            $referralId = $queryParams['caseId'] ?? ($queryParams['referralId'] ?? null);
            if (!$referralId) {
                http_response_code(400);
                echo json_encode(['error' => 'referralId parameter is required']);
                exit;
            }

            $selectFields = [
                $REFERRAL_ID_FIELD,
                $REFERRAL_REFID_FIELD,
                $REFERRAL_FINAL_OUTCOME_FIELD,
                $REFERRAL_INTERVENTION_COMPLETE_FIELD,
                'createdon',
                "_{$REFERRAL_STUDENT_FIELD}_value",
            ];
            if (!empty($REFERRAL_DOMAINS_ISSUES_FIELD)) $selectFields[] = $REFERRAL_DOMAINS_ISSUES_FIELD;
            if (!empty($REFERRAL_STRENGTHS_FIELD)) $selectFields[] = $REFERRAL_STRENGTHS_FIELD;
            if (!empty($REFERRAL_MEETING_NOTES_FIELD)) $selectFields[] = $REFERRAL_MEETING_NOTES_FIELD;
            if (!empty($REFERRAL_TRIGGER_SUBJECTS_FIELD)) $selectFields[] = $REFERRAL_TRIGGER_SUBJECTS_FIELD;
            if (!empty($REFERRAL_PREFERRED_SUBJECTS_FIELD)) $selectFields[] = $REFERRAL_PREFERRED_SUBJECTS_FIELD;
            $select = urlencode(implode(',', $selectFields));
            $expand = urlencode("{$REFERRAL_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");

            // Prefer expanded student, but fall back to no-expand if this environment uses a different nav property.
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}({$referralId})?\$select={$select}&\$expand={$expand}";
            $result = makeDataverseRequest('GET', $url, $accessToken);
            if (($result['status'] ?? 0) !== 200) {
                $invalid = dataverseInvalidProperty($result['body'] ?? '');
                if ($invalid && strcasecmp($invalid, $REFERRAL_STUDENT_NAV) === 0) {
                    $url2 = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}({$referralId})?\$select={$select}";
                    $result2 = makeDataverseRequest('GET', $url2, $accessToken);
                    if (($result2['status'] ?? 0) === 200) {
                        $result = $result2;
                    }
                }
            }

            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                echo json_encode([
                    'success' => true,
                    'referral' => $data
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch referral', 'details' => $result['body']]);
            }
            break;
            
        case 'actionPlansForReview':
            $debug = (($queryParams['debug'] ?? '') === '1');

            // Status-only filter:
            // - status is Pending (5) or Completed (7)
            $apFilterRaw = "({$ACTIONPLAN_STATUS_FIELD} eq 5 or {$ACTIONPLAN_STATUS_FIELD} eq 7)";
            $apFilter = urlencode($apFilterRaw);
            $apSelect = urlencode("{$ACTIONPLAN_ID_FIELD},{$ACTIONPLAN_ACTIONID_FIELD},{$ACTIONPLAN_TEXT_FIELD},{$ACTIONPLAN_ACTION_BY_FIELD},{$ACTIONPLAN_REVIEW_DATE_FIELD},{$ACTIONPLAN_STATUS_FIELD},createdon");
            $apOrder = urlencode("{$ACTIONPLAN_REVIEW_DATE_FIELD} asc");
            $apUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ACTIONPLAN_TABLE}?\$filter={$apFilter}&\$select={$apSelect}&\$orderby={$apOrder}";
            $apRes = makeDataverseRequest('GET', $apUrl, $accessToken);

            if ($apRes['status'] !== 200) {
                http_response_code($apRes['status']);
                echo json_encode(['error' => 'Failed to fetch action plans', 'details' => $apRes['body']]);
                break;
            }

            $apData = json_decode($apRes['body'], true);
            $actionPlans = $apData['value'] ?? [];
            $enrichedPlans = [];

            // Discover ReferralToAP -> Goal lookup attribute (for filtering referrals by goal)
            $refToGoalRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $REFERRAL_TABLE_LOGICAL, $GOAL_TABLE_LOGICAL);
            $refGoalLookupAttrs = [];
            foreach ($refToGoalRels as $r) {
                if (!empty($r['attr'])) $refGoalLookupAttrs[] = $r['attr'];
            }
            // Fallback guesses
            $refGoalLookupAttrs[] = 'crd88_goals';
            $refGoalLookupAttrs[] = 'crd88_goal';
            $refGoalLookupAttrs[] = 'crd88_goalid';

            // Discover Goal -> ActionPlan lookup attribute (for filtering goals by action plan)
            $goalToApRels = discoverManyToOneRelationshipToTarget($DATAVERSE_URL, $accessToken, $GOAL_TABLE_LOGICAL, $ACTIONPLAN_TABLE_LOGICAL);
            $goalApLookupAttrs = [];
            foreach ($goalToApRels as $r) {
                if (!empty($r['attr'])) $goalApLookupAttrs[] = $r['attr'];
            }
            $goalApLookupAttrs[] = $GOAL_ACTIONPLAN_LOOKUP_FIELD; // usually 'crd88_actionplan'
            $goalApLookupAttrs[] = 'crd88_actionplan';
            $goalApLookupAttrs[] = 'crd88_actionplanid';

            $goalByActionPlanId = [];      // apId -> [goal...]
            $referralByGoalId = [];        // goalId -> referral row (with expanded student)
            $studentById = [];             // studentId -> student row (cached)

            foreach ($actionPlans as $ap) {
                $apId = $ap[$ACTIONPLAN_ID_FIELD] ?? null;
                if (isBlank($apId)) continue;

                // 1) Find goal(s) linked to this action plan (Goal -> ActionPlan lookup)
                if (!isset($goalByActionPlanId[$apId])) {
                    $goals = [];
                    foreach ($goalApLookupAttrs as $lk) {
                        if (isBlank($lk)) continue;
                        $gFilter = urlencode("_{$lk}_value eq {$apId}");
                        $gSelect = urlencode("{$GOAL_ID_FIELD},{$GOAL_NAME_FIELD},{$GOAL_TEXT_FIELD},crd88_completed,crd88_completionnotes,createdon,_{$lk}_value");
                        $gUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}?\$filter={$gFilter}&\$select={$gSelect}";
                        $gRes = makeDataverseRequest('GET', $gUrl, $accessToken);
                        if (($gRes['status'] ?? 0) !== 200) {
                            $invalid = dataverseInvalidProperty($gRes['body'] ?? '');
                            if ($invalid && (strcasecmp($invalid, $lk) === 0 || strcasecmp($invalid, "_{$lk}_value") === 0)) continue;
                            continue;
                        }
                        $gData = json_decode($gRes['body'] ?? '', true);
                        $goals = $gData['value'] ?? [];
                        break;
                    }
                    $goalByActionPlanId[$apId] = $goals;
                }

                $goals = $goalByActionPlanId[$apId] ?? [];
                if (empty($goals)) continue;

                foreach ($goals as $goal) {
                    $goalId = $goal[$GOAL_ID_FIELD] ?? null;
                    if (isBlank($goalId)) continue;

                    // 2) Find referral linked to this goal (ReferralToAP -> Goal lookup)
                    if (!isset($referralByGoalId[$goalId])) {
                        $referralRow = null;
                        foreach ($refGoalLookupAttrs as $lk) {
                            if (isBlank($lk)) continue;
                            $rFilter = urlencode("_{$lk}_value eq {$goalId}");
                            $rFields = [
                                $REFERRAL_ID_FIELD,
                                $REFERRAL_REFID_FIELD,
                                "_{$REFERRAL_STUDENT_FIELD}_value",
                            ];
                            if (!empty($REFERRAL_STRENGTHS_FIELD)) $rFields[] = $REFERRAL_STRENGTHS_FIELD;
                            if (!empty($REFERRAL_DOMAINS_ISSUES_FIELD)) $rFields[] = $REFERRAL_DOMAINS_ISSUES_FIELD;
                            if (!empty($REFERRAL_MEETING_NOTES_FIELD)) $rFields[] = $REFERRAL_MEETING_NOTES_FIELD;
                            $rSelect = urlencode(implode(',', $rFields));
                            $rExpand = urlencode("{$REFERRAL_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");

                            // Prefer expanded student, but fall back to non-expanded if expand fails in this environment
                            $rUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}?\$filter={$rFilter}&\$select={$rSelect}&\$expand={$rExpand}&\$top=1";
                            $rRes = makeDataverseRequest('GET', $rUrl, $accessToken);
                            if (($rRes['status'] ?? 0) !== 200) {
                                // If expand failed due to missing nav property, retry without expand
                                $invalid = dataverseInvalidProperty($rRes['body'] ?? '');
                                if ($invalid && strcasecmp($invalid, $REFERRAL_STUDENT_NAV) === 0) {
                                    $rUrl2 = "{$DATAVERSE_URL}/api/data/v9.2/{$REFERRAL_TABLE}?\$filter={$rFilter}&\$select={$rSelect}&\$top=1";
                                    $rRes2 = makeDataverseRequest('GET', $rUrl2, $accessToken);
                                    if (($rRes2['status'] ?? 0) === 200) {
                                        $rData = json_decode($rRes2['body'] ?? '', true);
                                        $items = $rData['value'] ?? [];
                                        $referralRow = !empty($items) ? $items[0] : null;
                                        break;
                                    }
                                }

                                // If the lookup field doesn't exist, try next lookup attr
                                if ($invalid && (strcasecmp($invalid, $lk) === 0 || strcasecmp($invalid, "_{$lk}_value") === 0)) continue;
                                continue;
                            }

                            $rData = json_decode($rRes['body'] ?? '', true);
                            $items = $rData['value'] ?? [];
                            $referralRow = !empty($items) ? $items[0] : null;
                            break;
                        }
                        $referralByGoalId[$goalId] = $referralRow;
                    }

                    $referral = $referralByGoalId[$goalId];
                    if (!$referral) continue;

                    $referralId = $referral[$REFERRAL_ID_FIELD] ?? null;
                    $studentId = $referral["_{$REFERRAL_STUDENT_FIELD}_value"] ?? null;
                    if (isBlank($referralId) || isBlank($studentId)) continue;

                    $expanded = $referral[$REFERRAL_STUDENT_NAV] ?? null;
                    $studentName = is_array($expanded) ? ($expanded[$STUDENT_NAME_FIELD] ?? 'Unknown') : null;
                    $studentNumber = is_array($expanded) ? ($expanded[$STUDENT_NUMBER_FIELD] ?? '') : null;

                    // If expand wasn't available, fetch student record (cached)
                    if ($studentName === null || $studentNumber === null) {
                        if (!isset($studentById[$studentId])) {
                            $sSelect = urlencode("{$STUDENT_ID_FIELD},{$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD}");
                            $sUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$STUDENTS_TABLE}({$studentId})?\$select={$sSelect}";
                            $sRes = makeDataverseRequest('GET', $sUrl, $accessToken);
                            $studentById[$studentId] = ($sRes['status'] === 200) ? json_decode($sRes['body'] ?? '', true) : null;
                        }
                        $s = $studentById[$studentId];
                        $studentName = $studentName ?? (is_array($s) ? ($s[$STUDENT_NAME_FIELD] ?? 'Unknown') : 'Unknown');
                        $studentNumber = $studentNumber ?? (is_array($s) ? ($s[$STUDENT_NUMBER_FIELD] ?? '') : '');
                    }

                    $enrichedPlans[] = [
                        'actionPlanId' => $apId,
                        'actionPlanDetails' => $ap[$ACTIONPLAN_TEXT_FIELD] ?? '',
                        'actionBy' => $ap[$ACTIONPLAN_ACTION_BY_FIELD] ?? '',
                        'reviewDate' => $ap[$ACTIONPLAN_REVIEW_DATE_FIELD] ?? null,
                        'status' => $ap[$ACTIONPLAN_STATUS_FIELD] ?? 5,
                        'referralId' => $referralId,
                        'refId' => $referral[$REFERRAL_REFID_FIELD] ?? '',
                        'studentId' => $studentId,
                        'studentName' => $studentName,
                        'studentNumber' => $studentNumber,
                        'strengths' => $referral[$REFERRAL_STRENGTHS_FIELD] ?? '',
                        'domainsAndConditions' => $referral[$REFERRAL_DOMAINS_ISSUES_FIELD] ?? '',
                        'meetingNotes' => $referral[$REFERRAL_MEETING_NOTES_FIELD] ?? '',
                        'goalId' => $goalId,
                        'goalText' => $goal[$GOAL_TEXT_FIELD] ?? '',
                        'goalCompleted' => $goal['crd88_completed'] ?? 0,
                        'goalCompletionNotes' => $goal['crd88_completionnotes'] ?? '',
                    ];
                }
            }

            $out = [
                'success' => true,
                'actionPlans' => $enrichedPlans
            ];
            if ($debug) {
                $out['debug'] = [
                    'apFilterRaw' => $apFilterRaw,
                    'actionPlansFetched' => count($actionPlans),
                    'actionPlansEnriched' => count($enrichedPlans),
                ];
            }
            echo json_encode($out);
            break;

        case 'updateGoalCompletion':
            // POST /api/apReferral.php?action=updateGoalCompletion
            // Body: { goalId, completed (boolean or 0/1), completionNotes }
            // Updates the crd88_completed and crd88_completionnotes fields on the Goal table
            
            if ($method !== 'POST' && $method !== 'PATCH') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use POST or PATCH.']);
                exit;
            }
            
            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }
            
            $goalId = $requestBody['goalId'] ?? null;
            if (!$goalId) {
                http_response_code(400);
                echo json_encode(['error' => 'goalId is required']);
                exit;
            }
            
            $completed = $requestBody['completed'] ?? null;
            if ($completed === null) {
                http_response_code(400);
                echo json_encode(['error' => 'completed is required (boolean or 0/1)']);
                exit;
            }
            
            $completionNotes = $requestBody['completionNotes'] ?? '';

            // Dataverse expects Edm.Boolean for boolean columns
            $completedBool = null;
            if (is_bool($completed)) {
                $completedBool = $completed;
            } else if (is_int($completed) || is_float($completed) || (is_string($completed) && is_numeric($completed))) {
                $completedBool = ((int)$completed) === 1;
            } else if (is_string($completed)) {
                $v = strtolower(trim($completed));
                if (in_array($v, ['true', 'yes', 'y', 'on'], true)) $completedBool = true;
                if (in_array($v, ['false', 'no', 'n', 'off'], true)) $completedBool = false;
            }
            if ($completedBool === null) {
                http_response_code(400);
                echo json_encode(['error' => 'completed must be boolean or 0/1']);
                exit;
            }
            
            // Build update payload
            $updateData = [
                'crd88_completed' => $completedBool,
                'crd88_completionnotes' => (string)$completionNotes
            ];
            
            $updateUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$GOAL_TABLE}({$goalId})";
            $updateRes = makeDataverseRequest('PATCH', $updateUrl, $accessToken, $updateData);
            
            if ($updateRes['status'] === 204 || $updateRes['status'] === 200) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Goal completion status updated',
                    'goalId' => $goalId
                ]);
            } else {
                http_response_code($updateRes['status']);
                echo json_encode(['error' => 'Failed to update goal completion', 'details' => $updateRes['body']]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: classes, students, submitReferrals, outcomes, outcomesPending, outcomesCompleted, latestReferralByStudent, latestActionPlanByReferral, upsertActionPlan, listGoalsByActionPlan, replaceGoals, listGoalsByReferral, createGoal, updateGoal, listActionPlansByGoal, upsertActionPlanForGoal, updateReferral, getReferral, actionPlansForReview, or updateGoalCompletion']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
