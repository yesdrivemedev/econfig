<?php
$basePath = '../';
require_once __DIR__ . '/../includes/auth_check.php';
$pageTitle = 'Mobile — Hybride';
$activeLink = 'mobile/hybride.php';
$extraStyles = ['vendors/datatables.net-bs/css/dataTables.bootstrap.min.css'];
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
    .formule-row { border: 1px solid #dee2e6; border-radius: 6px; padding: 12px; margin-bottom: 10px; }
</style>

<h1 class="ec-page-title">Offres Mobile Hybride</h1>

<!-- Consultation d'une offre existante -->
<section class="ec-panel mb-5" style="background: #ffffff; padding: 24px; margin-bottom: 24px;">
    <h3>Consulter une offre existante</h3>
    <div class="row">
        <div class="col-md-3 form-group">
            <label class="control-label" for="search-tmcode">TMCODE</label>
            <input type="number" class="form-control" id="search-tmcode" placeholder="Ex: 861">
        </div>
        <div class="col-md-3 form-group">
            <label class="control-label" for="search-shdes">SHDES</label>
            <input type="text" class="form-control" id="search-shdes" placeholder="Ex: MOBC">
        </div>
        <div class="col-md-3 form-group" style="padding-top: 25px;">
            <button type="button" class="btn btn-primary" id="btn-search-offre">Rechercher</button>
        </div>
    </div>
    <div id="search-summary"></div>
    <table id="resultat-table" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>SPCODE</th>
                <th>Formule</th>
                <th>SHDES</th>
                <th>Prix (centimes)</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>

<!-- Creation d'une offre Hybride -->
<section class="ec-panel" style="background: #ffffff; padding: 24px; margin-bottom: 24px;">
    <h3>Creer une offre Hybride</h3>
    <form id="form-offre-hybride">
        <div class="row">
            <div class="col-md-6 form-group">
                <label class="control-label" for="des">Description longue</label>
                <input type="text" class="form-control" id="des" required placeholder="Ex: Mobile Confort">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="shdes">Description courte (SHDES)</label>
                <input type="text" class="form-control" id="shdes" required placeholder="Ex: MOBC">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="des_fr">Segment marche</label>
                <select class="form-control" id="des_fr" required>
                    <option value="B2C">B2C</option>
                    <option value="B2B">B2B</option>
                    <option value="TEST">TEST</option>
                </select>
            </div>
            <div class="col-md-4 form-group">
                <label class="control-label" for="vsdate">Date de version</label>
                <input type="date" class="form-control" id="vsdate" required>
            </div>
            <div class="col-md-4 form-group">
                <label class="control-label" for="apdate">Date d'application</label>
                <input type="datetime-local" class="form-control" id="apdate" required>
            </div>
            <div class="col-md-4 form-group">
                <label class="control-label">&nbsp;</label>
                <div class="checkbox">
                    <label>
                        <input type="checkbox" id="add_options" checked>
                        Inserer les options par defaut
                    </label>
                </div>
            </div>
        </div>

        <hr>

        <h4>Formules</h4>
        <div id="formules-container"></div>
        <button type="button" class="btn btn-default btn-sm" id="btn-add-formule">
            + Ajouter une formule
        </button>

        <br><br>

        <button type="submit" class="btn btn-success">Creer l'offre</button>
    </form>

    <div id="create-result" class="mt-4"></div>
</section>
<?php
$extraScripts = [
    'vendors/datatables.net/js/jquery.dataTables.min.js',
    'vendors/datatables.net-bs/js/dataTables.bootstrap.min.js',
    'addons/js/mobile.js',
];
require_once __DIR__ . '/../includes/layout_footer.php';
?>
