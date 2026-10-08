<?php
// Chrome commun (topbar + sidebar) partage par toutes les pages.
// Attendu avant inclusion : $basePath (chemin relatif vers la racine de frontend/).
// Optionnels : $pageTitle, $activeLink (lien du sous-item courant, tel que dans menu.php),
// $extraStyles (tableau de feuilles de style relatives a frontend/, chargees en plus du socle commun).
if (!isset($basePath))    { $basePath = ''; }
if (!isset($pageTitle))   { $pageTitle = ''; }
if (!isset($activeLink))  { $activeLink = ''; }
if (!isset($extraStyles)) { $extraStyles = []; }

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/menu.php';
$menu = getMenu();
$currentUser = isset($_SESSION[APP_SESSION_LOGIN_KEY]) ? $_SESSION[APP_SESSION_LOGIN_KEY] : '';
$userInitials = $currentUser !== '' ? strtoupper(substr($currentUser, 0, 2)) : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e-Config<?php echo $pageTitle !== '' ? ' - ' . htmlspecialchars($pageTitle) : ''; ?></title>
    <link rel="shortcut icon" href="<?php echo $basePath; ?>addons/images/orange_ico.png" />

    <link href="<?php echo $basePath; ?>vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo $basePath; ?>vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="<?php echo $basePath; ?>vendors/nprogress/nprogress.css" rel="stylesheet">
    <link href="<?php echo $basePath; ?>vendors/iCheck/skins/flat/green.css" rel="stylesheet">
    <?php foreach ($extraStyles as $style): ?>
    <link href="<?php echo $basePath . htmlspecialchars($style); ?>" rel="stylesheet">
    <?php endforeach; ?>
    <link href="<?php echo $basePath; ?>build/css/custom.min.css" rel="stylesheet">
    <link href="<?php echo $basePath; ?>build/css/econfig-redesign.css" rel="stylesheet">
</head>

<body class="nav-md">
<div class="ec-shell">

    <div class="ec-topbar">
        <a href="<?php echo $basePath; ?>index.php" class="ec-topbar-brand">
            <img src="<?php echo $basePath; ?>addons/images/orange_2.png" alt="Orange">
            <span>e-Config</span>
        </a>
        <div class="ec-topbar-user">
            <div class="ec-topbar-avatar"><?php echo htmlspecialchars($userInitials); ?></div>
            <span><?php echo htmlspecialchars($currentUser); ?></span>
            <a class="ec-topbar-logout" href="<?php echo $basePath; ?>logout.php" title="Deconnexion">
                <span class="glyphicon glyphicon-off" aria-hidden="true"></span>
            </a>
        </div>
    </div>

    <div class="ec-shell-body">
        <div class="ec-sidebar">
            <a class="ec-sidebar-item home<?php echo ($activeLink === '') ? ' active' : ''; ?>" href="<?php echo $basePath; ?>index.php">
                Accueil
            </a>
            <?php foreach ($menu as $category): ?>
            <div class="ec-sidebar-cat"><?php echo htmlspecialchars(strtoupper($category['label'])); ?></div>
            <?php foreach ($category['items'] as $item): ?>
                <?php if ($item['enabled']): ?>
                <a class="ec-sidebar-item<?php echo ($activeLink === $item['link']) ? ' active' : ''; ?>"
                   href="<?php echo $basePath . htmlspecialchars($item['link']); ?>">
                    <?php echo htmlspecialchars($item['label']); ?>
                </a>
                <?php else: ?>
                <span class="ec-sidebar-item disabled">
                    <?php echo htmlspecialchars($item['label']); ?>
                    <span class="ec-badge-soon">Bientot</span>
                </span>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>

        <div class="ec-content">
            <div class="ec-breadcrumb"><?php echo $pageTitle !== '' ? htmlspecialchars(str_replace(' — ', ' > ', $pageTitle)) : 'Accueil'; ?></div>
