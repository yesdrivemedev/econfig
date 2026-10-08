<?php
/**
 * POST /recharges/link_rateplan.php
 *
 * Associe un type de recharge à un plan tarifaire dans alcatel.wimax_config.
 * DES = RECHARGE_TYPE (même valeur pour les deux colonnes).
 *
 * Corps JSON requis :
 *   tmcode        (int)    — TMCODE du plan tarifaire (ex: 1119)
 *   recharge_type (string) — libellé du type de recharge, doit exister dans alcatel.recharge_types
 *                            (ex: "4G Home Flybox Prep")
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "tmcode": 1119,
 *     "recharge_type": "4G Home Flybox Prep",
 *     "message": "Association recharge-rateplan creee avec succes"
 *   }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(405, ['success' => false, 'error' => 'Methode non autorisee. Utilisez POST.']);
}

authenticate();

$body = getRequestBody();
requireFields($body, ['tmcode', 'recharge_type']);

$tmcode       = (int) $body['tmcode'];
$rechargeType = trim($body['recharge_type']);

if ($rechargeType === '') {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "recharge_type" ne peut pas etre vide']);
}

try {
    $conn = getConnection();

    // Vérification que le RECHARGE_TYPE existe
    $stmtChk = oci_parse($conn, 'SELECT COUNT(*) FROM alcatel.recharge_types WHERE RECHARGE_TYPE = :rt');
    if (!$stmtChk) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse verification RECHARGE_TYPE : ' . $err['message']);
    }
    oci_bind_by_name($stmtChk, ':rt', $rechargeType);
    oci_execute($stmtChk, OCI_NO_AUTO_COMMIT);
    $rowChk = oci_fetch_array($stmtChk, OCI_NUM);
    oci_free_statement($stmtChk);

    if ((int) $rowChk[0] === 0) {
        oci_close($conn);
        sendResponse(404, ['success' => false,
            'error' => 'Type de recharge introuvable : ' . $rechargeType]);
    }

    // ── alcatel.wimax_config ──────────────────────────────────────────────────
    $stmt = oci_parse($conn,
        'INSERT INTO alcatel.wimax_config (TMCODE, DES, RECHARGE_TYPE)
         VALUES (:tmcode, :recharge_type, :recharge_type2)');
    if (!$stmt) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse wimax_config : ' . $err['message']);
    }
    oci_bind_by_name($stmt, ':tmcode',        $tmcode,       -1, SQLT_INT);
    oci_bind_by_name($stmt, ':recharge_type',  $rechargeType);
    oci_bind_by_name($stmt, ':recharge_type2', $rechargeType);

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmt);
        oci_free_statement($stmt);
        throw new Exception('Erreur INSERT wimax_config : ' . $err['message']);
    }
    oci_free_statement($stmt);

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'       => true,
        'tmcode'        => $tmcode,
        'recharge_type' => $rechargeType,
        'message'       => 'Association recharge-rateplan creee avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
