<?php
    session_start();
    include '../../config/config.php';
    require '../../vendor/autoload.php';

    use PhpOffice\PhpSpreadsheet\IOFactory;
    use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

    if (isset($_POST['upload'])){
        if(isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['import_file']['tmp_name'];
            $file_name = $_FILES['import_file']['name'];
            $file_name_array = explode('.', $file_name);
            $extension = strtolower(end($file_name_array));

            $fileType = $_POST['fileType'] ?? '';
            $partner = $_POST['company'] ?? '';
            $selectedDate = date('Y-m-d');

            // Increase memory limit and execution time for large files
            ini_set('memory_limit', '100000M');
            ini_set('max_execution_time', 900); // 15 minutes

            if(is_readable($file)) {
                if($extension === 'xlsx' || $extension === 'xls') {
                    // Load the spreadsheet first
                    try {
                        $spreadsheet = IOFactory::load($file);
                    } catch (Exception $e) {
                        echo '<script>
                            Swal.fire({
                                icon: "error",
                                title: "File Loading Error",
                                text: "Error loading the Excel file: ' . $e->getMessage() . '",
                                confirmButtonText: "OK"
                            }).then(() => {
                                window.location.href = "../../admin/billspaymentImportFileCancellation.php";
                            });
                        </script>';
                        exit;
                    }
                }else{
                    echo '<script>
                                Swal.fire({
                                    icon: "error",
                                    title: "Invalid File Type",
                                    text: "Please upload a valid Excel file.",
                                    confirmButtonText: "OK"
                                }).then(() => {
                                    window.location.href = "billspaymentImportFileCancellation.php";
                                });
                            </script>';
                }
            }
        }
    }

?>