// Assembles the static preview pages from a shared shell + per-page content.
// Dev-only tool for this preview/ sandbox — not part of the app.
const fs = require('fs');
const path = require('path');

const MENU = [
  { label: 'MOBILE', items: [
    { label: 'Hybride', link: 'mobile-hybride.html', enabled: true },
    { label: 'Postpayee', link: null, enabled: false },
    { label: 'Prepayee', link: null, enabled: false },
  ]},
  { label: 'FTTH', items: [
    { label: 'Postpayee', link: null, enabled: false },
    { label: 'Prepayee', link: 'ftth-prepayee.html', enabled: true },
  ]},
  { label: '4G', items: [
    { label: 'Offres 4G', link: null, enabled: false },
  ]},
];

function sidebar(activeLink) {
  let html = `<a class="sidebar-item home active" href="accueil.html">&#8962;&nbsp; Accueil</a>`;
  for (const cat of MENU) {
    html += `<div class="sidebar-cat">${cat.label}</div>`;
    for (const item of cat.items) {
      if (item.enabled) {
        const cls = item.link === activeLink ? 'sidebar-item active' : 'sidebar-item';
        html += `<a class="${cls}" href="${item.link}">${item.label}</a>`;
      } else {
        html += `<span class="sidebar-item disabled">${item.label}<span class="badge-soon">Bientot</span></span>`;
      }
    }
  }
  return html;
}

function shell({ title, activeLink, breadcrumb, body, extraHead = '' }) {
  return `<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <title>e-Config — ${title} (preview)</title>
  <link rel="shortcut icon" href="assets/orange_ico.png">
  <link href="tokens.css" rel="stylesheet">
  ${extraHead}
</head>
<body>
  <div class="shell">
    <div class="topbar">
      <div class="topbar-brand">
        <img src="assets/orange_2.png" alt="Orange">
        <span>e-Config</span>
      </div>
      <div class="topbar-user">
        <div class="topbar-avatar">XG</div>
        <span>XGKR4260</span>
        <a class="topbar-logout" href="connexion.html" title="Deconnexion">&#9211;</a>
      </div>
    </div>
    <div class="shell-body">
      <div class="sidebar">${sidebar(activeLink)}</div>
      <div class="content">
        <div class="breadcrumb">${breadcrumb}</div>
        ${body}
      </div>
    </div>
  </div>
</body>
</html>`;
}

const pages = {};

// ---- Accueil ----
pages['accueil.html'] = shell({
  title: 'Accueil',
  activeLink: '',
  breadcrumb: 'Accueil',
  body: `
    <h1 class="page-title">Bonjour XGKR4260</h1>
    <p class="page-lead">Choisissez une technologie et un type d'offre pour commencer. Les autres combinaisons seront activées à mesure que leurs API seront prêtes.</p>
    <div class="card-grid">
      <div class="card accent">
        <div class="card-top-row"><span class="card-icon">&#128241;</span><span class="card-chip dark">Disponible</span></div>
        <div class="card-eyebrow">MOBILE</div>
        <h3>Offre Hybride</h3>
        <p>Consulter et créer des offres avec leurs formules.</p>
        <a class="card-link" href="mobile-hybride.html">Ouvrir &rarr;</a>
      </div>
      <div class="card accent">
        <div class="card-top-row"><span class="card-icon">&#128225;</span><span class="card-chip dark">Disponible</span></div>
        <div class="card-eyebrow">FTTH</div>
        <h3>Offre Prépayée</h3>
        <p>Débits, volume inclus et montant de recharge.</p>
        <a class="card-link" href="ftth-prepayee.html">Ouvrir &rarr;</a>
      </div>
      <div class="card">
        <div class="card-top-row"><span class="card-icon">&#128202;</span><span class="card-chip muted">Bientôt disponible</span></div>
        <div class="card-eyebrow">MOBILE &middot; FTTH &middot; 4G</div>
        <h3>Autres offres</h3>
        <p>Postpayée, prépayée mobile et offres 4G arrivent avec leurs API.</p>
      </div>
    </div>`,
});

// ---- Mobile Hybride ----
pages['mobile-hybride.html'] = shell({
  title: 'Mobile — Hybride',
  activeLink: 'mobile-hybride.html',
  breadcrumb: 'Mobile &gt; Hybride',
  body: `
    <h1 class="page-title">Offres Mobile Hybride</h1>
    <div class="panel">
      <h3>Consulter une offre existante</h3>
      <div class="field-grid cols-3" style="align-items:end; margin-bottom: 24px;">
        <div><label class="label">TMCODE</label><input class="input" placeholder="Ex : 861"></div>
        <div><label class="label">SHDES</label><input class="input" placeholder="Ex : MOBC"></div>
        <div><button class="btn btn-dark" style="width:100%;">&#128269;&nbsp; Rechercher</button></div>
      </div>
      <table class="data-table">
        <thead><tr><th>SPCODE</th><th>Formule</th><th>SHDES</th><th>Prix (centimes)</th><th>Statut</th></tr></thead>
        <tbody></tbody>
      </table>
      <div class="empty-state">
        <div class="icon">&#128269;</div>
        <div class="title">Aucune offre affichée</div>
        <div>Saisissez un TMCODE ou un SHDES pour lancer une recherche.</div>
      </div>
    </div>

    <div class="panel">
      <h3>Créer une offre Hybride</h3>
      <p class="hint">Les champs marqués * sont obligatoires.</p>

      <div class="banner" style="margin-bottom: 24px;">
        <div class="banner-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="7" x2="12" y2="13"/><circle cx="12" cy="16.5" r="0.5" fill="currentColor"/></svg></div>
        <div class="banner-body">
          <p class="banner-title">L'offre n'a pas pu être créée : 2 champs à corriger</p>
          <p class="banner-text"><a href="#">Description courte (SHDES)</a> : ce code existe déjà<br><a href="#">Formule 1 &gt; SHDES</a> : format attendu D_***</p>
        </div>
      </div>

      <div class="step-title"><span class="step-badge">1</span> Informations générales</div>
      <div class="field-grid cols-3">
        <div><label class="label">Description longue *</label><input class="input" placeholder="Ex : Mobile Confort"></div>
        <div><label class="label">Description courte (SHDES) *</label><input class="input has-error" value="MOBC"><p style="color:var(--error); font-size:12px; margin-top:6px;">Ce SHDES est déjà utilisé par une autre offre.</p></div>
        <div><label class="label">Segment marché</label><select class="input"><option>B2C</option><option>B2B</option><option>TEST</option></select></div>
      </div>
    </div>`,
});

// ---- FTTH Prepayee ----
pages['ftth-prepayee.html'] = shell({
  title: 'FTTH — Prépayée',
  activeLink: 'ftth-prepayee.html',
  breadcrumb: 'FTTH &gt; Prépayée',
  body: `
    <h1 class="page-title">Offres FTTH Prépayée</h1>

    <div class="banner success" style="margin-bottom: 24px;">
      <div class="banner-icon">&#10003;</div>
      <div class="banner-body">
        <p class="banner-title">Offre &laquo; FTTR Confort Prep &raquo; créée</p>
        <p class="banner-text">Elle sera appliquée le 09/10/2026 à 08:50. SET_ID attribué : [SET_ID]. &nbsp;<a href="#">Voir l'offre</a></p>
      </div>
    </div>

    <div class="panel">
      <h3>Offres existantes</h3>
      <table class="data-table">
        <thead><tr><th>SET_ID</th><th>Intitulé</th><th>Prix</th><th>Débit</th><th>Volume</th><th>Durée</th></tr></thead>
        <tbody></tbody>
      </table>
      <div class="empty-state">Lancez une recherche par TMCODE ou SHDES pour afficher les offres.</div>
    </div>

    <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 24px;">
      <div class="panel" style="margin-bottom:0;">
        <h3>Créer une offre FTTH Prépayée</h3>
        <p class="hint">Les champs marqués * sont obligatoires.</p>
        <div class="step-title"><span class="step-badge">1</span> Informations générales</div>
        <div class="field-grid">
          <div style="grid-column: 1 / -1;"><label class="label">Description longue *</label><input class="input" placeholder="Ex : FTTR Confort Prep"></div>
          <div><label class="label">Description courte (SHDES) *</label><input class="input" placeholder="Ex : FTTRP"></div>
          <div><label class="label">Segment marché</label><select class="input"><option>B2C</option></select></div>
          <div><label class="label">Date de version *</label><input class="input" value="09/10/2026"></div>
          <div><label class="label">Date d'application *</label><input class="input" value="09/10/2026 08:50"></div>
          <div><label class="label">Durée</label><select class="input"><option>1 mois</option></select></div>
        </div>
      </div>
      <div class="summary-panel">
        <h4><i></i> Récapitulatif</h4>
        <div class="summary-row"><div class="label-sm">Offre</div><div class="value">&mdash;</div></div>
        <div class="summary-grid">
          <div class="summary-row"><div class="label-sm">Débit</div><div class="value">&mdash;</div></div>
          <div class="summary-row"><div class="label-sm">Volume</div><div class="value">&mdash;</div></div>
          <div class="summary-row"><div class="label-sm">Durée</div><div class="value">1 mois</div></div>
          <div class="summary-row"><div class="label-sm">Segment</div><div class="value">B2C</div></div>
        </div>
        <div class="summary-row"><div class="label-sm">Montant de la recharge</div><div class="value" style="color:var(--orange); font-size:22px;">&mdash; FCFA</div></div>
      </div>
    </div>`,
});

// ---- Messages reference ----
pages['messages.html'] = shell({
  title: 'Messages — référence',
  activeLink: '',
  breadcrumb: 'Référence de style',
  body: `
    <h1 class="page-title">Messages d'erreur et d'information</h1>
    <p class="page-lead">Couleurs fonctionnelles Orange : erreur, information, succès, avertissement.</p>
    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items:start;">
      <div class="panel">
        <h3>Bannières</h3>
        <div class="banner" style="margin-bottom:14px;"><div class="banner-icon">&#9888;</div><div class="banner-body"><p class="banner-title">Erreur</p><p class="banner-text">L'action a échoué. Indiquez la cause et comment corriger.</p></div></div>
        <div class="banner info" style="margin-bottom:14px;"><div class="banner-icon">i</div><div class="banner-body"><p class="banner-title">Information</p><p class="banner-text">Contexte utile, sans action obligatoire (ex : recherche sans résultat).</p></div></div>
        <div class="banner success" style="margin-bottom:14px;"><div class="banner-icon">&#10003;</div><div class="banner-body"><p class="banner-title">Succès</p><p class="banner-text">Confirmation d'une création ou d'un enregistrement.</p></div></div>
        <div class="banner warning"><div class="banner-icon">!</div><div class="banner-body"><p class="banner-title">Avertissement</p><p class="banner-text">Risque à signaler avant de continuer (ex : date d'application passée).</p></div></div>
      </div>
      <div>
        <div class="panel">
          <h3>Messages de champ</h3>
          <div class="field-grid">
            <div><label class="label">Champ en erreur *</label><input class="input has-error" value="M11"><p style="color:var(--error); font-size:12px; margin-top:6px;">Format attendu : D_ + 3 caractères.</p></div>
            <div><label class="label">Champ avec aide</label><input class="input" placeholder="Ex : 12711.86"><p style="color:var(--text-secondary); font-size:12px; margin-top:6px;">Montant HT, 2 décimales maximum.</p></div>
          </div>
        </div>
        <div class="panel">
          <h3>Notification (toast)</h3>
          <p class="hint">En bas à droite, disparaît après 5 s. Pour les confirmations courtes.</p>
          <div class="toast"><span class="ok">&#10003;</span> Offre enregistrée <a href="#">Voir</a> <button>&times;</button></div>
          <div class="toast"><span class="err">&#9888;</span> Connexion au service perdue <button class="btn btn-dark" style="padding:4px 10px; font-size:12px;">Réessayer</button> <button>&times;</button></div>
        </div>
      </div>
    </div>`,
});

for (const [file, html] of Object.entries(pages)) {
  fs.writeFileSync(path.join(__dirname, file), html);
  console.log('wrote', file);
}
