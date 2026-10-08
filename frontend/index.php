<?php
$basePath = '';
require_once __DIR__ . '/includes/auth_check.php';
$pageTitle = 'Accueil';
$activeLink = '';
require_once __DIR__ . '/includes/layout_header.php';

// Descriptions courtes par lien, pour la grille de la page d'accueil.
// Purement presentatif : la disponibilite reelle reste pilotee par
// includes/menu.php (enabled/link), jamais codee en dur ici.
$cardDescriptions = [
    'mobile/hybride.php' => 'Consulter et créer des offres avec leurs formules.',
    'ftth/postpaye.php'  => "Débit, volume inclus et montant de l'abonnement.",
    'ftth/prepaye.php'   => 'Débits, volume inclus et montant de recharge.',
];

$availableCards = [];
$comingSoon = [];
foreach ($menu as $category) {
    foreach ($category['items'] as $item) {
        if ($item['enabled']) {
            $availableCards[] = [
                'category' => $category['label'],
                'icon'     => $category['icon'],
                'label'    => $item['label'],
                'link'     => $item['link'],
                'desc'     => isset($cardDescriptions[$item['link']]) ? $cardDescriptions[$item['link']] : 'Consulter et créer des offres.',
            ];
        } else {
            $comingSoon[] = $category['label'] . ' ' . $item['label'];
        }
    }
}
?>
<h1 class="ec-page-title">Bonjour <?php echo htmlspecialchars($currentUser); ?></h1>
<p class="ec-page-lead">Choisissez une technologie et un type d'offre pour commencer. Les autres combinaisons seront activées à mesure que leurs API seront prêtes.</p>

<div class="ec-card-grid">
    <?php foreach ($availableCards as $card): ?>
    <div class="ec-card accent">
        <div class="ec-card-top-row">
            <i class="fa <?php echo htmlspecialchars($card['icon']); ?>" style="font-size:20px;" aria-hidden="true"></i>
            <span class="ec-card-chip dark">Disponible</span>
        </div>
        <div class="ec-card-eyebrow"><?php echo htmlspecialchars(strtoupper($card['category'])); ?></div>
        <h3><?php echo htmlspecialchars($card['label']); ?></h3>
        <p><?php echo htmlspecialchars($card['desc']); ?></p>
        <a class="ec-card-link" href="<?php echo htmlspecialchars($card['link']); ?>">Ouvrir &rarr;</a>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($comingSoon)): ?>
    <div class="ec-card">
        <div class="ec-card-top-row">
            <i class="fa fa-clock-o" style="font-size:20px;" aria-hidden="true"></i>
            <span class="ec-card-chip muted">Bientôt disponible</span>
        </div>
        <div class="ec-card-eyebrow">A VENIR</div>
        <h3>Autres offres</h3>
        <p><?php echo htmlspecialchars(implode(', ', $comingSoon)); ?> arrivent avec leurs API.</p>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/layout_footer.php'; ?>
