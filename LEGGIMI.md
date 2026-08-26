# Micheal A. Collins — versione live (database)

Questa è la versione "vera" del sito: il catalogo libri vive in un
database MySQL sul tuo hosting, quindi ogni modifica che fai dal
pannello autore (aggiungere o togliere un libro, caricare una
copertina) diventa visibile subito a tutti i visitatori, senza dover
scaricare e ricaricare il file `index.html`.

Ho già testato tutta la parte tecnica (login, aggiunta, rimozione,
caricamento e ridimensionamento copertine, sicurezza) in locale con
PHP 8.4: funziona. Quello che segue è solo la parte di messa online,
che deve avvenire sul tuo hosting.

## Cosa contiene questa cartella

- `index.html` — il sito. Ora legge e scrive i libri tramite `api.php`
  invece che tenerli scritti dentro la pagina.
- `api.php` — il "motore": un solo file che risponde alle richieste
  del sito (elenco libri, aggiungi, rimuovi, login/logout).
- `config.php` — dove metti le credenziali del tuo database e la
  password del pannello autore (già pre-compilata con la password che
  hai scelto, "Figlidipitagora7@@", salvata in forma sicura).
- `schema.sql` — la struttura del database da importare una sola volta.
- `hash_password.php` — serve solo se in futuro vuoi cambiare la
  password del pannello autore.
- `.htaccess` (due copie: una qui, una dentro `uploads/`) — protezioni
  di sicurezza automatiche, non serve toccarle.
- `uploads/` — cartella vuota dove finiranno le copertine caricate.

## Requisiti

Il tuo hosting deve supportare PHP (versione 7.4 o superiore, va bene
anche l'8.x) e un database MySQL — esattamente quello che hai già,
visto che hai un account con MySQL disponibile. Praticamente tutti gli
hosting condivisi con cPanel (o pannelli simili) hanno entrambi.

## Passo 1 — Crea il database

Dal pannello del tuo hosting (su cPanel di solito si chiama "MySQL
Databases" o "Database MySQL"):

1. Crea un nuovo database (es. `collins_libri`).
2. Crea un utente MySQL con una password sicura.
3. Associa l'utente al database dandogli tutti i permessi.
4. Segna da qualche parte questi 4 valori: host (quasi sempre
   `localhost`), nome del database, nome utente, password.

## Passo 2 — Importa la struttura del database

Apri **phpMyAdmin** dal pannello del tuo hosting, seleziona il
database appena creato, vai su "Importa" (Import) e carica il file
`schema.sql` incluso qui. Questo crea la tabella dei libri e inserisce
i 3 libri di esempio già presenti sul sito.

## Passo 3 — Compila `config.php`

Apri `config.php` con un editor di testo e sostituisci questi 4 valori
con quelli del tuo database (presi al Passo 1):

```
DB_HOST
DB_NAME
DB_USER
DB_PASS
```

La password del pannello autore è già impostata correttamente: non
devi toccare `ADMIN_PASSWORD_HASH` a meno che tu non voglia cambiarla
(vedi in fondo a questa guida).

## Passo 4 — Carica i file sul server

Con File Manager del tuo hosting oppure via FTP, carica **tutto il
contenuto di questa cartella** (compresa la sottocartella `uploads/`)
nella cartella pubblica del tuo sito (di solito si chiama
`public_html` o `www`).

Verifica che la cartella `uploads/` abbia permessi di scrittura
(permessi 755 vanno bene quasi ovunque; se il pannello autore desse
errore nel salvare le copertine, prova 775).

## Passo 5 — Prova il sito

Apri il tuo dominio nel browser: dovresti vedere il sito con i 3 libri
di esempio. Clicca su "Author Panel" in fondo alla pagina, inserisci
la password, prova ad aggiungere un libro con copertina e verifica che
compaia subito nella libreria. Apri il sito da un altro dispositivo (o
in navigazione anonima) per controllare che il nuovo libro sia visibile
a tutti, non solo a te.

## Cambiare la password del pannello in futuro

1. Se hai accesso SSH al tuo hosting, esegui:
   ```
   php hash_password.php "LaTuaNuovaPassword"
   ```
   e copia il risultato.
2. Se non hai accesso SSH, apri nel browser (una sola volta):
   ```
   https://tuosito.com/hash_password.php?password=LaTuaNuovaPassword
   ```
   e copia il valore mostrato. Poi **cancella subito** il file
   `hash_password.php` dal server: se resta online chiunque potrebbe
   usarlo per generare hash a piacere.
3. Incolla il valore copiato in `config.php`, al posto del valore
   attuale di `ADMIN_PASSWORD_HASH`.

## Nota sulla versione su claude.ai

Il link claude.ai che hai usato finora resterà una versione statica
"demo" del sito: è comoda per farti un'idea o mostrarla a qualcuno
velocemente, ma non è collegata a questo database. Da quando pubblichi
questa versione sul tuo hosting, il sito "vero" — quello che i tuoi
lettori vedranno all'indirizzo del tuo dominio — è questo, e ogni
modifica che fai dal pannello autore qui sopra vale solo per questa
versione.
