<?php
require_once __DIR__ . '/../../config/config.php';
ini_set('display_errors',1);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | E_DEPRECATED | E_STRICT);
error_reporting(0);

// Validate and format the date from POST
if (isset($_POST['date'])) {
    $inputDate = $_POST['date'];
    $formattedDate = date('Y-m-d', strtotime($inputDate));
    $_SESSION['date'] = $formattedDate;
}

// Query definition
$date = $_SESSION['date'] ?? null;

if ($date) {
    // Query for MCL data
    $queryMcl = "SELECT * FROM mldb.billspayment_feedback_mcl WHERE partner_type='PAGIBIG' AND `date`='$date'";
    $queryExcel = "SELECT * FROM mldb.billspayment_feedback_excel WHERE partner_type='PAGIBIG' AND feedback_date='$date' ORDER BY id DESC";

    // Execute and fetch MCL data
    $result1 = $conn->query($queryMcl);
    if (!$result1) {
        die('Query failed: ' . $conn->error);
    }
    $mclData = $result1->fetch_all(MYSQLI_ASSOC);
    // $mclData = $result1->fetch_all(MYSQLI_ASSOC) ?: [];
    $_SESSION['mclData'] = $mclData;
    $mclData2 = $_SESSION['mclData'];

    // Execute and fetch Excel data
    $result2 = $conn->query($queryExcel);
    if (!$result2) {
        die('Query failed: ' . $conn->error);
    }
    $excelData = $result2->fetch_all(MYSQLI_ASSOC);
    // $excelData = $result2->fetch_all(MYSQLI_ASSOC) ?: [];
    $_SESSION['excelData'] = $excelData;
    $excelData2 = $_SESSION['excelData'];

    $mclConfirmStatus = $conn->query($queryMcl)->fetch_assoc()['confirm_status'];
    $excelConfirmStatus = $conn->query($queryExcel)->fetch_assoc()['confirm_status'];

    // Only display the CONFIRM button if both statuses are 'yes'
    // $showConfirmButton = ($mclConfirmStatus === 'yes' && $excelConfirmStatus === 'yes');
}

if (isset($_POST['confirm'])) {
    $mclData = $_SESSION['mclData'] ?? [];
    $excelData = $_SESSION['excelData'] ?? [];
    $hasError = false;

    $conn->begin_transaction();

    try {
        $stmtMcl = $conn->prepare("
            UPDATE mldb.billspayment_feedback_mcl
            SET confirm_status = 'YES'
            WHERE account_no = ?
            AND feedback_reference_code = ?
            AND type_of_amount = ?
        ");

        foreach ($mclData as $mclValue) {
            $stmtMcl->bind_param(
                'sss',
                $mclValue['account_no'],
                $mclValue['feedback_reference_code'],
                $mclValue['type_of_amount']
            );

            if (!$stmtMcl->execute()) {
                $hasError = true;
                error_log("MCL update failed: " . $stmtMcl->error);
                break;
            }
        }

        if (!$hasError) {
            $stmtExcel = $conn->prepare("
                UPDATE mldb.billspayment_feedback_excel
                SET confirm_status = 'YES'
                WHERE feedback_account_no = ?
                AND feedback_reference_no = ?
                AND feedback_amount_of_paid = ?
            ");

            foreach ($excelData as $excelValue) {
                $stmtExcel->bind_param(
                    'sss',
                    $excelValue['feedback_account_no'],
                    $excelValue['feedback_reference_no'],
                    $excelValue['feedback_amount_of_paid']
                );

                if (!$stmtExcel->execute()) {
                    $hasError = true;
                    error_log("Excel update failed: " . $stmtExcel->error);
                    break;
                }
            }

            $stmtExcel->close();
        }

        if ($hasError) {
            $conn->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to update one or more records.']);
        } else {
            $conn->commit();
            echo json_encode(['success' => true, 'message' => 'Records updated successfully.']);
        }
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Transaction failed: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred.']);
    }

    $stmtMcl->close();
    $conn->close();
    exit; // Ensure no additional output is sent
}

?>