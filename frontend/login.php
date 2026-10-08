<?php
require_once __DIR__ . '/includes/session.php';
appSessionStart();

// Redirection si deja connecte
if (isset($_SESSION[APP_SESSION_LOGIN_KEY])) {
    header('location: index.php');
    exit;
}

require_once __DIR__ . '/config/ldap.php';

$error = '';

function SignUpWithLDAP($username, $password) {
    $connect = ldap_connect(LDAP_SERVER, 389);
    if (!$connect) return false;
    if (!ldap_set_option($connect, LDAP_OPT_PROTOCOL_VERSION, 3)) return false;
    if (!ldap_set_option($connect, LDAP_OPT_REFERRALS, 0)) return false;
    $bind = ldap_bind($connect, LDAP_DOMAIN_PREFIX . "\\" . $username, $password);
    return $bind ? "OK" : "KO";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = isset($_POST['login'])    ? trim($_POST['login'])    : '';
    $pass = isset($_POST['password']) ? trim($_POST['password']) : '';

    if (!empty($user) && !empty($pass)) {
        $r = SignUpWithLDAP($user, $pass);
        if ($r === "OK") {
            $_SESSION[APP_SESSION_LOGIN_KEY] = $user;
            header('location: index.php');
            exit;
        } else {
            $error = 'Login ou mot de passe incorrects.';
        }
    } else {
        $error = 'Veuillez renseigner tous les champs.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>e-Config - Connexion</title>
    <link rel="shortcut icon" href="addons/images/orange_ico.png" />

    <link href="vendors/bootstrap/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="vendors/font-awesome/css/font-awesome.min.css" rel="stylesheet">
    <link href="build/css/custom.min.css" rel="stylesheet">
    <link href="build/css/econfig-redesign.css" rel="stylesheet">
</head>
<body class="login">
<div>
    <div class="login_wrapper">
        <div class="login_hero">
            <div class="brand">
                <img src="addons/images/orange_2.png" alt="Orange">
                <span>e-Config</span>
            </div>
            <div class="hero-body">
                <div class="hero-rule"></div>
                <h2>Configurez vos offres, simplement.</h2>
                <p>Créez et consultez les offres Mobile, FTTH et 4G depuis un seul espace.</p>
            </div>
            <div class="hero-legend">
                <span><i></i>Mobile</span>
                <span><i></i>FTTH</span>
                <span><i></i>4G</span>
            </div>
        </div>

        <div class="animate form login_form">
            <section class="login_content">
                <form method="POST" action="login.php">
                    <h1>Connexion</h1>
                    <p class="text-muted">Utilisez votre identifiant Orange (CUID).</p>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger" style="margin-bottom:15px;">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <div>
                        <label class="ec-field-label" for="login">Identifiant</label>
                        <input type="text"
                               class="form-control"
                               id="login"
                               name="login"
                               placeholder="Ex : ABCD1234"
                               value="<?php echo isset($_POST['login']) ? htmlspecialchars($_POST['login']) : ''; ?>"
                               required>
                    </div>
                    <div>
                        <label class="ec-field-label" for="password">Mot de passe</label>
                        <input type="password"
                               class="form-control"
                               id="password"
                               name="password"
                               placeholder="Votre mot de passe"
                               required>
                    </div>
                    <div>
                        <button type="submit" class="btn btn-default submit">Se connecter</button>
                    </div>

                    <div class="clearfix"></div>

                    <div class="separator">
                        <p class="change_link">
                            &copy; <?php echo date('Y'); ?> Configuration des Offres
                        </p>
                        <div class="clearfix"></div>
                    </div>
                </form>
            </section>
        </div>
    </div>
</div>
</body>
</html>
