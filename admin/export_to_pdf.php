<?php
    session_start();
    require_once '../vendor/autoload.php';
    require_once __DIR__ . '/../config/config.php';

    // use TCPDF;

    function generatePDF($conn, $settlement_type, $bank, $from, $to, $cadNo, $rfpNo) {
        // require_once('../vendor/technickcom/tcpdf/tcpdf.php'); // Make sure to include the TCPDF library
        
        $pdf = new TCPDF();
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetTitle('Billspayment Report');
        
        // Set header and footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
    
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9);
    
        // Title and headers
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'REQUEST FOR PAYMENT FORM', 0, 1, 'C');
        $pdf->Cell(120, 5, 'M. LHUILLIER PHILIPPINES, INC.', 0, 0, 'L'); 
        $pdf->Cell(0, 5, 'DATE: ' . date('F d, Y'), 0, 1, 'R');          
        $pdf->Cell(120, 5, 'BILLS PAYMENT SETTLEMENT', 0, 0, 'L'); 
        $pdf->Cell(0, 5, 'CAD NO.: ' . $cadNo, 0, 1, 'R'); 
        $pdf->Cell(0, 5, 'RFP NO.: ' . $rfpNo, 0, 1, 'R');

        
        // Date and Bank information
        $pdf->Cell(0, 5, 'BANK NAME : ' . $bank . ' ' . $settlement_type, 0, 1);
        $pdf->Cell(0, 5, 'DATE OF TRANSACTION : ' . date('F d, Y', strtotime($from)) . ' to ' . date('F d, Y', strtotime($to)), 0, 1);
        $pdf->Cell(0, 5, 'MODE OF PAYMENT', 0, 1);

        // Table Header
        $pdf->Ln(7);
        $pdf->SetFont('helvetica', 'B', 9);
        $headers = [
            'LIST OF BILLS PAYMENT PARTNER' => 60, 
            'ACCOUNT NAME' => 50,                  
            'ACCOUNT NUMBER' => 35,                
            'AMOUNT FOR SETTLEMENT' => 45          
        ];

        foreach ($headers as $header => $width) {
            $pdf->Cell($width, 10, $header, 1);
        }
        $pdf->Ln();
    
        $dlsql = "SELECT bt.cad_no, bt.rfp_no, MIN(bt.datetime) as from_datetime, MAX(bt.datetime) as to_datetime, pm.partner_name, pm.transaction_range, pm.charge_to, pm.serviceCharge, pm.partner_accName, COUNT(bt.id) AS total_count, SUM(bt.amount_paid) AS principal_amount, SUM(bt.charge_to_customer) AS total_charge_to_customer, 
                SUM(bt.charge_to_partner) AS total_charge_to_partner, GROUP_CONCAT(DISTINCT pb.bank_accNumber ORDER BY pb.id SEPARATOR ', ') AS all_bank_accNumbers, 
                GROUP_CONCAT(DISTINCT ct.charge_amount ORDER BY ct.id SEPARATOR ', ') AS all_charge_amount 
                FROM mldb.billspayment_transaction bt 
                INNER JOIN mldb.partner_masterfile pm ON bt.partner_id = pm.partner_id 
                INNER JOIN mldb.partner_bank pb ON pb.partner_id = pm.partner_id 
                INNER JOIN mldb.charge_table ct ON ct.partner_id = pm.partner_id
                WHERE pm.settled_online_check = '$settlement_type' 
                AND pb.bank = '$bank'
                AND bt.cad_no = '$cadNo'
                AND bt.rfp_no = '$rfpNo'
                GROUP BY bt.cad_no, bt.rfp_no, pm.partner_name, pm.transaction_range, pm.charge_to, pm.serviceCharge, pm.partner_accName
                ORDER By pm.charge_to";
        $result = mysqli_query($conn, $dlsql);
        $total_amount_for_settlement = 0;
        $previous_charge_to = null;
    
        while ($row = mysqli_fetch_assoc($result)) {
            if ($row['charge_to'] !== $previous_charge_to) {
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->MultiCell(0, 10, "NOTE: CHARGE BY " . $row['charge_to'], 1, 'L', 0, 1);
                $previous_charge_to = $row['charge_to'];
            }
        
            $settlement_amount = ($row['charge_to'] === 'PARTNER' && $row['serviceCharge'] === 'DAILY') ? 
                ($row['principal_amount'] - $row['total_charge_to_partner']) : $row['principal_amount'];
        
            $total_amount_for_settlement += $settlement_amount;
        
            $pdf->SetFont('helvetica', '', 9);
        
            $row_height = 8;
            $pdf->SetFont('helvetica', '', 8); 
        
            // Render each MultiCell in this row with consistent height
            $pdf->MultiCell(60, $row_height, $row['partner_name'], 1, 'L', 0, 0);
            $pdf->MultiCell(50, $row_height, $row['partner_accName'], 1, 'L', 0, 0);
            $pdf->MultiCell(35, $row_height, $row['all_bank_accNumbers'], 1, 'L', 0, 0);
            $pdf->MultiCell(45, $row_height, number_format($settlement_amount, 2), 1, 'R', 0, 1);
        }
        
    
        // Total row
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell(0, 10, 'TOTAL : PHP ' . number_format($total_amount_for_settlement, 2), 0, 1, 'R');
        
        // Signature section
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', '', 9);

        // Prepared by and Checked by
        $pdf->Cell(120, 5, 'Prepared by :', 0, 0, 'L');
        $pdf->Cell(0, 5, 'Checked by :', 0, 1, 'R'); // Use 1 at the end to move to the next line
        $pdf->Cell(120, 5, $_SESSION['admin_name'], 0, 0, 'L');
        $pdf->Cell(0, 5, 'ELVIE CILLO', 0, 1, 'R');
        $pdf->Cell(120, 5, 'Accounting Staff', 0, 0, 'L');
        $pdf->Cell(0, 5, 'Accounting Staff', 0, 1, 'R');

        // Add a new line between sections
        $pdf->Ln(10);

        // Reviewed by and Noted by
        $pdf->Cell(120, 5, 'Reviewed by :', 0, 0, 'L');
        $pdf->Cell(0, 5, 'Noted by :', 0, 1, 'R');
        $pdf->Cell(120, 5, 'CHERRY ROSE CULPA', 0, 0, 'L');
        $pdf->Cell(0, 5, 'LUELLA PERALTA', 0, 1, 'R');
        $pdf->Cell(120, 5, 'Department Manager', 0, 0, 'L');
        $pdf->Cell(0, 5, 'Division Manager', 0, 1, 'R');
        
        // Finalize the PDF document
        $pdf->Output('BillspaymentReport_' . $from . '_to_' .$to. '.pdf', 'D');
    }    

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $settlementType = $_POST['settlement_type'];
        $bank = $_POST['bank'];
        $fromDate = $_POST['from_date'];
        $toDate = $_POST['to_date'];
        $cadNo = $_POST['cad_no'];
        $rfpNo = $_POST['rfp_no'];

        generatePDF($conn, $settlementType, $bank, $fromDate, $toDate, $cadNo, $rfpNo);
    }

?>