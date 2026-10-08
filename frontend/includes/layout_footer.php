<?php
// Attendu avant inclusion : $basePath. Optionnel : $extraScripts (tableau de
// chemins relatifs a la racine de frontend/, ex: ['addons/js/mobile.js']).
if (!isset($basePath))     { $basePath = ''; }
if (!isset($extraScripts)) { $extraScripts = []; }
?>
        </div>
        <!-- /ec-content -->
    </div>
    <!-- /ec-shell-body -->

    <footer class="ec-footer">
        e-Config | Configuration des offres
    </footer>
</div>
<!-- /ec-shell -->

<script>
    // Consomme par addons/js/config.js : chemin de proxy.php relatif a la page courante,
    // donc toujours meme origine que la page (pas de souci CORS ni d'IP/port a maintenir).
    var PROXY_URL = <?php echo json_encode($basePath . 'proxy.php'); ?>;
</script>

<!-- jQuery -->
<script src="<?php echo $basePath; ?>vendors/jquery/dist/jquery.min.js"></script>
<!-- Bootstrap -->
<script src="<?php echo $basePath; ?>vendors/bootstrap/dist/js/bootstrap.min.js"></script>
<!-- FastClick -->
<script src="<?php echo $basePath; ?>vendors/fastclick/lib/fastclick.js"></script>
<!-- NProgress -->
<script src="<?php echo $basePath; ?>vendors/nprogress/nprogress.js"></script>
<!-- iCheck -->
<script src="<?php echo $basePath; ?>vendors/iCheck/icheck.min.js"></script>

<script src="<?php echo $basePath; ?>addons/js/config.js"></script>
<?php foreach ($extraScripts as $script): ?>
<script src="<?php echo $basePath . htmlspecialchars($script); ?>"></script>
<?php endforeach; ?>

<!-- Custom Theme Scripts (mecanique sidebar/menu_toggle/tooltips) -->
<script src="<?php echo $basePath; ?>build/js/custom.min.js"></script>
</body>
</html>
