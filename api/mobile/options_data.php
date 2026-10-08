<?php
/**
 * Données statiques pour les options par défaut d'un rateplan mobile.
 * Utilisé par create_rateplan.php quand add_options=1.
 * 48 TSP + 90 TM1 + 29 TM2 — même transaction que le rateplan.
 */

function getMobileOptionsData() {
    return [
        'tsp' => _tspRows(),
        'tm1' => _tm1Rows(),
        'tm2' => _tm2Rows(),
    ];
}

// ── TARIFF_SERVICE_PACKAGE ────────────────────────────────────────────────────
// [spcode, service_package_def]
function _tspRows() {
    $rows = [
        [725,null],[1665,null],[1254,null],[1664,null],[1200,null],[1250,null],
        [1395,null],[1484,null],[1134,null],[1244,null],[958,null],[8,null],
        [1501,null],[1253,null],[1428,null],[1245,null],[1663,null],[1135,null],
        [426,null],[1350,null],[1248,null],[1396,null],[1343,null],[1667,null],
        [1666,null],[21,null],[1514,null],[1351,null],[1551,null],[1247,null],
        [1,null],[1508,null],[1668,null],[1240,null],[115,null],[1500,null],
        [1572,null],[1947,null],[1136,null],[858,'X'],[1693,null],[52,null],
        [1077,null],[1252,null],[1251,null],[1074,null],[1075,null],[1246,null],
    ];
    $sql = [];
    foreach ($rows as $r) {
        $spd = $r[1] === null ? 'null' : "'" . $r[1] . "'";
        $sql[] = "INSERT INTO TARIFF_SERVICE_PACKAGE"
               . " (TMCODE, SPCODE, TMVSCODE, SERVICE_PACKAGE_DEF, ATOMIC_SERVICE_PACKAGE)"
               . " VALUES (:tmcode, {$r[0]}, 0, $spd, null)";
    }
    return $sql;
}

// ── MPULKTM1 ─────────────────────────────────────────────────────────────────
// Per-row: [spcode,sncode,subscript,accessfee,event,echind,amtind,frqind,srvind,
//           proind,advind,susind,acccat,acccode,acctype,usgcat,usgcode,usgtype,
//           subcat,subcode,subtype,itype,itvl,pv,prm,prtsub,prtacc,recver,
//           defpayusg,initcredit,ovwptype,ovwplen,srvprio,deftypectrl,subgl,accgl,usggl]
function _tm1Rows() {
    $C1 = 'Ca1 20%'; $C2 = 'Ca2 20%';
    $D1 = 'Cd1 20%'; $D2 = 'Cd2 20%';
    $A  = 'ALL';

    $rows = [
        //spcode,sncode,sub, acc,   evt,  ech,  amt,  frq,  srv, pro,  adv,  sus,  acccat,acccode,acctype, usgcat,usgcode,usgtype, subcat,subcode,subtype, ity,ivl, pv, prm,psub,pacc,rv,dpu,init, ovwpt,ovwpl,sp,dtc,sgl, agl,  ugl
        [1134, 839, 0,  2118,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [958,  745, 0,  0,     null,null,null,null,'P','N','P','N', $C2,$D2,$A, null,null,null, null,$D1,$A, 'M',1,null,'N','N','N',1,3,null,null,null,745,null,1233,1233,1418],
        [426,  496, 0,  1694,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','Y',1,3,null,'M',null,496,null,1225,1225,1135],
        [21,   7,   0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,7,  2,   1233,1215,1357],
        [21,   18,  0,  0,     null,null,null,null,'P','Y','A','N', $C2,$D2,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,18, 2,   1233,1262,1135],
        [1351, 839, 0,  42373, null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1551, 839, 0,  247729,null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1508, 839, 0,  24793, null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1668, 839, 0,  6611,  null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1572, 839, 0,  123967,null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1693, 1056,0,  0,     null,null,null,'P', 'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,1056,null,1,  1,   null],
        [52,   55,  0,  0,     null,null,null,null,'P','Y','A','N', $C2,$D2,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,55, 2,   1233,1215,1135],
        [52,   54,  0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,54, 2,   1,   1,   1   ],
        [1074, 827, 0,  0,     null,null,null,null,'P','N','P','Y', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'Y','Y','Y',1,1,null,null,null,null,2,  1134,1185,null],
        [1664, 839, 0,  2479,  null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1200, 855, 0,  0,     null,null,null,null,'P','Y','A','N', $C2,$D2,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,0,  2,   1,   1,   1   ],
        [1484, 839, 0,  165289,null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1501, 839, 0,  33058, null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1253, 249, 0,  12712, null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1253, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [1663, 839, 0,  1653,  null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1249, 249, 0,  4237,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1249, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [1947, 1169,0,  200,   null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',4,3,0,   'M',null,0,  null,1,   1,   null],
        [1246, 875, 0,  4237,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1254, 249, 0,  25424, null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1254, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [1244, 874, 0,  0,     null,null,null,'P', 'P','N','A','Y', $C2,$D2,$A, null,null,null, $C1,$D1,$A, 'M',6,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1428, 839, 0,  21186, null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1245, 875, 0,  4237,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1135, 839, 0,  4237,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1248, 249, 0,  2542,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1248, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [1396, 839, 0,  12712, null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,0,  null,1,   1,   null],
        [1343, 900, 0,  0,     null,null,null,'P', 'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,3370,'Y','N','N',6,3,0,   'M',null,0,  null,1,   1,   null],
        [1667, 839, 0,  5785,  null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1247, 875, 0,  4237,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [115,  129, 0,  0,     null,null,null,null,'P','N','P','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,129,2,   1448,1386,1510],
        [115,  122, 0,  0,     null,null,null,null,'P','N','P','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,122,2,   1443,1381,1505],
        [115,  126, 0,  0,     null,null,null,null,'P','N','P','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,126,2,   1446,1384,1508],
        [115,  130, 0,  0,     null,null,null,null,'P','N','P','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,130,2,   1447,1385,1509],
        [115,  124, 0,  0,     null,null,null,null,'P','N','P','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,124,2,   1485,1423,1361],
        [115,  128, 0,  0,     null,null,null,null,'P','N','P','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,128,2,   1490,1428,1366],
        [115,  125, 0,  0,     null,null,null,null,'P','N','P','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,125,2,   1445,1383,1507],
        [115,  748, 0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,748,null,1,   1,   null],
        [1136, 839, 0,  8474,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1252, 249, 0,  5932,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1252, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [725,  614, 0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,614,2,   1233,1183,1418],
        [725,  613, 0,  0,     null,null,null,null,'P','Y','A','N', $C2,$D2,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,613,2,   1233,1183,1418],
        [1665, 839, 0,  3306,  null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1250, 249, 0,  8474,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1250, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [1395, 839, 0,  82645, null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [8,    16,  0,  847,   null,null,null,null,'P','F','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,16, 2,   1233,1261,1357],
        [8,    12,  0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,12, 2,   1233,1258,1357],
        [8,    15,  0,  847,   null,null,null,null,'P','F','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,15, 2,   1233,1260,1357],
        [8,    19,  0,  4237,  null,null,null,null,'P','F','A','N', $C2,$D2,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,19, 2,   1233,1257,1135],
        [8,    13,  0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,13, 2,   1233,1215,1357],
        [8,    14,  0,  1694,  null,null,null,null,'P','F','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,14, 2,   1233,1259,1357],
        [1350, 839, 0,  16949, null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1666, 839, 0,  4958,  null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1514, 839, 0,  115702,null,null,null,null,'P','N','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        // SPCODE=1, SRVIND='E' (event rows — SUBSCRIPT=null, ACCESSFEE=null)
        [1,    42,  null,null, 8474, 'A','C','O','E',null,null,null,$C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,42, 2,   1128,1270,1135],
        [1,    395, 0,  8474,  null,null,null,'P','P','F','A','N',  $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,395,2,   1233,1186,1135],
        [1,    394, 0,  16949, null,null,null,'P','P','F','A','N',  $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,394,2,   1233,1186,1135],
        [1,    393, 0,  42372, null,null,null,'P','P','F','A','N',  $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,393,2,   1233,1186,1135],
        [1,    869, null,null, 63559,'A','F','P','E',null,null,null,$C1,$D1,$A, null,null,null, null,null,null,'M',1,null,'N','N','N',1,1,null,null,null,0,  null,null,1,   null],
        [1,    911, null,null, 0,   'A','C','O','E',null,null,null, $C1,$D1,$A, null,null,null, null,null,null,'M',1,null,'N','N','N',1,1,null,null,null,0,  null,null,1,   null],
        [1,    868, null,null, 55085,'A','F','P','E',null,null,null,$C1,$D1,$A, null,null,null, null,null,null,'M',1,null,'N','N','N',1,1,null,null,null,0,  null,null,1,   null],
        [1,    867, null,null, 42373,'A','F','P','E',null,null,null,$C1,$D1,$A, null,null,null, null,null,null,'M',1,null,'N','N','N',1,1,null,null,null,0,  null,null,1,   null],
        [1,    866, null,null, 25424,'A','F','P','E',null,null,null,$C1,$D1,$A, null,null,null, null,null,null,'M',1,null,'N','N','N',1,1,null,null,null,0,  null,null,1,   null],
        [1,    60,  null,null, 0,   'A','C','O','E',null,null,null, $C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,60, 2,  1128,1256,1135],
        [1,    35,  null,null, 8474,'A','F','O','E',null,null,null, $C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,35, 2,  1128,1269,1135],
        [1,    20,  null,null, 0,   'A','F','P','E',null,null,null, $C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,20, 2,  1128,1264,1135],
        [1,    23,  null,null, 4237,'A','F','O','E',null,null,null, $C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,23, 2,  1128,1266,1135],
        [1,    24,  null,null, 0,   'A','F','O','E',null,null,null, $C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,24, 2,  1128,1267,1135],
        [1,    46,  null,null, 84745,'A','C','O','E',null,null,null,$C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,46, 2,  1128,1271,1135],
        [1,    32,  null,null, 16949,'A','C','O','E',null,null,null,$C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,32, 2,  1128,1268,1135],
        [1,    392, 0,  84745, null,null,null,'P','P','F','A','N',  $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,null,null,null,392,2,   1233,1186,1135],
        [1,    22,  null,null, 8474,'A','C','O','E',null,null,null, $C2,$D2,$A, null,null,null, null,null,null,null,null,null,'N','N','N',1,1,null,null,null,22, 2,  1128,1265,1135],
        [1500, 249, 0,  7438,  null,null,null,null,'P','N','P','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1500, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [858,  1,   0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,1,  2,   1,   1,   1   ],
        [858,  50,  0,  0,     null,null,null,null,'P','Y','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,1,  2,   1,   1,   1   ],
        [1077, 865, 0,  0,     null,null,null,'P', 'P','Y','A','N', $C2,$D2,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,0,  null,1,   1,   null],
        [1077, 833, 0,  0,     null,null,null,null,'P','N','A','N', $C2,$D2,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'Y','Y','Y',1,1,null,null,null,null,2,  1134,1185,null],
        [1251, 606, 0,  0,     null,null,null,null,'P','N','A','N', $C1,$D1,$A, $C1,$D1,$A,     $C1,$D1,$A, 'M',1,null,'N','N','N',1,1,0,   'M',null,606,2,   1,   1,   1   ],
        [1251, 249, 0,  42373, null,null,null,null,'P','F','A','N', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'N','N','N',1,3,0,   'M',null,249,null,1,   1,   null],
        [1075, 828, 0,  0,     null,null,null,null,'P','N','P','Y', $C1,$D1,$A, null,null,null, $C1,$D1,$A, 'M',1,null,'Y','Y','Y',1,1,null,null,null,null,2,  1134,1185,null],
    ];

    $sql = [];
    $cols = "INSERT INTO MPULKTM1 (TMCODE, VSCODE, VSDATE, STATUS,"
          . " SPCODE, SNCODE, SUBSCRIPT, ACCESSFEE, EVENT, ECHIND, AMTIND, FRQIND, SRVIND,"
          . " PROIND, ADVIND, SUSIND,"
          . " LTCODE, PLCODE, BILLFREQ, FREEDAYS, CSIND, CLCODE,"
          . " ACCSERV_CATCODE, ACCSERV_CODE, ACCSERV_TYPE,"
          . " USGSERV_CATCODE, USGSERV_CODE, USGSERV_TYPE,"
          . " SUBSERV_CATCODE, SUBSERV_CODE, SUBSERV_TYPE,"
          . " DEPOSIT, INTERVAL_TYPE, INTERVAL, PV_COMBI_ID,"
          . " PRM_PRINT_IND, PRINTSUBSCRIND, PRINTACCESSIND,"
          . " REC_VERSION, NON_EXPL_SERV_PREPAID_IND,"
          . " DEF_PAYMENT_COND_USG, DEF_TIME_PACKAGE_USG, INITIAL_CREDIT,"
          . " PAYMENT_COND_CHANGEABLE_USG, BOP_MODE_ID, DEF_PAYMENT_COND_ONETIME_REC,"
          . " OVW_RECUR_AMOUNT, OVW_PERIOD_TYPE, OVW_PERIOD_LENGTH,"
          . " SERVICE_PRIORITY, CHARGING_SCHEDULE, ACCOUNT_ID, DEF_TYPE_OF_CONTROL,"
          . " SUB_GL_ACC_PACK_ID, ACC_GL_ACC_PACK_ID, USG_GL_ACC_PACK_ID,"
          . " GL_PACK_SYSTEM_SCENARIO_FLAG, OFFER_ID, PAM_CONFIG_ID_MAIN, PAM_CONFIG_ID_REC_CHARGE)";

    foreach ($rows as $r) {
        $v = function($x) { return $x === null ? 'null' : (is_string($x) ? "'" . $x . "'" : $x); };
        $vals = ":tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W',"
              . " {$v($r[0])}, {$v($r[1])}, {$v($r[2])}, {$v($r[3])}, {$v($r[4])},"
              . " {$v($r[5])}, {$v($r[6])}, {$v($r[7])}, {$v($r[8])},"
              . " {$v($r[9])}, {$v($r[10])}, {$v($r[11])},"
              . " null, null, null, null, null, null,"
              . " {$v($r[12])}, {$v($r[13])}, {$v($r[14])},"
              . " {$v($r[15])}, {$v($r[16])}, {$v($r[17])},"
              . " {$v($r[18])}, {$v($r[19])}, {$v($r[20])},"
              . " null, {$v($r[21])}, {$v($r[22])}, {$v($r[23])},"
              . " {$v($r[24])}, {$v($r[25])}, {$v($r[26])},"
              . " {$v($r[27])}, null,"
              . " {$v($r[28])}, null, {$v($r[29])},"
              . " null, null, 1,"
              . " null, {$v($r[30])}, {$v($r[31])},"
              . " {$v($r[32])}, 'B', null, {$v($r[33])},"
              . " {$v($r[34])}, {$v($r[35])}, {$v($r[36])},"
              . " null, null, null, null";
        $sql[] = "$cols VALUES ($vals)";
    }
    return $sql;
}

// ── MPULKTM2 ─────────────────────────────────────────────────────────────────
// [spcode, sncode, svlcode, upcode, ricode, usage_type_id]
function _tm2Rows() {
    $rows = [
        [1254,606,'GSMT**B*****S3G*',48,191,4],
        [1248,606,'GSMT**B*****S3G*',48,191,4],
        [21,  7,  'GSMT**B*****S12*',57,253,4],
        [115, 122,'GSMT**BG0***S***',54,250,2],
        [115, 126,'GSMT**BG3***S***',54,250,2],
        [115, 130,'GSMTR3B*****S***',54,250,2],
        [115, 124,'GSMT**BG1***S***',54,250,2],
        [115, 128,'GSMTR1B*****S***',54,250,2],
        [115, 125,'GSMT**BG2***S***',54,250,2],
        [115, 129,'GSMTR2B*****S***',54,250,2],
        [52,  54, 'GSMT**B*****SC1*',57,253,4],
        [1252,606,'GSMT**B*****S3G*',48,191,4],
        [1200,855,'GSMTJ0B*****S***',57,253,2],
        [1250,606,'GSMT**B*****S3G*',48,191,4],
        [8,   16, 'GSMT**B*****S9B*',57,253,4],
        [8,   14, 'GSMT**B*****S9A*',57,253,4],
        [8,   12, 'GSMT**B*****S92*',57,253,4],
        [8,   15, 'GSMT**B*****S94*',57,253,4],
        [8,   13, 'GSMT**B*****S93*',57,253,4],
        [1253,606,'GSMT**B*****S3G*',48,191,4],
        [1,   395,'GSMT**B*****SIT*',48,191,4],
        [1,   394,'GSMT**B*****SIS*',48,191,4],
        [1,   393,'GSMT**B*****SIR*',48,191,4],
        [1,   392,'GSMT**B*****SIQ*',48,191,4],
        [1249,606,'GSMT**B*****S3G*',48,191,4],
        [1500,606,'GSMT**B*****S3G*',48,191,4],
        [858, 50, 'GSMT11B*****S***',57,253,2],
        [858, 1,  'GSMT11B*****S***',57,253,2],
        [1251,606,'GSMT**B*****S3G*',48,191,4],
    ];
    $sql = [];
    $cols = "INSERT INTO MPULKTM2"
          . " (TMCODE, VSCODE, VSDATE, STATUS, SPCODE, SNCODE,"
          . " SVLCODE, RATEIND, UPCODE, RICODE, REC_VERSION, USAGE_TYPE_ID,"
          . " CP_PRICELIST_ID, FALLBACK_SRV_FLAG)";
    foreach ($rows as $r) {
        $sql[] = "$cols VALUES"
               . " (:tmcode, 0, TO_DATE(:vsdate, 'DD-MM-YYYY'), 'W',"
               . " {$r[0]}, {$r[1]}, '{$r[2]}', 'Y', {$r[3]}, {$r[4]}, 1, {$r[5]}, null, null)";
    }
    return $sql;
}
