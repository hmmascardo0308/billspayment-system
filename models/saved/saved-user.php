<?php
require_once __DIR__ . '/../../config/config.php';

session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is authorized
if (!isset($_SESSION['user_type'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Check if JSON decode was successful
        if ($input === null) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            exit;
        }
        
        // Validate required fields
        $required_fields = ['user_type', 'id_number', 'first_name', 'last_name', 'username'];
        foreach ($required_fields as $field) {
            if (empty($input[$field])) {
                echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
                exit;
            }
        }
        
        // Sanitize inputs
        $id_number = mysqli_real_escape_string($conn, trim($input['id_number']));
        $first_name = mysqli_real_escape_string($conn, trim($input['first_name']));
        $middle_name = mysqli_real_escape_string($conn, trim($input['middle_name'] ?? ''));
        $last_name = mysqli_real_escape_string($conn, trim($input['last_name']));
        $username = mysqli_real_escape_string($conn, trim($input['username']));
        $user_type = mysqli_real_escape_string($conn, trim($input['user_type']));
        $password = md5('Mlinc1234'); // Hash the default password
        $status = 'Active'; // Default status
        
        // Get current user info for created_by field
        $created_by = '';
        if ($_SESSION['user_type'] === 'admin' && isset($_SESSION['admin_name'])) {
            $created_by = $_SESSION['admin_name'];
        } elseif ($_SESSION['user_type'] === 'user' && isset($_SESSION['user_name'])) {
            $created_by = $_SESSION['user_name'];
        } else {
            $created_by = 'System';
        }
        
        $date_created = date('Y-m-d H:i:s');
        
        // Check if ID number already exists in user_form table
        $check_query = "SELECT id_number FROM mldb.user_form WHERE id_number = ?";
        $check_stmt = mysqli_prepare($conn, $check_query);
        mysqli_stmt_bind_param($check_stmt, "s", $id_number);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);
        
        if (mysqli_num_rows($check_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'ID Number already exists']);
            exit;
        }
        
        // Check if email/username already exists in user_form table
        $check_email_query = "SELECT email FROM mldb.user_form WHERE email = ?";
        $check_email_stmt = mysqli_prepare($conn, $check_email_query);
        mysqli_stmt_bind_param($check_email_stmt, "s", $username);
        mysqli_stmt_execute($check_email_stmt);
        $check_email_result = mysqli_stmt_get_result($check_email_stmt);
        
        if (mysqli_num_rows($check_email_result) > 0) {
            echo json_encode(['success' => false, 'message' => 'Username/Email already exists']);
            exit;
        }
        
        // Determine default access_level and permissions based on user_type
        $access_level = 0;
        $permissions_array = [];

        // Try to read accesslevel map file to extract permission lists
        $mapPath = __DIR__ . '/../../assets/js/accesslevel-map.json';
        $mapRaw = null;
        $mapDecoded = null;
        if (file_exists($mapPath)) {
            $mapRaw = @file_get_contents($mapPath);
            $mapDecoded = @json_decode($mapRaw, true);
        }

        // helper to collect leaf keys from catalog-like structure
     function collect_leaf_keys(array $nodes): array
{
    $out = [];

    foreach ($nodes as $node) {
        if (!is_array($node)) continue;

        if (isset($node['children']) && is_array($node['children']) && count($node['children'])) {
            $out = array_merge($out, collect_leaf_keys($node['children']));
        } else {
            if (isset($node['key']) && is_string($node['key'])) {
                $out[] = $node['key'];
            }
        }
    }

    $out = array_values(array_unique(array_filter($out, function ($v) {
        return is_string($v) && trim($v) !== '';
    })));

    sort($out, SORT_STRING);

    return $out;
}

        if (strtolower($user_type) === 'admin') {
            $access_level = 31;
            // Admins get all leaf permissions from the permission catalog if available
            if (is_array($mapDecoded)) {
                $catalog = [];
                if (isset($mapDecoded['permission_catalog']) && is_array($mapDecoded['permission_catalog'])) {
                    $catalog = $mapDecoded['permission_catalog'];
                } elseif (array_keys($mapDecoded) === range(0, count($mapDecoded) - 1)) {
                    // older format: the file may itself be a catalog array
                    $catalog = $mapDecoded;
                }
                $permissions_array = collect_leaf_keys($catalog);
            }
        } else {
            // Default 'user' type: access level 14 and permissions from map for level 14
            $access_level = 14;
            if (is_array($mapDecoded)) {
                // try to find explicit access_levels array
                if (isset($mapDecoded['access_levels']) && is_array($mapDecoded['access_levels'])) {
                    foreach ($mapDecoded['access_levels'] as $r) {
                        if (isset($r['access_level']) && intval($r['access_level']) === 14) {
                            if (isset($r['permissions']) && is_array($r['permissions'])) {
                                $permissions_array = $r['permissions'];
                            }
                            break;
                        }
                    }
                }
                // fallback: try to extract by scanning raw content for access_level 14
                if (empty($permissions_array) && is_string($mapRaw) && strlen($mapRaw) > 0) {
                    if (preg_match('/"access_level"\s*:\s*14\s*,[\s\S]*?"permissions"\s*:\s*\[([^\]]*)\]/i', $mapRaw, $m)) {
                        $permBlock = $m[1];
                        if (preg_match_all('/"([^\"]*)"/', $permBlock, $pm)) {
                            $permissions_array = array_map('strval', $pm[1]);
                        }
                    }
                }
            }
        }

        // Ensure permissions array is sorted unique strings
        if (!is_array($permissions_array)) $permissions_array = [];
        $permSet = [];
        foreach ($permissions_array as $p) {
            if (is_string($p) && trim($p) !== '') $permSet[trim($p)] = true;
        }
        $permissions_array = array_values(array_unique(array_keys($permSet)));
        sort($permissions_array, SORT_STRING);

        $permissions_json = json_encode($permissions_array, JSON_UNESCAPED_SLASHES);
        if ($permissions_json === false) $permissions_json = json_encode([]);

        // Determine if the table has access_level and permissions columns
        $hasAccessLevel = false;
        $hasPermissions = false;
        $colRes = @mysqli_query($conn, "SHOW COLUMNS FROM mldb.user_form LIKE 'access_level'");
        if ($colRes && mysqli_num_rows($colRes) > 0) $hasAccessLevel = true;
        $colRes2 = @mysqli_query($conn, "SHOW COLUMNS FROM mldb.user_form LIKE 'permissions'");
        if ($colRes2 && mysqli_num_rows($colRes2) > 0) $hasPermissions = true;

        if ($hasAccessLevel && $hasPermissions) {
            // Insert new user including access_level and permissions
            $insert_query = "INSERT INTO mldb.user_form 
                            (id_number, first_name, middle_name, last_name, email, password, user_type, status, date_created, created_by, access_level, permissions) 
                            VALUES 
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "ssssssssssis", 
                $id_number, $first_name, $middle_name, $last_name, $username, 
                $password, $user_type, $status, $date_created, $created_by,
                $access_level, $permissions_json
            );
        } else {
            // Fallback: original insert without new columns
            $insert_query = "INSERT INTO mldb.user_form 
                            (id_number, first_name, middle_name, last_name, email, password, user_type, status, date_created, created_by) 
                            VALUES 
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $insert_stmt = mysqli_prepare($conn, $insert_query);
            mysqli_stmt_bind_param($insert_stmt, "ssssssssss", 
                $id_number, $first_name, $middle_name, $last_name, $username, 
                $password, $user_type, $status, $date_created, $created_by
            );
        }
        
        if (mysqli_stmt_execute($insert_stmt)) {
            // Get the newly created user data from user_form table
            $selectFields = "id_number, first_name, middle_name, last_name, email as username, user_type, status, last_online, date_created, created_by, modified_date, modified_by";
            if (isset($hasAccessLevel) && $hasAccessLevel) $selectFields .= ", access_level";
            if (isset($hasPermissions) && $hasPermissions) $selectFields .= ", permissions";
            $new_user_query = "SELECT $selectFields FROM mldb.user_form WHERE id_number = ? LIMIT 1";
            $new_user_stmt = mysqli_prepare($conn, $new_user_query);
            mysqli_stmt_bind_param($new_user_stmt, "s", $id_number);
            mysqli_stmt_execute($new_user_stmt);
            $new_user_result = mysqli_stmt_get_result($new_user_stmt);
            $new_user_data = mysqli_fetch_assoc($new_user_result);
            
            echo json_encode([
                'success' => true, 
                'message' => 'User created successfully',
                'user_data' => $new_user_data
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}

mysqli_close($conn);
?>