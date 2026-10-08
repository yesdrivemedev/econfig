<?php
/**
 * Source unique du sidemenu : categorie > sous-items (type d'offre).
 * 'link' est un chemin relatif a la racine de frontend/, ou null si pas encore construit.
 * Activer une offre plus tard = renseigner 'link' et passer 'enabled' a true.
 */
function getMenu()
{
    return [
        [
            'label' => 'Mobile',
            'icon'  => 'fa-mobile',
            'items' => [
                ['label' => 'Hybride',   'link' => 'mobile/hybride.php', 'enabled' => true],
                ['label' => 'Postpayee', 'link' => null, 'enabled' => false],
                ['label' => 'Prepayee',  'link' => null, 'enabled' => false],
            ],
        ],
        [
            'label' => 'FTTH',
            'icon'  => 'fa-wifi',
            'items' => [
                ['label' => 'Postpayee', 'link' => 'ftth/postpaye.php', 'enabled' => true],
                ['label' => 'Prepayee',  'link' => 'ftth/prepaye.php',  'enabled' => true],
            ],
        ],
        [
            'label' => '4G',
            'icon'  => 'fa-signal',
            'items' => [
                ['label' => 'Hybride',  'link' => null, 'enabled' => false],
                ['label' => 'Prepayee', 'link' => null, 'enabled' => false],
            ],
        ],
    ];
}
