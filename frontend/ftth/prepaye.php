<?php
$basePath = '../';
require_once __DIR__ . '/../includes/auth_check.php';
$pageTitle = 'FTTH — Prepayee';
$activeLink = 'ftth/prepaye.php';
$extraStyles = ['vendors/datatables.net-bs/css/dataTables.bootstrap.min.css'];
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../config/ftth.php';
$durees = getFtthPrepayeeDurees();
?>
<h1 class="ec-page-title">Offres FTTH Prépayée</h1>

<!-- Consultation d'une offre existante -->
<section class="mb-5">
    <h3>Consulter une offre existante</h3>
    <div class="row">
        <div class="col-md-3 form-group">
            <label class="control-label" for="search-tmcode">TMCODE</label>
            <input type="number" class="form-control" id="search-tmcode" placeholder="Ex: 1171">
        </div>
        <div class="col-md-3 form-group">
            <label class="control-label" for="search-shdes">SHDES</label>
            <input type="text" class="form-control" id="search-shdes" placeholder="Ex: Z2C1V">
        </div>
        <div class="col-md-3 form-group" style="padding-top: 25px;">
            <button type="button" class="btn btn-primary" id="btn-search-offre">Rechercher</button>
        </div>
    </div>
    <div id="search-summary"></div>
    <table id="resultat-table" class="table table-striped table-bordered" style="width:100%">
        <thead>
            <tr>
                <th>SET_ID</th>
                <th>Intitule</th>
                <th>Prix</th>
                <th>Debit</th>
                <th>Volume</th>
                <th>Duree</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</section>

<hr>

<!-- Creation d'une offre FTTH Prepayee -->
<section>
    <h3>Creer une offre FTTH Prepayee</h3>
    <form id="form-offre-ftth">
        <div class="row">
            <div class="col-md-6 form-group">
                <label class="control-label" for="des">Description longue</label>
                <input type="text" class="form-control" id="des" required placeholder="Ex: FTTR Confort Prep">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="shdes">Description courte (SHDES)</label>
                <input type="text" class="form-control" id="shdes" required placeholder="Ex: FTTRP">
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
                <label class="control-label" for="duree">Duree</label>
                <select class="form-control" id="duree" required>
                    <?php foreach ($durees as $duree): ?>
                    <option value="<?php echo htmlspecialchars($duree); ?>"><?php echo htmlspecialchars($duree); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <hr>

        <h4>Caracteristiques de l'offre</h4>
        <div class="row">
            <div class="col-md-3 form-group">
                <label class="control-label" for="formule">Intitule commercial (formule)</label>
                <input type="text" class="form-control" id="formule" required placeholder="Ex: FTTR CONFORT PREP">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="debit">Debit</label>
                <input type="text" class="form-control" id="debit" required placeholder="Ex: 50M">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="debit_montant">Debit montant (upload)</label>
                <input type="text" class="form-control" id="debit_montant" required placeholder="Ex: 8M">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="debit_descendant">Debit descendant</label>
                <input type="text" class="form-control" id="debit_descendant" required placeholder="Ex: 50M">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="volume">Volume inclus</label>
                <input type="text" class="form-control" id="volume" required placeholder="Ex: 1.2To">
            </div>
        </div>

        <hr>

        <h4>Pricing</h4>
        <div class="row">
            <div class="col-md-4 form-group">
                <label class="control-label" for="pricing_des">Intitule du pricing</label>
                <input type="text" class="form-control" id="pricing_des" placeholder="Par defaut : meme valeur que la formule">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="accessfee">Montant de la recharge</label>
                <input type="number" step="any" class="form-control" id="accessfee" required placeholder="Ex: 12711.86">
            </div>
        </div>

        <hr>

        <div class="checkbox">
            <label>
                <input type="checkbox" id="cesar">
                Associer la formule Cesar
            </label>
        </div>
        <div id="cesar-fields" class="row">
            <div class="col-md-4 form-group">
                <label class="control-label" for="cesar_des">Libelle du pricing Cesar</label>
                <input type="text" class="form-control" id="cesar_des" placeholder="Ex: FTTH CESAR">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="cesar_accessfee">Montant Cesar</label>
                <input type="number" step="any" class="form-control" id="cesar_accessfee" placeholder="Ex: 33058">
            </div>
            <div class="col-md-3 form-group">
                <label class="control-label" for="cesar_prm_value_string">Valeur du parametre 479</label>
                <input type="text" class="form-control" id="cesar_prm_value_string" placeholder="Ex: CESAR_VALUE">
            </div>
        </div>

        <br>
        <button type="submit" class="btn btn-success">Creer l'offre</button>
    </form>

    <div id="create-result" class="mt-4"></div>
</section>
<?php
$extraScripts = [
    'vendors/datatables.net/js/jquery.dataTables.min.js',
    'vendors/datatables.net-bs/js/dataTables.bootstrap.min.js',
    'addons/js/ftth.js',
];
?>
<script>
    var FTTH_OFFER_CONFIG = {
        extrnlRateplanId: 6,
        dureeFixed: null
    };
</script>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
