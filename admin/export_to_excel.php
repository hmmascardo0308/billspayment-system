<?php
    session_start();
    require_once __DIR__ . '/../config/config.php';
    require '../vendor/autoload.php';

    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xls;
    use PhpOffice\PhpSpreadsheet\Cell\DataType;
    use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
    use PhpOffice\PhpSpreadsheet\Style\Alignment;
 
    function generateDownload($conn, $settlement_type, $bank, $from, $to, $cadNo, $rfpNo) {
        
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
                ORDER By pm.charge_to;";

        $dlresult = mysqli_query($conn, $dlsql);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet(); 

        if (mysqli_num_rows($dlresult) > 0) {

            $headerRow = mysqli_fetch_assoc($dlresult);
            $headers = [
                ['', 'REQUEST FOR PAYMENT FORM', '', '', '', '', '', '', ''],
                ['', 'M. LHUILLIER PHILIPPINES, INC.', '', '', '', '', '', '', 'DATE : ' . date('F d, Y')],
                ['', 'BILLS PAYMENT SETTLEMENT', '', '', '', '', '', '', 'CAD NO. : ' . $cadNo],
                ['', '', '', '', '', '', '', '', 'RFP NO. : ' . $rfpNo],
                ['', '', '', '', '', '', '', '', ''],
                ['', 'BANK NAME : ' . $bank . ' ' . $settlement_type, '', '', '', '', '', '', ''],
                ['', 'DATE OF TRANSACTION : ' . date('F d, Y', strtotime($headerRow['from_datetime'])) . ' to ' . date('F d, Y', strtotime($headerRow['to_datetime'])), '', '', '', '', '', '', ''],
                ['', 'MODE OF PAYMENT', '', '', '', '', '', '', ''],
                ['', '', '', '', '', '', '', '', ''],
                ['', 'LIST OF BILLS PAYMENT PARTNER', 'ACCOUNT NAME', 'ACCOUNT NUMBER', 'TXN COUNT', 'PRINCIPAL', 'CHARGE', 'ADJUSTMENT (add/less)', 'AMOUNT FOR SETTLEMENT']
            ];
        
            $sheet->fromArray($headers, null, 'A1');
            $sheet->mergeCells('B1:I1');
            $sheet->getStyle('B1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B1:I10')->getFont()->setBold(true);
        
            foreach (range('A', 'I') as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        
            $rowIndex = 11;
            $total_amount_for_settlement = 0;
            $previous_charge_to = null;
            // Reset result pointer to the first row
            mysqli_data_seek($dlresult, 0);
            while ($excelRow = mysqli_fetch_assoc($dlresult)) {

                // Display "Charge by" only if the charge type has changed
                if ($excelRow['charge_to'] !== $previous_charge_to) {
                    $sheet->setCellValue('B' . $rowIndex, "NOTE: CHARGE BY " . $excelRow['charge_to']);
                    $sheet->getStyle('B' . $rowIndex)->getFont()->setBold(true);
                    
                    $previous_charge_to = $excelRow['charge_to'];
                    $rowIndex++;
                }

                // Fill data in rows
                $sheet->setCellValue('B' . $rowIndex, $excelRow['partner_name']);
                $sheet->setCellValue('C' . $rowIndex, $excelRow['partner_accName']);
                $sheet->setCellValueExplicit('D' . $rowIndex, $excelRow['all_bank_accNumbers'], DataType::TYPE_STRING);
                $sheet->setCellValue('E' . $rowIndex, $excelRow['total_count']);
                $sheet->getStyle('E' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0'); 
                $sheet->setCellValue('F' . $rowIndex, $excelRow['principal_amount']);
                $sheet->getStyle('F' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00'); 
                $sheet->setCellValue('G' . $rowIndex, $excelRow['total_charge_to_customer']);
                $sheet->getStyle('G' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00'); 
                $sheet->setCellValue('H' . $rowIndex, $excelRow['total_charge_to_partner']);
                $sheet->getStyle('H' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00'); 
            
                $settlement_amount = ($excelRow['charge_to'] === 'PARTNER' && $excelRow['serviceCharge'] === 'DAILY') ? 
                    ($excelRow['principal_amount'] - $excelRow['total_charge_to_partner']) : 
                    $excelRow['principal_amount'];
            
                $sheet->setCellValue('I' . $rowIndex, $settlement_amount);
                $sheet->getStyle('I' . $rowIndex)->getNumberFormat()->setFormatCode('#,##0.00');

            
                $total_amount_for_settlement += $settlement_amount;
            
                $rowIndex++;
            }
            
            $sheet->mergeCells('F' . $rowIndex . ':H' . $rowIndex);
            $sheet->setCellValue('F' . $rowIndex, 'TOTAL : PHP');
            $sheet->getStyle('F' . $rowIndex)->getFont()->setBold(true);
            $sheet->getStyle('F' . $rowIndex)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $sheet->setCellValue('I' . $rowIndex, number_format($total_amount_for_settlement, 2));
            $sheet->getStyle('I' . $rowIndex)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
            $sheet->getStyle('I' . $rowIndex)->getFont()->setBold(true);
            $sheet->getStyle('I' . $rowIndex)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        
            $sheet->setCellValue('B' . ($rowIndex + 3), 'Prepared by :');
            $sheet->setCellValue('B' . ($rowIndex + 5), $_SESSION['admin_name']);
            $sheet->getStyle('B' . ($rowIndex + 5))->getFont()->setBold(true);
            $sheet->setCellValue('B' . ($rowIndex + 6), 'Accounting Staff');
            $sheet->setCellValue('B' . ($rowIndex + 8), 'Reviewed by :');
            $sheet->setCellValue('B' . ($rowIndex + 9), 'CHERRY ROSE CULPA');
            $sheet->getStyle('B' . ($rowIndex + 9))->getFont()->setBold(true);
            $sheet->setCellValue('B' . ($rowIndex + 10), 'Department Manager');
        
            $sheet->setCellValue('D' . ($rowIndex + 3), 'Checked by :');
            $sheet->setCellValue('D' . ($rowIndex + 5), 'ELVIE CILLO');
            $sheet->getStyle('D' . ($rowIndex + 5))->getFont()->setBold(true);
            $sheet->setCellValue('D' . ($rowIndex + 6), 'Accounting Staff');
            $sheet->setCellValue('D' . ($rowIndex + 8), 'Noted by :');
            $sheet->setCellValue('D' . ($rowIndex + 9), 'LUELLA PERALTA');
            $sheet->getStyle('D' . ($rowIndex + 9))->getFont()->setBold(true);
            $sheet->setCellValue('D' . ($rowIndex + 10), 'Division Manager');
        }

        // Prepare the writer for download
        $writer = new Xls($spreadsheet);

        // Set the headers for file download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="BillspaymentReport ' . $from . ' to ' .$to. ' .xls"');
        header('Cache-Control: max-age=0');

        // Write file to output
        $writer->save('php://output');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        
        $settlementType = $_POST['settlement_type'];
        $bank = $_POST['bank'];
        $cadNo = $_POST['cad_no'];
        $rfpNo = $_POST['rfp_no'];
        $from = $_POST['from'];
        $to = $_POST['to'];

        generateDownload($conn, $settlementType, $bank, $from, $to, $cadNo, $rfpNo);
    }

?>