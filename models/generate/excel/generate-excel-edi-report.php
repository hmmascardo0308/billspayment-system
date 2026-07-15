<?php
require_once __DIR__ . '/../../../config/config.php';
require '../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

session_start();

if (!isset($_SESSION['user_type'])) {
    http_response_code(403);
    exit('Unauthorized access');
}

if (!$conn) {
    http_response_code(500);
    exit('Database connection failed');
}

function readInput(string $key, $default = '') {
    if (isset($_POST[$key])) {
        return $_POST[$key];
    }

    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    return $default;
}

function buildTimeFrameSegment(string $filterType, string $startDate, string $endDate): string {
    switch ($filterType) {
        case 'per_day':
            return $startDate;
        case 'date_range':
            return $startDate . '_to_' . $endDate;
        case 'per_month':
            return $startDate;
        case 'month_range':
            return $startDate . '_to_' . $endDate;
        case 'per_year':
            return $startDate;
        case 'year_range':
            return $startDate . '_to_' . $endDate;
        default:
            return 'CUSTOM';
    }
}

function sanitizeFilePart(string $value): string {
    $value = preg_replace('/[^A-Za-z0-9_\-]/', '-', $value);
    $value = preg_replace('/-+/', '-', $value);
    return trim($value, '-');
}

function sanitizeSheetTitle(string $value): string {
    $value = trim($value);
    if ($value === '') {
        $value = 'NO_ZONE';
    }

    $value = str_replace(['\\', '/', '?', '*', ':', '[', ']'], '-', $value);
    if (mb_strlen($value) > 31) {
        $value = mb_substr($value, 0, 31);
    }

    return $value;
}

$partner_name_raw = readInput('partner_name', 'All');
$mainzone = strtoupper(trim(readInput('mainzone', 'ALL')));
$zoneInput = strtoupper(trim(readInput('zone', 'ALL')));
$zone = ($zoneInput === 'SHOWROOM') ? 'Showroom' : $zoneInput;
$region = strtoupper(trim(readInput('region', 'ALL')));
$area = trim(readInput('area', 'ALL'));
$filterType = readInput('filterType', '');
$startDate = readInput('startDate', '');
$endDate = readInput('endDate', '');

if ($filterType === '' || $startDate === '') {
    http_response_code(400);
    exit('Missing required filter parameters');
}

if (in_array($filterType, ['date_range', 'month_range', 'year_range'], true) && $endDate === '') {
    http_response_code(400);
    exit('End date is required for selected filter type');
}

if (!in_array($filterType, ['date_range', 'month_range', 'year_range'], true)) {
    $endDate = $startDate;
}

$dateCondition = '';
$params = [];
$types = '';

if ($filterType === 'date_range') {
    $dateCondition = "(
        DATE(bt.datetime) BETWEEN ? AND ?
        OR
        DATE(bt.cancellation_date) BETWEEN ? AND ?
        OR
        DATE(bt.report_date) BETWEEN ? AND ?
    )";
    $params = array_merge($params, [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
    $types .= 'ssssss';
} elseif ($filterType === 'month_range') {
    $dateCondition = "(
        (YEAR(bt.datetime) >= YEAR(?) AND MONTH(bt.datetime) >= MONTH(?)) AND
        (YEAR(bt.datetime) <= YEAR(?) AND MONTH(bt.datetime) <= MONTH(?))
        OR
        (YEAR(bt.cancellation_date) >= YEAR(?) AND MONTH(bt.cancellation_date) >= MONTH(?)) AND
        (YEAR(bt.cancellation_date) <= YEAR(?) AND MONTH(bt.cancellation_date) <= MONTH(?))
        OR
        (YEAR(bt.report_date) >= YEAR(?) AND MONTH(bt.report_date) >= MONTH(?)) AND
        (YEAR(bt.report_date) <= YEAR(?) AND MONTH(bt.report_date) <= MONTH(?))
    )";
    $params = array_merge($params, [
        $startDate . '-01', $startDate . '-01', $endDate . '-01', $endDate . '-01',
        $startDate . '-01', $startDate . '-01', $endDate . '-01', $endDate . '-01',
        $startDate . '-01', $startDate . '-01', $endDate . '-01', $endDate . '-01'
    ]);
    $types .= 'ssssssssssss';
} elseif ($filterType === 'year_range') {
    $dateCondition = "(
        YEAR(bt.datetime) BETWEEN ? AND ?
        OR
        YEAR(bt.cancellation_date) BETWEEN ? AND ?
        OR
        YEAR(bt.report_date) BETWEEN ? AND ?
    )";
    $params = array_merge($params, [$startDate, $endDate, $startDate, $endDate, $startDate, $endDate]);
    $types .= 'ssssss';
} elseif ($filterType === 'per_day') {
    $dateCondition = "(
        DATE(bt.datetime) = ?
        OR
        DATE(bt.cancellation_date) = ?
        OR
        DATE(bt.report_date) = ?
    )";
    $params = array_merge($params, [$startDate, $startDate, $startDate]);
    $types .= 'sss';
} elseif ($filterType === 'per_month') {
    $dateCondition = "(
        (YEAR(bt.datetime) = YEAR(?) AND MONTH(bt.datetime) = MONTH(?))
        OR
        (YEAR(bt.cancellation_date) = YEAR(?) AND MONTH(bt.cancellation_date) = MONTH(?))
        OR
        (YEAR(bt.report_date) = YEAR(?) AND MONTH(bt.report_date) = MONTH(?))
    )";
    $params = array_merge($params, [
        $startDate . '-01', $startDate . '-01',
        $startDate . '-01', $startDate . '-01',
        $startDate . '-01', $startDate . '-01'
    ]);
    $types .= 'ssssss';
} elseif ($filterType === 'per_year') {
    $dateCondition = "(
        YEAR(bt.datetime) = ?
        OR
        YEAR(bt.cancellation_date) = ?
        OR
        YEAR(bt.report_date) = ?
    )";
    $params = array_merge($params, [$startDate, $startDate, $startDate]);
    $types .= 'sss';
} else {
    http_response_code(400);
    exit('Invalid filter type');
}

$getDataSql = "WITH all_branches AS (
                    SELECT
                        mbp.zone,
                        mbp.ml_matic_region,
                        mbp.region,
                        mbp.region_code,
                            mbp.area,
                            mbp.kp_code,
                            mbp.branch_id
                    FROM masterdata.branch_profile AS mbp
                ),
                all_branch_transactions AS (
                    SELECT
                        bt.branch_id,
                        bt.partner_name,
                        SUM(bt.charge_to_customer + bt.charge_to_partner) AS charges
                    FROM mldb.billspayment_transaction AS bt
                    WHERE
                        " . $dateCondition . "
                        AND bt.branch_id NOT IN ('1', '2', '4937', '4938', '4962', '4987', '4993', '4944')
                        AND bt.outlet NOT IN ('ML CEBU HEAD OFFICE', 'ML HEAD OFFICE', 'CEBU HEAD OFFICE', 'HEAD OFFICE')
                        AND NOT REGEXP_LIKE(bt.payor, '\\bTEST\\b')
                    GROUP BY bt.branch_id, bt.partner_name
                )
                SELECT
                    MAX(ab.ml_matic_region) AS ml_matic_region,
                    MAX(ab.zone) AS zone,
                    MAX(ab.region) AS region,
                    MAX(ab.region_code) AS region_code,
                    MAX(ab.kp_code) AS kp_code,
                    SUM(abt.charges) AS total_charges,
                    abt.branch_id
                FROM all_branch_transactions AS abt
                LEFT JOIN all_branches AS ab ON abt.branch_id = ab.branch_id
                WHERE 1=1";

if ($partner_name_raw !== 'All') {
    $getDataSql .= ' AND abt.partner_name = ?';
    $params[] = $partner_name_raw;
    $types .= 's';
}

$geoConditions = [];
$geoParams = [];

if ($mainzone !== 'ALL') {
    if ($zone !== 'ALL') {
        if ($zone !== 'Showroom') {
            if ($region !== 'ALL') {
                if ($region === 'LZN' || $region === 'NCR') {
                    $geoConditions[] = 'ab.ml_matic_region = ? AND ab.zone = ?';
                    $geoParams[] = 'LNCR ' . $zone;
                    $geoParams[] = $region;
                } elseif ($region === 'VIS' || $region === 'MIN') {
                    $geoConditions[] = 'ab.ml_matic_region = ? AND ab.zone = ?';
                    $geoParams[] = 'VISMIN ' . $zone;
                    $geoParams[] = $region;
                } else {
                    $geoConditions[] = 'ab.region_code = ?';
                    $geoParams[] = $region;
                }

                if ($area !== 'ALL') {
                    $geoConditions[] = 'ab.area = ?';
                    $geoParams[] = $area;
                }
            } else {
                if ($mainzone === 'LNCR') {
                    $geoConditions[] = 'ab.zone = ? AND ab.ml_matic_region <> ?';
                    $geoParams[] = $zone;
                    $geoParams[] = $mainzone . ' Showroom';
                } elseif($mainzone === 'VISMIN') {
                        $geoConditions[] = 'ab.zone = ? AND ab.ml_matic_region <> ?';
                        $geoParams[] = $zone;
                        $geoParams[] = $mainzone . ' Showroom';
                } else {
                    $geoConditions[] = 'ab.ml_matic_region = ?';
                    $geoParams[] = $mainzone . ' ' . $zone;
                }
            }
        } else {
            if ($region !== 'ALL') {
                if ($region === 'LZN' || $region === 'NCR') {
                    $geoConditions[] = 'ab.ml_matic_region = ? AND ab.zone = ?';
                    $geoParams[] = 'LNCR Showroom';
                    $geoParams[] = $region;
                } elseif ($region === 'VIS' || $region === 'MIN') {
                    $geoConditions[] = 'ab.ml_matic_region = ? AND ab.zone = ?';
                    $geoParams[] = 'VISMIN Showroom';
                    $geoParams[] = $region;
                } else {
                    $geoConditions[] = 'ab.ml_matic_region = ?';
                    $geoParams[] = $mainzone . ' Showroom';
                }

                if ($area !== 'ALL') {
                    $geoConditions[] = 'ab.area = ?';
                    $geoParams[] = $area;
                }
            } else {
                if ($mainzone === 'LNCR') {
                    $geoConditions[] = 'ab.ml_matic_region = ?';
                    $geoParams[] = $mainzone . ' Showroom';
                } elseif ($mainzone === 'VISMIN') {
                    $geoConditions[] = 'ab.ml_matic_region = ?';
                    $geoParams[] = $mainzone . ' Showroom';
                } else {
                    $geoConditions[] = 'ab.ml_matic_region = ?';
                    $geoParams[] = $mainzone . ' Showroom';
                }
            }
        }
    } else {
        if ($region !== 'ALL') {
            if ($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                $geoConditions[] = 'ab.zone = ?';
                $geoParams[] = $region;
            } else {
                $geoConditions[] = 'ab.region_code = ?';
                $geoParams[] = $region;
            }

            if ($area !== 'ALL') {
                $geoConditions[] = 'ab.area = ?';
                $geoParams[] = $area;
            }
        } else {
            if ($mainzone === 'LNCR') {
                $geoConditions[] = "(ab.zone IN ('LZN', 'NCR') OR ab.ml_matic_region = ?)";
                $geoParams[] = $mainzone . ' Showroom';
            } elseif ($mainzone === 'VISMIN') {
                $geoConditions[] = "(ab.zone IN ('VIS', 'MIN') OR ab.ml_matic_region = ?)";
                $geoParams[] = $mainzone . ' Showroom';
            } else {
                $geoConditions[] = 'ab.ml_matic_region LIKE ?';
                $geoParams[] = $mainzone . '%';
            }
        }
    }
} else {
    if ($zone !== 'ALL') {
        if ($zone !== 'Showroom') {
            if ($region !== 'ALL') {
                if ($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                    $geoConditions[] = 'ab.zone = ?';
                    $geoParams[] = $region;
                } else {
                    $geoConditions[] = 'ab.region_code = ?';
                    $geoParams[] = $region;
                }

                if ($area !== 'ALL') {
                    $geoConditions[] = 'ab.area = ?';
                    $geoParams[] = $area;
                }
            } else {
                $geoConditions[] = 'ab.zone = ?';
                $geoParams[] = $zone;
            }
        } else {
            if ($region !== 'ALL') {
                if ($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                    $geoConditions[] = 'ab.ml_matic_region LIKE ? AND ab.zone = ?';
                    $geoParams[] = '% Showroom';
                    $geoParams[] = $region;
                }

                if ($area !== 'ALL') {
                    $geoConditions[] = 'ab.area = ?';
                    $geoParams[] = $area;
                }
            } else {
                $geoConditions[] = 'ab.ml_matic_region LIKE ?';
                $geoParams[] = '% Showroom';
            }
        }
    } else {
        if ($region !== 'ALL') {
            if ($region === 'LZN' || $region === 'NCR' || $region === 'VIS' || $region === 'MIN') {
                $geoConditions[] = 'ab.zone = ?';
                $geoParams[] = $region;
            } else {
                $geoConditions[] = 'ab.region_code = ?';
                $geoParams[] = $region;
            }

            if ($area !== 'ALL') {
                $geoConditions[] = 'ab.area = ?';
                $geoParams[] = $area;
            }
        }
    }
}

if (!empty($geoConditions)) {
    $getDataSql .= ' AND (' . implode(' AND ', $geoConditions) . ')';
}

$getDataSql .= ' GROUP BY abt.branch_id ORDER BY MAX(ab.zone), MAX(ab.region), MAX(ab.kp_code)';

if (!empty($geoParams)) {
    $params = array_merge($params, $geoParams);
    $types .= str_repeat('s', count($geoParams));
}

$stmt = $conn->prepare($getDataSql);
if (!$stmt) {
    http_response_code(500);
    exit('Failed to prepare query');
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$stmt->close();

if (count($rows) === 0) {
    http_response_code(204);
    exit('No data found for the selected filters');
}

$groupedByZone = [];

foreach ($rows as $row) {
    $mlMaticRegion = $row['ml_matic_region'] ?? '';
    $zoneLabel = ($mlMaticRegion === 'VISMIN Showroom' || $mlMaticRegion === 'LNCR Showroom')
        ? $mlMaticRegion
        : ($row['zone'] ?? '-');

    if ($zoneLabel === '') {
        $zoneLabel = '-';
    }

    $groupedByZone[$zoneLabel][] = $row;
}

$spreadsheet = new Spreadsheet();
$sheetIndex = 0;

foreach ($groupedByZone as $zoneLabel => $zoneRows) {
    if ($sheetIndex === 0) {
        $sheet = $spreadsheet->getActiveSheet();
    } else {
        $sheet = $spreadsheet->createSheet();
    }

    $sheet->setTitle(sanitizeSheetTitle($zoneLabel));

    $sheet->setCellValue('A2', 'ZONE');
    $sheet->setCellValue('B2', 'REGION');
    $sheet->setCellValue('C2', 'KPCODE');
    $sheet->setCellValue('D2', 'CHARGE');

    $sheet->getStyle('A2:D2')->applyFromArray([
        'font' => ['bold' => true],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER
        ],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ]
    ]);

    $currentRow = 3;
    $sheetTotal = 0;

    foreach ($zoneRows as $rowData) {
        $mlMaticRegion = $rowData['ml_matic_region'] ?? '';
        $zoneValue = ($mlMaticRegion === 'VISMIN Showroom' || $mlMaticRegion === 'LNCR Showroom')
            ? $mlMaticRegion
            : ($rowData['zone'] ?? '-');

        $regionValue = ($mlMaticRegion === 'VISMIN Showroom' || $mlMaticRegion === 'LNCR Showroom')
            ? $mlMaticRegion
            : ($rowData['region'] ?? '-');

        $kpCodeValue = $rowData['kp_code'] ?? '-';
        $chargeValue = (float)($rowData['total_charges'] ?? 0);

        $sheet->setCellValue('A' . $currentRow, $zoneValue);
        $sheet->setCellValue('B' . $currentRow, $regionValue);
        $sheet->setCellValue('C' . $currentRow, $kpCodeValue);
        $sheet->setCellValue('D' . $currentRow, $chargeValue);

        $sheetTotal += $chargeValue;
        $currentRow++;
    }

    $totalRow = $currentRow+1;
    $sheet->mergeCells('A' . $totalRow . ':C' . $totalRow);
    $sheet->setCellValue('A' . $totalRow, 'Total:');
    $sheet->setCellValue('D' . $totalRow, $sheetTotal);

    $sheet->getStyle('A' . $totalRow . ':D' . $totalRow)->applyFromArray([
        'font' => ['bold' => true],
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ]
    ]);

    $sheet->getStyle('A' . $totalRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->getStyle('A3:D' . $totalRow)->applyFromArray([
        'borders' => [
            'allBorders' => ['borderStyle' => Border::BORDER_THIN]
        ]
    ]);

    $sheet->getStyle('D3:D' . $totalRow)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);

    $sheet->getColumnDimension('A')->setWidth(24);
    $sheet->getColumnDimension('B')->setWidth(24);
    $sheet->getColumnDimension('C')->setWidth(16);
    $sheet->getColumnDimension('D')->setWidth(18);

    $sheetIndex++;
}

$spreadsheet->setActiveSheetIndex(0);

$timeFrameSegment = sanitizeFilePart(buildTimeFrameSegment($filterType, $startDate, $endDate));
$filename = 'BILLSPAYMENT-EDI-TRANSACTION_(' . $timeFrameSegment . ').xls';

header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xls($spreadsheet);
$writer->save('php://output');

$spreadsheet->disconnectWorksheets();
unset($spreadsheet);

exit;
?>
