# Pubblicare il sito su Railway

Railway funziona in modo diverso dall'hosting classico che avevamo
preparato prima (niente FTP, niente phpMyAdmin): pubblica il sito a
partire da un repository GitHub e builda l'app in automatico. Ho già
creato il progetto sul tuo account Railway, si chiama
**micheal-a-collins-author**. Mancano tre pezzi, tutti semplici:

1. mettere questo codice su GitHub
2. collegare quel repository al progetto Railway
3. aggiungere il database MySQL e un piccolo "Volume" per le copertine

Poi mi occupo io di collegare le variabili e generarti il link pubblico.

## 1. Metti il codice su GitHub

Se non hai già un repository per questo sito:

1. Vai su [github.com/new](https://github.com/new), crea un repository
   (anche privato va benissimo, es. `micheal-a-collins-author`), **senza**
   spuntare "Add a README" (lo aggiungiamo noi).
2. Sul tuo computer, apri il terminale nella cartella con questi file
   (quella che hai scaricato da me) ed esegui:
   ```
   git init
   git add .
   git commit -m "Sito Micheal A. Collins"
   git branch -M main
   git remote add origin https://github.com/TUO-UTENTE/micheal-a-collins-author.git
   git push -u origin main
   ```
   (Sostituisci `TUO-UTENTE` e il nome del repository con i tuoi.) Git ti
   chiederà di autenticarti con GitHub: segui la procedura che ti
   propone (di solito apre il browser).

Se hai già un repository con questo sito, salta questo passo e dimmi
solo il nome nel formato `tuo-utente/nome-repo`.

## 2. Dimmi il nome del repository

Scrivimi `tuo-utente/nome-repo` (esattamente come appare nell'indirizzo
GitHub, es. `andrea058/micheal-a-collins-author`): lo collego subito al
progetto Railway e parte la prima build.

**Nota:** la prima volta che Railway prova ad accedere a un tuo
repository privato, potrebbe chiederti di autorizzare la GitHub App di
Railway a vederlo: è un consenso che dai tu direttamente su GitHub,
richiesto una sola volta.

## 3. Aggiungi il database MySQL (due click, li fai tu dal pannello Railway)

Nel progetto **micheal-a-collins-author** su [railway.com](https://railway.com):

1. Clicca **+ New** → **Database** → **Add MySQL**.
2. Fatto. Railway crea il database e le variabili di connessione da
   solo: non devi copiare nessuna password, il nostro `config.php` le
   legge automaticamente.

## 4. Aggiungi un Volume per le copertine (altrettanto rapido)

Senza questo passaggio, ogni volta che pubblichi un aggiornamento del
sito le copertine caricate dal pannello autore spariscono: il
container su cui gira il sito viene ricreato da zero ad ogni deploy, e
solo un Volume rende una cartella permanente.

1. Nel progetto, apri il servizio del sito (quello creato dal tuo
   repository, non il database).
2. Vai su **Settings → Volumes → New Volume**.
3. Come **Mount path** inserisci: `/app/uploads`
4. Salva.

## Cosa faccio io una volta collegato il repository

- Imposto `RAILWAY_PHP_EXTENSIONS=gd` in modo che il ridimensionamento
  automatico delle copertine funzioni.
- Importo la struttura del database (`schema.sql`) nel MySQL che crei
  al passo 3.
- Genero il link pubblico del sito (un indirizzo tipo
  `qualcosa.up.railway.app`, che puoi in seguito sostituire con un tuo
  dominio).
- Verifico che il sito, il pannello autore e il caricamento copertine
  funzionino davvero, prima di dirti che è pronto.

## Cosa cambia rispetto alla versione per hosting classico

- Non serve più compilare `config.php` a mano: su Railway legge le
  credenziali del database da solo.
- Non serve più `hash_password.php` via FTP: per cambiare la password
  del pannello autore, generi il nuovo hash con lo stesso comando di
  prima e lo incolli come variabile d'ambiente `ADMIN_PASSWORD_HASH`
  nelle impostazioni del servizio su Railway (Variables), senza
  toccare il codice né rifare il deploy manualmente.
- I file `.htaccess` restano nel progetto ma su Railway non vengono
  usati (Railway non gira su Apache): non fanno male, semplicemente
  non servono a nulla qui. Se un giorno torni a un hosting classico,
  tornano utili automaticamente.
