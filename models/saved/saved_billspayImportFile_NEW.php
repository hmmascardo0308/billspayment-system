<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
}

include '../../config/config.php';
session_start();
@include_once __DIR__ . '/../../templates/middleware.php';

$id = function_exists('resolve_user_identifier') ? resolve_user_identifier() : null;
if (empty($id)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (ob_get_length() !== false) ob_clean();
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login_form.php');
    exit;
}

if (!function_exists('has_any_permission') || !has_any_permission(['Import Transaction', 'Bills Payment'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (ob_get_length() !== false) ob_clean();
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Forbidden']);
        exit;
    }
    header('Location: ../../dashboard/home.php');
    exit;
}

$importerPath = '../../dashboard/billspayment/import/billspay-transaction.php';

if (isset($_GET['cancel']) && $_GET['cancel'] === '1') {
    unset($_SESSION['debug_import_files']);
    header('Location: ' . $importerPath);
    exit;
}

function load_branch_ids(): array
{
    $branchPath = __DIR__ . '/../../branch.json';
    $raw = is_file($branchPath) ? file_get_contents($branchPath) : '[]';
    $rows = json_decode((string)$raw, true);
    $branchIds = [];

    if (is_array($rows)) {
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists('branch_id', $row)) continue;
            $branchId = trim((string)$row['branch_id']);
            if ($branchId !== '') $branchIds[$branchId] = true;
        }
    }

    return $branchIds;
}

function normalize_import_row(array $row, string $filename, string $fileSourceType): array
{
    return [
        'filename' => $filename,
        'file_source_type' => $fileSourceType,
        'report_date' => $row['report_date'] ?? null,
        'source_type' => $row['source_type'] ?? null,
        'status' => $row['status'] ?? null,
        'datetime' => $row['datetime'] ?? null,
        'cancellation_date' => $row['cancellation_date'] ?? null,
        'control_no' => $row['control_no'] ?? null,
        'reference_no' => $row['reference_no'] ?? null,
        'payor_name' => $row['payor_name'] ?? null,
        'address' => $row['address'] ?? null,
        'account_no' => $row['account_no'] ?? null,
        'account_name' => $row['account_name'] ?? null,
        'amount_paid' => $row['amount_paid'] ?? null,
        'charge_customer' => $row['charge_customer'] ?? null,
        'charge_partner' => $row['charge_partner'] ?? null,
        'contact_no' => $row['contact_no'] ?? null,
        'other_details' => $row['other_details'] ?? null,
        'branch_id' => $row['branch_id'] ?? null,
        'branch_code' => $row['branch_code'] ?? null,
        'branch_outlet' => $row['branch_outlet'] ?? null,
        'zone_code' => $row['zone_code'] ?? null,
        'region_code' => $row['region_code'] ?? null,
        'region_name' => $row['region_name'] ?? null,
        'operator' => $row['operator'] ?? null,
        'remote_branch' => $row['remote_branch'] ?? null,
        'remote_operator' => $row['remote_operator'] ?? null,
        '2nd_approver' => $row['2nd_approver'] ?? ($row['second_approver'] ?? null),
        'partner_name' => $row['partner_name'] ?? null,
        'partner_id_kpx' => $row['partner_id_kpx'] ?? null,
        'partner_id' => $row['partner_id'] ?? null,
        'gl_code' => $row['gl_code'] ?? null,
        'post_transaction' => $row['post_transaction'] ?? null,
        'imported_date' => $row['imported_date'] ?? null,
        'imported_by' => $row['imported_by'] ?? null,
    ];
}

function find_partner_by_column(string $column, string $value, bool $activeOnly = true): ?array
{
    global $conn;

    static $cache = [];
    $value = trim($value);
    if ($value === '') return null;

    $allowedColumns = ['partner_id_kpx', 'partner_id', 'tg_partner_name'];
    if (!in_array($column, $allowedColumns, true)) return null;

    $cacheKey = $column . ':' . $value . ':' . ($activeOnly ? 'active' : 'any');
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $statusSql = $activeOnly ? " AND status = 'ACTIVE'" : '';
    $sql = "SELECT partner_id, partner_id_kpx, partner_name, tg_partner_name, status FROM masterdata.partner_masterfile WHERE {$column} = ?{$statusSql} LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $cache[$cacheKey] = null;
        return null;
    }

    $stmt->bind_param('s', $value);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $cache[$cacheKey] = is_array($row) ? $row : null;
    return $cache[$cacheKey];
}

function partner_exists_by_column(string $column, string $value): bool
{
    return find_partner_by_column($column, $value, true) !== null;
}

function normalize_partner_lookup_name(string $value): string
{
    return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
}

function load_partner_json_rows(): array
{
    static $partners = null;

    if ($partners === null) {
        $partners = [];
        $partnerPath = __DIR__ . '/../../partner.json';
        $raw = is_file($partnerPath) ? file_get_contents($partnerPath) : '[]';
        $rows = json_decode((string)$raw, true);

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $partners[] = [
                    'partner_id' => $row['partner_id'] ?? '',
                    'partner_id_kpx' => $row['partner_id_kpx'] ?? '',
                    'partner_name' => $row['tg_partner_name'] ?? '',
                    'tg_partner_name' => $row['tg_partner_name'] ?? '',
                    'status' => 'ACTIVE',
                ];
            }
        }
    }

    return $partners;
}

function find_partner_in_json_by_tg_partner_name(string $partnerName): ?array
{
    $lookupName = normalize_partner_lookup_name($partnerName);
    if ($lookupName === '') return null;

    foreach (load_partner_json_rows() as $partner) {
        if (normalize_partner_lookup_name((string)($partner['tg_partner_name'] ?? '')) === $lookupName) {
            return $partner;
        }
    }

    return null;
}

function partner_exists_in_json_for_excel_row(string $partnerName, string $partnerIdKpx, string $partnerId): bool
{
    $lookupName = normalize_partner_lookup_name($partnerName);
    $lookupPartnerIdKpx = trim($partnerIdKpx);
    $lookupPartnerId = trim($partnerId);

    foreach (load_partner_json_rows() as $partner) {
        $jsonName = normalize_partner_lookup_name((string)($partner['tg_partner_name'] ?? ''));
        $jsonPartnerIdKpx = trim((string)($partner['partner_id_kpx'] ?? ''));
        $jsonPartnerId = trim((string)($partner['partner_id'] ?? ''));

        if ($lookupName !== '' && $jsonName === $lookupName) return true;
        if ($lookupPartnerIdKpx !== '' && $jsonPartnerIdKpx === $lookupPartnerIdKpx) return true;
        if ($lookupPartnerId !== '' && $jsonPartnerId === $lookupPartnerId) return true;
    }

    return false;
}

function find_active_partner_by_name_with_json_fallback(string $partnerName): ?array
{
    $lookupName = normalize_partner_lookup_name($partnerName);
    if ($lookupName === '') return null;

    $partner = find_partner_by_column('tg_partner_name', $lookupName, true);
    return $partner ?? find_partner_in_json_by_tg_partner_name($lookupName);
}

function get_partner_display_name(?array $partner): string
{
    if (!is_array($partner)) return '';
    return trim((string)($partner['tg_partner_name'] ?? ($partner['partner_name'] ?? '')));
}

function partner_names_match(string $excelPartnerName, array $ownerPartner): bool
{
    $ownerPartnerName = get_partner_display_name($ownerPartner);
    if (trim($excelPartnerName) === '' || $ownerPartnerName === '') return true;
    return normalize_partner_lookup_name($excelPartnerName) === normalize_partner_lookup_name($ownerPartnerName);
}

function partner_name_change_issue_payload(array $normalized, int $rowNumber, string $lookupColumn, string $lookupValue, array $ownerPartner, string $excelPartnerIdKpx, string $excelPartnerId): array
{
    return [
        'row' => $rowNumber,
        'type' => 'partner_name_change',
        'label' => 'Partner Name Change',
        'value' => $lookupValue,
        'lookup_column' => $lookupColumn,
        'source_type' => $normalized['source_type'] ?? '',
        'partner_id_kpx' => $excelPartnerIdKpx,
        'partner_id' => $excelPartnerId,
        'partner_name' => $normalized['partner_name'] ?? '',
        'owner_partner_id' => $ownerPartner['partner_id'] ?? '',
        'owner_partner_id_kpx' => $ownerPartner['partner_id_kpx'] ?? '',
        'owner_partner_name' => get_partner_display_name($ownerPartner),
        'owner_status' => $ownerPartner['status'] ?? '',
        'branch_outlet' => $normalized['branch_outlet'] ?? '',
    ];
}

function partner_issue_payload(array $normalized, int $rowNumber, string $lookupColumn, string $lookupValue, string $excelPartnerIdKpx, string $excelPartnerId): array
{
    $ownerPartner = find_partner_by_column($lookupColumn, $lookupValue, false);
    $hasInactiveOrNonActivePartner = is_array($ownerPartner);
    $issueLabel = $hasInactiveOrNonActivePartner ? 'Existing Partner ID' : 'New Partner';

    return [
        'row' => $rowNumber,
        'type' => 'new_partner',
        'label' => $issueLabel,
        'value' => $lookupValue,
        'lookup_column' => $lookupColumn,
        'source_type' => $normalized['source_type'] ?? '',
        'partner_id_kpx' => $excelPartnerIdKpx,
        'partner_id' => $excelPartnerId,
        'partner_name' => $normalized['partner_name'] ?? '',
        'owner_partner_id' => $ownerPartner['partner_id'] ?? '',
        'owner_partner_id_kpx' => $ownerPartner['partner_id_kpx'] ?? '',
        'owner_partner_name' => get_partner_display_name($ownerPartner),
        'owner_status' => $ownerPartner['status'] ?? '',
        'branch_outlet' => $normalized['branch_outlet'] ?? '',
    ];
}

function normalize_date_for_lookup(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

function map_database_override_row(array $row): array
{
    return [
        'source_of_data' => 'Database',
        'report_date' => $row['report_date'] ?? '',
        'source_type' => $row['source_file'] ?? '',
        'status' => $row['status'] ?? '',
        'datetime' => $row['datetime'] ?? '',
        'cancellation_date' => $row['cancellation_date'] ?? '',
        'control_no' => $row['control_no'] ?? '',
        'reference_no' => $row['reference_no'] ?? '',
        'payor_name' => $row['payor'] ?? '',
        'address' => $row['address'] ?? '',
        'account_no' => $row['account_no'] ?? '',
        'account_name' => $row['account_name'] ?? '',
        'amount_paid' => $row['amount_paid'] ?? '',
        'charge_customer' => $row['charge_to_customer'] ?? '',
        'charge_partner' => $row['charge_to_partner'] ?? '',
        'contact_no' => $row['contact_no'] ?? '',
        'other_details' => $row['other_details'] ?? '',
        'branch_id' => $row['branch_id'] ?? '',
        'branch_code' => $row['branch_code'] ?? '',
        'branch_outlet' => $row['outlet'] ?? '',
        'zone_code' => $row['zone_code'] ?? '',
        'region_code' => $row['region_code'] ?? '',
        'region_name' => $row['region'] ?? '',
        'operator' => $row['operator'] ?? '',
        'remote_branch' => $row['remote_branch'] ?? '',
        'remote_operator' => $row['remote_operator'] ?? '',
        '2nd_approver' => $row['2nd_approver'] ?? '',
        'partner_name' => $row['partner_name'] ?? '',
        'partner_id' => $row['partner_id'] ?? '',
        'partner_id_kpx' => $row['partner_id_kpx'] ?? '',
        'gl_code' => $row['mpm_gl_code'] ?? '',
        'post_transaction' => $row['post_transaction'] ?? '',
        'imported_date' => $row['imported_date'] ?? '',
        'imported_by' => $row['imported_by'] ?? '',
    ];
}

function map_excel_override_row(array $row): array
{
    $row['source_of_data'] = 'Excel Data';
    return $row;
}

function get_override_matches(array $row): array
{
    global $conn;

    static $cache = [];

    $postTransaction = trim((string)($row['post_transaction'] ?? ''));
    $datetime = normalize_date_for_lookup($row['datetime'] ?? '');
    $cancellationDate = normalize_date_for_lookup($row['cancellation_date'] ?? '');
    $referenceNo = trim((string)($row['reference_no'] ?? ''));

    if ($postTransaction === '' || $referenceNo === '' || ($datetime === '' && $cancellationDate === '')) {
        return [];
    }

    $lookupDate = $datetime !== '' ? $datetime : $cancellationDate;
    $cacheKey = $postTransaction . '|' . $lookupDate . '|' . $referenceNo;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $sql = 'SELECT * FROM mldb.billspayment_transaction WHERE post_transaction = ? AND (DATE(datetime) = ? OR DATE(cancellation_date) = ?) AND reference_no = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $cache[$cacheKey] = [];
        return [];
    }

    $stmt->bind_param('ssss', $postTransaction, $lookupDate, $lookupDate, $referenceNo);
    $stmt->execute();
    $result = $stmt->get_result();
    $matches = [];
    if ($result) {
        while ($resultRow = $result->fetch_assoc()) {
            $matches[] = map_database_override_row($resultRow);
        }
    }
    $stmt->close();

    $cache[$cacheKey] = $matches;
    return $cache[$cacheKey];
}

function bind_and_execute(mysqli_stmt $stmt, array $params): bool
{
    if (empty($params)) {
        return $stmt->execute();
    }

    $types = str_repeat('s', count($params));
    $refs = [];
    foreach ($params as $key => $value) {
        $refs[$key] = $params[$key];
    }

    $bindArgs = [$types];
    foreach ($refs as $key => &$value) {
        $bindArgs[] = &$value;
    }

    call_user_func_array([$stmt, 'bind_param'], $bindArgs);
    return $stmt->execute();
}

function clean_db_value(mixed $value)
{
    $value = is_string($value) ? trim($value) : $value;
    return $value === '' ? null : $value;
}

function clean_db_amount(mixed $value): string
{
    $value = str_replace(',', '', trim((string)$value));
    return is_numeric($value) ? $value : '0';
}

function clean_db_required_value(mixed $value): string
{
    $value = is_string($value) ? trim($value) : $value;
    return $value === null ? '' : (string)$value;
}

function clean_db_datetime(mixed $value)
{
    $value = trim((string)$value);
    if ($value === '') return null;

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        $value = preg_replace('/\s*(AM|PM)\s*$/i', '', $value);
        $timestamp = strtotime((string)$value);
    }

    return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
}

function transaction_column_map(array $row): array
{
    $sourceType = strtoupper(trim((string)($row['source_type'] ?? '')));
    $columns = [
        'status' => clean_db_value($row['status'] ?? null),
        'report_date' => clean_db_value($row['report_date'] ?? null),
        'datetime' => clean_db_datetime($row['datetime'] ?? null),
        'cancellation_date' => clean_db_datetime($row['cancellation_date'] ?? null),
        'source_file' => clean_db_value($row['source_type'] ?? null),
        'control_no' => clean_db_value($row['control_no'] ?? null),
        'reference_no' => clean_db_value($row['reference_no'] ?? null),
        'payor' => clean_db_value($row['payor_name'] ?? null),
        'address' => clean_db_value($row['address'] ?? null),
        'account_no' => clean_db_value($row['account_no'] ?? null),
        'account_name' => clean_db_value($row['account_name'] ?? null),
        'amount_paid' => clean_db_amount($row['amount_paid'] ?? '0'),
        'charge_to_customer' => clean_db_amount($row['charge_customer'] ?? '0'),
        'charge_to_partner' => clean_db_amount($row['charge_partner'] ?? '0'),
        'contact_no' => clean_db_value($row['contact_no'] ?? null),
        'other_details' => clean_db_value($row['other_details'] ?? null),
        'branch_id' => clean_db_required_value($row['branch_id'] ?? ''),
        'outlet' => clean_db_value($row['branch_outlet'] ?? null),
        'zone_code' => clean_db_value($row['zone_code'] ?? null),
        'region_code' => clean_db_value($row['region_code'] ?? null),
        'region' => clean_db_value($row['region_name'] ?? null),
        'operator' => clean_db_value($row['operator'] ?? null),
        'remote_branch' => clean_db_value($row['remote_branch'] ?? null),
        'remote_operator' => clean_db_value($row['remote_operator'] ?? null),
        '2nd_approver' => clean_db_value($row['2nd_approver'] ?? null),
        'partner_name' => clean_db_value($row['partner_name'] ?? null),
        'partner_id' => clean_db_value($row['partner_id'] ?? null),
        'partner_id_kpx' => clean_db_value($row['partner_id_kpx'] ?? null),
        'mpm_gl_code' => clean_db_value($row['gl_code'] ?? null),
        'settle_unsettle' => 'Unsettle',
        'imported_by' => clean_db_value($row['imported_by'] ?? null),
        'imported_date' => clean_db_value($row['imported_date'] ?? null),
        'post_transaction' => clean_db_value($row['post_transaction'] ?? null),
    ];

    if ($sourceType === 'KP7') {
        $columns = array_merge(
            array_slice($columns, 0, 17, true),
            ['branch_code' => clean_db_value($row['branch_code'] ?? null)],
            array_slice($columns, 17, null, true)
        );
    }

    return $columns;
}

function insert_billspayment_transaction(array $row): void
{
    global $conn;

    $columns = transaction_column_map($row);
    $columnSql = implode(', ', array_map(static function (string $column): string {
        return $column === '2nd_approver' ? '`2nd_approver`' : $column;
    }, array_keys($columns)));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO mldb.billspayment_transaction ({$columnSql}) VALUES ({$placeholders})";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Insert prepare failed: ' . $conn->error);
    }

    if (!bind_and_execute($stmt, array_values($columns))) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Insert failed: ' . $error);
    }
    $stmt->close();
}

function update_billspayment_transaction(array $row, string $reportDate, string $referenceNo): void
{
    global $conn;

    $columns = transaction_column_map($row);
    $setSql = implode(', ', array_map(static function (string $column): string {
        $safeColumn = $column === '2nd_approver' ? '`2nd_approver`' : $column;
        return $safeColumn . ' = ?';
    }, array_keys($columns)));
    $params = array_merge(array_values($columns), [$reportDate, $referenceNo]);
    $sql = "UPDATE mldb.billspayment_transaction SET {$setSql} WHERE report_date = ? AND reference_no = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Update prepare failed: ' . $conn->error);
    }

    if (!bind_and_execute($stmt, $params)) {
        $error = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Update failed: ' . $error);
    }
    $stmt->close();
}

function transaction_exists_by_report_reference(string $reportDate, string $referenceNo): bool
{
    global $conn;

    $sql = 'SELECT COUNT(*) AS total FROM mldb.billspayment_transaction WHERE report_date = ? AND reference_no = ? LIMIT 1';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Override lookup prepare failed: ' . $conn->error);
    }

    $stmt->bind_param('ss', $reportDate, $referenceNo);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return ((int)($row['total'] ?? 0)) > 0;
}

function import_debug_files(array $files): array
{
    global $conn;

    $summary = [
        'files' => 0,
        'rows' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
    ];

    $conn->begin_transaction();
    try {
        foreach ($files as $file) {
            if (!is_array($file)) continue;

            $status = strtolower((string)($file['status'] ?? ''));
            if ($status !== 'valid' && $status !== 'override') {
                $summary['skipped']++;
                continue;
            }

            $summary['files']++;
            $rows = isset($file['rows']) && is_array($file['rows']) ? $file['rows'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;

                $summary['rows']++;
                if ($status === 'override') {
                    $reportDate = trim((string)($row['report_date'] ?? ''));
                    $referenceNo = trim((string)($row['reference_no'] ?? ''));

                    if ($reportDate !== '' && $referenceNo !== '' && transaction_exists_by_report_reference($reportDate, $referenceNo)) {
                        update_billspayment_transaction($row, $reportDate, $referenceNo);
                        $summary['updated']++;
                    } else {
                        insert_billspayment_transaction($row);
                        $summary['inserted']++;
                    }
                } else {
                    insert_billspayment_transaction($row);
                    $summary['inserted']++;
                }
            }
        }

        $conn->commit();
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }

    return $summary;
}

function validate_file_payload(array $file, array $branchIds): array
{
    $filename = trim((string)($file['filename'] ?? ''));
    $fileSourceType = trim((string)($file['file_source_type'] ?? ''));
    $rows = isset($file['rows']) && is_array($file['rows']) ? $file['rows'] : [];
    $normalizedRows = [];
    $issues = [];
    $overrides = [];

    foreach ($rows as $index => $row) {
        if (!is_array($row)) continue;

        $normalized = normalize_import_row($row, $filename, $fileSourceType);
        $branchId = trim((string)($normalized['branch_id'] ?? ''));
        $sourceType = strtoupper(trim((string)($normalized['source_type'] ?? $fileSourceType)));
        $branchOutlet = strtoupper(trim(preg_replace('/\s+/', ' ', (string)($normalized['branch_outlet'] ?? '')) ?? ''));
        $kp7AllowedBranchOutlets = [
            'ML MIS US',
            'MIS DIVISION',
        ];
        $isKp7AllowedBranchOutlet = $sourceType === 'KP7' && in_array($branchOutlet, $kp7AllowedBranchOutlets, true);
        $partnerIdKpx = trim((string)($normalized['partner_id_kpx'] ?? ''));
        $partnerId = trim((string)($normalized['partner_id'] ?? ''));
        $partnerName = trim((string)($normalized['partner_name'] ?? ''));

        if (!$isKp7AllowedBranchOutlet && $branchId === '') {
            $issues[] = [
                'row' => $index + 1,
                'type' => 'no_branch_id',
                'label' => 'No Branch ID',
                'branch_id' => '',
                'branch_outlet' => $normalized['branch_outlet'] ?? '',
            ];
        } elseif (!$isKp7AllowedBranchOutlet && !isset($branchIds[$branchId])) {
            $issues[] = [
                'row' => $index + 1,
                'type' => 'new_branch_id',
                'label' => 'New Branch ID',
                'branch_id' => $branchId,
                'branch_outlet' => $normalized['branch_outlet'] ?? '',
            ];
        }

        if ($partnerIdKpx !== '') {
            $activePartner = find_partner_by_column('partner_id_kpx', $partnerIdKpx, true);
            if (!is_array($activePartner)) {
                $issues[] = partner_issue_payload($normalized, $index + 1, 'partner_id_kpx', $partnerIdKpx, $partnerIdKpx, $partnerId);
            } elseif (!partner_exists_in_json_for_excel_row($partnerName, $partnerIdKpx, $partnerId) && !partner_names_match($partnerName, $activePartner)) {
                $issues[] = partner_name_change_issue_payload($normalized, $index + 1, 'partner_id_kpx', $partnerIdKpx, $activePartner, $partnerIdKpx, $partnerId);
            }
        } elseif ($partnerId !== '') {
            $activePartner = find_partner_by_column('partner_id', $partnerId, true);
            if (!is_array($activePartner)) {
                $issues[] = partner_issue_payload($normalized, $index + 1, 'partner_id', $partnerId, '', $partnerId);
            } elseif (!partner_exists_in_json_for_excel_row($partnerName, $partnerIdKpx, $partnerId) && !partner_names_match($partnerName, $activePartner)) {
                $issues[] = partner_name_change_issue_payload($normalized, $index + 1, 'partner_id', $partnerId, $activePartner, '', $partnerId);
            }
        } else {
            $partnerByName = find_active_partner_by_name_with_json_fallback($partnerName);
            if (is_array($partnerByName)) {
                $normalized['partner_id'] = $partnerByName['partner_id'] ?? null;
                $normalized['partner_id_kpx'] = $partnerByName['partner_id_kpx'] ?? null;
                $partnerId = trim((string)($normalized['partner_id'] ?? ''));
                $partnerIdKpx = trim((string)($normalized['partner_id_kpx'] ?? ''));
            } elseif ($partnerName !== '') {
                $issues[] = partner_issue_payload($normalized, $index + 1, 'tg_partner_name', $partnerName, '', '');
            } else {
                $issues[] = [
                    'row' => $index + 1,
                    'type' => 'no_partner_id',
                    'label' => 'No Partner ID',
                    'value' => '',
                    'partner_id_kpx' => '',
                    'partner_id' => '',
                    'partner_name' => $normalized['partner_name'] ?? '',
                    'branch_outlet' => $normalized['branch_outlet'] ?? '',
                ];
            }
        }

        $overrideMatches = get_override_matches($normalized);
        if (!empty($overrideMatches)) {
            $overrides[] = [
                'row' => $index + 1,
                'type' => 'override',
                'label' => 'Override',
                'reference_no' => $normalized['reference_no'] ?? '',
                'datetime' => $normalized['datetime'] ?? '',
                'cancellation_date' => $normalized['cancellation_date'] ?? '',
                'post_transaction' => $normalized['post_transaction'] ?? '',
                'excel_row' => map_excel_override_row($normalized),
                'database_rows' => $overrideMatches,
            ];
        }

        $normalizedRows[] = $normalized;
    }

    $status = !empty($issues) ? 'invalid' : (!empty($overrides) ? 'override' : 'valid');

    return [
        'filename' => $filename,
        'file_source_type' => $fileSourceType,
        'total_rows' => count($normalizedRows),
        'valid_rows' => max(0, count($normalizedRows) - count($issues)),
        'status' => $status,
        'issues' => $issues,
        'overrides' => $overrides,
        'rows' => $normalizedRows,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
            exit;
        }

        if (($input['action'] ?? '') === 'reset_validation') {
            unset($_SESSION['debug_import_payload_files'], $_SESSION['debug_import_files']);
            if (ob_get_length() !== false) {
                ob_clean();
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if (($input['action'] ?? '') === 'append_validation_chunk') {
            if (!isset($_SESSION['debug_import_payload_files']) || !is_array($_SESSION['debug_import_payload_files'])) {
                $_SESSION['debug_import_payload_files'] = [];
            }

            $file = isset($input['file']) && is_array($input['file']) ? $input['file'] : [];
            $filename = trim((string)($file['filename'] ?? ''));
            $fileSourceType = trim((string)($file['file_source_type'] ?? ''));
            $rows = isset($input['rows']) && is_array($input['rows']) ? $input['rows'] : [];

            if ($filename === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing chunk filename.']);
                exit;
            }

            if (!isset($_SESSION['debug_import_payload_files'][$filename])) {
                $_SESSION['debug_import_payload_files'][$filename] = [
                    'filename' => $filename,
                    'file_source_type' => $fileSourceType,
                    'rows' => [],
                ];
            }

            $_SESSION['debug_import_payload_files'][$filename]['rows'] = array_merge(
                $_SESSION['debug_import_payload_files'][$filename]['rows'],
                $rows
            );

            if (ob_get_length() !== false) {
                ob_clean();
            }
            echo json_encode(['success' => true]);
            exit;
        }

        if (($input['action'] ?? '') === 'finalize_validation') {
            $files = isset($_SESSION['debug_import_payload_files']) && is_array($_SESSION['debug_import_payload_files'])
                ? array_values($_SESSION['debug_import_payload_files'])
                : [];
        } else {
            $files = isset($input['files']) && is_array($input['files']) ? $input['files'] : [];
        }

        if (($input['action'] ?? '') === 'import') {
            $sessionFiles = isset($_SESSION['debug_import_files']) && is_array($_SESSION['debug_import_files'])
                ? $_SESSION['debug_import_files']
                : [];

            if (empty($sessionFiles)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No validated files found for import.']);
                exit;
            }

            $summary = import_debug_files($sessionFiles);
            unset($_SESSION['debug_import_files']);
            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'redirect' => $importerPath,
            ]);
            exit;
        }

        $branchIds = load_branch_ids();
        $validatedFiles = [];

        foreach ($files as $file) {
            if (is_array($file)) {
                $validatedFiles[] = validate_file_payload($file, $branchIds);
            }
        }

        $_SESSION['debug_import_files'] = $validatedFiles;
        unset($_SESSION['debug_import_payload_files']);

        if (ob_get_length() !== false) {
            ob_clean();
        }
        echo json_encode([
            'success' => true,
            'redirect' => '../../models/saved/saved_billspayImportFile_NEW.php',
            'files' => array_map(static function (array $file): array {
                return [
                    'filename' => $file['filename'],
                    'file_source_type' => $file['file_source_type'],
                    'total_rows' => $file['total_rows'],
                    'valid_rows' => $file['valid_rows'],
                    'status' => $file['status'],
                    'issue_count' => count($file['issues']),
                    'override_count' => count($file['overrides'] ?? []),
                    'issues' => $file['issues'],
                    'overrides' => $file['overrides'] ?? [],
                ];
            }, $validatedFiles),
        ]);
    } catch (Throwable $exception) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $exception->getMessage(),
        ]);
    }
    exit;
}

$files = isset($_SESSION['debug_import_files']) && is_array($_SESSION['debug_import_files'])
    ? $_SESSION['debug_import_files']
    : [];
$totalIssueCount = 0;
$validFileCount = 0;
foreach ($files as $file) {
    $issueCount = count($file['issues'] ?? []);
    $fileStatus = (string)($file['status'] ?? ($issueCount ? 'invalid' : 'valid'));
    $totalIssueCount += $issueCount;
    if ($fileStatus === 'valid' || $fileStatus === 'override') {
        $validFileCount++;
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validate & Import | BillsPayment</title>
    <link rel="stylesheet" href="../../assets/css/templates/style.css?v=<?php echo time(); ?>">
    <script src="https://kit.fontawesome.com/30b908cc5a.js" crossorigin="anonymous"></script>
    <style>
        body { background: #f5f6f8; font-family: Arial, sans-serif; }
        .validation-page { padding: 10px; }
        .validation-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        .validation-title h2 { margin: 0; font-size: 24px; font-weight: 800; color: #212529; }
        .validation-title p { margin: 4px 0 0; color: #6c757d; }
        .file-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(430px, 1fr)); gap: 12px; }
        .controls-bar {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
            margin: 0 0 16px;
        }
        .overall-summary-btn {
            border: 0;
            min-height: 38px;
            padding: 8px 12px;
            border-radius: 6px;
            background: #0b8f55;
            color: #fff;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }
        .overall-summary-btn:hover { background: #087848; color: #fff; }
        .filter-bar { display: flex; align-items: center; gap: 8px; }
        .filter-bar label {
            font-weight: 800;
            color: #111827;
        }
        .filter-bar select {
            min-width: 165px;
            height: 38px;
            border: 1px solid #ced4da;
            border-radius: 6px;
            padding: 6px 34px 6px 10px;
            background-color: #fff;
            color: #111827;
            font-size: 15px;
        }
        .file-card {
            background: #fff5f5;
            border: 1px solid #dc3545;
            border-radius: 8px;
            padding: 18px 18px 14px;
            min-height: 190px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
        .file-card.valid {
            background: #f0fff4;
            border-color: #198754;
        }
        .file-card.override {
            background: #fff8e1;
            border-color: #ffc107;
        }
        .file-card-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: start;
        }
        .file-name {
            font-size: 18px;
            line-height: 1.18;
            font-weight: 800;
            color: #111827;
            word-break: break-word;
            margin: 2px 0 18px;
        }
        .branch-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 74px;
            min-height: 42px;
            padding: 7px 12px;
            border-radius: 18px;
            background: #dc3545;
            color: #fff;
            font-size: 11px;
            font-weight: 900;
            text-align: center;
            line-height: 1.1;
            text-transform: uppercase;
        }
        .branch-pill.ok { background: #20c997; color: #fff; }
        .branch-pill.valid { background: #198754; color: #fff; }
        .branch-pill.override { background: #ffc107; color: #111827; }
        .field-label { color: #6c757d; font-size: 13px; margin-bottom: 3px; }
        .source-badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 8px;
            border-radius: 4px;
            background: #0d6efd;
            color: #fff;
            font-size: 12px;
            font-weight: 800;
        }
        .rows-found { margin-top: 16px; color: #6c757d; font-size: 14px; }
        .rows-found strong { color: #111827; font-weight: 900; }
        .card-actions { display: flex; align-items: center; gap: 0; margin-top: 18px; flex-wrap: wrap; }
        .card-action {
            border: 0;
            min-height: 30px;
            padding: 6px 10px;
            font-size: 13px;
            font-weight: 700;
            color: #06141f;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .card-action.details { background: #08c7e8; }
        .card-action.summary { background: #0b8f55; color: #fff; }
        .card-action.issue {
            margin-left: 10px;
            width: 30px;
            height: 30px;
            border: 2px solid #dc3545;
            border-radius: 50%;
            background: #fff;
            color: #dc3545;
            justify-content: center;
            padding: 0;
        }
        .card-action.override {
            margin-left: 10px;
            width: 30px;
            height: 30px;
            border: 2px solid #ffc107;
            border-radius: 50%;
            background: #fff;
            color: #ffc107;
            justify-content: center;
            padding: 0;
        }
        .issues-modal-table-wrap { max-height: 380px; overflow: auto; border: 1px solid #dee2e6; }
        .issues-modal-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
        .issues-modal-table th,
        .issues-modal-table td { border-right: 1px solid #dee2e6; border-bottom: 1px solid #dee2e6; padding: 7px 9px; text-align: left; }
        .issues-modal-table th { position: sticky; background: #fff; color: #343a40; font-weight: 800; z-index: 2; }
        .issues-modal-table thead tr:first-child th { top: 0; z-index: 3; text-align: center; }
        .issues-modal-table thead tr:nth-child(2) th { top: 34px; z-index: 3; }
        .issues-modal-table thead tr:nth-child(3) th { top: 68px; z-index: 3; }
        .issues-modal-table td { color: #343a40; }
        .issues-modal-popup { width: min(96vw, 720px) !important; }
        .override-modal-popup { width: min(98vw, 1280px) !important; }
        .override-modal-popup .swal2-html-container { margin-left: 0.75em; margin-right: 0.75em; }
        .override-modal-table-wrap { max-height: 520px; overflow: auto; border: 1px solid #dee2e6; }
        .override-modal-table { width: max-content; min-width: 100%; border-collapse: collapse; font-size: 12px; }
        .override-modal-table th,
        .override-modal-table td { border-right: 1px solid #dee2e6; border-bottom: 1px solid #dee2e6; padding: 7px 9px; text-align: left; white-space: nowrap; }
        .override-modal-table th { position: sticky; top: 0; background: #ffc107; color: #111827; font-weight: 800; z-index: 2; }
        .override-modal-table td.amount-cell { text-align: right; }
        .override-modal-table td.mismatch-database { background: #f8d7da; color: #842029; }
        .override-modal-table td.mismatch-excel { background: #fff3cd; color: #664d03; }
        .override-modal-table tr.override-single-hover:hover td {
            box-shadow: inset 0 0 0 9999px rgba(13, 110, 253, 0.08);
        }
        .override-modal-table tr.override-pair-hover td {
            outline: 2px solid #0d6efd;
            outline-offset: -2px;
            box-shadow: inset 0 0 0 9999px rgba(13, 110, 253, 0.08);
        }
        .override-mode-toggle {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 12px;
            padding: 6px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background: #f8f9fa;
        }
        .override-mode-toggle label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin: 0;
            padding: 6px 10px;
            border-radius: 4px;
            color: #212529;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
        }
        .override-modal-tools {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
            color: #495057;
            font-size: 13px;
            font-weight: 700;
        }
        .override-modal-pager { display: inline-flex; align-items: center; gap: 8px; }
        .override-modal-pager button {
            border: 0;
            border-radius: 4px;
            padding: 6px 10px;
            background: #6c757d;
            color: #fff;
            font-weight: 800;
            cursor: pointer;
        }
        .override-modal-pager button:disabled { opacity: 0.45; cursor: not-allowed; }
        .summary-modal-popup { width: min(96vw, 1060px) !important; padding: 0 !important; }
        .summary-modal-popup .swal2-html-container { margin: 0; }
        .summary-box { border-radius: 4px; overflow: hidden; background: #fff; }
        .summary-title {
            background: #dc3545;
            color: #fff;
            text-align: center;
            font-size: 23px;
            font-weight: 800;
            padding: 14px 12px;
        }
        .summary-content { padding: 14px; }
        .summary-filename {
            margin-top: 10px;
            color: #6c757d;
            font-size: 14px;
            text-align: left;
        }
        .summary-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .summary-table th {
            background: #dc3545;
            color: #fff;
            padding: 10px 8px;
            text-align: center;
            font-size: 14px;
            border: 1px solid #dee2e6;
        }
        .summary-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            vertical-align: middle;
            font-size: 14px;
            font-weight: 800;
            color: #111827;
        }
        .summary-table tfoot th {
            background: #000;
            color: #fff;
            border: 1px solid #000;
            padding: 10px 8px;
            font-size: 15px;
            font-weight: 900;
        }
        .summary-table tfoot th:first-child { text-align: right; }
        .summary-table tfoot th:last-child { text-align: right; }
        .summary-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .summary-label { display: inline-flex; align-items: center; gap: 7px; text-align: left; }
        .summary-value { text-align: right; white-space: nowrap; }
        .summary-icon.count { color: #607d8b; }
        .summary-icon.principal { color: #00965e; }
        .summary-icon.charge { color: #dc3545; }
        .summary-icon.partner { color: #0d6efd; }
        .summary-icon.customer { color: #17a2b8; }
        .empty-state { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 28px; color: #6c757d; text-align: center; font-weight: 700; }
        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 8px 14px;
            border-radius: 6px;
            background: #6c757d;
            color: #fff;
            text-decoration: none;
            font-weight: 800;
        }
        .back-link:hover { background: #5c636a; color: #fff; }
        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: flex-end; }
        .export-all-errors-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 36px;
            padding: 8px 14px;
            border-radius: 6px;
            background: #dc3545;
            color: #fff;
            text-decoration: none;
            font-weight: 800;
        }
        .export-all-errors-btn:hover { background: #bb2d3b; color: #fff; }
        .import-files-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 36px;
            padding: 8px 14px;
            border-radius: 6px;
            background: #198754;
            color: #fff;
            text-decoration: none;
            font-weight: 800;
        }
        .import-files-btn:hover { background: #157347; color: #fff; }
        @media (max-width: 520px) {
            .file-grid { grid-template-columns: 1fr; }
            .file-card { padding: 14px; }
            .file-name { font-size: 16px; }
        }
    </style>
</head>
<body>
    <main class="validation-page">
        <div class="validation-header">
            <div class="validation-title">
                <h2>File Validation & Import</h2>
                <!-- <p>Validated from JSON payload against branch.json.</p> -->
            </div>
            <div class="header-actions">
                <?php if ($totalIssueCount > 0): ?>
                    <a class="export-all-errors-btn" href="#" id="exportAllErrorsBtn">
                        <i class="fa-solid fa-file-pdf"></i> Export All Error Detected in PDF Format (<?php echo number_format($totalIssueCount); ?>)
                    </a>
                <?php endif; ?>
                <?php if ($validFileCount > 0): ?>
                    <a class="import-files-btn" href="#" id="importFilesBtn">
                        <i class="fa-solid fa-file-import"></i> Import File (<?php echo number_format($validFileCount); ?>)
                    </a>
                <?php endif; ?>
                <a class="back-link" href="?cancel=1" id="cancelImportBtn"><i class="fa-solid fa-xmark"></i> Cancel</a>
            </div>
        </div>

        <?php if (empty($files)): ?>
            <div class="empty-state">No debug import files to display.</div>
        <?php else: ?>
            <div class="controls-bar">
                <a class="overall-summary-btn" href="#" id="overallSummaryBtn">
                    <i class="fa-solid fa-chart-bar"></i> Transaction Summary Overall
                </a>
                <div class="filter-bar">
                    <label for="fileStatusFilter">Show:</label>
                    <select id="fileStatusFilter">
                        <option value="all">All</option>
                        <option value="invalid">Invalid</option>
                        <option value="valid">Valid / Override</option>
                    </select>
                </div>
            </div>
            <div class="file-grid">
                <?php foreach ($files as $fileIndex => $file): ?>
                    <?php
                        $issueCount = count($file['issues'] ?? []);
                        $fileStatus = (string)($file['status'] ?? ($issueCount ? 'invalid' : 'valid'));
                        $cardClass = $fileStatus === 'valid' ? 'valid' : ($fileStatus === 'override' ? 'override' : '');
                        $pillClass = $fileStatus === 'valid' ? 'valid' : ($fileStatus === 'override' ? 'override' : '');
                        $pillText = $fileStatus === 'override' ? 'Override' : ($fileStatus === 'valid' ? 'Valid' : 'Invalid');
                    ?>
                    <section class="file-card <?php echo h($cardClass); ?>" data-status="<?php echo h($fileStatus); ?>">
                        <div class="file-card-top">
                            <div>
                                <div class="file-name"><?php echo h($file['filename'] ?? ''); ?></div>
                                <div class="field-label">Source Type</div>
                                <span class="source-badge"><?php echo h($file['file_source_type'] ?? ''); ?></span>
                            </div>
                            <span class="branch-pill <?php echo h($pillClass); ?>">
                                <?php echo h($pillText); ?>
                            </span>
                        </div>

                        <div class="rows-found">
                            Data Rows Found: <strong><?php echo number_format((int)($file['total_rows'] ?? 0)); ?></strong>
                        </div>

                        <div class="card-actions">
                            <a class="card-action summary" href="#" data-index="<?php echo (int)$fileIndex; ?>">
                                <i class="fa-solid fa-chart-bar"></i> Transaction Summary
                            </a>
                            <?php if ($issueCount > 0): ?>
                                <a class="card-action issue" href="#" data-index="<?php echo (int)$fileIndex; ?>" title="Missing Data Detected">
                                    <i class="fa-solid fa-circle-question"></i>
                                </a>
                            <?php elseif ($fileStatus === 'override'): ?>
                                <a class="card-action override" href="#" data-index="<?php echo (int)$fileIndex; ?>" title="Override Detected">
                                    <i class="fa-solid fa-circle-exclamation"></i>
                                </a>
                            <?php endif; ?>
                        </div>

                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <script src="../../assets/js/sweetalert2.all.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
    <script>
        const validationFiles = <?php echo json_encode($files, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        const cancelImportBtn = document.getElementById('cancelImportBtn');
        if (cancelImportBtn) {
            cancelImportBtn.addEventListener('click', function(event) {
                event.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Cancel Import?',
                    text: 'All uploaded files will be discarded. This action cannot be undone.',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, cancel import',
                    cancelButtonText: 'No, go back',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#0d6efd',
                    reverseButtons: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false
                }).then(function(result) {
                    if (result.isConfirmed) {
                        window.location.href = cancelImportBtn.getAttribute('href');
                    }
                });
            });
        }

        const overallSummaryBtn = document.getElementById('overallSummaryBtn');
        if (overallSummaryBtn) {
            overallSummaryBtn.addEventListener('click', function(event) {
                event.preventDefault();
                const overallRows = validationFiles.reduce(function(rows, fileData) {
                    return rows.concat(Array.isArray(fileData.rows) ? fileData.rows : []);
                }, []);
                Swal.fire({
                    html: buildTransactionSummaryModal({
                        filename: 'Overall',
                        rows: overallRows
                    }),
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    showConfirmButton: true,
                    confirmButtonText: 'Close',
                    confirmButtonColor: '#6c757d',
                    customClass: { popup: 'summary-modal-popup' }
                });
            });
        }

        const fileStatusFilter = document.getElementById('fileStatusFilter');
        if (fileStatusFilter) {
            function getFilterStatus(cardStatus) {
                return cardStatus === 'override' ? 'valid' : cardStatus;
            }

            function updateHeaderActionsForFilter(selectedStatus) {
                const exportButton = document.getElementById('exportAllErrorsBtn');
                const importButton = document.getElementById('importFilesBtn');

                if (exportButton) {
                    exportButton.style.display = selectedStatus === 'valid' ? 'none' : '';
                }

                if (importButton) {
                    importButton.style.display = selectedStatus === 'invalid' ? 'none' : '';
                }
            }

            fileStatusFilter.addEventListener('change', function() {
                const selectedStatus = fileStatusFilter.value;
                document.querySelectorAll('.file-card').forEach(function(card) {
                    const cardStatus = card.getAttribute('data-status') || '';
                    const filterStatus = getFilterStatus(cardStatus);
                    card.style.display = selectedStatus === 'all' || filterStatus === selectedStatus ? '' : 'none';
                });
                updateHeaderActionsForFilter(selectedStatus);
            });
        }

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function formatIssueValue(issue) {
            if (issue && issue.type === 'no_partner_id') return 'Empty';
            if (issue && issue.type === 'new_partner') return String(issue.value || issue.partner_id_kpx || issue.partner_id || '').trim();
            const branchId = String(issue && issue.branch_id ? issue.branch_id : '').trim();
            return branchId === '' ? 'Empty' : branchId;
        }

        function getIssueText(issue) {
            const type = issue && issue.type ? issue.type : '';
            if (type === 'no_branch_id') return 'Missing Branch ID';
            if (type === 'new_branch_id') return 'New Branch ID';
            if (type === 'new_partner') return issue && issue.label ? issue.label : 'New Partner';
            if (type === 'partner_name_change') return issue && issue.label ? issue.label : 'Partner Name Change';
            if (type === 'no_partner_id') return 'No Partner ID';
            return issue && issue.label ? issue.label : 'Issue';
        }

        function isPartnerIssue(issue) {
            return issue && (issue.type === 'new_partner' || issue.type === 'partner_name_change' || issue.type === 'no_partner_id');
        }

        function getPartnerIssueId(issue, owner) {
            const sourceType = String(issue && issue.source_type ? issue.source_type : '').trim().toUpperCase();
            const lookupColumn = String(issue && issue.lookup_column ? issue.lookup_column : '').trim();

            if (owner) {
                if (sourceType === 'KP7' || lookupColumn === 'partner_id') return String(issue.owner_partner_id || '').trim();
                if (sourceType === 'KPX' || lookupColumn === 'partner_id_kpx') return String(issue.owner_partner_id_kpx || '').trim();
                return String(issue.owner_partner_id_kpx || issue.owner_partner_id || '').trim();
            }

            if (lookupColumn === 'tg_partner_name') return String(issue.partner_id_kpx || issue.partner_id || '').trim();
            if (sourceType === 'KP7' || lookupColumn === 'partner_id') return String(issue.partner_id || issue.value || '').trim();
            if (sourceType === 'KPX' || lookupColumn === 'partner_id_kpx') return String(issue.partner_id_kpx || issue.value || '').trim();
            return String(issue.partner_id_kpx || issue.partner_id || issue.value || '').trim();
        }

        function buildBranchIssuesTable(issues) {
            if (!issues.length) return '';

            const rows = issues.map(function(issue) {
                const issueText = getIssueText(issue);
                return '<tr>'
                    + '<td>' + escapeHtml(issue.row) + '</td>'
                    + '<td>' + escapeHtml(issue.branch_outlet || '') + '</td>'
                    + '<td>' + escapeHtml(issueText) + '</td>'
                    + '<td>' + escapeHtml(formatIssueValue(issue)) + '</td>'
                    + '</tr>';
            }).join('');

            return '<div class="issues-modal-table-wrap">'
                + '<table class="issues-modal-table">'
                + '<thead><tr><th colspan="4"><center>Branch Issues - ' + issues.length + ' Data row(s) found</center></th></tr><tr><th>Excel Row</th><th>Outlet</th><th>Issue</th><th>Value</th></tr></thead>'
                + '<tbody>' + rows + '</tbody>'
                + '</table>'
                + '</div>';
        }

        function buildPartnerIssuesTable(issues) {
            if (!issues.length) return '';

            const rows = issues.map(function(issue) {
                const excelPartnerId = getPartnerIssueId(issue, false);
                const ownerPartnerId = getPartnerIssueId(issue, true);
                return '<tr>'
                    + '<td>' + escapeHtml(ownerPartnerId) + '</td>'
                    + '<td>' + escapeHtml(issue.owner_partner_name || '') + '</td>'
                    + '<td>' + escapeHtml(excelPartnerId) + '</td>'
                    + '<td>' + escapeHtml(issue.partner_name || '') + '</td>'
                    + '<td>' + escapeHtml(getIssueText(issue)) + '</td>'
                    + '</tr>';
            }).join('');

            return '<div class="issues-modal-table-wrap" style="margin-top:14px;">'
                + '<table class="issues-modal-table">'
                + '<thead>'
                + '<tr><th colspan="5"><center>Partner Issues - ' + issues.length + ' Data row(s) found</center></th></tr>'
                + '<tr><th colspan="2">OWNER</th><th colspan="2">FROM EXCEL</th><th rowspan="2">Issue</th></tr>'
                + '<tr><th>Partner ID</th><th>Partner Name</th><th>Partner ID</th><th>Partner Name</th></tr>'
                + '</thead>'
                + '<tbody>' + rows + '</tbody>'
                + '</table>'
                + '</div>';
        }

        function buildIssuesTable(fileData) {
            const issues = Array.isArray(fileData && fileData.issues) ? fileData.issues : [];
            const subtitle = '<div style="margin:-4px 0 14px; color:#6c757d; font-size:14px;"><i>Filename: ' + escapeHtml(fileData && fileData.filename ? fileData.filename : '') + '</i></div>';
            if (!issues.length) {
                return subtitle + '<div style="padding:22px; color:#6c757d; font-weight:700;">No missing data found.</div>';
            }

            const branchIssues = issues.filter(function(issue) { return !isPartnerIssue(issue); });
            const partnerIssues = issues.filter(isPartnerIssue);
            return subtitle + buildBranchIssuesTable(branchIssues) + buildPartnerIssuesTable(partnerIssues);
        }

        const overrideColumns = [
            { label: 'Source of Data', key: 'source_of_data' },
            { label: 'Report Date', key: 'report_date' },
            { label: 'Source Type', key: 'source_type' },
            { label: 'Status', key: 'status' },
            { label: 'Date Time', key: 'datetime' },
            { label: 'Cancellation Date', key: 'cancellation_date' },
            { label: 'Control Number', key: 'control_no' },
            { label: 'Reference Number', key: 'reference_no' },
            { label: 'Payor Name', key: 'payor_name' },
            { label: 'Address', key: 'address' },
            { label: 'Account Number', key: 'account_no' },
            { label: 'Account Name', key: 'account_name' },
            { label: 'Amount Paid', key: 'amount_paid', amount: true },
            { label: 'Charge to Customer', key: 'charge_customer', amount: true },
            { label: 'Charge to Partner', key: 'charge_partner', amount: true },
            { label: 'Contact Number', key: 'contact_no' },
            { label: 'Other Details', key: 'other_details' },
            { label: 'Branch ID', key: 'branch_id' },
            { label: 'Branch Code', key: 'branch_code' },
            { label: 'Branch Outlet', key: 'branch_outlet' },
            { label: 'Zone Code', key: 'zone_code' },
            { label: 'Region Code', key: 'region_code' },
            { label: 'Region Name', key: 'region_name' },
            { label: 'Operator', key: 'operator' },
            { label: 'Remote Branch', key: 'remote_branch' },
            { label: 'Remote Operator', key: 'remote_operator' },
            { label: 'Second Approver', key: '2nd_approver' },
            { label: 'Partner Name', key: 'partner_name' },
            { label: 'KP7 Partner ID', key: 'partner_id' },
            { label: 'KPX Partner ID', key: 'partner_id_kpx' },
            { label: 'GL Code', key: 'gl_code' },
            { label: 'CAD Status', key: 'post_transaction' },
            { label: 'Imported Date', key: 'imported_date' },
            { label: 'Imported By', key: 'imported_by' }
        ];
        const nonExistingOverrideColumns = overrideColumns.filter(function(column) {
            return column.key !== 'source_of_data';
        });

        function formatOverrideCell(row, column) {
            const value = row && row[column.key] !== undefined && row[column.key] !== null ? row[column.key] : '';
            if (column.key === 'datetime' || column.key === 'cancellation_date') {
                return escapeHtml(String(value).replace(/\s*(AM|PM)\s*$/i, '').trim());
            }

            if (!column.amount || String(value).trim() === '') {
                return escapeHtml(value);
            }

            const numeric = toNumber(value);
            return escapeHtml(numeric.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }));
        }

        function normalizeOverrideCompareValue(row, column) {
            const value = row && row[column.key] !== undefined && row[column.key] !== null ? row[column.key] : '';
            if (column.key === 'source_of_data') return '';
            if (column.amount) return String(toNumber(value));
            if (column.key === 'datetime' || column.key === 'cancellation_date') {
                const cleanValue = String(value)
                    .replace(/\s*(AM|PM)\s*$/i, '')
                    .replace(/\s+/g, ' ')
                    .trim();
                const match = cleanValue.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
                if (match) {
                    const hour = Number(match[2]);
                    const compareHour = hour % 12;
                    return match[1] + ' ' + String(compareHour).padStart(2, '0') + ':' + match[3] + ':' + (match[4] || '00');
                }

                return cleanValue.toUpperCase();
            }

            return String(value)
                .replace(/\s+/g, ' ')
                .trim()
                .toUpperCase();
        }

        function getOverrideMismatchKeys(databaseRow, excelRow) {
            const keys = {};

            overrideColumns.forEach(function(column) {
                if (column.key === 'source_of_data') return;
                if (normalizeOverrideCompareValue(databaseRow, column) !== normalizeOverrideCompareValue(excelRow, column)) {
                    keys[column.key] = true;
                }
            });

            return keys;
        }

        function getOverrideRecords(fileData) {
            const overrides = Array.isArray(fileData && fileData.overrides) ? fileData.overrides : [];
            const records = [];

            overrides.forEach(function(override) {
                const excelRow = override && override.excel_row ? override.excel_row : null;
                const databaseRows = Array.isArray(override && override.database_rows) ? override.database_rows : [];

                databaseRows.forEach(function(databaseRow, databaseIndex) {
                    const mismatchKeys = excelRow ? getOverrideMismatchKeys(databaseRow, excelRow) : {};
                    const pairId = 'override-pair-' + records.length + '-' + databaseIndex;

                    records.push({
                        data: databaseRow,
                        source: 'database',
                        pairId: pairId,
                        mismatchKeys: mismatchKeys
                    });

                    if (excelRow) {
                        records.push({
                            data: excelRow,
                            source: 'excel',
                            pairId: pairId,
                            mismatchKeys: mismatchKeys
                        });
                    }
                });
            });

            return records;
        }

        function getOverrideExistingKeys(fileData) {
            const keys = {};
            const overrides = Array.isArray(fileData && fileData.overrides) ? fileData.overrides : [];

            overrides.forEach(function(override) {
                const excelRow = override && override.excel_row ? override.excel_row : null;
                if (!excelRow) return;

                const reportDate = String(excelRow.report_date || '').trim();
                const referenceNo = String(excelRow.reference_no || '').trim();
                if (reportDate !== '' || referenceNo !== '') {
                    keys[reportDate + '|' + referenceNo] = true;
                }
            });

            return keys;
        }

        function getNonExistingOverrideRecords(fileData) {
            const rows = Array.isArray(fileData && fileData.rows) ? fileData.rows : [];
            const existingKeys = getOverrideExistingKeys(fileData);

            return rows.filter(function(row) {
                const reportDate = String(row && row.report_date ? row.report_date : '').trim();
                const referenceNo = String(row && row.reference_no ? row.reference_no : '').trim();
                return !existingKeys[reportDate + '|' + referenceNo];
            });
        }

        function buildOverrideRows(records, page, pageSize) {
            const start = page * pageSize;
            return records.slice(start, start + pageSize).map(function(record) {
                const row = record && record.data ? record.data : {};
                const pairId = record && record.pairId ? record.pairId : '';
                return '<tr' + (pairId ? ' data-pair-id="' + escapeHtml(pairId) + '"' : '') + '>' + overrideColumns.map(function(column) {
                    const cellClasses = [];
                    if (column.amount) cellClasses.push('amount-cell');
                    if (record && record.mismatchKeys && record.mismatchKeys[column.key]) {
                        cellClasses.push(record.source === 'database' ? 'mismatch-database' : 'mismatch-excel');
                    }

                    return '<td' + (cellClasses.length ? ' class="' + cellClasses.join(' ') + '"' : '') + '>' + formatOverrideCell(row, column) + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }

        function buildNonExistingOverrideRows(records, page, pageSize) {
            const start = page * pageSize;
            return records.slice(start, start + pageSize).map(function(row) {
                return '<tr class="override-single-hover">' + nonExistingOverrideColumns.map(function(column) {
                    const cellClasses = [];
                    if (column.amount) cellClasses.push('amount-cell');
                    return '<td' + (cellClasses.length ? ' class="' + cellClasses.join(' ') + '"' : '') + '>' + formatOverrideCell(row, column) + '</td>';
                }).join('') + '</tr>';
            }).join('');
        }

        function bindOverridePairHover() {
            document.querySelectorAll('#overrideTableBody tr[data-pair-id]').forEach(function(row) {
                row.addEventListener('mouseenter', function() {
                    const pairId = row.getAttribute('data-pair-id');
                    document.querySelectorAll('#overrideTableBody tr[data-pair-id="' + pairId + '"]').forEach(function(pairRow) {
                        pairRow.classList.add('override-pair-hover');
                    });
                });

                row.addEventListener('mouseleave', function() {
                    const pairId = row.getAttribute('data-pair-id');
                    document.querySelectorAll('#overrideTableBody tr[data-pair-id="' + pairId + '"]').forEach(function(pairRow) {
                        pairRow.classList.remove('override-pair-hover');
                    });
                });
            });
        }

        function updateOverridePager(records, page, pageSize, mode) {
            const pageCount = Math.max(1, Math.ceil(records.length / pageSize));
            const start = records.length ? (page * pageSize) + 1 : 0;
            const end = Math.min(records.length, (page + 1) * pageSize);
            const body = document.getElementById('overrideTableBody');
            const range = document.getElementById('overridePageRange');
            const pageLabel = document.getElementById('overridePageLabel');
            const prev = document.getElementById('overridePrevPage');
            const next = document.getElementById('overrideNextPage');

            if (body) {
                body.innerHTML = mode === 'non-existing'
                    ? buildNonExistingOverrideRows(records, page, pageSize)
                    : buildOverrideRows(records, page, pageSize);
                if (mode !== 'non-existing') bindOverridePairHover();
            }
            if (range) range.textContent = 'Showing ' + start.toLocaleString('en-US') + '-' + end.toLocaleString('en-US') + ' of ' + records.length.toLocaleString('en-US') + ' row(s)';
            if (pageLabel) pageLabel.textContent = 'Page ' + (page + 1).toLocaleString('en-US') + ' of ' + pageCount.toLocaleString('en-US');
            if (prev) prev.disabled = page <= 0;
            if (next) next.disabled = page >= pageCount - 1;
        }

        function buildOverrideDataTable(fileData, mode) {
            const isNonExisting = mode === 'non-existing';
            const records = isNonExisting ? getNonExistingOverrideRecords(fileData) : getOverrideRecords(fileData);
            const pageSize = 20;

            if (!records.length) {
                return '<div style="padding:22px; color:#6c757d; font-weight:700;">No ' + (isNonExisting ? 'non-existing' : 'existing') + ' override data found.</div>';
            }

            const columns = isNonExisting ? nonExistingOverrideColumns : overrideColumns;
            const headers = columns.map(function(column) {
                return '<th>' + escapeHtml(column.label) + '</th>';
            }).join('');

            return '<div class="override-modal-tools">'
                + '<span id="overridePageRange">Showing 1-' + Math.min(pageSize, records.length).toLocaleString('en-US') + ' of ' + records.length.toLocaleString('en-US') + ' row(s)</span>'
                + '<span class="override-modal-pager">'
                + '<button type="button" id="overridePrevPage" disabled>Previous</button>'
                + '<span id="overridePageLabel">Page 1 of ' + Math.max(1, Math.ceil(records.length / pageSize)).toLocaleString('en-US') + '</span>'
                + '<button type="button" id="overrideNextPage"' + (records.length <= pageSize ? ' disabled' : '') + '>Next</button>'
                + '</span>'
                + '</div>'
                + '<div class="override-modal-table-wrap">'
                + '<table class="override-modal-table">'
                + '<thead><tr>' + headers + '</tr></thead>'
                + '<tbody id="overrideTableBody">' + (isNonExisting ? buildNonExistingOverrideRows(records, 0, pageSize) : buildOverrideRows(records, 0, pageSize)) + '</tbody>'
                + '</table>'
                + '</div>';
        }

        function buildOverrideDetectedTable(fileData) {
            const subtitle = '<div style="margin:-4px 0 14px; color:#6c757d; font-size:14px;"><i>Filename: ' + escapeHtml(fileData && fileData.filename ? fileData.filename : '') + '</i></div>';

            return subtitle
                + '<div class="override-mode-toggle">'
                + '<label><input type="radio" name="overrideDataMode" value="existing" checked> Existed Data</label>'
                + '<label><input type="radio" name="overrideDataMode" value="non-existing"> Non-existing Data</label>'
                + '</div>'
                + '<div id="overrideDataHost">' + buildOverrideDataTable(fileData, 'existing') + '</div>';
        }

        function bindOverridePager(fileData, mode) {
            const records = mode === 'non-existing' ? getNonExistingOverrideRecords(fileData) : getOverrideRecords(fileData);
            const pageSize = 20;
            let page = 0;
            const prev = document.getElementById('overridePrevPage');
            const next = document.getElementById('overrideNextPage');

            if (prev) {
                prev.addEventListener('click', function() {
                    if (page <= 0) return;
                    page--;
                    updateOverridePager(records, page, pageSize, mode);
                });
            }

            if (next) {
                next.addEventListener('click', function() {
                    if (page >= Math.ceil(records.length / pageSize) - 1) return;
                    page++;
                    updateOverridePager(records, page, pageSize, mode);
                });
            }

            if (mode !== 'non-existing') bindOverridePairHover();
        }

        function bindOverrideModeControls(fileData) {
            document.querySelectorAll('input[name="overrideDataMode"]').forEach(function(input) {
                input.addEventListener('change', function() {
                    if (!input.checked) return;
                    const mode = input.value;
                    const host = document.getElementById('overrideDataHost');
                    if (!host) return;
                    host.innerHTML = buildOverrideDataTable(fileData, mode);
                    bindOverridePager(fileData, mode);
                });
            });

            bindOverridePager(fileData, 'existing');
        }

        function safePdfFilename(filename) {
            const baseName = String(filename || 'missing-data')
                .replace(/\.[^/.]+$/, '')
                .replace(/[^a-zA-Z0-9._-]/g, '_');
            return 'missing_data_' + (baseName || 'file') + '.pdf';
        }

        function exportMissingDataPdf(fileData) {
            const jsPDFRef = window.jspdf && window.jspdf.jsPDF ? window.jspdf.jsPDF : null;
            if (!jsPDFRef) {
                Swal.fire({
                    icon: 'error',
                    title: 'PDF Export Error',
                    text: 'PDF library is not available.'
                });
                return;
            }

            const issues = Array.isArray(fileData && fileData.issues) ? fileData.issues : [];
            const branchIssues = issues.filter(function(issue) { return !isPartnerIssue(issue); });
            const partnerIssues = issues.filter(isPartnerIssue);
            const doc = new jsPDFRef({ orientation: 'portrait', unit: 'pt', format: 'a4' });
            const pageWidth = doc.internal.pageSize.getWidth();
            let y = 36;

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(16);
            doc.text('Missing Data Detected', pageWidth / 2, y, { align: 'center' });
            y += 20;

            doc.setFont('helvetica', 'italic');
            doc.setFontSize(10);
            doc.text('Filename: ' + String(fileData && fileData.filename ? fileData.filename : ''), 40, y);
            y += 18;

            if (!issues.length) {
                doc.setFont('helvetica', 'normal');
                doc.text('No missing data found.', 40, y);
                doc.save(safePdfFilename(fileData && fileData.filename));
                return;
            }

            if (branchIssues.length) {
                doc.autoTable({
                    startY: y,
                    head: [
                        [{ content: 'Branch Issues - ' + branchIssues.length + ' Data row(s) found', colSpan: 4, styles: { halign: 'center' } }],
                        ['Excel Row', 'Outlet', 'Issue', 'Value']
                    ],
                    body: branchIssues.map(function(issue) {
                        return [
                            String(issue.row || ''),
                            String(issue.branch_outlet || ''),
                            getIssueText(issue),
                            formatIssueValue(issue)
                        ];
                    }),
                    theme: 'grid',
                    tableWidth: 'auto',
                    margin: { left: 40, right: 40 },
                    styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
                    headStyles: { fillColor: [220, 53, 69], textColor: [255, 255, 255], fontStyle: 'bold' },
                    columnStyles: {
                        0: { cellWidth: 'auto' },
                        1: { cellWidth: 'auto' },
                        2: { cellWidth: 'auto' },
                        3: { cellWidth: 'auto' }
                    }
                });
                y = doc.lastAutoTable.finalY + 18;
            }

            if (partnerIssues.length) {
                doc.autoTable({
                    startY: y,
                    head: [
                        [{ content: 'Partner Issues - ' + partnerIssues.length + ' Data row(s) found', colSpan: 5, styles: { halign: 'center' } }],
                        [
                            { content: 'OWNER', colSpan: 2, styles: { halign: 'center' } },
                            { content: 'FROM EXCEL', colSpan: 2, styles: { halign: 'center' } },
                            { content: 'Issue', rowSpan: 2, styles: { halign: 'center', valign: 'middle' } }
                        ],
                        ['Partner ID', 'Partner Name', 'Partner ID', 'Partner Name']
                    ],
                    body: partnerIssues.map(function(issue) {
                        const excelPartnerId = getPartnerIssueId(issue, false);
                        const ownerPartnerId = getPartnerIssueId(issue, true);
                        return [
                            ownerPartnerId,
                            String(issue.owner_partner_name || ''),
                            excelPartnerId,
                            String(issue.partner_name || ''),
                            getIssueText(issue)
                        ];
                    }),
                    theme: 'grid',
                    tableWidth: 'auto',
                    margin: { left: 40, right: 40 },
                    styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
                    headStyles: { fillColor: [220, 53, 69], textColor: [255, 255, 255], fontStyle: 'bold' },
                    columnStyles: {
                        0: { cellWidth: 'auto' },
                        1: { cellWidth: 'auto' },
                        2: { cellWidth: 'auto' },
                        3: { cellWidth: 'auto' },
                        4: { cellWidth: 'auto' }
                    }
                });
            }

            doc.save(safePdfFilename(fileData && fileData.filename));
        }

        function exportAllErrorsPdf() {
            const jsPDFRef = window.jspdf && window.jspdf.jsPDF ? window.jspdf.jsPDF : null;
            if (!jsPDFRef) {
                Swal.fire({
                    icon: 'error',
                    title: 'PDF Export Error',
                    text: 'PDF library is not available.'
                });
                return;
            }

            const filesWithIssues = validationFiles.filter(function(fileData) {
                return Array.isArray(fileData.issues) && fileData.issues.length > 0;
            });

            if (!filesWithIssues.length) {
                Swal.fire({
                    icon: 'info',
                    title: 'No Errors Detected',
                    text: 'There are no detected errors to export.'
                });
                return;
            }

            const doc = new jsPDFRef({ orientation: 'portrait', unit: 'pt', format: 'a4' });
            const pageWidth = doc.internal.pageSize.getWidth();
            let y = 36;

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(16);
            doc.text('All Error Detected', pageWidth / 2, y, { align: 'center' });
            y += 24;

            filesWithIssues.forEach(function(fileData, fileIndex) {
                if (fileIndex > 0) {
                    doc.addPage();
                    y = 36;
                }

                doc.setFont('helvetica', 'italic');
                doc.setFontSize(10);
                doc.text('Filename: ' + String(fileData.filename || ''), 40, y);
                y += 18;

                const branchIssues = fileData.issues.filter(function(issue) { return !isPartnerIssue(issue); });
                const partnerIssues = fileData.issues.filter(isPartnerIssue);

                if (branchIssues.length) {
                    doc.autoTable({
                        startY: y,
                        head: [
                            [{ content: 'Branch Issues - ' + branchIssues.length + ' Data row(s) found', colSpan: 4, styles: { halign: 'center' } }],
                            ['Excel Row', 'Outlet', 'Issue', 'Value']
                        ],
                        body: branchIssues.map(function(issue) {
                            return [
                                String(issue.row || ''),
                                String(issue.branch_outlet || ''),
                                getIssueText(issue),
                                formatIssueValue(issue)
                            ];
                        }),
                        theme: 'grid',
                        tableWidth: 'auto',
                        margin: { left: 40, right: 40 },
                        styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
                        headStyles: { fillColor: [220, 53, 69], textColor: [255, 255, 255], fontStyle: 'bold' },
                        columnStyles: {
                            0: { cellWidth: 'auto' },
                            1: { cellWidth: 'auto' },
                            2: { cellWidth: 'auto' },
                            3: { cellWidth: 'auto' }
                        }
                    });
                    y = doc.lastAutoTable.finalY + 18;
                }

                if (partnerIssues.length) {
                    doc.autoTable({
                        startY: y,
                        head: [
                            [{ content: 'Partner Issues - ' + partnerIssues.length + ' Data row(s) found', colSpan: 5, styles: { halign: 'center' } }],
                            [
                                { content: 'OWNER', colSpan: 2, styles: { halign: 'center' } },
                                { content: 'FROM EXCEL', colSpan: 2, styles: { halign: 'center' } },
                                { content: 'Issue', rowSpan: 2, styles: { halign: 'center', valign: 'middle' } }
                            ],
                            ['Partner ID', 'Partner Name', 'Partner ID', 'Partner Name']
                        ],
                        body: partnerIssues.map(function(issue) {
                            const excelPartnerId = getPartnerIssueId(issue, false);
                            const ownerPartnerId = getPartnerIssueId(issue, true);
                            return [
                                ownerPartnerId,
                                String(issue.owner_partner_name || ''),
                                excelPartnerId,
                                String(issue.partner_name || ''),
                                getIssueText(issue)
                            ];
                        }),
                        theme: 'grid',
                        tableWidth: 'auto',
                        margin: { left: 40, right: 40 },
                        styles: { fontSize: 8, cellPadding: 4, overflow: 'linebreak' },
                        headStyles: { fillColor: [220, 53, 69], textColor: [255, 255, 255], fontStyle: 'bold' },
                        columnStyles: {
                            0: { cellWidth: 'auto' },
                            1: { cellWidth: 'auto' },
                            2: { cellWidth: 'auto' },
                            3: { cellWidth: 'auto' },
                            4: { cellWidth: 'auto' }
                        }
                    });
                    y = doc.lastAutoTable.finalY + 18;
                }
            });

            doc.save('all_error_detected.pdf');
        }

        function toNumber(value) {
            const numeric = Number(String(value ?? '').replace(/,/g, '').trim());
            return Number.isNaN(numeric) ? 0 : numeric;
        }

        function formatInteger(value) {
            return Math.trunc(Number(value) || 0).toLocaleString('en-US', {
                maximumFractionDigits: 0
            });
        }

        function formatPeso(value) {
            return '₱ ' + (Number(value) || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function calculateTransactionSummary(fileData) {
            const rows = Array.isArray(fileData && fileData.rows) ? fileData.rows : [];
            const summary = {
                total: { count: 0, principal: 0, charge: 0, partner: 0, customer: 0 },
                cancelled: { count: 0, principal: 0, charge: 0, partner: 0, customer: 0 },
                net: { count: 0, principal: 0, charge: 0, partner: 0, customer: 0 }
            };

            rows.forEach(function(row) {
                const amountPaid = toNumber(row.amount_paid);
                const chargePartner = toNumber(row.charge_partner);
                const chargeCustomer = toNumber(row.charge_customer);
                const totalCharge = chargePartner + chargeCustomer;
                const isCancelled = String(row.status || '').trim() === '*';

                if (isCancelled) {
                    summary.cancelled.count++;
                    summary.cancelled.principal += Math.abs(amountPaid);
                    summary.cancelled.charge += Math.abs(totalCharge);
                    summary.cancelled.partner += Math.abs(chargePartner);
                    summary.cancelled.customer += Math.abs(chargeCustomer);
                } else {
                    summary.total.count++;
                    summary.total.principal += amountPaid;
                    summary.total.charge += totalCharge;
                    summary.total.partner += chargePartner;
                    summary.total.customer += chargeCustomer;
                }
            });

            summary.net.count = summary.total.count - summary.cancelled.count;
            summary.net.principal = summary.total.principal - summary.cancelled.principal;
            summary.net.charge = summary.total.charge - summary.cancelled.charge;
            summary.net.partner = summary.total.partner - summary.cancelled.partner;
            summary.net.customer = summary.total.customer - summary.cancelled.customer;
            summary.settlement = {
                amount: summary.net.principal - summary.net.charge
            };

            return summary;
        }

        function summaryCell(iconClass, label, value) {
            return '<div class="summary-item">'
                + '<span class="summary-label"><i class="' + iconClass + '"></i>' + escapeHtml(label) + '</span>'
                + '<span class="summary-value">' + value + '</span>'
                + '</div>';
        }

        function buildTransactionSummaryModal(fileData) {
            const summary = calculateTransactionSummary(fileData);
            const columns = [summary.total, summary.cancelled, summary.net];
            const rows = [
                { label: 'TOTAL COUNT', icon: 'fa-solid fa-calculator summary-icon count', key: 'count', format: formatInteger },
                { label: 'TOTAL PRINCIPAL', icon: 'fa-solid fa-money-bill-wave summary-icon principal', key: 'principal', format: formatPeso },
                { label: 'TOTAL CHARGE', icon: 'fa-solid fa-receipt summary-icon charge', key: 'charge', format: formatPeso },
                { label: 'CHARGE TO PARTNER', icon: 'fa-solid fa-building-columns summary-icon partner', key: 'partner', format: formatPeso },
                { label: 'CHARGE TO CUSTOMER', icon: 'fa-solid fa-user summary-icon customer', key: 'customer', format: formatPeso }
            ];

            const bodyRows = rows.map(function(row) {
                return '<tr>'
                    + columns.map(function(col) {
                        return '<td>' + summaryCell(row.icon, row.label, row.format(col[row.key])) + '</td>';
                    }).join('')
                    + '</tr>';
            }).join('');

            return '<div class="summary-box">'
                + '<div class="summary-title"><i class="fa-solid fa-chart-line"></i> Transaction Summary</div>'
                + '<div class="summary-content">'
                + '<table class="summary-table">'
                + '<thead><tr><th>SUMMARY</th><th>CANCELLED TRANSACTIONS</th><th>NET</th></tr></thead>'
                + '<tbody>' + bodyRows + '</tbody>'
                + '<tfoot><tr><th colspan="2">SETTLEMENT AMOUNT:</th><th>' + formatPeso(summary.settlement.amount) + '</th></tr></tfoot>'
                + '</table>'
                + '<div class="summary-filename"><i>Filename: ' + escapeHtml(fileData && fileData.filename ? fileData.filename : '') + '</i></div>'
                + '</div>'
                + '</div>';
        }

        document.querySelectorAll('.card-action.issue').forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const index = Number(button.getAttribute('data-index'));
                const fileData = validationFiles[index] || {};
                Swal.fire({
                    title: 'Missing Data Detected',
                    html: buildIssuesTable(fileData),
                    customClass: { popup: 'issues-modal-popup' },
                    showCancelButton: true,
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    confirmButtonText: 'Export PDF Format',
                    cancelButtonText: 'Close',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d'
                }).then(function(result) {
                    if (result.isConfirmed) {
                        exportMissingDataPdf(fileData);
                    }
                });
            });
        });

        document.querySelectorAll('.card-action.override').forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const index = Number(button.getAttribute('data-index'));
                const fileData = validationFiles[index] || {};
                Swal.fire({
                    title: 'Override Detected',
                    html: buildOverrideDetectedTable(fileData),
                    customClass: { popup: 'override-modal-popup' },
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    didOpen: function() {
                        bindOverrideModeControls(fileData);
                    },
                    confirmButtonText: 'Close',
                    confirmButtonColor: '#6c757d'
                });
            });
        });

        const exportAllErrorsBtn = document.getElementById('exportAllErrorsBtn');
        if (exportAllErrorsBtn) {
            exportAllErrorsBtn.addEventListener('click', function(event) {
                event.preventDefault();
                exportAllErrorsPdf();
            });
        }

        const importFilesBtn = document.getElementById('importFilesBtn');
        if (importFilesBtn) {
            importFilesBtn.addEventListener('click', function(event) {
                event.preventDefault();
                const importableFiles = validationFiles.filter(function(fileData) {
                    const status = String(fileData && fileData.status ? fileData.status : '').toLowerCase();
                    return status === 'valid' || status === 'override';
                });
                const totalFiles = importableFiles.length;

                if (!totalFiles) {
                    Swal.fire({
                        icon: 'info',
                        title: 'No Importable File',
                        text: 'Only Valid and Override card files can be imported.',
                        confirmButtonColor: '#6c757d'
                    });
                    return;
                }

                Swal.fire({
                    title: 'Importing...',
                    html: '<div class="progress" style="height:16px; background:#e9ecef; border-radius:5px; overflow:hidden;">'
                        + '<div id="importProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="height:100%; width:0%; background:#198754;"></div>'
                        + '</div>'
                        + '<div id="importProgressText" style="margin-top:14px; text-align:left; font-weight:700;">0/' + totalFiles.toLocaleString('en-US') + '</div>',
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Hide',
                    cancelButtonColor: '#6c757d',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    didOpen: function() {
                        Swal.showLoading();
                    }
                });

                fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'import' })
                })
                    .then(function(response) {
                        return response.json().then(function(data) {
                            if (!response.ok || !data.success) {
                                throw new Error(data && data.error ? data.error : 'Import failed.');
                            }
                            return data;
                        });
                    })
                    .then(function(data) {
                        const progressBar = document.getElementById('importProgressBar');
                        const progressText = document.getElementById('importProgressText');
                        if (progressBar) progressBar.style.width = '100%';
                        if (progressText) progressText.textContent = totalFiles.toLocaleString('en-US') + '/' + totalFiles.toLocaleString('en-US');

                        const summary = data.summary || {};
                        Swal.fire({
                            icon: 'success',
                            title: 'Import Complete',
                            html: '<div style="text-align:left; font-weight:700; line-height:1.8;">'
                                + 'Files Imported: ' + escapeHtml(formatInteger(summary.files || 0)) + '<br>'
                                + 'Rows Processed: ' + escapeHtml(formatInteger(summary.rows || 0)) + '<br>'
                                + 'Inserted: ' + escapeHtml(formatInteger(summary.inserted || 0)) + '<br>'
                                + 'Updated: ' + escapeHtml(formatInteger(summary.updated || 0)) + '<br>'
                                + 'Skipped: ' + escapeHtml(formatInteger(summary.skipped || 0))
                                + '</div>',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            allowEnterKey: false,
                            confirmButtonText: 'Back to Importer',
                            confirmButtonColor: '#198754'
                        }).then(function() {
                            window.location.href = data.redirect || '../../dashboard/billspayment/import/billspay-transaction.php';
                        });
                    })
                    .catch(function(error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Import Failed',
                            text: error.message || 'Unable to import file data.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            allowEnterKey: false,
                            confirmButtonColor: '#dc3545'
                        });
                    });
                });
        }

        document.querySelectorAll('.card-action.summary').forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const index = Number(button.getAttribute('data-index'));
                const fileData = validationFiles[index] || {};
                Swal.fire({
                    html: buildTransactionSummaryModal(fileData),
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    allowEnterKey: false,
                    showConfirmButton: true,
                    confirmButtonText: 'Close',
                    confirmButtonColor: '#6c757d',
                    customClass: { popup: 'summary-modal-popup' }
                });
            });
        });
    </script>
</body>
</html>
