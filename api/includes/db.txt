<?php
require_once __DIR__ . '/../config/database.php';

/**
 * Ouvre et retourne une connexion OCI8 vers Oracle.
 * Lance une Exception en cas d'échec.
 */
function getConnection()
{
    $dsn  = DB_HOST . ':' . DB_PORT . '/' . DB_SID;
    $conn = oci_connect(DB_USER, DB_PASS, $dsn, DB_CHARSET);

    if (!$conn) {
        $err = oci_error();
        throw new Exception('Connexion Oracle echouee : ' . $err['message']);
    }

    return $conn;
}

/**
 * Prépare et exécute une requête SELECT retournant une seule valeur scalaire.
 * Utile pour les MAX()+1.
 */
function fetchScalar($conn, $sql)
{
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse SQL : ' . $err['message']);
    }

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmt);
        throw new Exception('Erreur execution SQL : ' . $err['message']);
    }

    $row = oci_fetch_array($stmt, OCI_NUM);
    oci_free_statement($stmt);

    return isset($row[0]) ? $row[0] : null;
}

/**
 * Retourne le type OCI approprié selon la valeur PHP.
 * NULL et strings → SQLT_CHR, entiers → SQLT_INT.
 */
function ociType($value)
{
    return is_int($value) ? SQLT_INT : SQLT_CHR;
}

/**
 * Exécute un INSERT avec des variables liées.
 * $binds = [':nom' => valeur, ...]
 *
 * IMPORTANT : on lie chaque élément via $binds[$placeholder] (référence directe
 * à la case du tableau local) et non via une variable de boucle partagée.
 * Avec un foreach(&$value), oci_bind_by_name lierait TOUS les placeholders
 * à la même référence et ils recevraient tous la dernière valeur → ORA-01722.
 */
function executeInsert($conn, $sql, array $binds)
{
    $stmt = oci_parse($conn, $sql);
    if (!$stmt) {
        $err = oci_error($conn);
        throw new Exception('Erreur parse SQL : ' . $err['message']);
    }

    foreach (array_keys($binds) as $placeholder) {
        // Détection du type PHP pour éviter ORA-01722 sur les colonnes NUMBER
        $ociType = is_int($binds[$placeholder]) ? SQLT_INT : SQLT_CHR;
        oci_bind_by_name($stmt, $placeholder, $binds[$placeholder], -1, $ociType);
    }

    if (!oci_execute($stmt, OCI_NO_AUTO_COMMIT)) {
        $err = oci_error($stmt);
        oci_free_statement($stmt);
        throw new Exception('Erreur INSERT : ' . $err['message']);
    }

    oci_free_statement($stmt);
}
