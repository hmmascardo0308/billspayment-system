/**
 * Reference Number Error Handling Functions
 * Contains the print and export functionality for duplicate reference number error reports
 */

// Global variables
let filenamePHP = '';
let sourceFileTypePHP = '';
let transactionDatePHP = '';
let currentDatePHP = '';

// Initialize the data from PHP when document is ready
document.addEventListener('DOMContentLoaded', function() {
    // These values are set inline in the HTML by PHP
    filenamePHP = document.getElementById('original_filename') ? 
        document.getElementById('original_filename').value : 'reference_number_errors';
    sourceFileTypePHP = document.getElementById('file_type') ? 
        document.getElementById('file_type').value : 'Unknown';
    transactionDatePHP = document.getElementById('date_picker') ? 
        document.getElementById('date_picker').value : new Date().toISOString().split('T')[0];
    currentDatePHP = document.getElementById('current_date') ? 
        document.getElementById('current_date').value : new Date().toLocaleString();
});

/**
 * Format date string to "Month-Day-Year" format
 * @param {string} dateString - Date string to format
 * @return {string} Formatted date string
 */
function formatDateToWords(dateString) {
    // Handle empty or invalid dates
    if (!dateString) return '';
    
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString; // Return original if invalid
    
    // Array of month names
    const months = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];
    
    // Format as Month-Day-Year
    const month = months[date.getMonth()];
    const day = date.getDate();
    const year = date.getFullYear();
    
    return `${month} ${day}, ${year}`;
}

/**
 * Print reference number error report
 */
function printReport() {
    try {
        // Create a new window with custom HTML content for printing
        var printWindow = window.open('', '_blank', 'width=800,height=600');
        
        // Get values directly from the hidden input fields
        var filename = document.getElementById('original_filename').value;
        var sourceFileType = document.getElementById('file_type').value;
        var transactionDate = document.getElementById('date_picker').value;
        var currentDate = document.getElementById('current_date').value;
        
        // Format dates to Month-Day-Year format
        var formattedTransactionDate = formatDateToWords(transactionDate);
        var formattedCurrentDate = formatDateToWords(currentDate);
        
        // Prepare the data from the table
        var tableData = getTableData();
        
        // Build the HTML content for the print window
        var htmlContent = '<!DOCTYPE html>' +
            '<html>' +
            '<head>' +
            '    <title>Duplicate Reference Numbers Report</title>' +
            '    <style>' +
            '        body { font-family: Arial, Helvetica, sans-serif; margin: 20px; color: #333; }' +
            '        h1 { text-align: center; color: #d33; margin-bottom: 10px; }' +
            '        .metadata { text-align: center; margin-bottom: 30px; line-height: 1.6; }' +
            '        .table-container { margin: 20px 0; }' +
            '        table { width: 100%; border-collapse: collapse; }' +
            '        th { background-color: #2c3e50; color: white; padding: 8px; text-align: center; border: 1px solid #ddd; }' +
            '        td { padding: 8px; border: 1px solid #ddd; }' +
            '        tr:nth-child(even) { background-color: #f5f5f5; }' +
            '        .message { margin: 20px 0; text-align: justify; font-weight: bold; }' +
            '        .footer { margin-top: 30px; text-align: center; font-style: italic; font-size: 12px; line-height: 1.5; }' +
            '        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }' +
            '    </style>' +
            '</head>' +
            '<body>' +
            '    <h1>Duplicate Reference Numbers Report</h1>' +
            '    <div class="metadata">' +
            '        <p><strong>File:</strong> ' + filename + '</p>' +
            '        <p><strong>Source File Type:</strong> ' + sourceFileType + '</p>' +
            '        <p><strong>Transaction Date:</strong> ' + formattedTransactionDate + '</p>' +
            '        <p><strong>Date:</strong> ' + formattedCurrentDate + '</p>' +
            '    </div>' +
            '    <div class="message">' +
            '        The following reference numbers appear more than once in the file:' +
            '    </div>' +
            '    <div class="table-container">' +
            '        <table>' +
            '            <thead>' +
            '                <tr>' +
            '                    <th style="width: 8%;">No.</th>' +
            '                    <th style="width: 20%;">Reference Number</th>' +
            '                    <th style="width: 37%;">Payor</th>' +
            '                    <th style="width: 20%;">Amount</th>' +
            '                    <th style="width: 15%;">Row in Excel</th>' +
            '                </tr>' +
            '            </thead>' +
            '            <tbody>';
        
        // Add table rows
        for (var i = 0; i < tableData.length; i++) {
            var item = tableData[i];
            htmlContent += 
            '                <tr>' +
            '                    <td style="text-align: center;">' + item.num + '</td>' +
            '                    <td>' + item.reference_no + '</td>' +
            '                    <td>' + item.payor + '</td>' +
            '                    <td style="text-align: right;">' + item.amount + '</td>' +
            '                    <td style="text-align: center;">' + item.row + '</td>' +
            '                </tr>';
        }
        
        // Complete the HTML
        htmlContent +=
            '            </tbody>' +
            '        </table>' +
            '    </div>' +
            '    <div class="footer">' +
            '        <p>Please remove duplicate reference numbers and try again.</p>' +
            '        <p>Duplicate references can cause transaction processing issues.</p>' +
            '    </div>' +
            '    <script>' +
            '        window.onload = function() { window.print(); };' +
            '    </script>' +
            '</body>' +
            '</html>';
        
        // Write to the new window and close document
        printWindow.document.open();
        printWindow.document.write(htmlContent);
        printWindow.document.close();
    } catch (error) {
        console.error("Print error:", error);
        alert("Error generating print preview: " + error.message);
    }
}

/**
 * Export reference number errors to PDF
 */
function exportToPDF() {
    try {
        // Create the form for submission
        var form = document.createElement("form");
        form.method = "post";
        form.action = "referenceNumberErrorExportToPdf.php";
        
        // Collect data from the table
        var tableData = getTableData();
        
        if (tableData.length === 0) {
            throw new Error("No data found in the table");
        }
        
        // Create and append the errors input
        var errorsInput = document.createElement("input");
        errorsInput.type = "hidden";
        errorsInput.name = "errors";
        errorsInput.value = JSON.stringify(tableData);
        
        // Create and append the filename input
        var filenameInput = document.createElement("input");
        filenameInput.type = "hidden";
        filenameInput.name = "filename";
        filenameInput.value = document.getElementById('original_filename').value;
        
        // Append inputs to the form
        form.appendChild(errorsInput);
        form.appendChild(filenameInput);
        
        // Append form to the document body and submit
        document.body.appendChild(form);
        form.submit();
        
        // Remove the form after submission
        setTimeout(function() {
            document.body.removeChild(form);
        }, 100);
        
    } catch (error) {
        console.error("Error exporting to PDF:", error);
        alert("Error exporting to PDF: " + error.message);
    }
}

/**
 * Helper function to get table data
 */
function getTableData() {
    var tableData = [];
    var rows = document.querySelectorAll('.table tbody tr');
    
    if (!rows || rows.length === 0) {
        console.warn("No rows found in the table");
        return tableData;
    }
    
    for (var i = 0; i < rows.length; i++) {
        var cells = rows[i].querySelectorAll('td');
        if (cells.length >= 5) {
            tableData.push({
                num: cells[0].textContent.trim(),
                reference_no: cells[1].textContent.trim(),
                payor: cells[2].textContent.trim(),
                amount: cells[3].textContent.trim(),
                row: cells[4].textContent.trim()
            });
        }
    }
    
    return tableData;
}
