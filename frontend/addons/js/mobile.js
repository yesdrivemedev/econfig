(function() {
    var formuleIndex = 0;
    var offreTable = null;

    function formuleRowTemplate(index) {
        return '' +
            '<div class="formule-row row" data-index="' + index + '">' +
            '  <div class="col-md-5 form-group">' +
            '    <label class="control-label">Nom de la formule</label>' +
            '    <input type="text" class="form-control formule-des" required placeholder="Ex: BR MIX 3">' +
            '  </div>' +
            '  <div class="col-md-3 form-group">' +
            '    <label class="control-label">SHDES (format D_***)</label>' +
            '    <input type="text" class="form-control formule-shdes" required maxlength="5" pattern="^D_.{3}$" placeholder="Ex: D_M11">' +
            '  </div>' +
            '  <div class="col-md-3 form-group">' +
            '    <label class="control-label">Prix (centimes)</label>' +
            '    <input type="number" class="form-control formule-accessfee" required min="0" placeholder="Ex: 2119">' +
            '  </div>' +
            '  <div class="col-md-1 form-group">' +
            '    <label class="control-label">&nbsp;</label>' +
            '    <button type="button" class="btn btn-danger btn-block btn-remove-formule">&times;</button>' +
            '  </div>' +
            '</div>';
    }

    function addFormuleRow() {
        var container = document.getElementById('formules-container');
        var maxFormules = 20;
        if (container.querySelectorAll('.formule-row').length >= maxFormules) {
            alert('Maximum ' + maxFormules + ' formules par offre.');
            return;
        }
        formuleIndex++;
        container.insertAdjacentHTML('beforeend', formuleRowTemplate(formuleIndex));
    }

    function collectFormules() {
        var rows = document.querySelectorAll('#formules-container .formule-row');
        var formules = [];
        rows.forEach(function(row) {
            formules.push({
                des: row.querySelector('.formule-des').value.trim(),
                shdes: row.querySelector('.formule-shdes').value.trim(),
                accessfee: parseInt(row.querySelector('.formule-accessfee').value, 10)
            });
        });
        return formules;
    }

    function renderCreateResult(result) {
        var el = document.getElementById('create-result');
        if (result.ok && result.data.success) {
            var rows = (result.data.formules || []).map(function(f) {
                return '<tr>' +
                    '<td>' + escapeHtml(f.spcode) + '</td>' +
                    '<td>' + escapeHtml(f.des) + '</td>' +
                    '<td>' + escapeHtml(f.shdes) + '</td>' +
                    '</tr>';
            }).join('');
            el.innerHTML =
                '<div class="alert alert-success">' + escapeHtml(result.data.message || 'Offre creee avec succes') +
                ' — TMCODE : <strong>' + escapeHtml(result.data.tmcode) + '</strong></div>' +
                '<table class="table table-bordered">' +
                '<thead><tr><th>SPCODE</th><th>Formule</th><th>SHDES</th></tr></thead>' +
                '<tbody>' + rows + '</tbody></table>';
        } else {
            el.innerHTML = '<div class="alert alert-danger">' +
                escapeHtml((result.data && result.data.error) || 'Erreur inconnue') + '</div>';
        }
    }

    // Remplace les lignes de #resultat-table via DataTables (tri/recherche/pagination).
    function setOffreTableData(formules) {
        if (offreTable) {
            offreTable.destroy();
        }
        offreTable = $('#resultat-table').DataTable({
            data: formules,
            columns: [
                { data: 'spcode' },
                { data: 'des' },
                { data: 'shdes' },
                { data: 'accessfee' },
                { data: 'status' }
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
                escapeHtml(offre.des) + ' (' + escapeHtml(offre.shdes) + ', ' + escapeHtml(offre.des_fr) + ')</p>';
            setOffreTableData(offre.formules || []);
        } else {
            summaryEl.innerHTML = '<div class="alert alert-warning">' +
                escapeHtml((result.data && result.data.error) || 'Offre introuvable') + '</div>';
            setOffreTableData([]);
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Une formule par defaut au chargement
        addFormuleRow();
        setOffreTableData([]);

        if (window.jQuery && $.fn.iCheck) {
            $('#add_options').iCheck({ checkboxClass: 'icheckbox_flat-green' });
        }

        document.getElementById('btn-add-formule').addEventListener('click', addFormuleRow);

        document.getElementById('formules-container').addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-remove-formule')) {
                var rows = document.querySelectorAll('#formules-container .formule-row');
                if (rows.length <= 1) {
                    alert('Au moins une formule est requise.');
                    return;
                }
                e.target.closest('.formule-row').remove();
            }
        });

        document.getElementById('form-offre-hybride').addEventListener('submit', function(e) {
            e.preventDefault();

            var formules = collectFormules();
            if (formules.length === 0) {
                alert('Ajoutez au moins une formule.');
                return;
            }

            var vsdate = document.getElementById('vsdate').value;
            var apdate = document.getElementById('apdate').value;

            var payload = {
                des: document.getElementById('des').value.trim(),
                shdes: document.getElementById('shdes').value.trim(),
                des_fr: document.getElementById('des_fr').value,
                vsdate: toDdMmYyyy(vsdate),
                apdate: toDdMmYyyyHms(apdate),
                extrnl_rateplan_id: EXTRNL_RATEPLAN_ID.HYBRIDE,
                add_options: document.getElementById('add_options').checked ? 1 : 0,
                formules: formules
            };

            var submitBtn = e.target.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.textContent = 'Creation en cours...';

            callApi('mobile/create_offre.php', 'POST', payload)
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

            callApi('mobile/get_offre.php', 'GET', params)
                .then(renderSearchResult)
                .catch(function(err) {
                    document.getElementById('search-summary').innerHTML =
                        '<div class="alert alert-danger">Erreur reseau : ' + escapeHtml(err.message) + '</div>';
                });
        });
    });
})();
