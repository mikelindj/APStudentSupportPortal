<?php
/**
 * Attendance Gateway API for ACS Academy
 * Handles attendance operations with Dataverse
 * 
 * Endpoint: parents.acsacademy.edu.sg/api/attendanceGateway.php
 * 
 * IMPORTANT: Replace the placeholder values below with your actual credentials:
 * - CLIENT_ID: Your Azure AD App Registration Client ID
 * - CLIENT_SECRET: Your Azure AD App Registration Client Secret
 * - TENANT_ID: Your Azure AD Tenant ID
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================================
// AZURE AD CONFIGURATION - Load from environment variables
// ============================================================================
// These should be set as environment variables on your server
// For local development, you can set them in your .env file or server config
$CLIENT_ID = getenv('AZURE_CLIENT_ID');
$CLIENT_SECRET = getenv('AZURE_CLIENT_SECRET');
$TENANT_ID = getenv('AZURE_TENANT_ID');
$DATAVERSE_URL = getenv('DATAVERSE_URL');

// ============================================================================
// DATAVERSE TABLE NAMES - Updated to match your Dataverse schema
// ============================================================================
// EntitySetName (used in OData URLs) vs LogicalName (used in @odata.bind)
$CLASSES_TABLE = 'crd88_classeses'; // EntitySetName from table definition (use in OData URLs)
$CLASSES_TABLE_LOGICAL = 'crd88_classes'; // LogicalName (use in @odata.bind references)
$STUDENTS_TABLE = 'new_studentses'; // EntitySetName (use in OData URLs)
$STUDENTS_TABLE_LOGICAL = 'new_students'; // LogicalName (use in @odata.bind references)
$ATTENDANCE_TABLE = 'crd88_attendances'; // EntitySetName (use in OData URLs) - plural form
$ATTENDANCE_TABLE_LOGICAL = 'crd88_attendance'; // LogicalName (use in @odata.bind references)

// ============================================================================
// DATAVERSE FIELD NAMES - Updated based on attendance record schema
// ============================================================================
// Attendance table fields
$ATTENDANCE_ID_FIELD = 'crd88_attendanceid';      // Primary key
$ATTENDANCE_DATE_FIELD = 'crd88_date';            // Date field
$ATTENDANCE_PRESENT_FIELD = 'crd88_status';       // Status choice field: 1000=Present, 1001=Absent, 1002=Late Arrival, 1003=Early Dismissal
$ATTENDANCE_REMARKS_FIELD = 'crd88_remarks';      // Remarks text field
// Lookup field logical names (confirmed from Dataverse metadata)
// Note: Navigation property names are case-sensitive!
$ATTENDANCE_STUDENT_FIELD = 'crd88_new_students'; // Lookup field logical name (for filtering)
$ATTENDANCE_CLASS_FIELD = 'crd88_classes';        // Lookup field logical name (for filtering)

// Navigation property names for @odata.bind (case-sensitive from relationship metadata)
$ATTENDANCE_STUDENT_NAV = 'crd88_new_Students';   // Navigation property name (capital S)
$ATTENDANCE_CLASS_NAV = 'crd88_Classes';          // Navigation property name (capital C)

// Classes table fields - Updated from table definition
$CLASS_ID_FIELD = 'crd88_classesid';            // Primary ID field (from PrimaryIdAttribute)
$CLASS_NAME_FIELD = 'crd88_classid';             // Primary name field (from PrimaryNameAttribute) - this is the name/identifier
$CLASS_CODE_FIELD = 'crd88_code';                // Code field (update if different in your schema)

// Students table fields - Updated from sample data
$STUDENT_ID_FIELD = 'new_studentsid';           // Primary ID field (from sample: new_studentsid)
$STUDENT_NAME_FIELD = 'new_fullname';           // Name field (from sample: new_fullname)
$STUDENT_NUMBER_FIELD = 'crd88_indexnumber';    // Student number/index (from sample: crd88_indexnumber)
$STUDENT_CLASS_FIELD = 'crd88_class';           // Class lookup field logical name (from sample: _crd88_class_value)

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
    
    // Extract org name from Dataverse URL for scope
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
            // GET /api/attendanceGateway.php?action=classes - Get all classes
            // Select only fields we know exist from table definition
            $select = urlencode("{$CLASS_ID_FIELD},{$CLASS_NAME_FIELD}");
            // Add code field only if it exists (will fail gracefully if it doesn't)
            // $select = urlencode("{$CLASS_ID_FIELD},{$CLASS_NAME_FIELD},{$CLASS_CODE_FIELD}");
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
            // GET /api/attendanceGateway.php?action=students&classId=xxx - Get students in a class
            $classId = $queryParams['classId'] ?? null;
            if (!$classId) {
                http_response_code(400);
                echo json_encode(['error' => 'classId parameter is required']);
                exit;
            }
            
            // Filter students by class
            // For lookup fields, use the _value field format
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
            
        case 'attendance':
            if ($method === 'GET') {
                // GET /api/attendanceGateway.php?action=attendance&date=2024-01-01&classId=xxx - Get attendance for a date
                $date = $queryParams['date'] ?? null;
                $classId = $queryParams['classId'] ?? null;
                
                if (!$date || !$classId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'date and classId parameters are required']);
                    exit;
                }
                
                // Filter attendance by date and class
                // Format date for Dataverse: convert YYYY-MM-DD to datetime
                $dateTime = $date . 'T00:00:00Z';
                $dateTimeEnd = $date . 'T23:59:59Z';
                // Use date range filter for Dataverse (URL encode the filter)
                // Note: For lookup fields, use the logical name with guid format
                $filter = urlencode("{$ATTENDANCE_DATE_FIELD} ge {$dateTime} and {$ATTENDANCE_DATE_FIELD} le {$dateTimeEnd} and _{$ATTENDANCE_CLASS_FIELD}_value eq {$classId}");
                // Include lookup value fields to extract student ID
                // Only select actual fields, not lookup field logical names
                // Use _value field for lookup, not the logical name
                $select = urlencode("{$ATTENDANCE_ID_FIELD},{$ATTENDANCE_PRESENT_FIELD},{$ATTENDANCE_DATE_FIELD},{$ATTENDANCE_REMARKS_FIELD},_{$ATTENDANCE_STUDENT_FIELD}_value");
                $url = "{$DATAVERSE_URL}/api/data/v9.2/{$ATTENDANCE_TABLE}?\$filter={$filter}&\$select={$select}";
                $result = makeDataverseRequest('GET', $url, $accessToken);
                
                if ($result['status'] === 200) {
                    $data = json_decode($result['body'], true);
                    // Map attendance records to include present field and status for frontend compatibility
                    $attendanceRecords = $data['value'] ?? [];
                    foreach ($attendanceRecords as &$record) {
                        // Include both status (for dropdown) and present (for legacy compatibility)
                        $status = $record[$ATTENDANCE_PRESENT_FIELD] ?? 1000;
                        $record['crd88_status'] = $status; // Include status field
                        $record['present'] = ($status == 1000); // Boolean for legacy compatibility
                        $record['studentId'] = $record["_{$ATTENDANCE_STUDENT_FIELD}_value"] ?? null;
                        // Include remarks field
                        $record['crd88_remarks'] = $record[$ATTENDANCE_REMARKS_FIELD] ?? '';
                    }
                    unset($record); // Break reference
                    
                    echo json_encode([
                        'success' => true,
                        'attendance' => $attendanceRecords
                    ]);
                } else {
                    http_response_code($result['status']);
                    echo json_encode(['error' => 'Failed to fetch attendance', 'details' => $result['body']]);
                }
            } elseif ($method === 'POST') {
                // POST /api/attendanceGateway.php?action=attendance - Submit attendance
                if (!$requestBody || !isset($requestBody['date']) || !isset($requestBody['classId']) || !isset($requestBody['records'])) {
                    http_response_code(400);
                    echo json_encode(['error' => 'date, classId, and records are required']);
                    exit;
                }
                
                $date = $requestBody['date'];
                $classId = $requestBody['classId'];
                $records = $requestBody['records']; // Array of {studentId, present}
                $createdBy = $requestBody['createdBy'] ?? null;
                
                $results = [];
                $errors = [];
                
                foreach ($records as $record) {
                    $studentId = $record['studentId'];
                    
                    // Support both 'status' field (direct) and 'present' field (legacy boolean)
                    // Choice field values: 1000=Present, 1001=Absent, 1002=Late Arrival, 1003=Early Dismissal
                    $statusValue = null;
                    if (isset($record['status'])) {
                        // Direct status value provided
                        $statusValue = (int)$record['status'];
                        // Validate status value
                        if (!in_array($statusValue, [1000, 1001, 1002, 1003])) {
                            $errors[] = [
                                'studentId' => $studentId,
                                'error' => "Invalid status value: {$statusValue}. Must be 1000, 1001, 1002, or 1003."
                            ];
                            continue;
                        }
                    } elseif (isset($record['present'])) {
                        // Legacy boolean format - convert to status
                        $present = (bool)$record['present'];
                        $statusValue = $present ? 1000 : 1001; // 1000 = Present, 1001 = Absent
                    } else {
                        // Default to Present if neither provided
                        $statusValue = 1000;
                    }
                    
                    // Check if attendance record already exists
                    // Format date for Dataverse
                    $dateTime = $date . 'T00:00:00Z';
                    $dateTimeEnd = $date . 'T23:59:59Z';
                    // Note: For lookup fields, use guid format
                    $checkFilter = urlencode("{$ATTENDANCE_DATE_FIELD} ge {$dateTime} and {$ATTENDANCE_DATE_FIELD} le {$dateTimeEnd} and _{$ATTENDANCE_STUDENT_FIELD}_value eq {$studentId} and _{$ATTENDANCE_CLASS_FIELD}_value eq {$classId}");
                    $checkUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ATTENDANCE_TABLE}?\$filter={$checkFilter}";
                    $checkResult = makeDataverseRequest('GET', $checkUrl, $accessToken);
                    
                    // Format date for Dataverse (ISO 8601 format)
                    $dateTimeValue = $date . 'T00:00:00Z';
                    
                    // For lookup fields, MUST use @odata.bind (navigation properties)
                    // Navigation property names are CASE-SENSITIVE and differ from logical names
                    // From relationship metadata:
                    // - Student: crd88_new_Students (capital S) not crd88_new_students
                    // - Class: crd88_Classes (capital C) not crd88_classes
                    // Get remarks from record (if provided)
                    $remarksText = isset($record['remarks']) ? trim($record['remarks']) : '';
                    
                    $attendanceData = [
                        $ATTENDANCE_DATE_FIELD => $dateTimeValue,
                        "{$ATTENDANCE_STUDENT_NAV}@odata.bind" => "/{$STUDENTS_TABLE}({$studentId})",
                        "{$ATTENDANCE_CLASS_NAV}@odata.bind" => "/{$CLASSES_TABLE}({$classId})",
                        $ATTENDANCE_PRESENT_FIELD => $statusValue
                    ];
                    
                    // Add remarks field (always include to allow clearing)
                    $attendanceData[$ATTENDANCE_REMARKS_FIELD] = $remarksText;
                    
                    // Note: crd88_createdby field doesn't exist in the attendance table
                    // If you need to track who created the record, add a custom field to the table first
                    // if ($createdBy) {
                    //     $attendanceData['crd88_createdby'] = $createdBy;
                    // }
                    
                    if ($checkResult['status'] === 200) {
                        $existing = json_decode($checkResult['body'], true);
                        if (!empty($existing['value'])) {
                            // Update existing record
                            $existingId = $existing['value'][0][$ATTENDANCE_ID_FIELD];
                            $updateUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ATTENDANCE_TABLE}({$existingId})";
                            $updateResult = makeDataverseRequest('PATCH', $updateUrl, $accessToken, $attendanceData);
                            
                            if ($updateResult['status'] === 204 || $updateResult['status'] === 200) {
                                $results[] = ['studentId' => $studentId, 'action' => 'updated', 'id' => $existingId];
                            } else {
                                $errors[] = ['studentId' => $studentId, 'error' => $updateResult['body']];
                            }
                        } else {
                            // Create new record
                            $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ATTENDANCE_TABLE}";
                            $createResult = makeDataverseRequest('POST', $createUrl, $accessToken, $attendanceData);
                            
                            if ($createResult['status'] === 201 || $createResult['status'] === 204) {
                                $createdData = json_decode($createResult['body'], true);
                                $results[] = ['studentId' => $studentId, 'action' => 'created', 'id' => $createdData[$ATTENDANCE_ID_FIELD] ?? 'new'];
                            } else {
                                $errors[] = ['studentId' => $studentId, 'error' => $createResult['body']];
                            }
                        }
                    } else {
                        // Create new record
                        $createUrl = "{$DATAVERSE_URL}/api/data/v9.2/{$ATTENDANCE_TABLE}";
                        $createResult = makeDataverseRequest('POST', $createUrl, $accessToken, $attendanceData);
                        
                        if ($createResult['status'] === 201 || $createResult['status'] === 204) {
                            $createdData = json_decode($createResult['body'], true);
                            $results[] = ['studentId' => $studentId, 'action' => 'created', 'id' => $createdData[$ATTENDANCE_ID_FIELD] ?? 'new'];
                        } else {
                            $errors[] = ['studentId' => $studentId, 'error' => $createResult['body']];
                        }
                    }
                }
                
                if (empty($errors)) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Attendance saved successfully',
                        'results' => $results
                    ]);
                } else {
                    http_response_code(207); // Multi-Status
                    echo json_encode([
                        'success' => false,
                        'message' => 'Some records failed',
                        'results' => $results,
                        'errors' => $errors
                    ]);
                }
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: classes, students, or attendance']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

