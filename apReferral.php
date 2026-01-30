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

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
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

// Student Support Case table
$SUPPORT_CASE_TABLE = 'crd88_studentsupportcases';
$SUPPORT_CASE_TABLE_LOGICAL = 'crd88_studentsupportcase';

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

// Student Support Case fields (from table definition)
$CASE_ID_FIELD = 'crd88_studentsupportcaseid';
$CASE_REFID_FIELD = 'crd88_refid';                    // Primary name field (autonumber in Dataverse)
$CASE_STUDENT_FIELD = 'crd88_new_students';           // Lookup field logical name
$CASE_STUDENT_NAV = 'crd88_new_Students';             // Navigation property (capital S based on pattern)
$CASE_DOMAINS_ISSUES_FIELD = 'crd88_domainsandissues';
$CASE_ACTION_PLAN_FIELD = 'crd88_ap_actionplan';
$CASE_ACTION_BY_FIELD = 'crd88_ap_actionby';
$CASE_REVIEW_DATE_FIELD = 'crd88_ap_reviewdate';
$CASE_GOALS_FIELD = 'crd88_ap_goals';
$CASE_FINAL_OUTCOME_FIELD = 'crd88_final_outcome';
$CASE_INTERVENTION_COMPLETE_FIELD = 'crd88_final_interventioncomplete';

// Validate configuration
if (empty($CLIENT_ID) || empty($CLIENT_SECRET) || empty($TENANT_ID)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Azure AD configuration missing. Please set AZURE_CLIENT_ID, AZURE_CLIENT_SECRET, and AZURE_TENANT_ID environment variables on your server.'
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
            
            foreach ($referrals as $referral) {
                $studentId = $referral['studentId'] ?? null;
                $domainsAndIssues = $referral['domainsAndIssues'] ?? '';
                $referredBy = $referral['referredBy'] ?? '';
                $referredByName = $referral['referredByName'] ?? '';
                
                if (!$studentId) {
                    $errors[] = [
                        'referral' => $referral,
                        'error' => 'studentId is required'
                    ];
                    continue;
                }
                
                // Prepare the support case data
                $caseData = [
                    "{$CASE_STUDENT_NAV}@odata.bind" => "/{$STUDENTS_TABLE}({$studentId})",
                    $CASE_DOMAINS_ISSUES_FIELD => $domainsAndIssues,
                    $CASE_INTERVENTION_COMPLETE_FIELD => false
                ];
                
                // Create the support case record
                $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$SUPPORT_CASE_TABLE}";
                $createResult = makeDataverseRequest('POST', $createUrl, $accessToken, $caseData);
                
                if ($createResult['status'] === 201 || $createResult['status'] === 204) {
                    $createdData = json_decode($createResult['body'], true);
                    $results[] = [
                        'studentId' => $studentId,
                        // Dataverse autonumber should populate crd88_refid; return it if present
                        'refId' => $createdData[$CASE_REFID_FIELD] ?? null,
                        'action' => 'created',
                        'id' => $createdData[$CASE_ID_FIELD] ?? 'new'
                    ];
                } else {
                    $errors[] = [
                        'studentId' => $studentId,
                        'error' => $createResult['body']
                    ];
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
            // GET /api/apReferral.php?action=outcomes - Get all support cases
            $select = urlencode("{$CASE_ID_FIELD},{$CASE_REFID_FIELD},{$CASE_DOMAINS_ISSUES_FIELD},{$CASE_ACTION_PLAN_FIELD},{$CASE_ACTION_BY_FIELD},{$CASE_REVIEW_DATE_FIELD},{$CASE_GOALS_FIELD},{$CASE_FINAL_OUTCOME_FIELD},{$CASE_INTERVENTION_COMPLETE_FIELD},createdon,_{$CASE_STUDENT_FIELD}_value");
            $orderby = urlencode("createdon desc");
            // Try to expand student for display (safe even if frontend ignores it)
            $expand = urlencode("{$CASE_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$SUPPORT_CASE_TABLE}?\$select={$select}&\$orderby={$orderby}&\$expand={$expand}";
            $result = makeDataverseRequest('GET', $url, $accessToken);
            
            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                echo json_encode([
                    'success' => true,
                    'cases' => $data['value'] ?? []
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch outcomes', 'details' => $result['body']]);
            }
            break;

        case 'outcomesPending':
            // GET /api/apReferral.php?action=outcomesPending
            // Pending = final outcome blank/null
            $filter = urlencode("({$CASE_FINAL_OUTCOME_FIELD} eq null or {$CASE_FINAL_OUTCOME_FIELD} eq '')");
            $select = urlencode("{$CASE_ID_FIELD},{$CASE_REFID_FIELD},{$CASE_DOMAINS_ISSUES_FIELD},{$CASE_ACTION_PLAN_FIELD},{$CASE_ACTION_BY_FIELD},{$CASE_REVIEW_DATE_FIELD},{$CASE_GOALS_FIELD},{$CASE_FINAL_OUTCOME_FIELD},{$CASE_INTERVENTION_COMPLETE_FIELD},createdon,_{$CASE_STUDENT_FIELD}_value");
            $orderby = urlencode("{$CASE_REVIEW_DATE_FIELD} desc");
            $expand = urlencode("{$CASE_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$SUPPORT_CASE_TABLE}?\$filter={$filter}&\$select={$select}&\$orderby={$orderby}&\$expand={$expand}";
            $result = makeDataverseRequest('GET', $url, $accessToken);

            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                echo json_encode([
                    'success' => true,
                    'cases' => $data['value'] ?? []
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch pending outcomes', 'details' => $result['body']]);
            }
            break;

        case 'outcomesCompleted':
            // GET /api/apReferral.php?action=outcomesCompleted
            // Completed = final outcome filled (not null/empty)
            $filter = urlencode("({$CASE_FINAL_OUTCOME_FIELD} ne null and {$CASE_FINAL_OUTCOME_FIELD} ne '')");
            $select = urlencode("{$CASE_ID_FIELD},{$CASE_REFID_FIELD},{$CASE_DOMAINS_ISSUES_FIELD},{$CASE_ACTION_PLAN_FIELD},{$CASE_ACTION_BY_FIELD},{$CASE_REVIEW_DATE_FIELD},{$CASE_GOALS_FIELD},{$CASE_FINAL_OUTCOME_FIELD},{$CASE_INTERVENTION_COMPLETE_FIELD},createdon,_{$CASE_STUDENT_FIELD}_value");
            $orderby = urlencode("{$CASE_REVIEW_DATE_FIELD} desc");
            $expand = urlencode("{$CASE_STUDENT_NAV}(\$select={$STUDENT_NAME_FIELD},{$STUDENT_NUMBER_FIELD})");
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$SUPPORT_CASE_TABLE}?\$filter={$filter}&\$select={$select}&\$orderby={$orderby}&\$expand={$expand}";
            $result = makeDataverseRequest('GET', $url, $accessToken);

            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                echo json_encode([
                    'success' => true,
                    'cases' => $data['value'] ?? []
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch completed outcomes', 'details' => $result['body']]);
            }
            break;

        case 'latestCaseByStudent':
            // GET /api/apReferral.php?action=latestCaseByStudent&studentId=xxx - Get the latest support case for a student
            $studentId = $queryParams['studentId'] ?? null;
            if (!$studentId) {
                http_response_code(400);
                echo json_encode(['error' => 'studentId parameter is required']);
                exit;
            }

            $filter = urlencode("_{$CASE_STUDENT_FIELD}_value eq {$studentId}");
            $select = urlencode("{$CASE_ID_FIELD},{$CASE_REFID_FIELD},{$CASE_DOMAINS_ISSUES_FIELD},{$CASE_ACTION_PLAN_FIELD},{$CASE_ACTION_BY_FIELD},{$CASE_REVIEW_DATE_FIELD},{$CASE_GOALS_FIELD},{$CASE_FINAL_OUTCOME_FIELD},{$CASE_INTERVENTION_COMPLETE_FIELD},createdon,_{$CASE_STUDENT_FIELD}_value");
            $orderby = urlencode("createdon desc");
            $top = 1;
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$SUPPORT_CASE_TABLE}?\$filter={$filter}&\$select={$select}&\$orderby={$orderby}&\$top={$top}";
            $result = makeDataverseRequest('GET', $url, $accessToken);

            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                $items = $data['value'] ?? [];
                echo json_encode([
                    'success' => true,
                    'case' => !empty($items) ? $items[0] : null
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch latest case', 'details' => $result['body']]);
            }
            break;
            
        case 'updateCase':
            // PATCH /api/apReferral.php?action=updateCase&caseId=xxx - Update a support case
            if ($method !== 'PATCH' && $method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed. Use PATCH or POST.']);
                exit;
            }
            
            $caseId = $queryParams['caseId'] ?? null;
            if (!$caseId) {
                http_response_code(400);
                echo json_encode(['error' => 'caseId parameter is required']);
                exit;
            }
            
            if (!$requestBody) {
                http_response_code(400);
                echo json_encode(['error' => 'Request body is required']);
                exit;
            }
            
            // Build update data from allowed fields
            $updateData = [];
            $allowedFields = [
                'actionPlan' => $CASE_ACTION_PLAN_FIELD,
                'actionBy' => $CASE_ACTION_BY_FIELD,
                'reviewDate' => $CASE_REVIEW_DATE_FIELD,
                'goals' => $CASE_GOALS_FIELD,
                'finalOutcome' => $CASE_FINAL_OUTCOME_FIELD,
                'interventionComplete' => $CASE_INTERVENTION_COMPLETE_FIELD,
                'domainsAndIssues' => $CASE_DOMAINS_ISSUES_FIELD
            ];
            
            foreach ($allowedFields as $inputKey => $dataverseField) {
                if (isset($requestBody[$inputKey])) {
                    $updateData[$dataverseField] = $requestBody[$inputKey];
                }
            }
            
            if (empty($updateData)) {
                http_response_code(400);
                echo json_encode(['error' => 'No valid fields to update']);
                exit;
            }
            
            $updateUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$SUPPORT_CASE_TABLE}({$caseId})";
            $updateResult = makeDataverseRequest('PATCH', $updateUrl, $accessToken, $updateData);
            
            if ($updateResult['status'] === 204 || $updateResult['status'] === 200) {
                echo json_encode([
                    'success' => true,
                    'message' => 'Case updated successfully',
                    'caseId' => $caseId
                ]);
            } else {
                http_response_code($updateResult['status']);
                echo json_encode(['error' => 'Failed to update case', 'details' => $updateResult['body']]);
            }
            break;
            
        case 'getCase':
            // GET /api/apReferral.php?action=getCase&caseId=xxx - Get a single support case
            $caseId = $queryParams['caseId'] ?? null;
            if (!$caseId) {
                http_response_code(400);
                echo json_encode(['error' => 'caseId parameter is required']);
                exit;
            }
            
            $url = "{$DATAVERSE_URL}/api/data/v9.2/{$SUPPORT_CASE_TABLE}({$caseId})";
            $result = makeDataverseRequest('GET', $url, $accessToken);
            
            if ($result['status'] === 200) {
                $data = json_decode($result['body'], true);
                echo json_encode([
                    'success' => true,
                    'case' => $data
                ]);
            } else {
                http_response_code($result['status']);
                echo json_encode(['error' => 'Failed to fetch case', 'details' => $result['body']]);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: classes, students, submitReferrals, outcomes, outcomesPending, outcomesCompleted, latestCaseByStudent, updateCase, or getCase']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
