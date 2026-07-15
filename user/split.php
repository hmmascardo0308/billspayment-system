<?php
$conn = mysqli_connect('localhost', 'root', 'Password1', 'mldb');
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

function getDuplicateRows($conn)
{
    $query = "SELECT MIN(id) AS id, status, cancellation_date, date_time, control_number, reference_number, payor, address, account_number, account_name, 
              SUM(amount_paid) AS total_amount, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name, 
              partner_id, imported_date, imported_by
              FROM temp_billsPayment
              WHERE (payor, address, account_number, account_name, amount_paid, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name) IN (
                  SELECT payor, address, account_number, account_name, amount_paid, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name 
                  FROM temp_billsPayment 
                  WHERE other_details LIKE '%split%' -- Filter rows where other_details contain 'split'
                  GROUP BY payor, address, account_number, account_name, amount_paid, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name 
                  HAVING COUNT(*) > 1
              )
              GROUP BY status, cancellation_date, date_time, control_number, reference_number, payor, address, account_number, account_name, charge_to_partner, charge_to_customer, contact_number, other_details, ml_outlet, region, operator, partner_name, partner_id, imported_date, imported_by
              ORDER BY payor ASC, total_amount ASC"; // Use total_amount instead of amount_paid in the ORDER BY clause

    $result = $conn->query($query);
    if (!$result) {
        die("Error executing the query: " . $conn->error);
    }

    return $result;
}

// Function to export duplicate rows to CSV file// Function to export duplicate rows to CSV file// Function to export duplicate rows to CSV file
function exportDuplicatesToCSV($conn)
{
    $result = getDuplicateRows($conn);
    if ($result->num_rows > 0) {
       // Get the current date
       $currentDate = date("Y-m-d");

       // Create a filename with the current date
       $filename = "split_transactions_" . $currentDate . ".csv";

       // Set the appropriate headers for download
       header('Content-Type: application/csv');
       header('Content-Disposition: attachment; filename="' . $filename . '"');

       // Open output stream to php://output
       $output = fopen('php://output', 'w');
        $headers = array(
            'Status',
            'Cancellation_date',
            'Date/Time YYYY-MM-DD',
            'Control Number',
            'Reference Number',
            'Payor',
            'Address',
            'Account Number',
            'Account Name',
            'Amount Paid',
            'Charge to Partner',
            'Charge to Customer',
            'Contact Number',
            'Other Details',
            'ML Outlet',
            'Region',
            'Operator',
            'Partner Name',
            'Partner ID',
            'Imported Date',
            'Imported By'
        );

        fputcsv($output, $headers);

        while ($row = $result->fetch_assoc()) {
            // Exclude the 'id' column from the $row array before exporting
            unset($row['id']);
            fputcsv($output, $row);
        }

        // Close the output stream
        fclose($output);
    } else {
        echo "No duplicate transactions found.";
    }
}

// Export duplicate transactions to CSV file
if (isset($_POST['export_duplicates'])) {
    exportDuplicatesToCSV($conn);
    exit;

}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duplicate Data</title>
    <link rel="stylesheet" href="../css/dupMulSplit.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="btn-back">
        <a href="billsPayment.php" id="back">Back</a>
    </div>
 <!-- Export button -->
 <form method="post" class="export_button">
            <button type="submit" name="export_duplicates">Export Duplicate to CSV</button>
        </form>
    <div class="s-container">
       
        <!-- Display total number of rows above the table -->
        <?php
        // Get the total number of rows from table2
        $result = getDuplicateRows($conn);
        $total_rows = $result->num_rows;
        ?>
        <p style="font-size:14px;">Total Rows: <strong style="color:#d70c0c;"><?php echo number_format($total_rows); ?></strong></p>

        <!-- Display total amount for all duplicate payors -->
        <?php
        // Get duplicate rows
        $result = getDuplicateRows($conn);
        if ($result->num_rows > 0) {
            $total_duplicate_amount = 0; // Initialize the variable before calculating the total amount
            while ($row = $result->fetch_assoc()) {
                $total_duplicate_amount += $row['total_amount']; // Calculate the total amount for all duplicate payors
            }
            echo "<p style='font-size:14px;'>Total Amount for All Duplicate Payors: <strong style='color:#d70c0c;'>" . number_format($total_duplicate_amount, 2) . "</strong></p>";
        }
        ?>

        <table class="table2" id="tableID">
            <thead>
            <tr>
                <th>Status</th>
                <th>Cancellation Date</th>
                <th>Date/Time <br> YYYY-MM-DD</th>
                <th>Control Number</th>
                <th>Reference Number</th>
                <th>Payor</th>
                <th>Address</th>
                <th>Account Number</th>
                <th>Account Name</th>
                <th>Amount Paid</th>
                <th>Charge to Partner</th>
                <th>Charge to Customer</th>
                <th>Contact Number</th>
                <th>Other Details</th>
                <th>ML Outlet</th>
                <th>Region</th>
                <th>Operator</th>
                <th>Partner Name</th>
                <th>Partner ID</th>
                <th>Imported Date</th>
                <th>Imported By</th>
                </tr>
            </thead>
            <tbody>
                <?php
                // Get duplicate rows (moved this block here to avoid repetition)
                $result = getDuplicateRows($conn);
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                ?>
                        <tr>
                    <td style="text-align:left;"><?php echo $row['status']; ?></td>
                    <td style="text-align:left;"><?php echo $row['cancellation_date']; ?></td>
                    <td style="text-align:center;"><?php echo $row['date_time']; ?></td>
                    <td style="text-align:left;"><?php echo $row['control_number']; ?></td>
                    <td style="text-align:left;"><?php echo $row['reference_number']; ?></td>
                    <td style="text-align:left;"><?php echo $row['payor']; ?></td>
                    <td style="text-align:left;"><?php echo $row['address']; ?></td>
                    <td style="text-align:left;"><?php echo $row['account_number']; ?></td>
                    <td style="text-align:left;"><?php echo $row['account_name']; ?></td>
                    <td style="text-align:right;"><?php echo $row['total_amount']; ?></td>
                    <td style="text-align:right;"><?php echo $row['charge_to_partner']; ?></td>
                    <td style="text-align:right;"><?php echo $row['charge_to_customer']; ?></td>
                    <td style="text-align:left;"><?php echo $row['contact_number']; ?></td>
                    <td style="text-align:left;"><?php echo $row['other_details']; ?></td>
                    <td style="text-align:left;"><?php echo $row['ml_outlet']; ?></td>
                    <td style="text-align:left;"><?php echo $row['region']; ?></td>
                    <td style="text-align:left;"><?php echo $row['operator']; ?></td>
                    <td style="text-align:left;"><?php echo $row['partner_name']; ?></td>
                    <td style="text-align:center;"><?php echo $row['partner_id']; ?></td>
                    <td style="text-align:center;"><?php echo $row['imported_date']; ?></td>
                    <td style="text-align:center;"><?php echo $row['imported_by']; ?></td>
                </tr>
                <?php
                    }
                } else {
                    ?>
                    <tr><td colspan="22" id="no_data">No data(s) found...</td></tr>
                    <?php
                }
                ?>
            </tbody>
        </table>
    </div>
</body>

</html>
