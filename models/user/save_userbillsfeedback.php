<?php

require_once __DIR__ . '/../../config/config.php';

if (isset($_POST['proceed'])) {
    $option2 = $_SESSION['option2'];
    $option1 = $_SESSION['option1'];
    $validRows = $_SESSION['validRows'] ?? [];
    $insertedCount = 0;
    $errors = [];

    if($option2 === '.mcl'){

        foreach ($validRows as $row) {
            if (!isset($row['Account Number']) || !isset($row['Last Name']) || !isset($row['First Name']) || !isset($row['Middle Name']) || !isset($row['Loan Type']) || !isset($row['Total Amount']) || !isset($row['Feedback Date']) || !isset($row['Timestamp']) || !isset($row['Feedback Ref. Code.']) || !isset($row['Unknown Code']) || !isset($row['Phone Number']) || !isset($row['Status 1']) || !isset($row['Branch Name']) || !isset($row['Status 2'])) {
                continue; // Skip rows that don't have all the necessary keys
            }

            $account_no = $row['Account Number'];
            $lastname = $row['Last Name'];
            $firstname = str_replace(array("\r", "\n", "\t", " "), "", $row['First Name']);
            $middlename = str_replace(array("\r", "\n", "\t", " "), "", $row['Middle Name']);
            $type_of_loan = str_replace(array("\r", "\n", "\t", " "), "", $row['Loan Type']);
            //$type_of_amount1 = str_replace(array("\r", "\n", "\t", " "), "", $row['Total Amount']);
            $type_of_amount = number_format(str_replace(array("\r", "\n", "\t", " "), "", $row['Total Amount']), 2, '.', ','); // Format number with 2 decimal places, dot as decimal point, and comma as thousand separator
            $date = $row['Feedback Date'];
            $timestamp = $row['Timestamp'];
            $feedback_reference_code = $row['Feedback Ref. Code.'];
            $unknown_code = $row['Unknown Code'];
            $phone_no = $row['Phone Number'];
            $status1 = $row['Status 1'];
            $branch_name = $row['Branch Name'];
            $status2 = str_replace(array("\r", "\n", "\t", " "), "", $row['Status 2']);
            $uploaded_date = date("Y-m-d H:i:s");
            $uploaded_by = $_SESSION['user_name']; // Change this to the actual username
            $usertype = $_SESSION['user_type']; // Change this to the actual usertype

            $sql = "INSERT INTO mldb.billspayment_feedback_mcl (account_no, lastname, firstname, middlename, type_of_loan, type_of_amount, `date`, `timestamp`, feedback_reference_code, unknown_code, phone_no, `status1`, branch_name, `status2`, partner_type, uploaded_date, uploaded_by, user_type)
                    VALUES ('$account_no', '$lastname', '$firstname', '$middlename', '$type_of_loan', '$type_of_amount', '$date', '$timestamp', '$feedback_reference_code', '$unknown_code', '$phone_no', '$status1', '$branch_name', '$status2', '$option1', '$uploaded_date', '$uploaded_by', '$usertype')";

            if ($conn->query($sql) === TRUE) {
                $insertedCount++;
            } else {
                $errors[] = "Error: " . $sql . "<br>" . $conn->error;
            }
        }

        //$response = ['success' => $insertedCount > 0, 'message' => "$insertedCount record(s) inserted successfully."];
        if ($insertedCount > 0) {
            //$response['message'] = !empty($errors) ? implode("\n", $errors) : 'No records were inserted.';
            $response = ['success' => $insertedCount > 0, 'message' => "$insertedCount record(s) inserted successfully."];
            echo json_encode($response);
            exit();

        }else{
            $response['message'] = !empty($errors) ? implode("\n", $errors) : 'No records were inserted.';
            $conn->close();
            exit();
        }

    }elseif($option2 === '.xls'){
        if (empty($validRows)) {
            echo json_encode(['success' => false, 'message' => 'No valid rows found to process.']);
            exit();
        }

        foreach ($validRows as $row) {
            // Ensure all required fields are present
            $requiredFields = [
                'Date', 'Timestamp', 'Control No.', 'Reference No.', 'Payor Name', 'Address',
                'Account No.', 'Account Name', 'Amount Paid', 'Charge to Customer', 
                'Charge to Partner', 'Contact No.', 'Other Details', 'ML Branch Outlet',
                'Region', 'Operator', 'Remote Branch', 'Remote Operator'
            ];

            $missingFields = array_diff($requiredFields, array_keys($row));
            if (!empty($missingFields)) {
                continue; // Skip rows missing required fields
            }

            // Sanitize and prepare data
            $data = array_map(function($value) use ($conn) {
                return $conn->real_escape_string($value);
            }, $row);

            // Additional fields
            $data['uploaded_date'] = date("Y-m-d H:i:s");
            $data['partner_type'] = $conn->real_escape_string($option1);
            $data['usertype'] = $conn->real_escape_string($_SESSION['user_type']);
            $data['uploaded_by'] = $conn->real_escape_string($_SESSION['admin_name'] ?? $_SESSION['user_name']);

            // Insert query
            $sql = "INSERT INTO mldb.billspayment_feedback_excel (
                feedback_date, feedback_timestamp, feedback_control_no, feedback_reference_no, payor_name,
                feedback_address, feedback_account_no, feedback_account_name, feedback_amount_of_paid, charges_of_amount_customer,
                charges_of_amount_partner, feedback_phone_no, other_details, branch_outlet, region,
                operator, remote_branch, remote_operator, partner_type, uploaded_date, uploaded_by, user_type
            ) VALUES (
                '{$data['Date']}', '{$data['Timestamp']}', '{$data['Control No.']}', '{$data['Reference No.']}', '{$data['Payor Name']}',
                '{$data['Address']}', '{$data['Account No.']}', '{$data['Account Name']}', '{$data['Amount Paid']}', '{$data['Charge to Customer']}',
                '{$data['Charge to Partner']}', '{$data['Contact No.']}', '{$data['Other Details']}', '{$data['ML Branch Outlet']}', '{$data['Region']}',
                '{$data['Operator']}', '{$data['Remote Branch']}', '{$data['Remote Operator']}', '{$data['partner_type']}', '{$data['uploaded_date']}', '{$data['uploaded_by']}'
            , '{$data['usertype']}')";

            if ($conn->query($sql) === TRUE) {
                $insertedCount++;
            } else {
                $errors[] = "Error: " . $conn->error;
            }
        }

        if ($insertedCount > 0) {
            $response = ['success' => $insertedCount > 0, 'message' => "$insertedCount record(s) inserted successfully."];
            echo json_encode($response);
            //$response['message'] = !empty($errors) ? implode("\n", $errors) : 'No records were inserted.';
            // echo json_encode(['success' => true, 'message' => "$insertedCount record(s) inserted successfully."]);
        }else{
            echo json_encode(['success' => false, 'message' => $errorMessage]);
            $conn->close();
            // $response['message'] = !empty($errors) ? implode("\n", $errors) : 'No records were inserted.';
            //$errorMessage = !empty($errors) ? implode("\n", $errors) : 'No records were inserted.';
        }
        exit();
    }
    
}

?>