<?php
/**
 * POST /recharges/create_recharge.php
 *
 * Crée un type de recharge et ses configurations tarifaires.
 *
 * Étapes :
 *   1. Vérification unicité RECHARGE_TYPE dans alcatel.recharge_types
 *   2. INSERT alcatel.recharge_types
 *   3. INSERT alcatel.recharge_config × N (un par montant, DUREE auto-incrémenté de 1 à N)
 *
 * Corps JSON requis :
 *   recharge_type (string) — libellé unique du type de recharge (ex: "4G Home Flybox Prep")
 *   vsdate        (string) — date de version dd-mm-yyyy (ex: "01-01-2022")
 *   montants      (array)  — liste des montants (float > 0), ordonnés par durée croissante
 *                            ex: [8474.58, 14406.78, 21186.44, 25423.73, 38135.59]
 *                            DUREE est affecté automatiquement : 1, 2, 3, ...
 *
 * Valeurs fixes :
 *   VOLUME      = 'NULL' (chaîne littérale)
 *   VSCODE      = 1
 *   TMCODE      = NULL
 *   DESCRIPTION = NULL
 *
 * Réponse :
 *   {
 *     "success": true,
 *     "recharge_type": "4G Home Flybox Prep",
 *     "lignes_config": 5,
 *     "message": "Recharge parametree avec succes"
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
requireFields($body, ['recharge_type', 'vsdate', 'montants']);

$rechargeType = trim($body['recharge_type']);
$vsdate       = trim($body['vsdate']);
$montants     = $body['montants'];

if ($rechargeType === '') {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "recharge_type" ne peut pas etre vide']);
}
if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $vsdate)) {
    sendResponse(400, ['success' => false, 'error' => 'Format vsdate invalide. Utilisez dd-mm-yyyy (ex: 01-01-2022)']);
}
if (!is_array($montants) || empty($montants)) {
    sendResponse(400, ['success' => false, 'error' => 'Le champ "montants" doit etre un tableau non vide']);
}
foreach ($montants as $i => $m) {
    if (!is_numeric($m) || (float) $m <= 0) {
        sendResponse(400, ['success' => false, 'error' => 'montants[' . $i . '] doit etre un nombre positif']);
    }
}

try {
    $conn = getConnection();

    // Vérification unicité RECHARGE_TYPE
    $stmtChk = oci_parse($conn, 'SELECT COUNT(*) FROM alcatel.recharge_types WHERE RECHARGE_TYPE = :rt');
    if (!$stmtChk) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse verification RECHARGE_TYPE : ' . $err['message']);
    }
    oci_bind_by_name($stmtChk, ':rt', $rechargeType);
    oci_execute($stmtChk, OCI_NO_AUTO_COMMIT);
    $rowChk = oci_fetch_array($stmtChk, OCI_NUM);
    oci_free_statement($stmtChk);

    if ((int) $rowChk[0] > 0) {
        oci_close($conn);
        sendResponse(409, ['success' => false,
            'error' => 'Un type de recharge avec ce libelle existe deja : ' . $rechargeType]);
    }

    // ── alcatel.recharge_types ────────────────────────────────────────────────
    $stmtRt = oci_parse($conn, 'INSERT INTO alcatel.recharge_types (RECHARGE_TYPE) VALUES (:rt)');
    if (!$stmtRt) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse recharge_types : ' . $err['message']);
    }
    oci_bind_by_name($stmtRt, ':rt', $rechargeType);
    if (!oci_execute($stmtRt, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmtRt);
        oci_free_statement($stmtRt);
        throw new Exception('Erreur INSERT recharge_types : ' . $err['message']);
    }
    oci_free_statement($stmtRt);

    // ── alcatel.recharge_config — N lignes ────────────────────────────────────
    $sqlCfg = "INSERT INTO alcatel.recharge_config (
                   RECHARGE_TYPE, DUREE, VOLUME, MONTANT, VSCODE, VSDATE, TMCODE, DESCRIPTION
               ) VALUES (
                   :rt, :duree, 'NULL', :montant, 1, TO_DATE(:vsdate, 'DD-MM-YYYY'), NULL, NULL
               )";

    $count = 0;
    foreach ($montants as $i => $m) {
        $duree   = $i + 1;
        $montant = number_format((float) $m, 2, '.', '');

        $stmt = oci_parse($conn, $sqlCfg);
        if (!$stmt) {
            $err = oci_error($conn);
            throw new Exception('Erreur parse recharge_config : ' . $err['message']);
        }
        oci_bind_by_name($stmt, ':rt',      $rechargeType);
        oci_bind_by_name($stmt, ':duree',   $duree,   -1, SQLT_INT);
        oci_bind_by_name($stmt, ':montant', $montant);
        oci_bind_by_name($stmt, ':vsdate',  $vsdate);

        if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
            $err = oci_error($stmt);
            oci_free_statement($stmt);
            throw new Exception('Erreur INSERT recharge_config (DUREE ' . $duree . ') : ' . $err['message']);
        }
        oci_free_statement($stmt);
        $count++;
    }

    oci_commit($conn);
    oci_close($conn);

    sendResponse(201, [
        'success'       => true,
        'recharge_type' => $rechargeType,
        'lignes_config' => $count,
        'message'       => 'Recharge parametree avec succes',
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        oci_rollback($conn);
        oci_close($conn);
    }
    sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
}
