(function() {
    var offreTable = null;

    // Rempli par la page (postpaye.php / prepaye.php) avant le chargement de ce script :
    //   FTTH_OFFER_CONFIG.extrnlRateplanId (int)
    //   FTTH_OFFER_CONFIG.dureeFixed       (string|null) — si null, la duree est lue sur #duree
    var cfg = window.FTTH_OFFER_CONFIG || {};

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    function tomorrowDateStr() {
        var d = new Date();
        d.setDate(d.getDate() + 1);
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function tomorrowDatetimeLocalStr() {
        var now = new Date();
        return tomorrowDateStr() + 'T' + pad2(now.getHours()) + ':' + pad2(now.getMinutes());
    }

    function findParam(parametres, prmDes) {
        var found = (parametres || []).filter(function(p) { return p.prm_des === prmDes; })[0];
        return found ? found.valeur : '';
    }

    function toggleCesarFields() {
        var checked = document.getElementById('cesar').checked;
        document.getElementById('cesar-fields').style.display = checked ? '' : 'none';
        ['cesar_des', 'cesar_accessfee', 'cesar_prm_value_string'].forEach(function(id) {
            document.getElementById(id).required = checked;
        });
    }

    function renderCreateResult(result) {
        var el = document.getElementById('create-result');
        if (result.ok && result.data.success) {
            var etapesRows = Object.keys(result.data.etapes || {}).map(function(k) {
                return '<tr><td>' + escapeHtml(k) + '</td><td>' + escapeHtml(result.data.etapes[k]) + '</td></tr>';
            }).join('');
            el.innerHTML =
                '<div class="alert alert-success">' + escapeHtml(result.data.message || 'Offre creee avec succes') +
                ' — TMCODE : <strong>' + escapeHtml(result.data.tmcode) + '</strong>' +
                ' — PV_COMBI_ID : <strong>' + escapeHtml(result.data.pv_combi_id) + '</strong></div>' +
                '<table class="table table-bordered"><thead><tr><th>Etape</th><th>Resultat</th></tr></thead>' +
                '<tbody>' + etapesRows + '</tbody></table>';
        } else {
            el.innerHTML = '<div class="alert alert-danger">' +
                escapeHtml((result.data && result.data.error) || 'Erreur inconnue') + '</div>';
        }
    }

    function setOffreTableData(pricings) {
        var rows = pricings.map(function(p) {
            var premierParam = (p.parametres || [])[0] || {};
            return {
                set_id: p.set_id,
                des: p.des,
                accessfee: premierParam.accessfee !== undefined ? premierParam.accessfee : '',
                debit: findParam(p.parametres, '02_Debit'),
                volume: findParam(p.parametres, '05_Volume'),
                duree: findParam(p.parametres, '06_Duree')
            };
        });

        if (offreTable) {
            offreTable.destroy();
        }
        offreTable = $('#resultat-table').DataTable({
            data: rows,
            columns: [
                { data: 'set_id' },
                { data: 'des' },
                { data: 'accessfee' },
                { data: 'debit' },
                { data: 'volume' },
                { data: 'duree' }
            ],
            paging: true,
            searching: true,
            ordering: true,
            info: true,
            language: {
                sSearch: 'Rechercher :',
                sLengthMenu: 'Afficher _MENU_ elements',
                sInfo: 'Affichage de _START_ a _END_ sur _TOTAL_ elements',
                sInfoEmpty: 'Aucun element',
                sZeroRecords: 'Aucun element a afficher',
                oPaginate: { sFirst: 'Premier', sPrevious: 'Precedent', sNext: 'Suivant', sLast: 'Dernier' }
            }
        });
    }

    function renderSearchResult(result) {
        var summaryEl = document.getElementById('search-summary');
        if (result.ok && result.data.success) {
            var offre = result.data.offre;
            summaryEl.innerHTML = '<p><strong>' + escapeHtml(offre.tmcode) + '</strong> — ' +
                escapeHtml(offre.des) + ' (' + escapeHtml(offre.shdes) + ')</p>';
            setOffreTableData(offre.pricings || []);
        } else {
            summaryEl.innerHTML = '<div class="alert alert-warning">' +
                escapeHtml((result.data && result.data.error) || 'Offre introuvable') + '</div>';
            setOffreTableData([]);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('vsdate').value = tomorrowDateStr();
        document.getElementById('apdate').value = tomorrowDatetimeLocalStr();

        setOffreTableData([]);

        if (window.jQuery && $.fn.iCheck) {
            $('#cesar').iCheck({ checkboxClass: 'icheckbox_flat-green' });
            $('#cesar').on('ifChanged', toggleCesarFields);
        }
        document.getElementById('cesar').addEventListener('change', toggleCesarFields);
        toggleCesarFields();

        document.getElementById('form-offre-ftth').addEventListener('submit', function(e) {
            e.preventDefault();

            var cesarChecked = document.getElementById('cesar').checked;
            var duree = cfg.dureeFixed || document.getElementById('duree').value;

            var pricingDes = document.getElementById('pricing_des').value.trim();
            if (pricingDes === '') {
                pricingDes = document.getElementById('formule').value.trim();
            }

            var payload = {
                des: document.getElementById('des').value.trim(),
                shdes: document.getElementById('shdes').value.trim(),
                des_fr: document.getElementById('des_fr').value,
                vsdate: toDdMmYyyy(document.getElementById('vsdate').value),
                apdate: toDdMmYyyyHms(document.getElementById('apdate').value),
                extrnl_rateplan_id: cfg.extrnlRateplanId,
                formule: document.getElementById('formule').value.trim(),
                debit: document.getElementById('debit').value.trim(),
                debit_montant: document.getElementById('debit_montant').value.trim(),
                debit_descendant: document.getElementById('debit_descendant').value.trim(),
                volume: document.getElementById('volume').value.trim(),
                pricings: [{
                    set_id: 1,
                    des: pricingDes,
                    accessfee: parseFloat(document.getElementById('accessfee').value),
                    duree: duree
                }],
                cesar: cesarChecked ? 1 : 0
            };

            if (cesarChecked) {
                payload.cesar_des = document.getElementById('cesar_des').value.trim();
                payload.cesar_accessfee = parseFloat(document.getElementById('cesar_accessfee').value);
                payload.cesar_prm_value_string = document.getElementById('cesar_prm_value_string').value.trim();
            }

            var submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creation en cours...';

            callApi('ftth/create_offre.php', 'POST', payload)
                .then(renderCreateResult)
                .catch(function(err) {
                    document.getElementById('create-result').innerHTML =
                        '<div class="alert alert-danger">Erreur reseau : ' + escapeHtml(err.message) + '</div>';
                })
                .then(function() {
                    submitBtn.disabled = false;
                    submitBtn.textContent = "Creer l'offre";
                });
        });

        document.getElementById('btn-search-offre').addEventListener('click', function() {
            var tmcode = document.getElementById('search-tmcode').value.trim();
            var shdes = document.getElementById('search-shdes').value.trim();

            if (!tmcode && !shdes) {
                alert('Renseignez un TMCODE ou un SHDES.');
                return;
            }

            var params = tmcode ? { tmcode: tmcode } : { shdes: shdes };

            callApi('ftth/get_offer.php', 'GET', params)
                .then(renderSearchResult)
                .catch(function(err) {
                    document.getElementById('search-summary').innerHTML =
                        '<div class="alert alert-danger">Erreur reseau : ' + escapeHtml(err.message) + '</div>';
                });
        });
    });
})();
