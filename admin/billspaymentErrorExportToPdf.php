<?php
require '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $messages = json_decode($_POST['messages'], true);
    $date = $_POST['date'];
    $filename = $_POST['filename'];
    $title = isset($_POST['title']) ? $_POST['title'] : 'Existing Records Report';

    // Initialize Dompdf
    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);

    $html = '<style>
                body { font-family: Arial, sans-serif; }
                h3, p { text-align: center; margin: 5px 0; }
                table { width: 100%; border-collapse: collapse; margin-top: 15px; }
                th, td { border: 1px solid black; padding: 5px; text-align: left; }
                th { background-color: #f2f2f2; }
             </style>';

    // Match the print layout with centered title and information
    $html .= '<h3>' . $title . '</h3>';
    $html .= '<p>Date: ' . $date . '</p>';
    $html .= '<p>Filename: ' . $filename . '</p>';
    
    $html .= '<table>';
    $html .= '<thead><tr><th>#</th><th>Date & Time</th></tr></thead>';
    $html .= '<tbody>';

    // Process all messages, not just error ones
    $counter = 1;
    foreach ($messages as $msg) {
        if (isset($msg['id']) && isset($msg['datetime'])) {
            // Format from the data structure sent by exportToPDF function
            $html .= "<tr>
                        <td>{$msg['id']}</td>
                        <td>{$msg['datetime']}</td>
                      </tr>";
        } else if (isset($msg['C'])) {
            // Handle the original format if present
            $html .= "<tr>
                        <td>{$counter}</td>
                        <td>{$msg['C']}</td>
                      </tr>";
            $counter++;
        }
    }
    
    $html .= '</tbody></table>';

    $dompdf->loadHtml($html);

    // Use portrait for this report as it has fewer columns
    $dompdf->setPaper('A4', 'portrait');

    // Render the HTML as PDF
    $dompdf->render();

    $dompdf->stream('existing_records_report_' . date('Y-m-d') . '.pdf', array("Attachment" => 1));
}
?>