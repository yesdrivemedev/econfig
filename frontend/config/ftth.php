<?php
// Durees configurables pour les offres FTTH Prepayees.
// Valeur envoyee telle quelle dans pricings[].duree (parametre MPULKPV2 453).
// Postpaye n'apparait pas ici : sa duree est fixe ("Illimite"), geree directement
// dans ftth/postpaye.php.
function getFtthPrepayeeDurees()
{
    return ['1MOIS', '3MOIS', '9MOIS', '12MOIS', '2JOURS', '3JOURS', '7JOURS'];
}
