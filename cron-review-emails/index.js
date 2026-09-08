/**
 * Servizio "una tantum" pensato per girare come Cron Job su Railway
 * (schedulazione impostata nelle Settings del servizio su Railway, non
 * qui nel codice). Ogni esecuzione chiama l'endpoint del sito che spedisce
 * le email "chiedi la recensione" ormai scadute, poi esce.
 *
 * Variabili d'ambiente richieste (impostate su Railway, su QUESTO
 * servizio, non su quello del sito):
 *   SITE_URL     es. https://sito-production-0710.up.railway.app
 *   CRON_SECRET  stesso valore impostato come CRON_SECRET sul servizio
 *                del sito principale
 */

const siteUrl = process.env.SITE_URL;
const cronSecret = process.env.CRON_SECRET;

if (!siteUrl || !cronSecret) {
  console.error('review-email cron: SITE_URL o CRON_SECRET mancanti, esco senza fare nulla.');
  process.exit(1);
}

const url = siteUrl.replace(/\/+$/, '') + '/api.php?action=send_due_review_emails&key=' + encodeURIComponent(cronSecret);

fetch(url)
  .then(async (res) => {
    let data = null;
    try { data = await res.json(); } catch (e) { /* risposta non JSON, ignorato */ }
    console.log('review-email cron: risposta', res.status, JSON.stringify(data));
    if (!res.ok || !data || data.ok !== true) {
      process.exitCode = 1;
    }
  })
  .catch((err) => {
    console.error('review-email cron: chiamata fallita', err);
    process.exitCode = 1;
  });
