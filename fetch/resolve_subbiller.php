<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

$input = $_POST['sub_billers_name'] ?? '';
$input2 = $_POST['sub_billers_name_2'] ?? $input;
if (empty($input)) {
    echo json_encode(['success' => false, 'error' => 'Missing sub_billers_name']);
    exit;
}

try {
    $sql = "WITH
        direct_biller AS (
            SELECT partner_id, partner_id_kpx, gl_code, partner_name AS direct_billers_name, NULL AS sub_billers_name, status
            FROM masterdata.partner_masterfile
        ),
        sub_biller AS (
            SELECT partner_id_kpx, sub_billers_id, partner_name AS direct_billers_name, sub_billers_name, NULL AS sub_gl_code
            FROM masterdata.subbiller
        ),
        merged_left AS (
            SELECT
                d.partner_id,
                COALESCE(d.partner_id_kpx, s.partner_id_kpx) AS partner_id_kpx,
                s.sub_billers_id,
                COALESCE(d.gl_code, s.sub_gl_code) AS gl_code,
                CASE WHEN d.direct_billers_name = s.sub_billers_name THEN s.direct_billers_name ELSE d.direct_billers_name END AS direct_billers_name,
                COALESCE(s.sub_billers_name, d.sub_billers_name) AS sub_billers_name
            FROM direct_biller d
            LEFT JOIN sub_biller s
                ON d.direct_billers_name = s.sub_billers_name
            WHERE COALESCE(d.status, '') = 'ACTIVE'
        ),
        unmatched_sub AS (
            SELECT
                NULL AS partner_id,
                s.partner_id_kpx AS partner_id_kpx,
                s.sub_billers_id,
                s.sub_gl_code AS gl_code,
                s.direct_billers_name AS direct_billers_name,
                s.sub_billers_name AS sub_billers_name
            FROM sub_biller s
            WHERE NOT EXISTS (
                SELECT 1 FROM direct_biller d WHERE d.direct_billers_name = s.sub_billers_name AND COALESCE(d.status,'') = 'ACTIVE'
            )
        )
        SELECT t.partner_id, t.sub_billers_id, t.gl_code, t.direct_billers_name, t.sub_billers_name
        FROM (
            SELECT * FROM merged_left WHERE sub_billers_name = ?
            UNION ALL
            SELECT * FROM unmatched_sub WHERE sub_billers_name = ?
        ) t LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $input, $input2);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        echo json_encode(['success' => true, 'sub_billers_id' => $row['sub_billers_id'], 'partner_id' => $row['partner_id'] ?? null]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Not found']);
    }
    $stmt->close();
    exit;
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
